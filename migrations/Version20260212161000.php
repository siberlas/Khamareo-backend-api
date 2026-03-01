<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260212161000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add country_code to shipping_rate for country-specific pricing';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shipping_rate ADD country_code VARCHAR(2) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_4E03E2CFF026BB7C ON shipping_rate (country_code)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_4E03E2CFF026BB7C');
        $this->addSql('ALTER TABLE shipping_rate DROP country_code');
    }
}
