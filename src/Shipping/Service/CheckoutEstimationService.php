<?php

namespace App\Shipping\Service;

use App\Catalog\Entity\Product;
use App\Shared\Entity\StoreSettings;
use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\Carton;
use App\Shipping\Enum\DestinationZone;
use App\Shipping\Repository\CartonRepository;
use App\Shipping\Repository\ShippingRateRepository;
use App\Shipping\Service\CheckoutEstimation\CheckoutEstimationResult;
use App\Shipping\Service\CheckoutEstimation\ParcelEstimate;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Simule la répartition en colis et le prix de livraison pour un panier,
 * avant toute commande — aucune entité (Parcel, Order...) n'est créée ou
 * persistée ici, c'est une fonction pure de simulation, rejouable à
 * l'identique quand la commande sera réellement confirmée.
 *
 * Réutilise volontairement les mêmes règles que la préparation admin réelle
 * (ColissimoApiService::resolveParcelWeightKg / resolveVolumetricWeightApplicability)
 * pour que l'estimation affichée au client corresponde à ce qui sera
 * effectivement généré — dupliquées ici plutôt que partagées car les deux
 * services opèrent sur des données différentes (Parcel persisté vs panier
 * simulé) ; toute évolution de la règle doit être répercutée des deux côtés.
 */
class CheckoutEstimationService
{
    /**
     * Poids maximum réel autorisé par colis Colissimo (même plafond que
     * ColissimoApiService::resolveParcelWeightKg(), 30,00 kg) — le colisage
     * doit forcer un nouveau colis avant de dépasser cette limite, pas
     * seulement se fier au volume des cartons.
     */
    private const MAX_PARCEL_WEIGHT_GRAMS = 30000;

