<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add accounting currency fields to order table (amountEur, exchangeRate, exchangeRateDate, exchangeRateSource)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS amount_eur DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS exchange_rate NUMERIC(12,6) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS exchange_rate_date DATE DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD COLUMN IF NOT EXISTS exchange_rate_source VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS amount_eur');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS exchange_rate');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS exchange_rate_date');
        $this->addSql('ALTER TABLE "order" DROP COLUMN IF EXISTS exchange_rate_source');
    }
}
