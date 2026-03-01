<?php

namespace App\Admin\Service;

use App\Catalog\Entity\Category;
use App\Admin\Exception\CategoryStatusException;
use App\Catalog\Repository\CategoryRepository;

final class CategoryStatusManager
{
    public function __construct(
        private CategoryRepository $categoryRepository,
    ) {}

    /**
     * Règles:
     * - Un enfant ne peut pas être activé si son parent est désactivé.
     * - Désactiver un parent désactive tous ses descendants.
     */
    public function applyIsEnabled(Category $category, bool $newIsEnabled): void
    {
        if ($newIsEnabled) {
            $parent = $category->getParent();
            if ($parent !== null && $parent->isEnabled() === false) {
                throw new CategoryStatusException("Impossible d'activer une catégorie dont le parent est désactivé.");
            }
            $category->setIsEnabled(true);
            return;
        }

        // disable
        $category->setIsEnabled(false);
        $this->cascadeDisableDescendants($category);
    }

    /**
     * Règle lors d'un changement de parent :
     * - Si on rattache à un parent disabled => la catégorie doit être disabled (et ses descendants aussi).
     * - Sinon OK (pas d'auto-enable ici, on ne change pas l'intention de l'utilisateur).
     */
    public function onParentChanged(Category $category): void
    {
        $parent = $category->getParent();

        if ($parent !== null && $parent->isEnabled() === false) {
            // parent disabled => tout doit être disabled
            $category->setIsEnabled(false);
            $this->cascadeDisableDescendants($category);
        }
    }

    private function cascadeDisableDescendants(Category $category): void
    {
        // requête explicite (ne pas dépendre d'une collection Doctrine potentiellement lazy)
        $children = $this->categoryRepository->findBy(['parent' => $category]);

        foreach ($children as $child) {
            if ($child->isEnabled()) {
                $child->setIsEnabled(false);
            }
            $this->cascadeDisableDescendants($child);
        }
    }
}
