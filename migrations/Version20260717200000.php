<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Segment 3 : l'Email 1 (rappel) affiche désormais 3 produits (pas 1), donc
 * la colonne doit pouvoir stocker plusieurs IDs séparés par des virgules.
 */
final class Version20260717200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rename and widen promo_code.reminder_last_product_id to reminder_last_product_ids';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_code RENAME COLUMN reminder_last_product_id TO reminder_last_product_ids');
        $this->addSql('ALTER TABLE promo_code ALTER COLUMN reminder_last_product_ids TYPE VARCHAR(255)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE promo_code ALTER COLUMN reminder_last_product_ids TYPE VARCHAR(36)');
        $this->addSql('ALTER TABLE promo_code RENAME COLUMN reminder_last_product_ids TO reminder_last_product_id');
    }
}
