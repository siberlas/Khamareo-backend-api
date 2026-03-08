<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260302100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add confirmation_email_sent column to order table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD COLUMN confirmation_email_sent BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN confirmation_email_sent');
    }
}
