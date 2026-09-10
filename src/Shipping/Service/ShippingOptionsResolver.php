<?php

namespace App\Shipping\Service;

use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\ShippingRate;
use App\Shipping\Repository\CarrierModeRepository;
use App\Shipping\Repository\ShippingRateRepository;
use Psr\Log\LoggerInterface;

/**
 * Service qui résout les options de livraison disponibles
 * selon destination, poids, etc.
 */
class ShippingOptionsResolver
{
    public function __construct(
        private CarrierModeRepository $carrierModeRepository,
        private ShippingRateRepository $shippingRateRepository,
        private ShippingZoneMapper $zoneMapper,
        private CheckoutEstimationService $checkoutEstimationService,
        private LoggerInterface $shippingLogger,
    ) {}

    /**
     * Retourne toutes les options de livraison disponibles
     * pour une destination et un poids donnés
     *
     * Quand $cartItems est fourni, le prix de chaque option est recalculé
     * par CheckoutEstimationService (répartition en colis + poids
     * volumétrique + CAE + suppléments — les mêmes règles que la génération
     * réelle de l'étiquette). En cas d'échec de cette estimation (ex :
     * produit sans dimensions renseignées), l'option retombe silencieusement
     * sur l'ancien calcul au tarif de base (poids total, sans colisage) —
     * pour ne jamais faire disparaître une option de livraison au checkout
     * à cause d'une fiche produit incomplète.
     *
     * @param string $countryCode Code pays (FR, GP, BE, etc.)
     * @param int $weightGrams Poids total en grammes
     * @param array<array{product: \App\Catalog\Entity\Product, quantity: int}> $cartItems
     * @param string|null $postalCode
     * @return array [
     *   {
     *     'carrierMode': CarrierMode,
     *     'price': float,
     *     'estimatedDays': int,
     *     'carrierName': string,
     *     'modeName': string,
     *     'requiresPickupPoint': bool,
     *     'accuratePricing': bool,
     *     'parcels': array|null
     *   }
     * ]
     */
    public function getAvailableOptions(string $countryCode, int $weightGrams, array $cartItems = [], ?string $postalCode = null): array
    {
        $zone = $this->zoneMapper->mapCountryToZone($countryCode);
        return $this->resolveOptions($countryCode, $weightGrams, $zone, $cartItems, $postalCode);
    }

    private function resolveOptions(string $countryCode, int $weightGrams, string $zone, array $cartItems, ?string $postalCode): array
    {
        // 1. Zone déjà calculée par l'appelant

        // 2. Récupérer toutes les CarrierModes supportant cette zone
        $carrierModes = $this->carrierModeRepository->findByZone($zone);


        // 3. Filtrer par poids et restriction géographique
        $availableOptions = [];
        foreach ($carrierModes as $carrierMode) {
            $carrier = $carrierMode->getCarrier();

            // Restriction par pays (ex: Mondial Relay Point Relais EU → BE, ES, PT, LU, IT, PL, NL uniquement)
            if (!$carrierMode->isAllowedForCountry($countryCode)) {
                continue;
            }

            // Vérifier que le carrier peut gérer ce poids
            if ($weightGrams < $carrier->getMinWeightGrams() ||
                $weightGrams > $carrier->getMaxWeightGrams()) {
                continue;
            }

            // 4. Récupérer le tarif selon le poids
            $shippingRate = $this->shippingRateRepository->findBestRate(
                $carrierMode,
                $zone,
                $weightGrams,
                $countryCode
            );

            // Prix = tarif trouvé OU prix de base du CarrierMode
            // (repli utilisé aussi si l'estimation précise échoue ci-dessous)
            $price = $shippingRate
                ? $shippingRate->getPrice()
                : $carrierMode->getBasePrice();

            $accuratePricing = false;
            $parcels = null;
            if (!empty($cartItems)) {
                $estimation = $this->checkoutEstimationService->estimate($cartItems, $carrierMode, $countryCode, $postalCode);
                if ($estimation->success) {
                    $price = $estimation->totalPrice;
                    $accuratePricing = true;
                    $parcels = array_map(fn ($p) => [
                        'cartonName' => $p->cartonName,
                        'productNames' => $p->productNames,
                        'weightGrams' => $p->weightGrams,
                        'volumetricWeightGrams' => $p->volumetricWeightGrams,
                        'billableWeightGrams' => $p->billableWeightGrams,
                        // Détail du prix (utilisé pour l'affichage debug côté admin/dev)
                        'portNet' => $p->portNet,
                        'cae' => $p->cae,
                        'smicCompensation' => $p->smicCompensation,
                        'supplements' => $p->supplements,
                        'vat' => $p->vat,
                        'price' => $p->price,
                    ], $estimation->parcels);
                } else {
                    $this->shippingLogger->warning('Estimation checkout précise indisponible, repli sur le tarif de base', [
                        'carrierModeId' => $carrierMode->getId(),
                        'reason' => $estimation->error,
                    ]);
                }
            }

            $estimatedDays = $carrierMode->getEstimatedDeliveryDays()
                ?? $carrierMode->getDeliveryMaxDays()
                ?? $carrierMode->getDeliveryMinDays();

            $availableOptions[] = [
                'carrierMode' => $carrierMode,
                'carrierModeId' => $carrierMode->getId(),
                'price' => $price,
                'accuratePricing' => $accuratePricing,
                'parcels' => $parcels,
                'estimatedDays' => $estimatedDays,
                'deliveryDelay' => [
                    'minDays' => $carrierMode->getDeliveryMinDays(),
                    'maxDays' => $carrierMode->getDeliveryMaxDays(),
                    'unit' => $carrierMode->getDeliveryDaysUnit(),
                    'note' => $carrierMode->getDeliveryDaysNote(),
                ],
                'carrierName' => $carrier->getName(),
                'carrierCode' => $carrier->getCode(),
                'modeName' => $carrierMode->getShippingMode()->getName(),
                'modeCode' => $carrierMode->getShippingMode()->getCode(),
                'displayName' => $carrierMode->getDisplayName(),
                'requiresPickupPoint' => $carrierMode->getShippingMode()->requiresPickupPoint(),
                'icon' => $carrierMode->getShippingMode()->getIcon(),
                'zone' => $zone,
            ];
        }

        // 5. Trier par prix (moins cher en premier)
        usort($availableOptions, fn($a, $b) => $a['price'] <=> $b['price']);

        return $availableOptions;
    }

