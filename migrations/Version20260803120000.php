<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ajoute parcel.manual_weight_grams : poids réel pesé (emballage inclus),
 * saisi manuellement avant génération d'étiquette Colissimo — prioritaire
 * sur l'estimation automatique pour éviter les refus au dépôt.
 */
final class Version20260803120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute parcel.manual_weight_grams (integer, nullable)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel ADD manual_weight_grams INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE parcel DROP manual_weight_grams');
    }
}
