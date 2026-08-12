<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Corrige le tarif Suisse (carrier_mode union_europeenne, zone CH) : la
 * facture réelle (CO01399745, colis CA716487939FR) facture 14,22€ pour
 * 0,600 kg sous le code CA - "Colissimo Domicile Sans Sign. INT" — ce qui
 * correspond exactement au document "Domicile International sans
 * signature" (Belgique/Suisse), pas au document "avec signature" utilisé
 * par erreur dans Version20260812150000 (qui aurait donné 14,72€).
 *
 * L'Irlande (zone EU, code CB "Sign. INT") reste sur la grille avec
 * signature — confirmée correcte par la même facture, non modifiée ici.
 */
final class Version20260812180000 extends AbstractMigration
{
    private const BREAKPOINTS_FULL = [
        0.25, 0.50, 0.75, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10,
        11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
        21, 22, 23, 24, 25, 26, 27, 28, 29, 30,
    ];

    /** Suisse — Colissimo Domicile International sans signature. */
    private const CH_SANS_SIGNATURE = [
        11.28, 11.38, 14.22, 14.27, 16.01, 17.50, 19.32, 21.16, 27.83, 28.96, 30.10, 31.23, 32.36,
        37.56, 39.56, 41.56, 42.56, 42.77, 46.77, 48.97, 50.77, 51.07, 51.22,
        55.22, 57.42, 59.22, 59.52, 59.73, 60.93, 61.43, 61.93, 62.10, 62.13,
    ];

    public function getDescription(): string
    {
        return "Corrige le tarif Suisse (zone CH) : grille sans signature au lieu d'avec signature, confirmée par facture réelle";
    }

    public function up(Schema $schema): void
    {
        $this->addSql("SELECT setval('shipping_rate_id_seq', (SELECT COALESCE(MAX(id), 1) FROM shipping_rate))");
        $this->seed('union_europeenne', 'CH', 'CH', self::CH_SANS_SIGNATURE);
    }

    public function down(Schema $schema): void
    {
        // Pas de restauration de l'ancienne valeur "avec signature" erronée —
        // recharger Version20260812150000 manuellement si besoin de revenir en arrière.
        $this->delete('union_europeenne', 'CH', 'CH');
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