    /**
     * Résout le code pays depuis une adresse ou un code postal
     */
    public function resolveCountryCode(?string $country, ?string $postalCode): string
    {
        // 1. Si pays fourni et valide
        if ($country && strlen($country) === 2) {
            $country = strtoupper($country);

            // FR uniquement : raffiner via CP pour détecter les DOM-TOM (97xxx, 98xxx)
            // MC et AD exclus : leurs CP ressemblent à des CP français mais ils ont leur propre code pays
            if ($country === 'FR' && $postalCode) {
                $deduced = $this->deduceCountryFromPostalCode($postalCode);
                if ($deduced) {
                    return $deduced;
                }
            }

            return $country;
        }

        // 2. Sinon, tenter la déduction par code postal (FR/OM/AD/MC)
        if ($postalCode) {
            $deduced = $this->deduceCountryFromPostalCode($postalCode);
            if ($deduced) {
                return $deduced;
            }
        }

        return 'FR'; // Défaut
    }

    private function deduceCountryFromPostalCode(string $postalCode): ?string
    {
        // Normalize: remove non-digits and uppercase (for alpha prefixes)
        $raw = $postalCode;
        $postalCode = preg_replace('/\s+/', '', strtoupper($postalCode));

        // Andorre (codes like AD...)
        if (str_starts_with($postalCode, 'AD')) {
            return 'AD';
        }

        // Keep only digits for numeric postal codes
        $digits = preg_replace('/\D+/', '', $postalCode);

        // If more than 5 digits (e.g. user provided extra digit), take the first 5
        if (strlen($digits) > 5) {
            $digits = substr($digits, 0, 5);
        }

        if (!preg_match('/^\d{5}$/', $digits)) {
            return null;
        }

        $cp = (int) $digits;

        // DOM-TOM mapping (même logique que ColissimoApiService)
        if ($cp === 97024 || ($cp >= 97051 && $cp <= 97080) || ($cp >= 97149 && $cp <= 97150)) {
            return 'MF';
        }
        if (($cp >= 97012 && $cp <= 97022) || $cp === 97049 || ($cp >= 97095 && $cp <= 97099) || $cp === 97133) {
            return 'BL';
        }
        if (($cp >= 97100 && $cp <= 97132) || ($cp >= 97134 && $cp <= 97148) || ($cp >= 97151 && $cp <= 97199)) {
            return 'GP';
        }
        if ($cp >= 97200 && $cp <= 97299) {
            return 'MQ';
        }
        if ($cp >= 97300 && $cp <= 97399) {
            return 'GF';
        }
        if (($cp >= 97400 && $cp <= 97490) || ($cp >= 97820 && $cp <= 97899)) {
            return 'RE';
        }
        if ($cp >= 97600 && $cp <= 97690) {
            return 'YT';
        }
        if ($cp === 97500) {
            return 'PM';
        }

        // OM2 (NC, PF, WF, TF) via CP
        if ($cp >= 98800 && $cp <= 98899) {
            return 'NC';
        }
        if ($cp >= 98700 && $cp <= 98799) {
            return 'PF';
        }
        if ($cp >= 98600 && $cp <= 98699) {
            return 'WF';
        }
        if ($cp >= 98400 && $cp <= 98499) {
            return 'TF';
        }

        // France métropolitaine + Monaco
        if (($cp >= 1 && $cp <= 95999) || ($cp >= 98000 && $cp <= 98091)) {
            return 'FR';
        }

        return null;
    }
}