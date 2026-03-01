<?php

namespace App\Catalog\Service;

use App\Catalog\Entity\Category;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Service pour vérifier si une catégorie est VRAIMENT active
 * 
 * Règle : Une catégorie est active SI :
 * - Elle-même est isEnabled=true
 * - ET tous ses parents sont isEnabled=true
 * 
 * Optimisé avec CTE PostgreSQL pour éviter les N+1
 */
final class CategoryActiveChecker
{
    public function __construct(private Connection $db) {}

    /**
     * Vérifie si une catégorie est vraiment active (elle + tous parents)
     */
    public function isReallyActive(Category $category): bool
    {
        // Si la catégorie elle-même est désactivée
        if (!$category->isEnabled()) {
            return false;
        }

        // Si pas de parent, elle est active
        if ($category->getParent() === null) {
            return true;
        }

        // Vérifier via SQL que tous les parents sont actifs
        $id = $category->getId();
        if (!$id instanceof Uuid) {
            return false;
        }

        $sql = <<<SQL
WITH RECURSIVE ancestors AS (
    -- Catégorie de départ
    SELECT c.id, c.is_enabled, c.parent_id
    FROM category c
    WHERE c.id = :categoryId

    UNION ALL

    -- Parents récursifs
    SELECT p.id, p.is_enabled, p.parent_id
    FROM category p
    INNER JOIN ancestors a ON p.id = a.parent_id
)
-- Si tous les ancêtres sont enabled, COUNT = COUNT(*)
-- Si au moins un disabled, COUNT < COUNT(*)
SELECT 
    COUNT(*) as total,
    COUNT(*) FILTER (WHERE is_enabled = true) as active_count
FROM ancestors;
SQL;

        $result = $this->db->executeQuery($sql, [
            'categoryId' => $id->toRfc4122(),
        ])->fetchAssociative();

        // Toutes les catégories de la chaîne doivent être actives
        return $result && $result['total'] === $result['active_count'];
    }

    /**
     * Retourne TOUS les IDs de catégories vraiment actives
     * (optimisé en une seule requête PostgreSQL)
     * 
     * @return string[] Array de UUID strings
     */
    public function findAllActiveIds(): array
    {
        $sql = <<<SQL
WITH RECURSIVE category_tree AS (
    -- 1. Racines actives
    SELECT 
        c.id,
        c.parent_id,
        c.is_enabled,
        ARRAY[c.id] as path,
        true as all_parents_enabled
    FROM category c
    WHERE c.parent_id IS NULL
    
    UNION ALL
    
    -- 2. Enfants récursifs
    SELECT 
        child.id,
        child.parent_id,
        child.is_enabled,
        tree.path || child.id,
        tree.all_parents_enabled AND tree.is_enabled as all_parents_enabled
    FROM category child
    INNER JOIN category_tree tree ON child.parent_id = tree.id
)
-- Sélectionner uniquement celles où ELLE + TOUS PARENTS sont actifs
SELECT id::text
FROM category_tree
WHERE is_enabled = true 
  AND all_parents_enabled = true
ORDER BY id;
SQL;

        $result = $this->db->executeQuery($sql)->fetchFirstColumn();
        
        return $result ?: [];
    }

    /**
     * Vérifie si plusieurs catégories sont actives (batch)
     * Optimisé pour éviter N requêtes
     * 
     * @param Uuid[] $categoryIds
     * @return array<string, bool> Map UUID -> isActive
     */
    public function areCategoriesActive(array $categoryIds): array
    {
        if (empty($categoryIds)) {
            return [];
        }

        $idsStrings = array_map(
            fn(Uuid $uuid) => $uuid->toRfc4122(),
            $categoryIds
        );

        $sql = <<<SQL
WITH RECURSIVE category_ancestors AS (
    -- Catégories demandées
    SELECT 
        c.id,
        c.is_enabled,
        c.parent_id,
        c.id as original_id
    FROM category c
    WHERE c.id = ANY(:ids)
    
    UNION ALL
    
    -- Parents récursifs
    SELECT 
        p.id,
        p.is_enabled,
        p.parent_id,
        ca.original_id
    FROM category p
    INNER JOIN category_ancestors ca ON p.id = ca.parent_id
)
SELECT 
    original_id::text as category_id,
    bool_and(is_enabled) as is_active
FROM category_ancestors
GROUP BY original_id;
SQL;

        $result = $this->db->executeQuery($sql, [
            'ids' => sprintf('{%s}', implode(',', $idsStrings)),
        ])->fetchAllAssociativeIndexed();

        // Transformer en map simple
        $map = [];
        foreach ($result as $categoryId => $row) {
            $map[$categoryId] = (bool) $row['is_active'];
        }

        return $map;
    }
}