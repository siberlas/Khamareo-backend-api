<?php

namespace App\Shipping\Service;

use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\ShippingRate;
use App\Shipping\Repository\CarrierModeRepository;
use App\Shipping\Repository\ShippingRateRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Service qui résout les options de livraison disponibles
 * selon destination, poids, etc.
 */
class ShippingOptionsResolver
{
    private const TARIFF_NOTE = 'classification tarifaire Colissimo – indicative';

    // Tranches de poids en grammes pour le bucketing du cache
    private const WEIGHT_BUCKETS = [500, 1000, 2000, 3000, 5000, 10000, 20000, 30000];

    public function __construct(
        private CarrierModeRepository $carrierModeRepository,
        private ShippingRateRepository $shippingRateRepository,
        private CacheInterface $shippingCache,
        private ShippingZoneMapper $zoneMapper,
    ) {}

    /**
     * Retourne toutes les options de livraison disponibles
     * pour une destination et un poids donnés
     * 
     * @param string $countryCode Code pays (FR, GP, BE, etc.)
     * @param int $weightGrams Poids total en grammes
     * @return array [
     *   {
     *     'carrierMode': CarrierMode,
     *     'price': float,
     *     'estimatedDays': int,
     *     'carrierName': string,
     *     'modeName': string,
     *     'requiresPickupPoint': bool
     *   }
     * ]
     */
    public function getAvailableOptions(string $countryCode, int $weightGrams): array
    {
        $zone   = $this->zoneMapper->mapCountryToZone($countryCode);
        $bucket = $this->weightBucket($weightGrams);
        // La clé inclut le pays car certains carrier_modes ont des restrictions par pays (ex: Mondial Relay EU)
        $key    = sprintf('shipping.options.%s.%s.%d', $zone, strtoupper($countryCode), $bucket);

        return $this->shippingCache->get($key, function (ItemInterface $item) use ($countryCode, $weightGrams, $zone) {
            return $this->resolveOptions($countryCode, $weightGrams, $zone);
        });
    }

    private function resolveOptions(string $countryCode, int $weightGrams, string $zone): array
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
            $price = $shippingRate 
                ? $shippingRate->getPrice() 
                : $carrierMode->getBasePrice();

            $estimatedDays = $carrierMode->getEstimatedDeliveryDays()
                ?? $carrierMode->getDeliveryMaxDays()
                ?? $carrierMode->getDeliveryMinDays();

            $availableOptions[] = [
                'carrierMode' => $carrierMode,
                'carrierModeId' => $carrierMode->getId(),
                'price' => $price,
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
                'tariffClassificationNote' => self::TARIFF_NOTE,
            ];
        }

        // 5. Trier par prix (moins cher en premier)
        usort($availableOptions, fn($a, $b) => $a['price'] <=> $b['price']);

        return $availableOptions;
    }

    /**
     * Retourne la tranche de poids supérieure pour le bucketing du cache.
     * Ex : 750g → 1000, 1500g → 2000, 35000g → 35000 (au-delà du max connu)
     */
    private function weightBucket(int $weightGrams): int
    {
        foreach (self::WEIGHT_BUCKETS as $bucket) {
            if ($weightGrams <= $bucket) {
                return $bucket;
            }
        }
        return $weightGrams; // poids hors tranches connues
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