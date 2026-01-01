<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251221163716 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE currency (id UUID NOT NULL, code VARCHAR(3) NOT NULL, symbol VARCHAR(5) NOT NULL, name VARCHAR(50) NOT NULL, exchange_rate_to_eur NUMERIC(10, 6) DEFAULT NULL, is_default BOOLEAN DEFAULT false NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6956883F77153098 ON currency (code)');
        $this->addSql('COMMENT ON COLUMN currency.id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE product_price (id UUID NOT NULL, product_id UUID NOT NULL, currency_code VARCHAR(3) NOT NULL, price NUMERIC(10, 2) NOT NULL, original_price NUMERIC(10, 2) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_6B9459854584665A ON product_price (product_id)');
        $this->addSql('CREATE INDEX IDX_6B945985FDA273EC ON product_price (currency_code)');
        $this->addSql('CREATE UNIQUE INDEX uq_product_currency ON product_price (product_id, currency_code)');
        $this->addSql('COMMENT ON COLUMN product_price.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product_price.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE product_price ADD CONSTRAINT FK_6B9459854584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_price ADD CONSTRAINT FK_6B945985FDA273EC FOREIGN KEY (currency_code) REFERENCES currency (code) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product ALTER price TYPE NUMERIC(10, 2)');
        $this->addSql('ALTER TABLE product ALTER original_price TYPE NUMERIC(10, 2)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE product_price DROP CONSTRAINT FK_6B9459854584665A');
        $this->addSql('ALTER TABLE product_price DROP CONSTRAINT FK_6B945985FDA273EC');
        $this->addSql('DROP TABLE currency');
        $this->addSql('DROP TABLE product_price');
        $this->addSql('ALTER TABLE product ALTER price TYPE DOUBLE PRECISION');
        $this->addSql('ALTER TABLE product ALTER original_price TYPE DOUBLE PRECISION');
    }
}
