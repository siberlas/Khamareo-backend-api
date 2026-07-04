<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add address_complement to address table (complément d\'adresse pour étiquettes Colissimo/Mondial Relay)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address ADD COLUMN IF NOT EXISTS address_complement VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE address DROP COLUMN IF EXISTS address_complement');
    }
}
