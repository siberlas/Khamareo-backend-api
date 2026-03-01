<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260109180940 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE badge (id UUID NOT NULL, code VARCHAR(100) NOT NULL, label VARCHAR(255) NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FEF0481D77153098 ON badge (code)');
        $this->addSql('COMMENT ON COLUMN badge.id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE product ADD badge_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD is_enabled BOOLEAN DEFAULT true NOT NULL');
        $this->addSql('ALTER TABLE product DROP badge');
        $this->addSql('COMMENT ON COLUMN product.badge_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADF7A2C2FC FOREIGN KEY (badge_id) REFERENCES badge (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_D34A04ADF7A2C2FC ON product (badge_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04ADF7A2C2FC');
        $this->addSql('DROP TABLE badge');
        $this->addSql('DROP INDEX IDX_D34A04ADF7A2C2FC');
        $this->addSql('ALTER TABLE product ADD badge VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE product DROP badge_id');
        $this->addSql('ALTER TABLE product DROP is_enabled');
    }
}
