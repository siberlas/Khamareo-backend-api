<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 4 : cart:reminder doit désormais tourner toutes les heures (et non
 * plus une fois par jour) pour respecter le délai J+1h de l'étape 1.
 */
final class Version20260717210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set cart_reminder cron job to run hourly instead of daily';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("UPDATE cron_job SET cron_expression = '0 * * * *' WHERE key = 'cart_reminder'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE cron_job SET cron_expression = '0 10 * * *' WHERE key = 'cart_reminder'");
    }
}
