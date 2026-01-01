<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20251215004959 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajout des champs blog complets avec gestion des données existantes';
    }

    public function up(Schema $schema): void
    {
        // 1. Créer la table blog_category d'abord
        $this->addSql('CREATE TABLE blog_category (
            id UUID NOT NULL, 
            name VARCHAR(100) NOT NULL, 
            slug VARCHAR(120) NOT NULL, 
            description TEXT DEFAULT NULL, 
            created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, 
            PRIMARY KEY(id)
        )');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_72113DE6989D9B62 ON blog_category (slug)');
        $this->addSql('COMMENT ON COLUMN blog_category.id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN blog_category.created_at IS \'(DC2Type:datetime_immutable)\'');

        // 2. Ajouter les colonnes NULLABLE d'abord (pour ne pas bloquer sur les données existantes)
        $this->addSql('ALTER TABLE blog_post ADD category_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD slug VARCHAR(255) DEFAULT NULL'); // NULLABLE temporairement
        $this->addSql('ALTER TABLE blog_post ADD excerpt TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD featured_image VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD status VARCHAR(20) DEFAULT \'draft\' NOT NULL'); // Avec DEFAULT
        $this->addSql('ALTER TABLE blog_post ADD updated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD published_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD reading_time INT DEFAULT NULL');
        $this->addSql('ALTER TABLE blog_post ADD is_featured BOOLEAN DEFAULT FALSE NOT NULL'); // Avec DEFAULT
        $this->addSql('ALTER TABLE blog_post ADD author_name VARCHAR(255) DEFAULT NULL');

        // 3. Générer des slugs pour les articles existants (à partir du titre + id)
        $this->addSql("
            UPDATE blog_post 
            SET slug = LOWER(
                REGEXP_REPLACE(
                    REGEXP_REPLACE(
                        REGEXP_REPLACE(title, '[àáâãäå]', 'a', 'g'),
                        '[èéêë]', 'e', 'g'
                    ),
                    '[^a-z0-9\\s-]', '', 'g'
                ) || ' ' || SUBSTRING(id::text, 1, 8)
            )
            WHERE slug IS NULL
        ");
        
        // Remplacer les espaces par des tirets
        $this->addSql("
            UPDATE blog_post 
            SET slug = REGEXP_REPLACE(slug, '\\s+', '-', 'g')
        ");

        // 4. Maintenant rendre slug NOT NULL
        $this->addSql('ALTER TABLE blog_post ALTER COLUMN slug SET NOT NULL');
        
        // 5. Créer l'index unique sur slug
        $this->addSql('CREATE UNIQUE INDEX UNIQ_BA5AE01D989D9B62 ON blog_post (slug)');

        // 6. Ajouter les commentaires Doctrine
        $this->addSql('COMMENT ON COLUMN blog_post.category_id IS \'(DC2Type:uuid)\'');
        $this->addSql('COMMENT ON COLUMN blog_post.updated_at IS \'(DC2Type:datetime_immutable)\'');
        $this->addSql('COMMENT ON COLUMN blog_post.published_at IS \'(DC2Type:datetime_immutable)\'');

        // 7. Ajouter les contraintes de clés étrangères et index
        $this->addSql('ALTER TABLE blog_post ADD CONSTRAINT FK_BA5AE01D12469DE2 FOREIGN KEY (category_id) REFERENCES blog_category (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE INDEX IDX_BA5AE01D12469DE2 ON blog_post (category_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE SCHEMA public');
        $this->addSql('ALTER TABLE blog_post DROP CONSTRAINT FK_BA5AE01D12469DE2');
        $this->addSql('DROP TABLE blog_category');
        $this->addSql('DROP INDEX UNIQ_BA5AE01D989D9B62');
        $this->addSql('DROP INDEX IDX_BA5AE01D12469DE2');
        $this->addSql('ALTER TABLE blog_post DROP category_id');
        $this->addSql('ALTER TABLE blog_post DROP slug');
        $this->addSql('ALTER TABLE blog_post DROP excerpt');
        $this->addSql('ALTER TABLE blog_post DROP featured_image');
        $this->addSql('ALTER TABLE blog_post DROP status');
        $this->addSql('ALTER TABLE blog_post DROP updated_at');
        $this->addSql('ALTER TABLE blog_post DROP published_at');
        $this->addSql('ALTER TABLE blog_post DROP reading_time');
        $this->addSql('ALTER TABLE blog_post DROP is_featured');
        $this->addSql('ALTER TABLE blog_post DROP author_name');
    }
}