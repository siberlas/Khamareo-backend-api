<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute le suivi de la dernière erreur de génération d'étiquette sur Parcel
 * (affichage badge "génération bloquée" côté admin).
 */
final class Version20260706170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add last_label_error / last_label_error_at columns to parcel';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel ADD last_label_error TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE parcel ADD last_label_error_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql("COMMENT ON COLUMN parcel.last_label_error_at IS '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel DROP last_label_error');
        $this->addSql('ALTER TABLE parcel DROP last_label_error_at');
    }
}
