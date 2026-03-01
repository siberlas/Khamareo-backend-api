<?php

namespace App\Catalog\Repository;

use App\Catalog\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Retourne l'arbre de catégories pour le menu (version CTE PostgreSQL optimisée)
     * Retourne un tableau PHP structuré hiérarchiquement.
     */
    public function findMenuCategoriesOptimized(bool $onlyEnabled = true): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $enabledFilterAnchor    = $onlyEnabled ? 'AND is_enabled = true' : '';
        $enabledFilter          = $onlyEnabled ? 'AND c.is_enabled = true' : '';

        $sql = "
            WITH RECURSIVE category_tree AS (
                SELECT id, name, slug, parent_id, display_order, is_enabled, 0 AS depth
                FROM category
                WHERE parent_id IS NULL {$enabledFilterAnchor}

                UNION ALL

                SELECT c.id, c.name, c.slug, c.parent_id, c.display_order, c.is_enabled, ct.depth + 1
                FROM category c
                INNER JOIN category_tree ct ON c.parent_id = ct.id
                WHERE 1=1 {$enabledFilter}
            )
            SELECT ct.*,
                   (SELECT m.url FROM category_media cm
                    JOIN media m ON m.id = cm.media_id
                    WHERE cm.category_id = ct.id
                    ORDER BY cm.created_at ASC LIMIT 1) AS main_image_url
            FROM category_tree ct
            ORDER BY ct.depth, ct.display_order, ct.name
        ";

        $rows = $conn->executeQuery($sql)->fetchAllAssociative();

        return $this->buildTree($rows, null);
    }

    /**
     * Version Doctrine (eager loading) — moins performante mais plus flexible
     *
     * @return Category[]
     */
    public function findMenuCategories(bool $onlyEnabled = true): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.children', 'children')
            ->leftJoin('children.children', 'grandchildren')
            ->leftJoin('c.mainMedia', 'media')
            ->addSelect('children', 'grandchildren', 'media')
            ->where('c.parent IS NULL')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC');

        if ($onlyEnabled) {
            $qb->andWhere('c.isEnabled = true');
        }

        return $qb->getQuery()->getResult();
    }

    private function buildTree(array $rows, ?string $parentId): array
    {
        $tree = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] ?? null;
            if ($rowParent === $parentId) {
                $node = [
                    'id'           => $row['id'],
                    'name'         => $row['name'],
                    'slug'         => $row['slug'],
                    'displayOrder' => $row['display_order'],
                    'mainImage'    => $row['main_image_url'] ?? null,
                    'children'     => $this->buildTree($rows, $row['id']),
                ];
                $tree[] = $node;
            }
        }
        return $tree;
    }
}
