<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251025121328 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE shipping_address_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_method_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, order_id UUID NOT NULL, status VARCHAR(255) NOT NULL, provider VARCHAR(100) NOT NULL, amount DOUBLE PRECISION NOT NULL, transaction_id VARCHAR(255) DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840D8D9F6D38 ON payment (order_id)');
        $this->addSql('COMMENT ON COLUMN payment.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN payment.order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN payment.paid_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE shipping_address (id INT NOT NULL, owner_id UUID NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, street_address VARCHAR(255) NOT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(10) NOT NULL, country VARCHAR(100) NOT NULL, phone VARCHAR(20) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EB0669457E3C61F9 ON shipping_address (owner_id)');
        $this->addSql('COMMENT ON COLUMN shipping_address.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE shipping_method (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, price DOUBLE PRECISION NOT NULL, carrier_code VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES "order" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shipping_address ADD CONSTRAINT FK_EB0669457E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('DROP INDEX uniq_ba388b77e3c61f9');
        $this->addSql('ALTER TABLE cart ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN cart.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_BA388B77E3C61F9 ON cart (owner_id)');
        $this->addSql('ALTER TABLE "order" ADD shipping_method_id INT NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD shipping_address_id INT NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD reference VARCHAR(30) NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD billing_address VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD payment_method VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD payment_status VARCHAR(30) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD tracking_number VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD shipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD is_locked BOOLEAN NOT NULL');
        $this->addSql('ALTER TABLE "order" ADD customer_note TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD order_number VARCHAR(20) NOT NULL');
        $this->addSql('ALTER TABLE "order" ALTER status TYPE VARCHAR(20)');
        $this->addSql('COMMENT ON COLUMN "order".paid_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".shipped_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993985F7D6850 FOREIGN KEY (shipping_method_id) REFERENCES shipping_method (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993984D4CFF2B FOREIGN KEY (shipping_address_id) REFERENCES shipping_address (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F5299398AEA34913 ON "order" (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F5299398551F0F81 ON "order" (order_number)');
        $this->addSql('CREATE INDEX IDX_F52993985F7D6850 ON "order" (shipping_method_id)');
        $this->addSql('CREATE INDEX IDX_F52993984D4CFF2B ON "order" (shipping_address_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993984D4CFF2B');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993985F7D6850');
        $this->addSql('DROP SEQUENCE shipping_address_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_method_id_seq CASCADE');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D8D9F6D38');
        $this->addSql('ALTER TABLE shipping_address DROP CONSTRAINT FK_EB0669457E3C61F9');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE shipping_address');
        $this->addSql('DROP TABLE shipping_method');
        $this->addSql('DROP INDEX UNIQ_F5299398AEA34913');
        $this->addSql('DROP INDEX UNIQ_F5299398551F0F81');
        $this->addSql('DROP INDEX IDX_F52993985F7D6850');
        $this->addSql('DROP INDEX IDX_F52993984D4CFF2B');
        $this->addSql('ALTER TABLE "order" DROP shipping_method_id');
        $this->addSql('ALTER TABLE "order" DROP shipping_address_id');
        $this->addSql('ALTER TABLE "order" DROP reference');
        $this->addSql('ALTER TABLE "order" DROP billing_address');
        $this->addSql('ALTER TABLE "order" DROP payment_method');
        $this->addSql('ALTER TABLE "order" DROP payment_status');
        $this->addSql('ALTER TABLE "order" DROP paid_at');
        $this->addSql('ALTER TABLE "order" DROP tracking_number');
        $this->addSql('ALTER TABLE "order" DROP shipped_at');
        $this->addSql('ALTER TABLE "order" DROP delivered_at');
        $this->addSql('ALTER TABLE "order" DROP updated_at');
        $this->addSql('ALTER TABLE "order" DROP is_locked');
        $this->addSql('ALTER TABLE "order" DROP customer_note');
        $this->addSql('ALTER TABLE "order" DROP order_number');
        $this->addSql('ALTER TABLE "order" ALTER status TYPE VARCHAR(50)');
        $this->addSql('DROP INDEX IDX_BA388B77E3C61F9');
        $this->addSql('ALTER TABLE cart DROP updated_at');
        $this->addSql('CREATE UNIQUE INDEX uniq_ba388b77e3c61f9 ON cart (owner_id)');
    }
}
