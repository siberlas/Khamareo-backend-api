<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fix payment_status for manually recovered order ORD-52D90CF2';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE \"order\" SET payment_status = 'paid' WHERE reference = 'ORD-52D90CF2' AND payment_status != 'paid'");
    }

    public function down(Schema $schema): void
    {
        // Pas de rollback sur une correction de données
    }
}
