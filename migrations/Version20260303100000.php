<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260303100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add promo_codes_data JSON column to cart for multi-code stacking support';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD COLUMN promo_codes_data JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP COLUMN promo_codes_data');
    }
}
