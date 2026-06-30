<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701090000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"order\" ADD COLUMN IF NOT EXISTS is_test BOOLEAN NOT NULL DEFAULT FALSE");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE \"order\" DROP COLUMN IF EXISTS is_test");
    }
}
