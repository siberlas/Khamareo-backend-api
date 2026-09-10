<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remplace les tarifs shipping_rate de fixtures dev par les vraies grilles
 * tarifaires Colissimo (documents "Mes Conditions Tarifaires" du 07/08/2026,
 * compte 308977) :
 *  - France métropolitaine (Colissimo Domicile sans signature)
 *  - Outre-mer OM1/OM2 (Colissimo Domicile sans signature et Retour)
 *  - Union européenne / Suisse / Royaume-Uni et International : surcharges
 *    par pays (country_code) pour les destinations "Principales" du grillage
 *    Colissimo Domicile avec signature — seule variante fournie pour
 *    l'international. Les tarifs génériques de zone (EU/B/C, sans
 *    country_code) restent inchangés : la correspondance pays→ZONE 1-6 pour
 *    le "Reste des destinations" n'a pas été fournie, donc ces lignes ne
 *    sont pas mises à jour ici (à traiter séparément si besoin).
 *  - outre_mer_eco n'a pas de grille correspondante dans les documents
 *    fournis : non touché.
 *
 * Résolution du carrier_mode par colissimo_product_code_key (jamais par id
 * numérique brut, qui peut différer entre environnements).
 */
final class Version20260812150000 extends AbstractMigration
{
    /** Paliers France métro / Principales destinations (kg). */
    private const BREAKPOINTS_FULL = [
        0.25, 0.50, 0.75, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10,
        11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
    ];

    /** Paliers Outre-mer (kg). */
    private const BREAKPOINTS_OM = [0.5, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 15, 20, 25, 30];

    public function getDescription(): string
    {
        return 'Met à jour shipping_rate avec les vraies grilles tarifaires Colissimo (France métro, Outre-mer, surcharges par pays UE/CH/UK/International) au 07/08/2026';
    }

