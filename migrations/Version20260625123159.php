<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625123159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add allowed_countries restriction on carrier_mode (Mondial Relay Point Relais EU)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE carrier_mode ADD allowed_countries JSON DEFAULT NULL');
        // Mondial Relay zone FR : France uniquement
        $this->addSql("UPDATE carrier_mode SET allowed_countries = '[\"FR\"]' WHERE id IN (51, 52, 53)");
        // Mondial Relay Point Relais zone EU : BE, ES, PT, LU, IT, PL, NL uniquement
        $this->addSql("UPDATE carrier_mode SET allowed_countries = '[\"BE\",\"ES\",\"PT\",\"LU\",\"IT\",\"PL\",\"NL\"]' WHERE id = 54");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE carrier_mode DROP allowed_countries');
    }
}
