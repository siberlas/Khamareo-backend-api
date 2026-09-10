<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suite de Version20260812160000, avec la correspondance pays→ZONE 1-6
 * complète fournie par le client (document Colissimo "Reste des
 * destinations Europe & Monde") :
 *  - Complète les zones EU 2/3/4 avec les pays nouvellement identifiés,
 *    dont plusieurs qui étaient jusqu'ici mal routés par
 *    ShippingZoneMapper (zone générique 'C', même tarif que USA/Chine) —
 *    voir le correctif apporté à ShippingZoneMapper::EU dans le même commit.
 *  - Ajoute les pays "Zone 5" restants (carrier_mode international, zone C).
 *  - Remplace le tarif générique zone C (sans country_code, utilisé pour
 *    tout pays non listé individuellement) par les vrais tarifs "Zone 6"
 *    — Zone 6 étant par définition "le reste du monde" dans la
 *    classification Colissimo, exactement le rôle de ce fallback générique.
 *
 * Non couvert (ambiguïté non résolue) : la liste "Maghreb" et "autres pays
 * d'Europe de l'Est et Centrale" de la Zone 4 recoupe partiellement
 * l'actuel ZONE_B (Maghreb + Balkans + ex-URSS), sans certitude sur la
 * correspondance exacte pays par pays — ZONE_B n'est pas touchée ici.
 */
final class Version20260812170000 extends AbstractMigration
{
    private const BREAKPOINTS_FULL = [
        0.25, 0.50, 0.75, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10,
        11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
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

    private const ZONE5 = [
        27.43, 27.53, 30.39, 30.44, 41.74, 53.02, 60.78, 64.87, 82.93, 95.43, 105.23, 109.23, 115.13,
        160.13, 165.13, 168.13, 169.13, 170.40, 180.40, 182.40, 184.40, 186.40, 186.62,
        231.62, 237.62, 238.62, 239.37, 239.54, 269.54, 272.54, 274.54, 275.54, 276.05,
    ];

    private const ZONE6 = [
        31.47, 31.57, 37.08, 37.14, 50.99, 65.13, 78.12, 90.82, 104.11, 116.11, 131.11, 144.61, 157.61,
        209.61, 218.61, 219.51, 220.01, 220.12, 272.12, 281.12, 282.02, 282.32, 282.72,
        332.72, 341.72, 342.52, 343.02, 343.96, 395.96, 400.96, 403.96, 404.96, 405.23,
    ];

    public function getDescription(): string
    {
        return "Complète shipping_rate avec la correspondance pays/zone 1-6 complète (Guernesey, Jersey, Groenland, Îles Féroé, Liechtenstein, Turquie, Islande, Bulgarie, Chypre, Croatie, Grèce, Malte, Roumanie, Zone 5 restante, et tarif générique Zone 6)";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SELECT setval('shipping_rate_id_seq', (SELECT COALESCE(MAX(id), 1) FROM shipping_rate))");

        // --- Zone 2 (union_europeenne / EU) ---
        foreach (['GG', 'JE'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE2);
        }

        // --- Zone 3 (union_europeenne / EU) ---
        foreach (['GL', 'FO', 'LI'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE3);
        }

        // --- Zone 4 (union_europeenne / EU) ---
        foreach (['BG', 'CY', 'HR', 'GR', 'IS', 'MT', 'RO', 'TR'] as $country) {
            $this->seed('union_europeenne', 'EU', $country, self::ZONE4);
        }

        // --- Zone 5 restante (international / C) ---
        foreach (['KR', 'HK', 'IN', 'IL', 'JP', 'RU', 'SG', 'TW', 'TH', 'VN'] as $country) {
            $this->seed('international', 'C', $country, self::ZONE5);
        }

        // --- Zone 6 : tarif générique de la zone C (tout pays non listé individuellement) ---
        $this->seed('international', 'C', null, self::ZONE6);
    }

    public function down(Schema $schema): void
    {
        foreach (['GG', 'JE', 'GL', 'FO', 'LI', 'BG', 'CY', 'HR', 'GR', 'IS', 'MT', 'RO', 'TR'] as $country) {
            $this->delete('union_europeenne', 'EU', $country);
        }
        foreach (['KR', 'HK', 'IN', 'IL', 'JP', 'RU', 'SG', 'TW', 'TH', 'VN'] as $country) {
            $this->delete('international', 'C', $country);
        }
        $this->delete('international', 'C', null);
    }

    /**
     * @param float[] $prices
     */
    private function seed(string $productKey, string $zone, ?string $countryCode, array $prices): void
    {
        $this->delete($productKey, $zone, $countryCode);

        $previousMaxGrams = 0;
        foreach (self::BREAKPOINTS_FULL as $i => $kg) {
            $maxGrams = (int) round($kg * 1000);
            $minGrams = $previousMaxGrams === 0 ? 0 : $previousMaxGrams + 1;
            $price = $prices[$i];
            $countrySql = $countryCode === null ? 'NULL' : $this->quote($countryCode);

            $this->addSql(sprintf(
                "INSERT INTO shipping_rate (id, zone, price, created_at, carrier_mode_id, min_weight_grams, max_weight_grams, country_code) "
                . "VALUES (nextval('shipping_rate_id_seq'), %s, %s, NOW(), (SELECT id FROM carrier_mode WHERE colissimo_product_code_key = %s), %d, %d, %s)",
                $this->quote($zone),
                $price,
                $this->quote($productKey),
                $minGrams,
                $maxGrams,
                $countrySql
            ));

            $previousMaxGrams = $maxGrams;
        }
    }

    private function delete(string $productKey, string $zone, ?string $countryCode): void
    {
        $countryCondition = $countryCode === null ? 'country_code IS NULL' : 'country_code = ' . $this->quote($countryCode);

        $this->addSql(sprintf(
            "DELETE FROM shipping_rate WHERE carrier_mode_id = (SELECT id FROM carrier_mode WHERE colissimo_product_code_key = %s) AND zone = %s AND %s",
            $this->quote($productKey),
            $this->quote($zone),
            $countryCondition
        ));
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
