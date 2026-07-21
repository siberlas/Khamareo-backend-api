<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Dédoublonnage des badges (149 lignes constatées pour 4 libellés distincts,
 * origine exacte non identifiée — probablement un artefact d'une version
 * antérieure du code de création produit) + contrainte unique sur "label"
 * pour empêcher toute récidive, quelle qu'en soit la cause.
 *
 * Pour chaque libellé en double : le badge le plus ancien (premier créé) est
 * conservé, tous les produits pointant vers un doublon sont réaffectés vers
 * lui, puis les doublons sont supprimés.
 */
final class Version20260719120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Deduplicate badges by label and add a unique constraint to prevent recurrence';
    }

    public function up(Schema $schema): void
    {
        // 1. Réaffecter les produits des doublons vers le badge le plus ancien de chaque libellé
        $this->addSql(<<<SQL
            UPDATE product p
            SET badge_id = keeper.keeper_id
            FROM (
                SELECT label, MIN(id::text)::uuid AS keeper_id
                FROM badge
                GROUP BY label
            ) keeper
            JOIN badge b ON b.label = keeper.label
            WHERE p.badge_id = b.id AND b.id != keeper.keeper_id
        SQL);

        // 2. Supprimer les doublons (garder uniquement le "keeper" par libellé)
        $this->addSql(<<<SQL
            DELETE FROM badge b
            WHERE b.id NOT IN (
                SELECT MIN(id::text)::uuid
                FROM badge
                GROUP BY label
            )
        SQL);

        // 3. Contrainte unique pour empêcher toute récidive
        $this->addSql('ALTER TABLE badge ADD CONSTRAINT uniq_badge_label UNIQUE (label)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE badge DROP CONSTRAINT uniq_badge_label');
        // Pas de rollback pour la dédup (destructif par nature).
    }
}
