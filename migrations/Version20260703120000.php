<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add carrier_shipping_cost to order and cart (coût effectif transporteur avant remise livraison offerte)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS carrier_shipping_cost DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS carrier_shipping_cost DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS payment_last_error TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS carrier_shipping_cost');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS carrier_shipping_cost');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS payment_last_error');
    }
}
