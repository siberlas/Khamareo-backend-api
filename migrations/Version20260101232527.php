<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260101232527 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE carrier_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE carrier_mode_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_mode_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE carrier (id INT NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(50) NOT NULL, description TEXT DEFAULT NULL, max_weight_grams INT NOT NULL, min_weight_grams INT NOT NULL, is_active BOOLEAN NOT NULL, logo_url VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_4739F11C77153098 ON carrier (code)');
        $this->addSql('COMMENT ON COLUMN carrier.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE carrier_mode (id INT NOT NULL, carrier_id INT NOT NULL, shipping_mode_id INT NOT NULL, supported_zones JSON NOT NULL, is_active BOOLEAN NOT NULL, estimated_delivery_days INT DEFAULT NULL, base_price DOUBLE PRECISION DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C5A73B2C21DFC797 ON carrier_mode (carrier_id)');
        $this->addSql('CREATE INDEX IDX_C5A73B2C91AD838E ON carrier_mode (shipping_mode_id)');
        $this->addSql('COMMENT ON COLUMN carrier_mode.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE parcel (id UUID NOT NULL, order_id UUID NOT NULL, parcel_number INT NOT NULL, weight_grams INT DEFAULT NULL, tracking_number VARCHAR(100) DEFAULT NULL, label_pdf_path TEXT DEFAULT NULL, cn23_pdf_path TEXT DEFAULT NULL, invoice_pdf_path TEXT DEFAULT NULL, status VARCHAR(50) NOT NULL, label_generated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, shipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_C99B5D608D9F6D38 ON parcel (order_id)');
        $this->addSql('COMMENT ON COLUMN parcel.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN parcel.order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN parcel.label_generated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN parcel.shipped_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN parcel.delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN parcel.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE parcel_item (id UUID NOT NULL, parcel_id UUID NOT NULL, order_item_id UUID NOT NULL, quantity INT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_915CE21465E670C ON parcel_item (parcel_id)');
        $this->addSql('CREATE INDEX IDX_915CE21E415FB15 ON parcel_item (order_item_id)');
        $this->addSql('COMMENT ON COLUMN parcel_item.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN parcel_item.parcel_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN parcel_item.order_item_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE shipping_mode (id INT NOT NULL, name VARCHAR(100) NOT NULL, code VARCHAR(50) NOT NULL, description TEXT DEFAULT NULL, requires_pickup_point BOOLEAN NOT NULL, is_active BOOLEAN NOT NULL, icon VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_676D1A977153098 ON shipping_mode (code)');
        $this->addSql('COMMENT ON COLUMN shipping_mode.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE carrier_mode ADD CONSTRAINT FK_C5A73B2C21DFC797 FOREIGN KEY (carrier_id) REFERENCES carrier (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE carrier_mode ADD CONSTRAINT FK_C5A73B2C91AD838E FOREIGN KEY (shipping_mode_id) REFERENCES shipping_mode (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE parcel ADD CONSTRAINT FK_C99B5D608D9F6D38 FOREIGN KEY (order_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE parcel_item ADD CONSTRAINT FK_915CE21465E670C FOREIGN KEY (parcel_id) REFERENCES parcel (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE parcel_item ADD CONSTRAINT FK_915CE21E415FB15 FOREIGN KEY (order_item_id) REFERENCES order_item (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD carrier_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD shipping_mode_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD delivery_issue_type VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F529939821DFC797 FOREIGN KEY (carrier_id) REFERENCES carrier (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F529939891AD838E FOREIGN KEY (shipping_mode_id) REFERENCES shipping_mode (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_F529939821DFC797 ON "order" (carrier_id)');
        $this->addSql('CREATE INDEX IDX_F529939891AD838E ON "order" (shipping_mode_id)');
        $this->addSql('ALTER TABLE shipping_rate ADD carrier_mode_id INT NOT NULL');
        $this->addSql('ALTER TABLE shipping_rate ADD min_weight_grams INT NOT NULL');
        $this->addSql('ALTER TABLE shipping_rate ADD max_weight_grams INT NOT NULL');
        $this->addSql('ALTER TABLE shipping_rate DROP provider');
        $this->addSql('ALTER TABLE shipping_rate DROP min_weight');
        $this->addSql('ALTER TABLE shipping_rate DROP max_weight');
        $this->addSql('ALTER TABLE shipping_rate ALTER zone TYPE VARCHAR(10)');
        $this->addSql('ALTER TABLE shipping_rate ADD CONSTRAINT FK_4E50A93B2316A302 FOREIGN KEY (carrier_mode_id) REFERENCES carrier_mode (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_4E50A93B2316A302 ON shipping_rate (carrier_mode_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F529939821DFC797');
        $this->addSql('ALTER TABLE shipping_rate DROP CONSTRAINT FK_4E50A93B2316A302');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F529939891AD838E');
        $this->addSql('DROP SEQUENCE carrier_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE carrier_mode_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_mode_id_seq CASCADE');
        $this->addSql('ALTER TABLE carrier_mode DROP CONSTRAINT FK_C5A73B2C21DFC797');
        $this->addSql('ALTER TABLE carrier_mode DROP CONSTRAINT FK_C5A73B2C91AD838E');
        $this->addSql('ALTER TABLE parcel DROP CONSTRAINT FK_C99B5D608D9F6D38');
        $this->addSql('ALTER TABLE parcel_item DROP CONSTRAINT FK_915CE21465E670C');
        $this->addSql('ALTER TABLE parcel_item DROP CONSTRAINT FK_915CE21E415FB15');
        $this->addSql('DROP TABLE carrier');
        $this->addSql('DROP TABLE carrier_mode');
        $this->addSql('DROP TABLE parcel');
        $this->addSql('DROP TABLE parcel_item');
        $this->addSql('DROP TABLE shipping_mode');
        $this->addSql('DROP INDEX IDX_4E50A93B2316A302');
        $this->addSql('ALTER TABLE shipping_rate ADD provider VARCHAR(100) NOT NULL');
        $this->addSql('ALTER TABLE shipping_rate ADD min_weight DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE shipping_rate ADD max_weight DOUBLE PRECISION NOT NULL');
        $this->addSql('ALTER TABLE shipping_rate DROP carrier_mode_id');
        $this->addSql('ALTER TABLE shipping_rate DROP min_weight_grams');
        $this->addSql('ALTER TABLE shipping_rate DROP max_weight_grams');
        $this->addSql('ALTER TABLE shipping_rate ALTER zone TYPE VARCHAR(100)');
        $this->addSql('DROP INDEX IDX_F529939821DFC797');
        $this->addSql('DROP INDEX IDX_F529939891AD838E');
        $this->addSql('ALTER TABLE "order" DROP carrier_id');
        $this->addSql('ALTER TABLE "order" DROP shipping_mode_id');
        $this->addSql('ALTER TABLE "order" DROP delivery_issue_type');
    }
}
