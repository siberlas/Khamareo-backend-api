<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260105150322 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shipping_label ADD preparation_sheet_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE shipping_label ADD delivery_slip_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE shipping_label ADD generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN shipping_label.generated_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE shipping_label DROP preparation_sheet_url');
        $this->addSql('ALTER TABLE shipping_label DROP delivery_slip_url');
        $this->addSql('ALTER TABLE shipping_label DROP generated_at');
    }
}
