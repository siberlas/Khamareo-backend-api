<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute carrier_mode.energy_coefficient_type ('routier'/'aerien') et
 * remplace store_settings.cae_percent par deux taux distincts — la grille
 * Colissimo applique un CAE routier et un CAE aérien différents.
 */
final class Version20260811130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute carrier_mode.energy_coefficient_type, remplace store_settings.cae_percent par cae_percent_routier/cae_percent_aerien";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE carrier_mode ADD energy_coefficient_type VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD cae_percent_routier NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings ADD cae_percent_aerien NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings DROP cae_percent');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE store_settings ADD cae_percent NUMERIC(5, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE store_settings DROP cae_percent_routier');
        $this->addSql('ALTER TABLE store_settings DROP cae_percent_aerien');
        $this->addSql('ALTER TABLE carrier_mode DROP energy_coefficient_type');
    }
}
