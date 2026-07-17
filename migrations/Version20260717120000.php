<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 1 (relance newsletter non confirmée) : tracking par abonné +
 * table de dédup cross-segment commune aux futurs segments 3/4.
 */
final class Version20260717120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add newsletter reminder tracking columns and create email_send_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE newsletter_subscriber ADD COLUMN IF NOT EXISTS reminder_count INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE newsletter_subscriber ADD COLUMN IF NOT EXISTS reminder_first_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE newsletter_subscriber ADD COLUMN IF NOT EXISTS reminder_last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE newsletter_subscriber ADD COLUMN IF NOT EXISTS reminder_stopped BOOLEAN NOT NULL DEFAULT false');

        $this->addSql('CREATE TABLE email_send_log (id SERIAL NOT NULL, email VARCHAR(255) NOT NULL, segment VARCHAR(30) NOT NULL, sent_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('CREATE INDEX IDX_EMAIL_SEND_LOG_EMAIL_DATE ON email_send_log (email, sent_at)');

        $this->addSql("INSERT INTO cron_job (key, label, description, command_name, cron_expression, enabled, created_at) VALUES ('newsletter_reminder', 'Relance inscription newsletter non confirmée', 'Envoie un rappel hebdomadaire aux contacts qui n''ont pas confirmé leur inscription (double opt-in), pendant 8 semaines maximum.', 'app:send-newsletter-reminder', '0 9 * * *', true, now())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM cron_job WHERE key = 'newsletter_reminder'");
        $this->addSql('DROP TABLE email_send_log');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP COLUMN IF EXISTS reminder_count');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP COLUMN IF EXISTS reminder_first_sent_at');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP COLUMN IF EXISTS reminder_last_sent_at');
        $this->addSql('ALTER TABLE newsletter_subscriber DROP COLUMN IF EXISTS reminder_stopped');
    }
}
