<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Update Mondial Relay allowed countries: Domicile (DE,BE,FR,LU), Locker (BE,ES,FR,IT,LU,PL,PT), Point Relais (BE,ES,FR,IT,LU,NL,PL,PT)';
    }

    public function up(Schema $schema): void
    {
        $mondialrelayId = "(SELECT id FROM carrier WHERE code = 'mondialrelay' LIMIT 1)";

        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"DE\",\"BE\",\"FR\",\"LU\"]'
            WHERE carrier_id = {$mondialrelayId}
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'home' LIMIT 1)
        ");

        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"BE\",\"ES\",\"FR\",\"IT\",\"LU\",\"PL\",\"PT\"]'
            WHERE carrier_id = {$mondialrelayId}
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'locker' LIMIT 1)
        ");

        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"BE\",\"ES\",\"FR\",\"IT\",\"LU\",\"NL\",\"PL\",\"PT\"]'
            WHERE carrier_id = {$mondialrelayId}
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'relay_point' LIMIT 1)
        ");
    }

    public function down(Schema $schema): void
    {
        $mondialrelayId = "(SELECT id FROM carrier WHERE code = 'mondialrelay' LIMIT 1)";

        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"DE\",\"AT\",\"BE\",\"ES\",\"IT\",\"LU\",\"NL\",\"PT\"]'
            WHERE carrier_id = {$mondialrelayId}
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'home' LIMIT 1)
        ");

        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"FR\"]'
            WHERE carrier_id = {$mondialrelayId}
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'locker' LIMIT 1)
        ");

        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"BE\",\"ES\",\"PT\",\"LU\",\"IT\",\"PL\",\"NL\"]'
            WHERE carrier_id = {$mondialrelayId}
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'relay_point' LIMIT 1)
        ");
    }
}
