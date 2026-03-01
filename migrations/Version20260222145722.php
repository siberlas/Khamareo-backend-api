<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260222145722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create store_settings table for configurable seller dispatch delay';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE IF NOT EXISTS store_settings (
                id SERIAL PRIMARY KEY,
                dispatch_min_days INTEGER DEFAULT NULL,
                dispatch_max_days INTEGER DEFAULT NULL,
                dispatch_days_unit VARCHAR(20) NOT NULL DEFAULT 'working_days',
                dispatch_note TEXT DEFAULT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT NOW()
            )
        ");

        // Insert default row (singleton)
        $this->addSql("
            INSERT INTO store_settings (dispatch_min_days, dispatch_max_days, dispatch_days_unit, dispatch_note, updated_at)
            VALUES (2, 3, 'working_days', NULL, NOW())
            ON CONFLICT DO NOTHING
        ");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS store_settings');
    }
}
