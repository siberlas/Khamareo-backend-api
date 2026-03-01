<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260228100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add app_settings and pre_registration tables for Coming Soon feature';
    }

    public function up(Schema $schema): void
    {
        // Table des paramètres globaux de l'application
        $this->addSql('CREATE TABLE app_settings (
            id SERIAL NOT NULL,
            setting_key VARCHAR(100) NOT NULL,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN app_settings.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_app_settings_key ON app_settings (setting_key)');

        // Table des pré-inscriptions
        $this->addSql('CREATE TABLE pre_registration (
            id UUID NOT NULL,
            email VARCHAR(180) NOT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            consent_given BOOLEAN NOT NULL,
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
            PRIMARY KEY(id)
        )');
        $this->addSql('COMMENT ON COLUMN pre_registration.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN pre_registration.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE UNIQUE INDEX uniq_pre_registration_email ON pre_registration (email)');
        $this->addSql('CREATE INDEX idx_pre_registration_created_at ON pre_registration (created_at)');

        // Seed : coming soon activé par défaut, pas de date de lancement
        $this->addSql("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('coming_soon_enabled', 'true', NOW())");
        $this->addSql("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('coming_soon_launch_date', NULL, NOW())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE pre_registration');
        $this->addSql('DROP TABLE app_settings');
    }
}
