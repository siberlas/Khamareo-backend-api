<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Création de la table ext_translations pour Gedmo Translatable
 */
final class Version20251216125329 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Création de la table ext_translations pour les traductions Gedmo';
    }

    public function up(Schema $schema): void
    {
        // Création de la table ext_translations
        $this->addSql('CREATE TABLE ext_translations (
            id SERIAL PRIMARY KEY,
            locale VARCHAR(5) NOT NULL,
            object_class VARCHAR(191) NOT NULL,
            field VARCHAR(32) NOT NULL,
            foreign_key VARCHAR(64) NOT NULL,
            content TEXT DEFAULT NULL
        )');

        // Index pour les performances
        $this->addSql('CREATE UNIQUE INDEX lookup_unique_idx ON ext_translations (locale, object_class, field, foreign_key)');
        $this->addSql('CREATE INDEX translations_lookup_idx ON ext_translations (locale, object_class, foreign_key)');
    }

    public function down(Schema $schema): void
    {
        // Rollback : suppression de la table
        $this->addSql('DROP TABLE IF EXISTS ext_translations');
    }
}