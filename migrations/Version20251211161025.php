<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251211161025 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE cart ADD promo_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD discount_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD promo_code VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD discount_amount NUMERIC(10, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "order" DROP promo_code');
        $this->addSql('ALTER TABLE "order" DROP discount_amount');
        $this->addSql('ALTER TABLE cart DROP promo_code');
        $this->addSql('ALTER TABLE cart DROP discount_amount');
    }
}
