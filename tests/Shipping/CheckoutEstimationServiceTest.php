<?php

namespace App\Tests\Shipping;

use App\Catalog\Entity\Product;
use App\Shared\Entity\StoreSettings;
use App\Shipping\Entity\Carton;
use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\ShippingRate;
use App\Shipping\Repository\CartonRepository;
use App\Shipping\Repository\ShippingRateRepository;
use App\Shipping\Service\CheckoutEstimation\CheckoutEstimationResult;
use App\Shipping\Service\CheckoutEstimationService;
use App\Shipping\Service\DestinationClassifier;
use App\Shipping\Service\ShippingZoneMapper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests unitaires du simulateur de colisage / frais de port du checkout.
 *
 * Objectif principal : garantir que estimate() ne lève JAMAIS d'exception
 * non catchée vers le checkout / la création du PaymentIntent — tout cas
 * d'échec (panier vide, aucun carton, fiche produit incomplète, produit
 * trop grand ou trop lourd) doit revenir sous forme de
 * CheckoutEstimationResult::failure(), jamais sous forme de Throwable.
 *
 * Aucune base de données : les repositories et l'EntityManager sont mockés,
 * DestinationClassifier et ShippingZoneMapper sont utilisés en vrai (logique
 * pure).
 */
class CheckoutEstimationServiceTest extends TestCase
{
    private const PORT_NET = 10.0;

    private CartonRepository $cartonRepository;
    private ShippingRateRepository $shippingRateRepository;
    private CheckoutEstimationService $service;

    protected function setUp(): void
    {
        $this->cartonRepository = $this->createMock(CartonRepository::class);
        $this->shippingRateRepository = $this->createMock(ShippingRateRepository::class);

        // Tarif fixe et déterministe quel que soit le poids / la zone —
        // on teste ici le colisage et le poids, pas la grille tarifaire.
        $this->shippingRateRepository
            ->method('findBestRate')
            ->willReturn($this->makeRate(self::PORT_NET));

        // Par défaut : aucun StoreSettings → pas de CAE ni de suppléments,
        // prix = port net. Les tests suppléments rebâtissent le service via
        // serviceWithSettings().
        $this->service = $this->buildService(null);
    }

    private function buildService(?StoreSettings $settings): CheckoutEstimationService
    {
        $settingsRepo = $this->createMock(EntityRepository::class);
        $settingsRepo->method('findOneBy')->willReturn($settings);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->with(StoreSettings::class)->willReturn($settingsRepo);

        return new CheckoutEstimationService(
            $this->cartonRepository,
            $this->shippingRateRepository,
            new DestinationClassifier(new NullLogger()),
            new ShippingZoneMapper(),
            $em,
        );
    }

    private function serviceWithSettings(StoreSettings $settings): CheckoutEstimationService
    {
        return $this->buildService($settings);
    }

    // ------------------------------------------------------------------
    // Cas d'échec : doivent renvoyer failure(), jamais throw
    // ------------------------------------------------------------------

    public function testEmptyCartReturnsFailure(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        $result = $this->service->estimate([], $this->makeCarrierMode(), 'FR', '75001');

        $this->assertFalse($result->success);
        $this->assertSame('Panier vide.', $result->error);
    }

