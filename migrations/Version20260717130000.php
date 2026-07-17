<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 2 (relance compte non vérifié) : tracking par utilisateur,
 * même schéma que le tracking newsletter (segment 1).
 */
final class Version20260717130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add account verification reminder tracking columns to user';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_reminder_count INT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_reminder_first_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_reminder_last_sent_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS verification_reminder_stopped BOOLEAN NOT NULL DEFAULT false');

        $this->addSql("INSERT INTO cron_job (key, label, description, command_name, cron_expression, enabled, created_at) VALUES ('verification_reminder', 'Relance compte client non vérifié', 'Envoie un rappel hebdomadaire aux clients qui n''ont jamais confirmé leur adresse email après inscription, pendant 8 semaines maximum.', 'app:send-verification-reminder', '0 9 * * *', true, now())");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM cron_job WHERE key = 'verification_reminder'");
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS verification_reminder_count');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS verification_reminder_first_sent_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS verification_reminder_last_sent_at');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS verification_reminder_stopped');
    }
}
