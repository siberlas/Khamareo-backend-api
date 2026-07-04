<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add admin_reply and replied_at to contact_message for admin reply system';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS admin_reply TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS replied_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS admin_reply');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS replied_at');
    }
}
