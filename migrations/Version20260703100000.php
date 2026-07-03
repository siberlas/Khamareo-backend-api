<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260703100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add missing columns: user.is_test and contact_message.is_read (applied directly in dev without migration)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS is_test BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE contact_message ADD COLUMN IF NOT EXISTS is_read BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS is_test');
        $this->addSql('ALTER TABLE contact_message DROP COLUMN IF EXISTS is_read');
    }
}
