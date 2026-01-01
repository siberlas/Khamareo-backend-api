<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251229173530 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE shipping_label ADD label_url TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE shipping_label ADD label_data JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE shipping_label ADD document_url TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE shipping_label DROP label_url');
        $this->addSql('ALTER TABLE shipping_label DROP label_data');
        $this->addSql('ALTER TABLE shipping_label DROP document_url');
    }
}
