<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260406100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add product_categories join table for ManyToMany secondary categories + migrate existing data';
    }

    public function up(Schema $schema): void
    {
        // Créer la table pivot
        $this->addSql('CREATE TABLE product_categories (
            product_id UUID NOT NULL,
            category_id UUID NOT NULL,
            PRIMARY KEY(product_id, category_id)
        )');
        $this->addSql('CREATE INDEX IDX_A99419434584665A ON product_categories (product_id)');
        $this->addSql('CREATE INDEX IDX_A994194312469DE2 ON product_categories (category_id)');
        $this->addSql('ALTER TABLE product_categories ADD CONSTRAINT FK_A99419434584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE product_categories ADD CONSTRAINT FK_A994194312469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');

        // Copier les catégories principales existantes dans la table pivot
        $this->addSql('INSERT INTO product_categories (product_id, category_id) SELECT id, category_id FROM product WHERE category_id IS NOT NULL ON CONFLICT DO NOTHING');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE product_categories');
    }
}
