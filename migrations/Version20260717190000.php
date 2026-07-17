<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 3 phase 1 (relance codes promo "launch" non utilisés) : tracking
 * par code promo.
 */
final class Version20260717190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add promo code reminder tracking columns';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_code ADD COLUMN IF NOT EXISTS reminder_rappel_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD COLUMN IF NOT EXISTS reminder_urgency_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE promo_code ADD COLUMN IF NOT EXISTS reminder_last_product_id VARCHAR(36) DEFAULT NULL');

        $this->addSql("INSERT INTO cron_job (key, label, description, command_name, cron_expression, enabled, created_at) VALUES ('promo_code_reminder', 'Relance code promo non utilisé', 'Envoie un rappel puis une relance d''urgence (J-3 avant expiration) aux contacts ayant un code promo de lancement (AKWAABA) jamais utilisé.', 'app:send-promo-code-reminder', '0 9 * * *', true, now())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM cron_job WHERE key = 'promo_code_reminder'");
        $this->addSql('ALTER TABLE promo_code DROP COLUMN IF EXISTS reminder_rappel_sent_at');
        $this->addSql('ALTER TABLE promo_code DROP COLUMN IF EXISTS reminder_urgency_sent_at');
        $this->addSql('ALTER TABLE promo_code DROP COLUMN IF EXISTS reminder_last_product_id');
    }
}
