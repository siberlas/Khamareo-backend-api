<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_newsletter column to launch_email_queue';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE launch_email_queue ADD COLUMN IF NOT EXISTS is_newsletter BOOLEAN DEFAULT false NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE launch_email_queue DROP COLUMN IF EXISTS is_newsletter');
    }
}
