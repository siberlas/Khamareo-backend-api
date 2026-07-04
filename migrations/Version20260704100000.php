<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260704100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restrict Mondial Relay Domicile to DE, AT, BE, ES, IT, LU, NL, PT (actual home delivery countries)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = '[\"DE\",\"AT\",\"BE\",\"ES\",\"IT\",\"LU\",\"NL\",\"PT\"]'
            WHERE carrier_id = (SELECT id FROM carrier WHERE code = 'mondialrelay' LIMIT 1)
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'home' LIMIT 1)
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("
            UPDATE carrier_mode
            SET allowed_countries = NULL
            WHERE carrier_id = (SELECT id FROM carrier WHERE code = 'mondialrelay' LIMIT 1)
              AND shipping_mode_id = (SELECT id FROM shipping_mode WHERE code = 'home' LIMIT 1)
        ");
    }
}
