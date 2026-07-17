<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 4 phase 1 (relance panier abandonné en 3 étapes) : tracking par
 * panier + nouveau type de code promo "cart_recovery".
 */
final class Version20260717194500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add cart reminder stage tracking columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS reminder_stage INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS reminder_stage_last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS reminder_promo_code_id VARCHAR(36) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS reminder_stage');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS reminder_stage_last_sent_at');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS reminder_promo_code_id');
    }
}