    public function up(Schema $schema): void
    {
        // La séquence shipping_rate_id_seq n'est pas systématiquement à jour
        // avec MAX(id) (lignes historiquement insérées avec id explicite,
        // ex. fixtures) — resynchronisation avant tout nextval() ci-dessous.
        $this->addSql("SELECT setval('shipping_rate_id_seq', (SELECT COALESCE(MAX(id), 1) FROM shipping_rate))");

        // --- France métropolitaine (zone FR, Colissimo Domicile sans signature) ---
        $this->seed('france_metro', 'FR', null, self::BREAKPOINTS_FULL, [
            6.84, 7.71, 8.60, 9.34, 10.48, 11.49, 12.54, 13.54, 14.17, 15.16, 16.15, 17.17, 18.17,
            18.79, 19.77, 20.74, 21.75, 22.73, 23.70, 24.68, 25.66, 26.65, 27.62,
            28.33, 29.29, 30.28, 31.25, 32.19, 33.19, 34.13, 35.11, 36.12, 37.05,
        ]);

        // --- Outre-mer OM1/OM2 (Colissimo Domicile sans signature et Retour) ---
        $this->seed('outre_mer', 'OM1', null, self::BREAKPOINTS_OM, [
            10.86, 16.46, 22.43, 28.41, 34.39, 38.61, 46.37, 52.32, 56.96, 62.64, 69.45, 97.31, 125.22, 156.65, 189.29,
        ]);
        $this->seed('outre_mer', 'OM2', null, self::BREAKPOINTS_OM, [
            12.89, 20.03, 35.60, 51.14, 63.81, 78.69, 97.86, 113.42, 125.57, 138.24, 153.12, 233.99, 297.55, 367.68, 440.71,
        ]);

        // --- Union européenne / Suisse / UK : surcharges par pays (Colissimo Domicile avec signature — Principales Destinations Europe) ---
        $this->seed('union_europeenne', 'EU', 'DE', self::BREAKPOINTS_FULL, [
            9.18, 9.28, 11.28, 11.33, 12.13, 13.01, 13.81, 14.61, 15.41, 16.01, 17.01, 17.81, 18.61,
            21.61, 22.11, 22.81, 23.36, 23.44, 27.14, 27.44, 27.74, 27.94, 28.10,
            31.80, 32.10, 32.40, 32.60, 32.77, 36.47, 36.77, 37.07, 37.27, 37.44,
        ]);
        $this->seed('union_europeenne', 'EU', 'BE', self::BREAKPOINTS_FULL, [
            9.00, 9.10, 11.08, 11.11, 11.89, 12.76, 13.56, 14.36, 15.16, 15.76, 16.76, 17.56, 18.36,
            21.36, 21.86, 22.56, 22.90, 22.98, 25.98, 26.28, 26.58, 26.78, 27.54,
            31.24, 31.54, 31.84, 32.04, 32.13, 35.83, 36.13, 36.43, 36.63, 36.70,
        ]);
        $this->seed('union_europeenne', 'EU', 'NL', self::BREAKPOINTS_FULL, [
            9.23, 9.33, 11.34, 11.39, 12.19, 13.07, 13.87, 14.67, 15.47, 16.07, 17.07, 17.87, 18.67,
            21.67, 22.17, 22.87, 23.42, 23.55, 27.25, 27.55, 27.85, 28.05, 28.24,
            31.94, 32.24, 32.54, 32.74, 32.94, 36.64, 36.94, 37.24, 37.44, 37.63,
        ]);
        $this->seed('union_europeenne', 'EU', 'IT', self::BREAKPOINTS_FULL, [
            10.40, 10.50, 13.31, 13.36, 14.94, 15.92, 16.72, 17.52, 18.32, 18.92, 19.92, 20.72, 21.52,
            24.52, 25.02, 25.72, 26.27, 26.90, 30.60, 30.90, 31.20, 31.40, 31.55,
            35.25, 35.55, 35.85, 36.05, 36.19, 39.89, 40.19, 40.49, 40.69, 40.85,
        ]);
        $this->seed('union_europeenne', 'EU', 'ES', self::BREAKPOINTS_FULL, [
            10.35, 10.45, 13.24, 13.29, 14.87, 15.84, 16.64, 17.44, 18.24, 18.84, 19.84, 20.64, 21.44,
            24.44, 24.94, 25.64, 26.19, 26.77, 30.47, 30.77, 31.07, 31.27, 31.39,
            35.09, 35.39, 35.69, 35.89, 36.01, 39.71, 40.01, 40.31, 40.51, 40.65,
        ]);
        $this->seed('union_europeenne', 'EU', 'PT', self::BREAKPOINTS_FULL, [
            10.62, 10.72, 13.58, 13.63, 15.24, 16.24, 17.04, 17.84, 18.64, 19.24, 20.24, 21.04, 21.84,
            24.84, 25.34, 26.04, 26.59, 27.44, 31.14, 31.44, 31.74, 31.94, 32.18,
            35.88, 36.18, 36.48, 36.68, 36.91, 40.61, 40.91, 41.21, 41.41, 41.67,
        ]);
        $this->seed('union_europeenne', 'CH', 'CH', self::BREAKPOINTS_FULL, [
            11.78, 11.88, 14.72, 14.77, 16.51, 18.00, 19.82, 21.66, 28.33, 29.46, 30.60, 31.73, 32.86,
            38.06, 40.06, 42.06, 43.06, 43.27, 47.27, 49.47, 51.27, 51.57, 51.72,
            55.72, 57.92, 59.72, 60.02, 60.23, 61.43, 61.93, 62.43, 62.60, 62.63,
        ]);
        $this->seed('union_europeenne', 'UK', 'GB', self::BREAKPOINTS_FULL, [
            10.57, 10.67, 13.10, 13.56, 15.17, 16.16, 16.96, 17.76, 18.56, 19.16, 20.16, 20.96, 21.76,
            24.76, 25.26, 25.96, 26.51, 27.30, 30.95, 31.25, 31.60, 31.95, 32.03,
            35.73, 36.03, 36.33, 36.53, 36.73, 40.43, 40.73, 41.03, 41.23, 41.47,
        ]);

        // --- International : surcharges par pays (Colissimo Domicile avec signature — Principales Destinations Monde) ---
        $this->seed('international', 'C', 'US', self::BREAKPOINTS_FULL, [
            26.48, 26.58, 29.34, 29.39, 40.31, 51.21, 58.68, 62.64, 80.07, 92.57, 102.37, 106.37, 112.27,
            157.27, 162.27, 163.22, 164.22, 164.54, 174.54, 176.54, 178.54, 180.04, 180.19,
            225.19, 230.19, 230.89, 231.19, 231.30, 261.30, 264.30, 265.80, 266.30, 266.56,
        ]);
        $this->seed('international', 'C', 'CN', self::BREAKPOINTS_FULL, [
            26.75, 26.86, 29.62, 29.69, 40.71, 51.72, 59.29, 63.28, 80.89, 93.39, 103.19, 107.19, 113.09,
            158.09, 163.09, 164.04, 165.04, 166.22, 176.22, 178.22, 180.22, 181.97, 182.03,
            227.03, 232.03, 232.83, 233.53, 233.66, 263.66, 266.66, 268.46, 269.16, 269.28,
        ]);
        $this->seed('international', 'C', 'CA', self::BREAKPOINTS_FULL, [
            27.43, 27.53, 30.39, 30.44, 41.74, 53.02, 60.78, 64.87, 82.93, 95.43, 105.23, 109.23, 115.13,
            160.13, 165.13, 168.13, 169.13, 170.40, 180.40, 182.40, 184.40, 186.40, 186.62,
            231.62, 237.62, 238.62, 239.37, 239.54, 269.54, 272.54, 274.54, 275.54, 276.05,
        ]);
        $this->seed('international', 'C', 'AU', self::BREAKPOINTS_FULL, [
            27.43, 27.53, 30.39, 30.44, 41.74, 53.02, 60.78, 64.87, 82.93, 95.43, 105.23, 109.23, 115.13,
            160.13, 165.13, 168.13, 169.13, 170.40, 180.40, 182.40, 184.40, 186.40, 186.62,
            231.62, 237.62, 238.62, 239.37, 239.54, 269.54, 272.54, 274.54, 275.54, 276.05,
        ]);
    }