    public function testNoCartonConfiguredReturnsFailure(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([]);

        $result = $this->service->estimate(
            [['product' => $this->makeProduct(), 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('carton', strtolower((string) $result->error));
    }

    public function testProductWithoutWeightReturnsFailure(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        $product = $this->makeProduct(weightGrams: null);

        $result = $this->service->estimate(
            [['product' => $product, 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString($product->getName(), (string) $result->error);
    }

    public function testProductWithoutDimensionsReturnsFailure(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        $product = $this->makeProduct(lengthCm: null);

        $result = $this->service->estimate(
            [['product' => $product, 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('dimensions', strtolower((string) $result->error));
    }

    public function testProductTooLargeForEveryCartonReturnsFailure(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        // Carton par défaut : 30 × 20 × 15. Produit 200 × 200 × 200.
        $product = $this->makeProduct(lengthCm: 200, widthCm: 200, heightCm: 200);

        $result = $this->service->estimate(
            [['product' => $product, 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('carton', strtolower((string) $result->error));
    }

    public function testProductHeavierThanColissimoLimitReturnsFailure(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        // Rentre dans le carton, mais 31 kg > plafond 30 kg par colis.
        $product = $this->makeProduct(weightGrams: 31_000);

        $result = $this->service->estimate(
            [['product' => $product, 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertFalse($result->success);
        $this->assertStringContainsString('30 kg', (string) $result->error);
    }

    // ------------------------------------------------------------------
    // Chemin nominal : poids et colisage
    // ------------------------------------------------------------------

    public function testSingleParcelDomesticWeightIncludesEmptyCartonPlusTape(): void
    {
        $carton = $this->makeCarton(emptyWeightGrams: 200);
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$carton]);

        $result = $this->service->estimate(
            [['product' => $this->makeProduct(weightGrams: 500), 'quantity' => 2]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->parcels);

        // 2 × 500 g produits + 200 g carton + 5 g scotch, pas de marge CN23 en FR.
        $this->assertSame(1205, $result->parcels[0]->weightGrams);
        $this->assertNull($result->parcels[0]->volumetricWeightGrams);
        $this->assertSame(1205, $result->parcels[0]->billableWeightGrams);
    }

    public function testNonFrenchDestinationAddsCn23Margin(): void
    {
        $carton = $this->makeCarton(emptyWeightGrams: 200);
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$carton]);

        // Allemagne = Union européenne : marge CN23 (+30 g) mais pas de poids
        // volumétrique (réservé Outre-mer / International).
        $result = $this->service->estimate(
            [['product' => $this->makeProduct(weightGrams: 500), 'quantity' => 1]],
            $this->makeCarrierMode(),
            'DE',
            '10115',
        );

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->parcels);
        // 500 g + 200 g + 5 g + 30 g CN23.
        $this->assertSame(735, $result->parcels[0]->weightGrams);
        $this->assertNull($result->parcels[0]->volumetricWeightGrams);
    }

    public function testOverflowingVolumeSplitsIntoSeveralParcels(): void
    {
        // Carton 30 × 20 × 15 = 9 000 cm³. 10 unités de 1 000 cm³ → 10 000 cm³
        // de contenu : impossible en un seul colis.
        $carton = $this->makeCarton();
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$carton]);

        $result = $this->service->estimate(
            [['product' => $this->makeProduct(weightGrams: 100, lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 10]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(2, count($result->parcels));
    }

    public function testVolumetricWeightAppliesOnInternationalDestination(): void
    {
        // Carton 40 × 30 × 20 = 24 000 cm³ → poids volumétrique 24000/5000 kg = 4 800 g.
        $carton = $this->makeCarton(lengthCm: 40, widthCm: 30, heightCm: 20, emptyWeightGrams: 200);
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$carton]);

        $result = $this->service->estimate(
            [['product' => $this->makeProduct(weightGrams: 100, lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 1]],
            $this->makeCarrierMode('international'),
            'US',
            null,
        );

        $this->assertTrue($result->success);
        $this->assertCount(1, $result->parcels);
        $this->assertSame(4800, $result->parcels[0]->volumetricWeightGrams);
        // Poids réel (100 + 200 + 5 + 30 = 335 g) < volumétrique → facturé au volumétrique.
        $this->assertSame(4800, $result->parcels[0]->billableWeightGrams);
    }

    public function testVolumetricWeightNeverAppliesOnEcoOutreMerOffer(): void
    {
        $carton = $this->makeCarton(lengthCm: 40, widthCm: 30, heightCm: 20, emptyWeightGrams: 200);
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$carton]);

        $result = $this->service->estimate(
            [['product' => $this->makeProduct(weightGrams: 100, lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 1]],
            $this->makeCarrierMode('outre_mer_eco'),
            'MQ',
            '97200',
        );

        $this->assertTrue($result->success);
        $this->assertNull($result->parcels[0]->volumetricWeightGrams);
    }

    // ------------------------------------------------------------------
    // Suppléments : CAE, SMIC, surcharges par pays, TVA
    // ------------------------------------------------------------------

    public function testNoSettingsMeansPriceEqualsPortNet(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        $result = $this->service->estimate(
            [['product' => $this->makeProduct(), 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertTrue($result->success);
        $this->assertSame(self::PORT_NET, $result->parcels[0]->price);
        $this->assertSame(0.0, $result->parcels[0]->cae);
        $this->assertSame(0.0, $result->parcels[0]->smicCompensation);
        $this->assertSame(0.0, $result->parcels[0]->supplements);
        $this->assertSame(0.0, $result->parcels[0]->vat);
    }

    public function testShippingVatAppliedOnFranceAndEuOnly(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn(
            [$this->makeCarton(lengthCm: 40, widthCm: 30, heightCm: 20, emptyWeightGrams: 200)]
        );
        $settings = (new StoreSettings())->setShippingVatRatePercent(20.0);
        $service = $this->serviceWithSettings($settings);

        $product = fn () => ['product' => $this->makeProduct(weightGrams: 300, lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 1];

        // France : 20 % sur le port net (10,00 €) → 2,00 € de TVA.
        $fr = $service->estimate([$product()], $this->makeCarrierMode(), 'FR', '75001');
        $this->assertSame(2.0, $fr->parcels[0]->vat);
        $this->assertSame(12.0, $fr->parcels[0]->price);

        // Allemagne (UE) : TVA aussi.
        $de = $service->estimate([$product()], $this->makeCarrierMode('union_europeenne'), 'DE', '10115');
        $this->assertSame(2.0, $de->parcels[0]->vat);

        // Royaume-Uni : zone tarifaire 'union_europeenne' mais export → 0 % TVA.
        $gb = $service->estimate([$product()], $this->makeCarrierMode('union_europeenne'), 'GB', 'EC1A 1BB');
        $this->assertSame(0.0, $gb->parcels[0]->vat);

        // Suisse : hors UE → 0 %.
        $ch = $service->estimate([$product()], $this->makeCarrierMode('union_europeenne'), 'CH', '8001');
        $this->assertSame(0.0, $ch->parcels[0]->vat);

        // États-Unis : export → 0 %.
        $us = $service->estimate([$product()], $this->makeCarrierMode('international'), 'US', null);
        $this->assertSame(0.0, $us->parcels[0]->vat);

        // Martinique (Outre-mer) : hors territoire TVA → 0 %.
        $mq = $service->estimate([$product()], $this->makeCarrierMode('outre_mer'), 'MQ', '97200');
        $this->assertSame(0.0, $mq->parcels[0]->vat);
    }

    public function testShippingVatAssietteIncludesCaeSmicAndSupplements(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);
        $carrierMode = $this->makeCarrierMode('france_metro', 'routier');
        $settings = (new StoreSettings())
            ->setCaePercentRoutier(10.0)          // 10 % de 10,00 = 1,00
            ->setSmicCompensationPercent(1.0)     // 1 % de 10,00 = 0,10
            ->setSupplementDecarbonation(0.05)
            ->setShippingVatRatePercent(20.0);
        $service = $this->serviceWithSettings($settings);

        $result = $service->estimate(
            [['product' => $this->makeProduct(), 'quantity' => 1]],
            $carrierMode,
            'FR',
            '75001',
        );

        $this->assertTrue($result->success);
        // Assiette = 10,00 + 1,00 + 0,10 + 0,05 = 11,15 ; TVA 20 % = 2,23.
        $this->assertSame(2.23, $result->parcels[0]->vat);
        $this->assertSame(13.38, $result->parcels[0]->price);
    }

    public function testChinaSupplementAppliedOnlyForCn(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn(
            [$this->makeCarton(lengthCm: 40, widthCm: 30, heightCm: 20)]
        );
        $settings = (new StoreSettings())->setSupplementChina(6.0);
        $service = $this->serviceWithSettings($settings);

        $cn = $service->estimate(
            [['product' => $this->makeProduct(lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 1]],
            $this->makeCarrierMode('international'),
            'CN',
            null,
        );
        $this->assertTrue($cn->success);
        $this->assertSame(6.0, $cn->parcels[0]->supplements);
        $this->assertSame(self::PORT_NET + 6.0, $cn->parcels[0]->price);

        $us = $service->estimate(
            [['product' => $this->makeProduct(lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 1]],
            $this->makeCarrierMode('international'),
            'US',
            null,
        );
        $this->assertSame(0.0, $us->parcels[0]->supplements);
    }

    public function testUkSupplementApplied(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);
        $settings = (new StoreSettings())->setSupplementUk(4.4);
        $service = $this->serviceWithSettings($settings);

        $result = $service->estimate(
            [['product' => $this->makeProduct(weightGrams: 300), 'quantity' => 1]],
            $this->makeCarrierMode('union_europeenne'),
            'GB',
            'EC1A 1BB',
        );

        $this->assertTrue($result->success);
        $this->assertSame(4.4, $result->parcels[0]->supplements);
        $this->assertSame(self::PORT_NET + 4.4, $result->parcels[0]->price);
    }

    public function testColissimoSupplementsNotAppliedToNonColissimoCarrier(): void
    {
        // Un carrier_mode sans colissimo_product_code_key = Mondial Relay / Chronopost :
        // pas de CAE, pas de SMIC, pas de décarbonation, pas de sûreté.
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);
        $carrierMode = $this->makeCarrierMode(productCodeKey: null, energyType: 'routier');
        $settings = (new StoreSettings())
            ->setCaePercentRoutier(13.83)
            ->setSmicCompensationPercent(1.0)
            ->setSupplementDecarbonation(0.05)
            ->setSupplementInternationalSecurity(0.20)
            ->setShippingVatRatePercent(20.0);
        $service = $this->serviceWithSettings($settings);

        $result = $service->estimate(
            [['product' => $this->makeProduct(weightGrams: 300), 'quantity' => 1]],
            $carrierMode,
            'DE', // UE : la sûreté s'appliquerait pour du Colissimo
            '10115',
        );

        $this->assertTrue($result->success);
        $this->assertSame(0.0, $result->parcels[0]->cae);
        $this->assertSame(0.0, $result->parcels[0]->smicCompensation);
        $this->assertSame(0.0, $result->parcels[0]->supplements);
        $this->assertSame(0.0, $result->parcels[0]->vat);
        // Mondial Relay / Chronopost : uniquement le port net, aucun ajout Colissimo.
        $this->assertSame(self::PORT_NET, $result->parcels[0]->price);
    }

    public function testSmicCompensationAppliedOnPortNet(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);
        $settings = (new StoreSettings())->setSmicCompensationPercent(1.0);
        $service = $this->serviceWithSettings($settings);

        $result = $service->estimate(
            [['product' => $this->makeProduct(), 'quantity' => 1]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertTrue($result->success);
        // 1 % de 10,00 € de port net = 0,10 €.
        $this->assertSame(0.10, $result->parcels[0]->smicCompensation);
        $this->assertSame(10.10, $result->parcels[0]->price);
    }

    public function testSmicCompensationAppliesEvenWhenCarrierExcludedFromCae(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);
        $carrierMode = $this->makeCarrierMode('outre_mer_eco');
        $settings = (new StoreSettings())
            ->setSmicCompensationPercent(1.0)
            ->setCaePercentRoutier(5.0)
            ->setCaeExcludedCarrierModeIds([$carrierMode->getId()]);
        $service = $this->serviceWithSettings($settings);

        $result = $service->estimate(
            [['product' => $this->makeProduct(), 'quantity' => 1]],
            $carrierMode,
            'MQ',
            '97200',
        );

        $this->assertTrue($result->success);
        $this->assertSame(0.0, $result->parcels[0]->cae);
        $this->assertSame(0.10, $result->parcels[0]->smicCompensation);
    }

    public function testTotalPriceSumsAllParcels(): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);
        $settings = (new StoreSettings())->setSupplementDecarbonation(0.05);
        $service = $this->serviceWithSettings($settings);

        // Volume forçant 2 colis (cf. testOverflowingVolumeSplitsIntoSeveralParcels).
        $result = $service->estimate(
            [['product' => $this->makeProduct(weightGrams: 100, lengthCm: 10, widthCm: 10, heightCm: 10), 'quantity' => 10]],
            $this->makeCarrierMode(),
            'FR',
            '75001',
        );

        $this->assertTrue($result->success);
        $this->assertGreaterThanOrEqual(2, count($result->parcels));
        $expected = round(array_sum(array_map(fn ($p) => $p->price, $result->parcels)), 2);
        $this->assertSame($expected, $result->totalPrice);
    }

    // ------------------------------------------------------------------
    // Repli : surchargedPortNet() applique les mêmes surcharges Colissimo
    // que l'estimation complète, pour que désactiver un carton / omettre
    // les dimensions ne fasse pas chuter le tarif au port net nu.
    // ------------------------------------------------------------------

    public function testSurchargedPortNetAppliesColissimoSurchargesOnFallbackPrice(): void
    {
        $settings = (new StoreSettings())
            ->setCaePercentRoutier(13.83)
            ->setSmicCompensationPercent(1.0)
            ->setShippingVatRatePercent(20.0)
            ->setSupplementDecarbonation(0.05);
        $service = $this->serviceWithSettings($settings);

        // Port net 7,71 € (repli grille France) :
        //   CAE  = 7,71 × 13,83 % = 1,07
        //   SMIC = 7,71 × 1 %     = 0,08
        //   décarbonation         = 0,05
        //   TVA  = (7,71+1,07+0,08+0,05) × 20 % = 1,78
        //   total = 10,69
        $total = $service->surchargedPortNet(
            7.71,
            $this->makeCarrierMode('france_metro', 'routier'),
            'FR',
            '75001',
        );

        $this->assertSame(10.69, $total);
    }

    public function testSurchargedPortNetLeavesNonColissimoUntouched(): void
    {
        $settings = (new StoreSettings())
            ->setCaePercentRoutier(13.83)
            ->setShippingVatRatePercent(20.0);
        $service = $this->serviceWithSettings($settings);

        $total = $service->surchargedPortNet(7.49, $this->makeCarrierMode(null), 'FR', '75001');

        $this->assertSame(7.49, $total);
    }

    // ------------------------------------------------------------------
    // Robustesse : aucune entrée ne doit provoquer d'exception
    // ------------------------------------------------------------------

    #[DataProvider('provideRoughInputs')]
    public function testEstimateNeverThrows(?string $country, ?string $postalCode, int $quantity): void
    {
        $this->cartonRepository->method('findActiveOrdered')->willReturn([$this->makeCarton()]);

        try {
            $result = $this->service->estimate(
                [['product' => $this->makeProduct(), 'quantity' => $quantity]],
                $this->makeCarrierMode(),
                (string) $country,
                $postalCode,
            );
        } catch (\Throwable $e) {
            $this->fail(sprintf(
                'estimate() a levé %s : %s (country=%s, postalCode=%s, qty=%d)',
                $e::class,
                $e->getMessage(),
                var_export($country, true),
                var_export($postalCode, true),
                $quantity,
            ));
        }

        $this->assertInstanceOf(CheckoutEstimationResult::class, $result);
    }

    public static function provideRoughInputs(): iterable
    {
        yield 'pays inconnu' => ['ZZ', null, 1];
        yield 'pays vide' => ['', null, 1];
        yield 'pays minuscule' => ['fr', '75001', 1];
        yield 'code postal exotique' => ['US', 'ABC-123', 1];
        yield 'quantité nulle' => ['FR', '75001', 0];
        yield 'grosse quantité' => ['FR', '75001', 250];
        yield 'Andorre' => ['AD', 'AD500', 1];
        yield 'Outre-mer' => ['MQ', '97200', 3];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function makeProduct(
        ?int $weightGrams = 500,
        ?int $lengthCm = 10,
        ?int $widthCm = 10,
        ?int $heightCm = 10,
        string $name = 'Produit test',
    ): Product {
        return (new Product())
            ->setName($name)
            ->setSlug('produit-test')
            ->setWeightGrams($weightGrams)
            ->setLengthCm($lengthCm)
            ->setWidthCm($widthCm)
            ->setHeightCm($heightCm);
    }

    private function makeCarton(
        int $lengthCm = 30,
        int $widthCm = 20,
        int $heightCm = 15,
        int $emptyWeightGrams = 150,
        string $name = 'Carton test',
    ): Carton {
        return (new Carton())
            ->setName($name)
            ->setLengthCm($lengthCm)
            ->setWidthCm($widthCm)
            ->setHeightCm($heightCm)
            ->setEmptyWeightGrams($emptyWeightGrams);
    }

    private function makeCarrierMode(?string $productCodeKey = 'france_metro', ?string $energyType = null): CarrierMode
    {
        $carrierMode = $this->createMock(CarrierMode::class);
        $carrierMode->method('getId')->willReturn(1);
        $carrierMode->method('getColissimoProductCodeKey')->willReturn($productCodeKey);
        $carrierMode->method('getBasePrice')->willReturn(5.0);
        $carrierMode->method('getEnergyCoefficientType')->willReturn($energyType);

        return $carrierMode;
    }

    private function makeRate(float $price): ShippingRate
    {
        return (new ShippingRate())
            ->setZone('FR')
            ->setPrice($price)
            ->setMinWeightGrams(0)
            ->setMaxWeightGrams(30_000);
    }
}
