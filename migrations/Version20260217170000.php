<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add email to review';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review ADD email VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE review DROP email');
    }
}
