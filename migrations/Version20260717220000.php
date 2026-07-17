<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 3 phase 2 (cascade mensuelle pour les codes 60j/90j/120j) :
 * compteur de mises à jour déjà envoyées par code promo.
 */
final class Version20260717220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add promo_code.reminder_update_count for the monthly update cascade';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_code ADD COLUMN IF NOT EXISTS reminder_update_count INT NOT NULL DEFAULT 0');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_code DROP COLUMN IF EXISTS reminder_update_count');
    }
}
