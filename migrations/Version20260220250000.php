<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajout cgv_version et cgv_accepted_at sur la table order.
 * Traçabilité de l'acceptation des CGV par le client (L221-14 Code de la consommation).
 */
final class Version20260220250000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout cgv_version et cgv_accepted_at sur la table order (traçabilité CGV)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" ADD cgv_version VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE "order" ADD cgv_accepted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('COMMENT ON COLUMN "order".cgv_accepted_at IS \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE "order" DROP COLUMN cgv_version');
        $this->addSql('ALTER TABLE "order" DROP COLUMN cgv_accepted_at');
    }
}
