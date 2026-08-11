<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute les champs de configuration CAE + suppléments à store_settings
 * (page admin "Configuration livraison" / onglet "Frais de port").
 */
final class Version20260811110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute store_settings.cae_percent, cae_excluded_carrier_mode_ids, supplement_international_security, supplement_us, supplement_decarbonation";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_settings ADD cae_percent NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD cae_excluded_carrier_mode_ids JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD supplement_international_security NUMERIC(6, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD supplement_us NUMERIC(6, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD supplement_decarbonation NUMERIC(6, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_settings DROP cae_percent');
        $this->addSql('ALTER TABLE store_settings DROP cae_excluded_carrier_mode_ids');
        $this->addSql('ALTER TABLE store_settings DROP supplement_international_security');
        $this->addSql('ALTER TABLE store_settings DROP supplement_us');
        $this->addSql('ALTER TABLE store_settings DROP supplement_decarbonation');
    }
}
