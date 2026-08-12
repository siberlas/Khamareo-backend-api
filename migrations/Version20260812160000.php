<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Complète les surcharges par pays de shipping_rate (carrier_mode
 * union_europeenne, zone EU) avec les pays de la grille "Reste des
 * destinations Europe" (Colissimo Domicile International avec signature,
 * document du 07/08/2026) — ceux qui n'ont pas de prix "Principales
 * destinations" dédié (déjà couverts par Version20260812150000 pour
 * DE/BE/NL/IT/ES/PT).
 *
 * Correspondance pays → ZONE fournie par le client (pas dans le PDF) :
 *  ZONE 1 : Luxembourg (Allemagne/Belgique/Pays-Bas déjà couverts ailleurs)
 *  ZONE 2 : Autriche, République d'Irlande (Espagne/Italie/Portugal déjà couverts ailleurs)
 *  ZONE 3 : Danemark, Estonie, Hongrie, Lettonie, Lituanie, Pologne, République Tchèque, Slovaquie, Slovénie, Suède
 *  ZONE 4 : Finlande
 *
 * Non couvert : îles Baléares (Espagne) et Madère/Açores (Portugal) — même
 * country_code que le continent (ES/PT), impossible à isoler sans logique
 * par code postal (comme les DOM-TOM), non implémentée pour l'international.
 */
final class Version20260812160000 extends AbstractMigration
{
    private const BREAKPOINTS_FULL = [
        0.25, 0.50, 0.75, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10,
        11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
    ];

    private const ZONE1 = [
        9.23, 9.33, 11.34, 11.39, 12.19, 13.07, 13.87, 14.67, 15.47, 16.07, 17.07, 17.87, 18.67,
        21.67, 22.17, 22.87, 23.42, 23.55, 27.25, 27.55, 27.85, 28.05, 28.24,
        31.94, 32.24, 32.54, 32.74, 32.94, 36.64, 36.94, 37.24, 37.44, 37.63,
    ];

    private const ZONE2 = [
        10.62, 10.72, 13.58, 13.63, 15.24, 16.24, 17.04, 17.84, 18.64, 19.24, 20.24, 21.04, 21.84,
        24.84, 25.34, 26.04, 26.59, 27.44, 31.14, 31.44, 31.74, 31.94, 32.18,
        35.88, 36.18, 36.48, 36.68, 36.91, 40.61, 40.91, 41.21, 41.41, 41.67,
    ];

    private const ZONE3 = [
        11.84, 11.94, 14.78, 14.84, 16.60, 18.09, 19.91, 21.76, 28.48, 29.62, 30.76, 31.90, 33.04,
        38.24, 40.24, 42.24, 43.24, 43.48, 47.48, 49.68, 51.48, 51.78, 51.98,
        55.98, 58.18, 59.98, 60.28, 60.51, 61.71, 62.21, 62.71, 62.91, 62.94,
    ];

    private const ZONE4 = [
        13.89, 13.99, 17.05, 17.11, 18.84, 20.66, 23.10, 27.93, 31.60, 36.00, 41.35, 44.45, 48.24,
        60.24, 62.24, 63.24, 64.24, 65.24, 70.24, 72.24, 74.24, 76.24, 76.54,
        85.54, 88.54, 90.14, 90.54, 90.66, 100.66, 103.86, 104.36, 104.96, 105.18,
    ];

    public function getDescription(): string
    {
        return "Ajoute les surcharges par pays UE 'reste des destinations' (Zone 1-4) manquantes dans shipping_rate";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SELECT setval('shipping_rate_id_seq', (SELECT COALESCE(MAX(id), 1) FROM shipping_rate))");

        foreach (['LU'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE1);
        }
        foreach (['AT', 'IE'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE2);
        }
        foreach (['DK', 'EE', 'HU', 'LV', 'LT', 'PL', 'CZ', 'SK', 'SI', 'SE'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE3);
        }
        foreach (['FI'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE4);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (['LU', 'AT', 'IE', 'DK', 'EE', 'HU', 'LV', 'LT', 'PL', 'CZ', 'SK', 'SI', 'SE', 'FI'] as $country) {
            $this->delete('union_europeenne', 'EU', $country);
        }
    }

    /**
     * @param float[] $prices
     */
    private function seed(string $productKey, string $zone, string $countryCode, array $prices): void
    {
        $this->delete($productKey, $zone, $countryCode);

        $previousMaxGrams = 0;
        foreach (self::BREAKPOINTS_FULL as $i => $kg) {
            $maxGrams = (int) round($kg * 1000);
            $minGrams = $previousMaxGrams === 0 ? 0 : $previousMaxGrams + 1;
            $price = $prices[$i];

            $this->addSql(sprintf(
                "INSERT INTO shipping_rate (id, zone, price, created_at, carrier_mode_id, min_weight_grams, max_weight_grams, country_code) "
                . "VALUES (nextval('shipping_rate_id_seq'), %s, %s, NOW(), (SELECT id FROM carrier_mode WHERE colissimo_product_code_key = %s), %d, %d, %s)",
                $this->quote($zone),
                $price,
                $this->quote($productKey),
                $minGrams,
                $maxGrams,
                $this->quote($countryCode)
            ));

            $previousMaxGrams = $maxGrams;
        }
    }

    private function delete(string $productKey, string $zone, string $countryCode): void
    {
        $this->addSql(sprintf(
            "DELETE FROM shipping_rate WHERE carrier_mode_id = (SELECT id FROM carrier_mode WHERE colissimo_product_code_key = %s) AND zone = %s AND country_code = %s",
            $this->quote($productKey),
            $this->quote($zone),
            $this->quote($countryCode)
        ));
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
