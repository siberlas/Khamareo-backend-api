<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260702100000 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE store_settings ADD COLUMN IF NOT EXISTS free_shipping_thresholds JSON");

        // Valeurs par défaut cohérentes avec le comportement actuel :
        // - FR : 65€ (préservé), EU : 100€, zones OM/Intl : null (pas de gratuité)
        $this->addSql("
            UPDATE store_settings
            SET free_shipping_thresholds = '{\"FR\": 65, \"EU\": 100, \"OM1\": null, \"OM2\": null, \"CH\": 150, \"UK\": 150, \"B\": null, \"C\": null}'::json
            WHERE free_shipping_thresholds IS NULL
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE store_settings DROP COLUMN IF EXISTS free_shipping_thresholds");
    }
}
