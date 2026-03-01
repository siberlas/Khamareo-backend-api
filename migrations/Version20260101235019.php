<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260101235019 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT fk_f52993985f7d6850');
        $this->addSql('DROP INDEX idx_f52993985f7d6850');
        $this->addSql('ALTER TABLE "order" DROP shipping_method_id');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "order" ADD shipping_method_id INT NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT fk_f52993985f7d6850 FOREIGN KEY (shipping_method_id) REFERENCES shipping_method (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX idx_f52993985f7d6850 ON "order" (shipping_method_id)');
    }
}
