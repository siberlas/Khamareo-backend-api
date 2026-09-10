<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute la table carton (formats d'emballage, page admin "Emballage") et la
 * relation parcel.carton_id (format choisi pour ce colis).
 */
final class Version20260811100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée la table carton et ajoute parcel.carton_id (FK nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE carton (id UUID NOT NULL, name VARCHAR(255) NOT NULL, length_cm INT NOT NULL, width_cm INT NOT NULL, height_cm INT NOT NULL, empty_weight_grams INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))');
        $this->addSql('ALTER TABLE parcel ADD carton_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE parcel ADD CONSTRAINT FK_C99B5D60E77C2D51 FOREIGN KEY (carton_id) REFERENCES carton (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_C99B5D60E77C2D51 ON parcel (carton_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel DROP CONSTRAINT FK_C99B5D60E77C2D51');
        $this->addSql('DROP INDEX IDX_C99B5D60E77C2D51');
        $this->addSql('ALTER TABLE parcel DROP carton_id');
        $this->addSql('DROP TABLE carton');
    }
}
