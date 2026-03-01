<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260217160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_featured flag to product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD is_featured BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP is_featured');
    }
}
