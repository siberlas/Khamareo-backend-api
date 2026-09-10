<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute carton.is_active : permet de désactiver un format d'emballage
 * (rupture de stock de cartons) sans le supprimer. Un carton inactif reste
 * en base et sur les colis existants mais est exclu du colisage automatique
 * de l'estimation et du sélecteur de préparation.
 *
 * Tous les cartons existants sont marqués actifs.
 */
final class Version20260910120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute carton.is_active (activation/désactivation des formats d\'emballage)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE carton ADD is_active BOOLEAN DEFAULT true NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE carton DROP is_active');
    }
}
