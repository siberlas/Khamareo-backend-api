<?php

namespace App\Admin\Service;

use App\Catalog\Entity\Category;
use App\Catalog\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class CategoryPolicy
{
    public function __construct(
        private EntityManagerInterface $em,
        private Connection $db,
        private ProductRepository $productRepository,
    ) {}

    /**
     * Règle: impossible d'ajouter un enfant à une catégorie qui a des produits.
     */
    public function assertCanBeParent(Category $parent): void
    {
        $productsCount = (int) $this->productRepository->count(['category' => $parent]);
        if ($productsCount > 0) {
            throw new \DomainException(sprintf(
                'Impossible de définir cette catégorie comme parent : elle contient %d produit(s).',
                $productsCount
            ));
        }
    }

    /**
     * Règle: un enfant ne peut pas être activé si son parent est désactivé.
     */
    public function assertCanEnable(Category $category): void
    {
        $p = $category->getParent();
        if ($p !== null && !$p->isEnabled()) {
            throw new \DomainException('Activation impossible : le parent est désactivé.');
        }
    }

    /**
     * Règle: si un parent est désactivé => tous ses descendants sont désactivés.
     * Implémentation PostgreSQL via CTE récursive (rapide, 1 requête).
     */
    public function disableWithDescendants(Category $category): void
    {
        $id = $category->getId();
        if (!$id instanceof Uuid) {
            throw new \RuntimeException('Category ID manquant.');
        }

        // On met d'abord la catégorie à false en mémoire
        $category->setIsEnabled(false);
        $this->em->flush();

        // Puis bulk update descendants (PostgreSQL)
        $sql = <<<SQL
WITH RECURSIVE descendants AS (
    SELECT c.id
    FROM category c
    WHERE c.parent_id = :rootId

    UNION ALL

    SELECT c2.id
    FROM category c2
    INNER JOIN descendants d ON c2.parent_id = d.id
)
UPDATE category
SET is_enabled = FALSE
WHERE id IN (SELECT id FROM descendants);
SQL;

        $this->db->executeStatement($sql, [
            'rootId' => $id->toRfc4122(),
        ]);
    }

    /**
     * Activation simple : autorisée uniquement si parent activé.
     * (On n'active PAS automatiquement les enfants.)
     */
    public function enable(Category $category): void
    {
        $this->assertCanEnable($category);
        $category->setIsEnabled(true);
        $this->em->flush();
    }

    /**
     * Helper : quand on affecte un parent (création/update/reorder)
     * => on applique la contrainte "parent sans produits".
     */
    public function assertCanAttachToParent(?Category $parent): void
    {
        if ($parent === null) {
            return;
        }
        $this->assertCanBeParent($parent);
    }
}