    public function down(Schema $schema): void
    {
        foreach ([
            ['france_metro', 'FR', null],
            ['outre_mer', 'OM1', null],
            ['outre_mer', 'OM2', null],
            ['union_europeenne', 'EU', 'DE'],
            ['union_europeenne', 'EU', 'BE'],
            ['union_europeenne', 'EU', 'NL'],
            ['union_europeenne', 'EU', 'IT'],
            ['union_europeenne', 'EU', 'ES'],
            ['union_europeenne', 'EU', 'PT'],
            ['union_europeenne', 'CH', 'CH'],
            ['union_europeenne', 'UK', 'GB'],
            ['international', 'C', 'US'],
            ['international', 'C', 'CN'],
            ['international', 'C', 'CA'],
            ['international', 'C', 'AU'],
        ] as [$productKey, $zone, $countryCode]) {
            $this->delete($productKey, $zone, $countryCode);
        }
        // Pas de restauration des anciennes valeurs de fixtures : le down()
        // laisse ces lignes vides plutôt que de réinventer des données
        // arbitraires — recharger les fixtures dev si besoin de revenir en arrière.
    }

    /**
     * @param float[] $breakpointsKg
     * @param float[] $prices
     */
    private function seed(string $productKey, string $zone, ?string $countryCode, array $breakpointsKg, array $prices): void
    {
        $this->delete($productKey, $zone, $countryCode);

        $previousMaxGrams = 0;
        foreach ($breakpointsKg as $i => $kg) {
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
        $countryCondition = $countryCode === null
            ? 'country_code IS NULL'
            : 'country_code = ' . $this->quote($countryCode);

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
