<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251214232526 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE stock_alert_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE stock_alert (id INT NOT NULL, owner_id UUID NOT NULL, product_id UUID NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, notified BOOLEAN NOT NULL, notified_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_8BED5A307E3C61F9 ON stock_alert (owner_id)');
        $this->addSql('CREATE INDEX IDX_8BED5A304584665A ON stock_alert (product_id)');
        $this->addSql('CREATE UNIQUE INDEX owner_product_unique ON stock_alert (owner_id, product_id)');
        $this->addSql('COMMENT ON COLUMN stock_alert.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN stock_alert.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN stock_alert.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN stock_alert.notified_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE stock_alert ADD CONSTRAINT FK_8BED5A307E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE stock_alert ADD CONSTRAINT FK_8BED5A304584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE stock_alert_id_seq CASCADE');
        $this->addSql('ALTER TABLE stock_alert DROP CONSTRAINT FK_8BED5A307E3C61F9');
        $this->addSql('ALTER TABLE stock_alert DROP CONSTRAINT FK_8BED5A304584665A');
        $this->addSql('DROP TABLE stock_alert');
    }
}
