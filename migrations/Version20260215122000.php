<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260215122000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cart promo codes (multiple promos per cart)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cart_promo_code (id SERIAL NOT NULL, cart_id UUID NOT NULL, promo_code_id UUID NOT NULL, discount_amount NUMERIC(10, 2) DEFAULT NULL, applied_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE UNIQUE INDEX uniq_cart_promo_code ON cart_promo_code (cart_id, promo_code_id)');
        $this->addSql('CREATE INDEX IDX_CART_PROMO_CODE_CART ON cart_promo_code (cart_id)');
        $this->addSql('CREATE INDEX IDX_CART_PROMO_CODE_PROMO ON cart_promo_code (promo_code_id)');
        $this->addSql('COMMENT ON COLUMN cart_promo_code.cart_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN cart_promo_code.promo_code_id IS \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE cart_promo_code ADD CONSTRAINT FK_CART_PROMO_CODE_CART FOREIGN KEY (cart_id) REFERENCES cart (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE cart_promo_code ADD CONSTRAINT FK_CART_PROMO_CODE_PROMO FOREIGN KEY (promo_code_id) REFERENCES promo_code (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE cart_promo_code');
    }
}
