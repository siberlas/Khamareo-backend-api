<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623131137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE launch_email_queue ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE launch_email_queue ALTER is_newsletter DROP DEFAULT');
        $this->addSql('ALTER TABLE "order" ADD carrier_mode_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993982316A302 FOREIGN KEY (carrier_mode_id) REFERENCES carrier_mode (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F52993982316A302 ON "order" (carrier_mode_id)');
        $this->addSql('ALTER TABLE product_categories ALTER product_id TYPE UUID');
        $this->addSql('ALTER TABLE product_categories ALTER category_id TYPE UUID');
        $this->addSql('COMMENT ON COLUMN product_categories.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product_categories.category_id IS \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993982316A302');
        $this->addSql('DROP INDEX IDX_F52993982316A302');
        $this->addSql('ALTER TABLE "order" DROP carrier_mode_id');
        $this->addSql('ALTER TABLE launch_email_queue ALTER status SET DEFAULT \'pending\'');
        $this->addSql('ALTER TABLE launch_email_queue ALTER is_newsletter SET DEFAULT false');
        $this->addSql('ALTER TABLE product_categories ALTER product_id TYPE UUID');
        $this->addSql('ALTER TABLE product_categories ALTER category_id TYPE UUID');
        $this->addSql('COMMENT ON COLUMN product_categories.product_id IS NULL');
        $this->addSql('COMMENT ON COLUMN product_categories.category_id IS NULL');
    }
}
