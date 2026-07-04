<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locale to contact_conversation for localized reply emails';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE contact_conversation ADD COLUMN IF NOT EXISTS locale VARCHAR(5) NOT NULL DEFAULT 'fr'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact_conversation DROP COLUMN IF EXISTS locale');
    }
}
