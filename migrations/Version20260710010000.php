<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Provenance visiteur (source/pays/OS/appareil) — version réduite sans table
 * dédiée ni fingerprinting : quelques colonnes directement sur cart/order,
 * dérivées du header Referer, du header Cloudflare CF-IPCountry (déjà fourni
 * gratuitement puisque le site est déjà derrière Cloudflare) et du User-Agent.
 */
final class Version20260710010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add source/country/os_name/device_type tracking columns on cart and order';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS os_name VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS device_type VARCHAR(20) DEFAULT NULL');

        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS source VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS country VARCHAR(2) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS os_name VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS device_type VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS os_name');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS device_type');

        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS source');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS country');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS os_name');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS device_type');
    }
}
