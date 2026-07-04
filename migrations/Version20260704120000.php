<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add guest_country and guest_referrer to cart for anonymous user tracking';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS guest_country VARCHAR(5) DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS guest_referrer VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS guest_country');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS guest_referrer');
    }
}
