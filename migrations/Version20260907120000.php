<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les suppléments Colissimo manquants dans store_settings
 * (Suppléments tarifaires 2026) :
 *  - supplement_china : Supplément Chine (Colissimo Domicile avec signature)
 *  - supplement_uk : Supplément Royaume-Uni (Grande-Bretagne + Irlande du Nord)
 *  - smic_compensation_percent : Compensation évolution du SMIC, en %,
 *    appliquée sur le port net comme le CAE (1 % à compter du 01/08/2026).
 *  - shipping_vat_rate_percent : TVA (%) répercutée sur le port au checkout
 *    (La Poste facture 20 % France/UE, non récupérable en franchise 293 B).
 *
 * Colonnes nullables, sans valeur par défaut : à renseigner dans l'admin
 * (Réglages → Frais de port). Tant qu'elles sont nulles, le calcul reste
 * identique à aujourd'hui.
 */
final class Version20260907120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute store_settings.supplement_china, supplement_uk, smic_compensation_percent, shipping_vat_rate_percent';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_settings ADD supplement_china NUMERIC(6, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD supplement_uk NUMERIC(6, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD smic_compensation_percent NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD shipping_vat_rate_percent NUMERIC(5, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_settings DROP supplement_china');
        $this->addSql('ALTER TABLE store_settings DROP supplement_uk');
        $this->addSql('ALTER TABLE store_settings DROP smic_compensation_percent');
        $this->addSql('ALTER TABLE store_settings DROP shipping_vat_rate_percent');
    }
}
