<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class VersionCartReminderFields extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des champs de suivi de rappel sur Cart';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart ADD last_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD reminder_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE cart ADD last_guest_reminder_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE cart ADD guest_reminder_count INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cart DROP last_reminder_at');
        $this->addSql('ALTER TABLE cart DROP reminder_count');
        $this->addSql('ALTER TABLE cart DROP last_guest_reminder_at');
        $this->addSql('ALTER TABLE cart DROP guest_reminder_count');
    }
}
