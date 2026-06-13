<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260613200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add google_id column to user table for Google OAuth';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "user" ADD COLUMN IF NOT EXISTS google_id VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_8D93D64976F5C865 ON "user" (google_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS UNIQ_8D93D64976F5C865');
        $this->addSql('ALTER TABLE "user" DROP COLUMN IF EXISTS google_id');
    }
}
