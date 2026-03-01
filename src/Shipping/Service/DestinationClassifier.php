<?php
// src/Shipping/Service/DestinationClassifier.php

namespace App\Shipping\Service;

use App\Shipping\Enum\DestinationZone;
use Psr\Log\LoggerInterface;

class DestinationClassifier
{
    private const EU_COUNTRIES = [
        'AT','BE','BG','HR','CY','CZ','DK','EE','FI','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE',
    ];

    private const EUROPE_NON_EU = [
        'CH','GB',
    ];

    private const ZONE_B_COUNTRIES = [
        'NO','MA','DZ','TN','LY','EG','AL','BA','MK','ME','RS','XK','MD','UA','BY','GE','AM','AZ',
    ];

    public function __construct(private LoggerInterface $logger) {}

    public function classify(?string $postalCode, ?string $countryCode): DestinationZone
    {
        $postalCode  = $this->normalizePostalCode($postalCode);
        $countryCode = $this->normalizeCountryCode($countryCode);

        $this->logger->debug('Classifying destination', [
            'postal_code' => $postalCode,
            'country_code' => $countryCode,
        ]);

        // 1) Si on a un countryCode valide, il est PRIORITAIRE.
        if ($countryCode) {
            // FR/MC/AD => on peut raffiner par CP (OM vs FR metro)
            if (in_array($countryCode, ['FR', 'MC', 'AD'], true)) {
                if ($postalCode) {
                    $zoneByPostal = $this->classifyFrenchPostalCode($postalCode);
                    if ($zoneByPostal) {
                        $this->logger->info('Destination classified by FR postal code refinement', [
                            'postal_code' => $postalCode,
                            'zone' => $zoneByPostal->value,
                        ]);
                        return $zoneByPostal;
                    }
                }
                return DestinationZone::FRANCE_METRO;
            }

            // DROM/COM (OM1 + OM2)
            if (in_array($countryCode, ['GP','MQ','GF','RE','YT','PM','MF','BL','NC','PF','WF','TF'], true)) {
                return DestinationZone::OUTRE_MER;
            }

            // UE / Europe hors UE / International
            if (in_array($countryCode, self::EU_COUNTRIES, true)) {
                return DestinationZone::UNION_EUROPEENNE;
            }

            if (in_array($countryCode, self::EUROPE_NON_EU, true)) {
                return DestinationZone::EUROPE_HORS_UE;
            }

            if (in_array($countryCode, self::ZONE_B_COUNTRIES, true)) {
                return DestinationZone::INTERNATIONAL;
            }

            return DestinationZone::INTERNATIONAL;
        }

        // 2) Pas de countryCode => on peut tenter le code postal,
        //    mais uniquement si ça ressemble à du FR/OM/AD.
        if ($postalCode) {
            $zoneByPostal = $this->classifyFrenchPostalCode($postalCode);
            if ($zoneByPostal) {
                $this->logger->info('Destination classified by postal code (no countryCode)', [
                    'postal_code' => $postalCode,
                    'zone' => $zoneByPostal->value,
                ]);
                return $zoneByPostal;
            }
        }

        // 3) Fallback plus sûr : INTERNATIONAL (évite les faux FR)
        $this->logger->warning('Unable to classify destination, defaulting to INTERNATIONAL', [
            'postal_code' => $postalCode,
            'country_code' => $countryCode,
        ]);

        return DestinationZone::INTERNATIONAL;
    }

    /**
     * Ne gère QUE les CP FR / OM / Monaco / Andorre.
     * Retourne null si ça ne ressemble pas à une logique FR.
     */
    private function classifyFrenchPostalCode(string $postalCode): ?DestinationZone
    {
        // Andorre: AD100 etc.
        if (str_starts_with($postalCode, 'AD')) {
            return DestinationZone::FRANCE_METRO;
        }

        // CP FR = 5 digits
        if (!preg_match('/^\d{5}$/', $postalCode)) {
            return null;
        }

        $cp = (int) $postalCode;

        // OM: 97xxx / 98xxx spécifiques + 97500 etc.
        if ($cp === 97024 || ($cp >= 97051 && $cp <= 97080) || ($cp >= 97149 && $cp <= 97150)) {
            return DestinationZone::OUTRE_MER; // MF
        }

        if (($cp >= 97012 && $cp <= 97022) || $cp === 97049 || ($cp >= 97095 && $cp <= 97099) || $cp === 97133) {
            return DestinationZone::OUTRE_MER; // BL
        }

        if (($cp >= 97100 && $cp <= 97199) || ($cp >= 97200 && $cp <= 97299) || ($cp >= 97300 && $cp <= 97399)) {
            return DestinationZone::OUTRE_MER; // GP/MQ/GF
        }

        if (($cp >= 97400 && $cp <= 97490) || ($cp >= 97820 && $cp <= 97899)) {
            return DestinationZone::OUTRE_MER; // RE
        }

        if ($cp >= 97600 && $cp <= 97690) {
            return DestinationZone::OUTRE_MER; // YT
        }

        if ($cp === 97500) {
            return DestinationZone::OUTRE_MER; // PM
        }

        // OM2 : 986, 987, 988, 984
        if (($cp >= 98600 && $cp <= 98699) || ($cp >= 98700 && $cp <= 98799) || ($cp >= 98800 && $cp <= 98899) || ($cp >= 98400 && $cp <= 98499)) {
            return DestinationZone::OUTRE_MER;
        }

        // Monaco CP
        if ($cp >= 98000 && $cp <= 98091) {
            return DestinationZone::FRANCE_METRO;
        }

        // France métropolitaine : ici, on accepte seulement les vraies plages FR.
        // IMPORTANT: on ne doit pas utiliser "1..95999" comme preuve de FR si on n'est pas sûr.
        // Si pas de countryCode, c'est une supposition. Mais dans ton cas, tu veux quand même un fallback FR ?
        // -> je recommande de garder la plage FR standard (01xxx à 95xxx) ce qui est déjà ça.
        if ($cp >= 1000 && $cp <= 95999) { // 01000..95999 (évite 000xx etc.)
            return DestinationZone::FRANCE_METRO;
        }

        return null;
    }

    private function normalizePostalCode(?string $postalCode): ?string
    {
        if (!$postalCode) return null;
        $postalCode = strtoupper(trim($postalCode));
        return str_replace([' ', "\t", "\n", "\r", '-'], '', $postalCode);
    }

    private function normalizeCountryCode(?string $countryCode): ?string
    {
        if (!$countryCode) return null;
        $countryCode = strtoupper(trim($countryCode));
        return preg_match('/^[A-Z]{2}$/', $countryCode) ? $countryCode : null;
    }
}
