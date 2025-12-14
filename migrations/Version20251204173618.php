<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251204173618 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SEQUENCE address_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_label_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_method_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE SEQUENCE shipping_rate_id_seq INCREMENT BY 1 MINVALUE 1 START 1');
        $this->addSql('CREATE TABLE address (id INT NOT NULL, owner_id UUID DEFAULT NULL, address_kind VARCHAR(20) NOT NULL, street_address VARCHAR(255) NOT NULL, city VARCHAR(100) NOT NULL, postal_code VARCHAR(10) NOT NULL, country VARCHAR(100) NOT NULL, label VARCHAR(150) DEFAULT NULL, civility VARCHAR(10) DEFAULT NULL, first_name VARCHAR(100) DEFAULT NULL, last_name VARCHAR(100) DEFAULT NULL, phone VARCHAR(20) DEFAULT NULL, is_business BOOLEAN NOT NULL, company_name VARCHAR(255) DEFAULT NULL, is_relay_point BOOLEAN DEFAULT false NOT NULL, relay_point_id VARCHAR(100) DEFAULT NULL, relay_carrier VARCHAR(50) DEFAULT NULL, is_default BOOLEAN DEFAULT false NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_D4E6F817E3C61F9 ON address (owner_id)');
        $this->addSql('COMMENT ON COLUMN address.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE blog_post (id UUID NOT NULL, author_id UUID DEFAULT NULL, title VARCHAR(255) NOT NULL, content TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_BA5AE01DF675F31B ON blog_post (author_id)');
        $this->addSql('COMMENT ON COLUMN blog_post.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN blog_post.author_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN blog_post.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE cart (id UUID NOT NULL, owner_id UUID DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_active BOOLEAN DEFAULT true NOT NULL, guest_token VARCHAR(64) DEFAULT NULL, payment_intent_id VARCHAR(255) DEFAULT NULL, payment_client_secret VARCHAR(255) DEFAULT NULL, shipping_cost DOUBLE PRECISION DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BA388B74AC9362F ON cart (guest_token)');
        $this->addSql('CREATE INDEX IDX_BA388B77E3C61F9 ON cart (owner_id)');
        $this->addSql('COMMENT ON COLUMN cart.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN cart.owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN cart.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN cart.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE cart_item (id UUID NOT NULL, cart_id UUID NOT NULL, product_id UUID NOT NULL, quantity INT NOT NULL, unit_price DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_F0FE25271AD5CDBF ON cart_item (cart_id)');
        $this->addSql('CREATE INDEX IDX_F0FE25274584665A ON cart_item (product_id)');
        $this->addSql('CREATE UNIQUE INDEX cart_product_unique ON cart_item (cart_id, product_id)');
        $this->addSql('COMMENT ON COLUMN cart_item.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN cart_item.cart_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN cart_item.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE category (id UUID NOT NULL, parent_id UUID DEFAULT NULL, slug VARCHAR(255) NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_64C19C1989D9B62 ON category (slug)');
        $this->addSql('CREATE INDEX IDX_64C19C1727ACA70 ON category (parent_id)');
        $this->addSql('COMMENT ON COLUMN category.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN category.parent_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE newsletter_subscriber (id UUID NOT NULL, email VARCHAR(255) NOT NULL, subscribed_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN newsletter_subscriber.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN newsletter_subscriber.subscribed_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE "order" (id UUID NOT NULL, shipping_address_id INT DEFAULT NULL, billing_address_id INT DEFAULT NULL, owner_id UUID DEFAULT NULL, shipping_method_id INT NOT NULL, reference VARCHAR(30) NOT NULL, total_amount DOUBLE PRECISION NOT NULL, status VARCHAR(255) NOT NULL, payment_id VARCHAR(255) DEFAULT NULL, payment_method VARCHAR(50) DEFAULT NULL, payment_status VARCHAR(30) DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, tracking_number VARCHAR(100) DEFAULT NULL, shipped_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_locked BOOLEAN NOT NULL, customer_note TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, order_number VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, shipping_cost DOUBLE PRECISION DEFAULT NULL, guest_email VARCHAR(180) DEFAULT NULL, guest_first_name VARCHAR(100) DEFAULT NULL, guest_last_name VARCHAR(100) DEFAULT NULL, guest_phone VARCHAR(30) DEFAULT NULL, is_relay_point BOOLEAN NOT NULL, relay_point_id VARCHAR(100) DEFAULT NULL, relay_carrier VARCHAR(50) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F5299398AEA34913 ON "order" (reference)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_F5299398551F0F81 ON "order" (order_number)');
        $this->addSql('CREATE INDEX IDX_F52993984D4CFF2B ON "order" (shipping_address_id)');
        $this->addSql('CREATE INDEX IDX_F529939879D0C0E4 ON "order" (billing_address_id)');
        $this->addSql('CREATE INDEX IDX_F52993987E3C61F9 ON "order" (owner_id)');
        $this->addSql('CREATE INDEX IDX_F52993985F7D6850 ON "order" (shipping_method_id)');
        $this->addSql('COMMENT ON COLUMN "order".id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "order".owner_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "order".paid_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".shipped_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".delivered_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN "order".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE order_item (id UUID NOT NULL, customer_order_id UUID NOT NULL, product_id UUID NOT NULL, quantity INT NOT NULL, unit_price DOUBLE PRECISION NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_52EA1F09A15A2E17 ON order_item (customer_order_id)');
        $this->addSql('CREATE INDEX IDX_52EA1F094584665A ON order_item (product_id)');
        $this->addSql('COMMENT ON COLUMN order_item.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN order_item.customer_order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN order_item.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE payment (id UUID NOT NULL, order_id UUID NOT NULL, status VARCHAR(255) NOT NULL, provider VARCHAR(100) NOT NULL, amount DOUBLE PRECISION NOT NULL, transaction_id VARCHAR(255) DEFAULT NULL, provider_payment_id VARCHAR(255) DEFAULT NULL, client_secret VARCHAR(255) DEFAULT NULL, method VARCHAR(50) DEFAULT NULL, provider_response JSON DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_6D28840D8D9F6D38 ON payment (order_id)');
        $this->addSql('COMMENT ON COLUMN payment.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN payment.order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN payment.paid_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE product (id UUID NOT NULL, category_id UUID NOT NULL, name VARCHAR(255) NOT NULL, description TEXT DEFAULT NULL, price DOUBLE PRECISION NOT NULL, weight DOUBLE PRECISION DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stock INT NOT NULL, image_url VARCHAR(255) NOT NULL, slug VARCHAR(255) NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, reviews_count INT DEFAULT NULL, rating DOUBLE PRECISION DEFAULT NULL, badge VARCHAR(255) DEFAULT NULL, benefits JSON DEFAULT NULL, ingredients TEXT DEFAULT NULL, usage TEXT DEFAULT NULL, original_price DOUBLE PRECISION DEFAULT NULL, images JSON DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D34A04AD989D9B62 ON product (slug)');
        $this->addSql('CREATE INDEX IDX_D34A04AD12469DE2 ON product (category_id)');
        $this->addSql('COMMENT ON COLUMN product.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product.category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN product.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE product_related (product_source UUID NOT NULL, product_target UUID NOT NULL, PRIMARY KEY(product_source, product_target))');
        $this->addSql('CREATE INDEX IDX_B18E6B203DF63ED7 ON product_related (product_source)');
        $this->addSql('CREATE INDEX IDX_B18E6B2024136E58 ON product_related (product_target)');
        $this->addSql('COMMENT ON COLUMN product_related.product_source IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN product_related.product_target IS \'(DC2Type:uuid)\'');
        $this->addSql('CREATE TABLE review (id UUID NOT NULL, product_id UUID NOT NULL, name VARCHAR(255) NOT NULL, rating SMALLINT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, comment TEXT NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_794381C64584665A ON review (product_id)');
        $this->addSql('COMMENT ON COLUMN review.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN review.product_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN review.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE shipping_label (id INT NOT NULL, order_id UUID NOT NULL, provider VARCHAR(100) NOT NULL, tracking_number VARCHAR(150) NOT NULL, file_path VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_E0388D528D9F6D38 ON shipping_label (order_id)');
        $this->addSql('COMMENT ON COLUMN shipping_label.order_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN shipping_label.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE shipping_method (id INT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, price DOUBLE PRECISION NOT NULL, carrier_code VARCHAR(100) DEFAULT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE TABLE shipping_rate (id INT NOT NULL, provider VARCHAR(100) NOT NULL, zone VARCHAR(100) NOT NULL, min_weight DOUBLE PRECISION NOT NULL, max_weight DOUBLE PRECISION NOT NULL, price DOUBLE PRECISION NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN shipping_rate.created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(255) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, first_name VARCHAR(255) NOT NULL, last_name VARCHAR(255) NOT NULL, phone VARCHAR(20) DEFAULT NULL, address VARCHAR(255) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, stripe_customer_id VARCHAR(64) DEFAULT NULL, accept_terms BOOLEAN NOT NULL, newsletter BOOLEAN DEFAULT false NOT NULL, confirmation_token VARCHAR(255) DEFAULT NULL, is_verified BOOLEAN DEFAULT false NOT NULL, reset_password_token VARCHAR(255) DEFAULT NULL, reset_password_requested_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, is_guest BOOLEAN DEFAULT false NOT NULL, PRIMARY KEY(id))');
        $this->addSql('COMMENT ON COLUMN "user".id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN "user".created_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE address ADD CONSTRAINT FK_D4E6F817E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE blog_post ADD CONSTRAINT FK_BA5AE01DF675F31B FOREIGN KEY (author_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cart ADD CONSTRAINT FK_BA388B77E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25271AD5CDBF FOREIGN KEY (cart_id) REFERENCES cart (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cart_item ADD CONSTRAINT FK_F0FE25274584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993984D4CFF2B FOREIGN KEY (shipping_address_id) REFERENCES address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F529939879D0C0E4 FOREIGN KEY (billing_address_id) REFERENCES address (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993987E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE "order" ADD CONSTRAINT FK_F52993985F7D6850 FOREIGN KEY (shipping_method_id) REFERENCES shipping_method (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F09A15A2E17 FOREIGN KEY (customer_order_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F094584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D8D9F6D38 FOREIGN KEY (order_id) REFERENCES "order" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04AD12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_related ADD CONSTRAINT FK_B18E6B203DF63ED7 FOREIGN KEY (product_source) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_related ADD CONSTRAINT FK_B18E6B2024136E58 FOREIGN KEY (product_target) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE review ADD CONSTRAINT FK_794381C64584665A FOREIGN KEY (product_id) REFERENCES product (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shipping_label ADD CONSTRAINT FK_E0388D528D9F6D38 FOREIGN KEY (order_id) REFERENCES "order" (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('DROP SEQUENCE address_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_label_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_method_id_seq CASCADE');
        $this->addSql('DROP SEQUENCE shipping_rate_id_seq CASCADE');
        $this->addSql('ALTER TABLE address DROP CONSTRAINT FK_D4E6F817E3C61F9');
        $this->addSql('ALTER TABLE blog_post DROP CONSTRAINT FK_BA5AE01DF675F31B');
        $this->addSql('ALTER TABLE cart DROP CONSTRAINT FK_BA388B77E3C61F9');
        $this->addSql('ALTER TABLE cart_item DROP CONSTRAINT FK_F0FE25271AD5CDBF');
        $this->addSql('ALTER TABLE cart_item DROP CONSTRAINT FK_F0FE25274584665A');
        $this->addSql('ALTER TABLE category DROP CONSTRAINT FK_64C19C1727ACA70');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993984D4CFF2B');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F529939879D0C0E4');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993987E3C61F9');
        $this->addSql('ALTER TABLE "order" DROP CONSTRAINT FK_F52993985F7D6850');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F09A15A2E17');
        $this->addSql('ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F094584665A');
        $this->addSql('ALTER TABLE payment DROP CONSTRAINT FK_6D28840D8D9F6D38');
        $this->addSql('ALTER TABLE product DROP CONSTRAINT FK_D34A04AD12469DE2');
        $this->addSql('ALTER TABLE product_related DROP CONSTRAINT FK_B18E6B203DF63ED7');
        $this->addSql('ALTER TABLE product_related DROP CONSTRAINT FK_B18E6B2024136E58');
        $this->addSql('ALTER TABLE review DROP CONSTRAINT FK_794381C64584665A');
        $this->addSql('ALTER TABLE shipping_label DROP CONSTRAINT FK_E0388D528D9F6D38');
        $this->addSql('DROP TABLE address');
        $this->addSql('DROP TABLE blog_post');
        $this->addSql('DROP TABLE cart');
        $this->addSql('DROP TABLE cart_item');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE newsletter_subscriber');
        $this->addSql('DROP TABLE "order"');
        $this->addSql('DROP TABLE order_item');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP TABLE product');
        $this->addSql('DROP TABLE product_related');
        $this->addSql('DROP TABLE review');
        $this->addSql('DROP TABLE shipping_label');
        $this->addSql('DROP TABLE shipping_method');
        $this->addSql('DROP TABLE shipping_rate');
        $this->addSql('DROP TABLE "user"');
    }
}