    public function __construct(
        private readonly CartonRepository $cartonRepository,
        private readonly ShippingRateRepository $shippingRateRepository,
        private readonly DestinationClassifier $destinationClassifier,
        private readonly ShippingZoneMapper $zoneMapper,
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * @param array<array{product: Product, quantity: int}> $cartItems
     */
    public function estimate(
        array $cartItems,
        CarrierMode $carrierMode,
        string $countryCode,
        ?string $postalCode
    ): CheckoutEstimationResult {
        if (empty($cartItems)) {
            return CheckoutEstimationResult::failure('Panier vide.');
        }

        $cartons = $this->cartonRepository->findActiveOrdered();
        if (empty($cartons)) {
            return CheckoutEstimationResult::failure('Aucun format de carton actif configuré (page Emballage).');
        }

        // Un carton par format, du plus petit au plus grand volume — on
        // cherche toujours le plus petit format suffisant, jamais le premier
        // trouvé dans un ordre arbitraire.
        usort($cartons, fn (Carton $a, Carton $b) => $a->getVolumeCm3() <=> $b->getVolumeCm3());

        $units = [];
        foreach ($cartItems as $line) {
            $product = $line['product'];
            if ($product->getWeightGrams() === null || $product->getWeightGrams() <= 0) {
                return CheckoutEstimationResult::failure(sprintf(
                    "Le produit « %s » n'a pas de poids renseigné, impossible d'estimer les frais de port.",
                    $product->getName()
                ));
            }
            if ($product->getVolumeCm3() === null) {
                return CheckoutEstimationResult::failure(sprintf(
                    "Le produit « %s » n'a pas de dimensions renseignées (fiche produit), impossible d'estimer les frais de port.",
                    $product->getName()
                ));
            }
            for ($i = 0; $i < $line['quantity']; $i++) {
                $units[] = $product;
            }
        }

        // Plus gros volume d'abord : place les contraintes fortes en premier,
        // limite le risque de devoir rouvrir un colis après coup.
        usort($units, fn (Product $a, Product $b) => $b->getVolumeCm3() <=> $a->getVolumeCm3());

        // Même critère que ColissimoApiService::buildDomesticParcelPayload()
        // (countryCode !== 'FR', pas la zone) : nécessaire ici déjà pour le
        // colisage, la marge CN23 pesant sur le plafond 30kg par colis.
        $requiresCn23Margin = strtoupper($countryCode) !== 'FR';

        try {
            $packedParcels = $this->packUnits($units, $cartons, $requiresCn23Margin);
        } catch (\RuntimeException $e) {
            return CheckoutEstimationResult::failure($e->getMessage());
        }

        $zone = $this->destinationClassifier->classify($postalCode, $countryCode);
        $rateZone = $this->zoneMapper->mapCountryToZone($countryCode);
        $settings = $this->em->getRepository(StoreSettings::class)->findOneBy([]);

        $estimates = [];
        foreach ($packedParcels as $packed) {
            $estimates[] = $this->estimateParcel(
                $packed['products'],
                $packed['carton'],
                $carrierMode,
                $countryCode,
                $zone,
                $rateZone,
                $settings
            );
        }

        // TVA répercutée : appliquée UNE seule fois sur le total HT de la
        // commande (port net + CAE + SMIC + suppléments de tous les colis),
        // comme sur la facture La Poste — pas colis par colis.
        $totalHt = round(array_sum(array_map(fn (ParcelEstimate $p) => $p->price, $estimates)), 2);
        $vatRate = ($settings !== null
            && $settings->getShippingVatRatePercent() !== null
            && $this->zoneMapper->isFrenchVatApplicable($countryCode))
            ? (float) $settings->getShippingVatRatePercent()
            : 0.0;
        $totalVat = round($totalHt * $vatRate / 100, 2);

        return CheckoutEstimationResult::success($estimates, $totalHt, $totalVat);
    }

    /**
     * Remplissage glouton : plus gros volume d'abord, chaque unité rejoint
     * le colis en cours si un format de carton peut encore contenir tout ce
     * qui y est déjà placé + la nouvelle unité (dimensions avec rotation +
     * volume cumulé) — au besoin en passant à un format plus grand. Si même
     * le plus grand format n'y suffit plus, le colis en cours est fermé et
     * un nouveau colis démarre avec cette unité.
     *
     * @param Product[] $units
     * @param Carton[] $cartonsAscByVolume
     * @return array<array{carton: Carton, products: Product[]}>
     */
    private function packUnits(array $units, array $cartonsAscByVolume, bool $requiresCn23Margin): array
    {
        $parcels = [];
        $current = [];
        $currentCarton = null;

        foreach ($units as $unit) {
            $candidate = [...$current, $unit];
            $carton = $this->findSmallestFittingCarton($candidate, $cartonsAscByVolume, $requiresCn23Margin);

            if ($carton !== null) {
                $current = $candidate;
                $currentCarton = $carton;
                continue;
            }

            if (empty($current)) {
                // Même seul, ce produit ne rentre dans aucun colis valide.
                throw new \RuntimeException($this->describeUnpackableUnit($unit, $cartonsAscByVolume, $requiresCn23Margin));
            }

            // Ferme le colis en cours tel quel, en ouvre un nouveau pour cette unité.
            $parcels[] = ['carton' => $currentCarton, 'products' => $current];

            $soloCarton = $this->findSmallestFittingCarton([$unit], $cartonsAscByVolume, $requiresCn23Margin);
            if ($soloCarton === null) {
                throw new \RuntimeException($this->describeUnpackableUnit($unit, $cartonsAscByVolume, $requiresCn23Margin));
            }
            $current = [$unit];
            $currentCarton = $soloCarton;
        }

        if (!empty($current)) {
            $parcels[] = ['carton' => $currentCarton, 'products' => $current];
        }

        return $parcels;
    }

    /**
     * Plus petit format (déjà trié par volume croissant) capable de
     * contenir tous les produits donnés : chacun doit tenir dimensionnellement
     * (3 dimensions triées, rotation autorisée), la somme des volumes ne
     * doit pas dépasser le volume du carton, et le poids réel du colis
     * (produits + emballage) ne doit pas dépasser MAX_PARCEL_WEIGHT_GRAMS
     * (30kg, même plafond que la génération réelle d'étiquette) — sans quoi
     * un colis "virtuel" pourrait dépasser ce que Colissimo accepte
     * réellement et retomber sur un tarif de base non pertinent faute de
     * tranche de poids correspondante.
     *
     * @param Product[] $products
     * @param Carton[] $cartonsAscByVolume
     */
    private function findSmallestFittingCarton(array $products, array $cartonsAscByVolume, bool $requiresCn23Margin): ?Carton
    {
        $totalVolume = array_sum(array_map(fn (Product $p) => $p->getVolumeCm3(), $products));
        $sumProductWeightGrams = array_sum(array_map(fn (Product $p) => $p->getWeightGrams(), $products));

        foreach ($cartonsAscByVolume as $carton) {
            if ($carton->getVolumeCm3() < $totalVolume) {
                continue;
            }

            $marginGrams = $carton->getEmptyWeightGrams() + 5 + ($requiresCn23Margin ? 30 : 0);
            if ($sumProductWeightGrams + $marginGrams > self::MAX_PARCEL_WEIGHT_GRAMS) {
                continue;
            }

            $allFit = true;
            foreach ($products as $product) {
                if (!$this->fitsDimensionally($product, $carton)) {
                    $allFit = false;
                    break;
                }
            }
            if ($allFit) {
                return $carton;
            }
        }

        return null;
    }

    /**
     * @param Carton[] $cartonsAscByVolume
     */
    private function describeUnpackableUnit(Product $unit, array $cartonsAscByVolume, bool $requiresCn23Margin): string
    {
        // Distingue la cause précise (dimensions/volume vs poids > 30kg) pour
        // un message exploitable, plutôt qu'un "ne rentre nulle part" générique.
        $fitsSomeCartonIgnoringWeight = false;
        foreach ($cartonsAscByVolume as $carton) {
            if ($carton->getVolumeCm3() >= $unit->getVolumeCm3() && $this->fitsDimensionally($unit, $carton)) {
                $fitsSomeCartonIgnoringWeight = true;
                break;
            }
        }

        if ($fitsSomeCartonIgnoringWeight) {
            return sprintf(
                "Le produit « %s » (%.2f kg) dépasse à lui seul la limite de 30 kg par colis Colissimo, "
                . "même dans le plus petit carton adapté à sa taille.",
                $unit->getName(),
                $unit->getWeightGrams() / 1000
            );
        }

        return sprintf(
            "Le produit « %s » ne rentre dans aucun format de carton disponible — "
            . "ajoutez un format plus grand (page Emballage).",
            $unit->getName()
        );
    }

    /** Test de fit avec rotation autorisée : compare les 3 dimensions triées. */
    private function fitsDimensionally(Product $product, Carton $carton): bool
    {
        $itemDims = [$product->getLengthCm(), $product->getWidthCm(), $product->getHeightCm()];
        sort($itemDims);
        $cartonDims = [$carton->getLengthCm(), $carton->getWidthCm(), $carton->getHeightCm()];
        sort($cartonDims);

        return $itemDims[0] <= $cartonDims[0]
            && $itemDims[1] <= $cartonDims[1]
            && $itemDims[2] <= $cartonDims[2];
    }

    /**
     * @param Product[] $products
     */
    private function estimateParcel(
        array $products,
        Carton $carton,
        CarrierMode $carrierMode,
        string $countryCode,
        DestinationZone $zone,
        string $rateZone,
        ?StoreSettings $settings
    ): ParcelEstimate {
        $sumProductWeightGrams = array_sum(array_map(fn (Product $p) => $p->getWeightGrams(), $products));

        // Même critère que ColissimoApiService::buildDomesticParcelPayload()
        // (countryCode !== 'FR', pas la zone) : l'Andorre reste en zone
        // FRANCE_METRO mais son countryCode est "AD", donc requiert quand
        // même la marge CN23 — un check par zone la raterait à tort.
        $requiresCn23Margin = strtoupper($countryCode) !== 'FR';
        $marginGrams = $carton->getEmptyWeightGrams() + 5 + ($requiresCn23Margin ? 30 : 0);
        $weightGrams = $sumProductWeightGrams + $marginGrams;

        // Même critère que resolveVolumetricWeightApplicability() : Outre-mer/
        // International uniquement, jamais Eco Outre-mer.
        $appliesVolumetricWeight = ($zone === DestinationZone::OUTRE_MER || $zone === DestinationZone::INTERNATIONAL)
            && $carrierMode->getColissimoProductCodeKey() !== 'outre_mer_eco';
        $volumetricWeightGrams = $appliesVolumetricWeight ? $carton->getVolumetricWeightGrams() : null;
        $billableWeightGrams = $volumetricWeightGrams !== null
            ? max($weightGrams, $volumetricWeightGrams)
            : $weightGrams;

        // findBestRate() retient déjà la tranche immédiatement supérieure au
        // poids donné — c'est l'arrondi "toujours au palier supérieur" voulu.
        $rate = $this->shippingRateRepository->findBestRate($carrierMode, $rateZone, $billableWeightGrams, $countryCode);
        $portNet = $rate ? $rate->getPrice() : (float) ($carrierMode->getBasePrice() ?? 0.0);

        [$cae, $smicCompensation, $supplements, $vat] =
            $this->computeSurcharges($portNet, $carrierMode, $countryCode, $zone, $settings);

        // Montant HT du colis (hors TVA — celle-ci est agrégée au niveau commande).
        $price = round($portNet + $cae + $smicCompensation + $supplements, 2);

        return new ParcelEstimate(
            cartonId: $carton->getId()->toRfc4122(),
            cartonName: $carton->getName(),
            productNames: array_values(array_unique(array_map(fn (Product $p) => $p->getName(), $products))),
            weightGrams: $weightGrams,
            volumetricWeightGrams: $volumetricWeightGrams,
            billableWeightGrams: $billableWeightGrams,
            portNet: $portNet,
            cae: $cae,
            smicCompensation: $smicCompensation,
            supplements: $supplements,
            vat: $vat,
            price: $price,
        );
    }

    /**
     * Applique les surcharges Colissimo (CAE, SMIC, sûreté, suppléments pays,
     * décarbonation, TVA répercutée) sur un port net déjà connu, et renvoie le
     * total arrondi. Utilisé par les chemins de repli (estimation précise
     * indisponible) pour que le prix affiché / facturé reste cohérent avec le
     * calcul complet — sans quoi désactiver un carton ou omettre les dimensions
     * d'un produit ferait chuter le tarif au port net nu.
     *
     * Ne s'applique qu'aux offres Colissimo ; renvoie le port net inchangé
     * pour Mondial Relay / Chronopost.
     */
    public function surchargedPortNet(
        float $portNet,
        CarrierMode $carrierMode,
        string $countryCode,
        ?string $postalCode
    ): float {
        $zone = $this->destinationClassifier->classify($postalCode, $countryCode);
        $settings = $this->em->getRepository(StoreSettings::class)->findOneBy([]);

        [$cae, $smic, $supplements, $vat] =
            $this->computeSurcharges($portNet, $carrierMode, $countryCode, $zone, $settings);

        return round($portNet + $cae + $smic + $supplements + $vat, 2);
    }

    /**
     * CAE, compensation SMIC, suppléments (sûreté + pays + décarbonation) et TVA
     * répercutée — notions du contrat Colissimo, appliquées uniquement aux
     * offres Colissimo (Mondial Relay / Chronopost ont leur propre facturation ;
     * un carrier_mode Colissimo est le seul à porter un colissimo_product_code_key).
     *
     * @return array{0: float, 1: float, 2: float, 3: float} [cae, smicCompensation, supplements, vat]
     */
    private function computeSurcharges(
        float $portNet,
        CarrierMode $carrierMode,
        string $countryCode,
        DestinationZone $zone,
        ?StoreSettings $settings
    ): array {
        $isColissimo = $carrierMode->getColissimoProductCodeKey() !== null;
        if ($settings === null || !$isColissimo) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $cae = 0.0;
        if (!in_array($carrierMode->getId(), $settings->getCaeExcludedCarrierModeIds(), true)) {
            $caePercent = match ($carrierMode->getEnergyCoefficientType()) {
                'routier' => $settings->getCaePercentRoutier(),
                'aerien' => $settings->getCaePercentAerien(),
                default => null,
            };
            if ($caePercent !== null) {
                $cae = round($portNet * $caePercent / 100, 2);
            }
        }

        $smicCompensation = 0.0;
        if ($settings->getSmicCompensationPercent() !== null) {
            // Sur le port net (HT après remise), comme le CAE, mais sans
            // exclusion d'offre (s'applique aussi à Colissimo Eco Outre-mer).
            $smicCompensation = round($portNet * $settings->getSmicCompensationPercent() / 100, 2);
        }

        $supplements = 0.0;
        // Sûreté internationale : UE / Europe hors UE / International —
        // jamais France métro ni Outre-mer.
        if (in_array($zone, [DestinationZone::UNION_EUROPEENNE, DestinationZone::EUROPE_HORS_UE, DestinationZone::INTERNATIONAL], true)) {
            $supplements += $settings->getSupplementInternationalSecurity() ?? 0.0;
        }
        // Suppléments par pays (Colissimo Domicile avec signature) — hors option DDP.
        $supplements += match (strtoupper($countryCode)) {
            'US' => $settings->getSupplementUs() ?? 0.0,
            'CN' => $settings->getSupplementChina() ?? 0.0,
            'GB' => $settings->getSupplementUk() ?? 0.0,
            default => 0.0,
        };
        // Décarbonation : systématique sur toutes les offres Colissimo.
        $supplements += $settings->getSupplementDecarbonation() ?? 0.0;
        $supplements = round($supplements, 2);

        // TVA répercutée : La Poste facture 20 % sur France/UE (0 % export),
        // non récupérable en franchise 293 B — assise sur port + CAE + SMIC +
        // suppléments, comme sur la facture La Poste.
        $vat = 0.0;
        if ($settings->getShippingVatRatePercent() !== null
            && $this->zoneMapper->isFrenchVatApplicable($countryCode)
        ) {
            $vat = round(($portNet + $cae + $smicCompensation + $supplements) * $settings->getShippingVatRatePercent() / 100, 2);
        }

        return [$cae, $smicCompensation, $supplements, $vat];
    }
}
