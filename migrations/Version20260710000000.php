<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Répare le drift constaté en prod : VersionCartReminderFields est marquée
 * "exécutée" dans doctrine_migration_versions (stampée lors d'un premier
 * déploiement via doctrine:migrations:version --add --all) sans que les
 * colonnes aient réellement été créées à l'époque — l'entité Cart ne les
 * mappait pas encore à ce moment-là. doctrine:migrations:migrate ignore donc
 * silencieusement cette migration "déjà faite". Cette migration porte un
 * nouveau numéro de version jamais vu, donc elle sera bien exécutée par
 * deploy.sh au prochain déploiement. IF NOT EXISTS la rend sans risque
 * partout, y compris là où les colonnes existent déjà (dev/test).
 */
final class Version20260710000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair: (re)create cart reminder columns missing despite VersionCartReminderFields marked executed';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS last_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS reminder_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS last_guest_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD COLUMN IF NOT EXISTS guest_reminder_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS last_reminder_at');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS reminder_count');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS last_guest_reminder_at');
        $this->addSql('ALTER TABLE cart DROP COLUMN IF EXISTS guest_reminder_count');
    }
}
