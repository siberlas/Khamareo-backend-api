<?php

namespace App\Catalog\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use App\Catalog\Service\CategoryActiveChecker;

/**
 * Provider pour le catalogue PUBLIC uniquement
 * 
 * RÈGLE MÉTIER : Affiche les produits SI :
 * - product.isEnabled = true
 * - product.isDeleted = false
 * - category.isEnabled = true (catégorie du produit)
 * - ET tous les parents de la catégorie sont isEnabled = true
 * 
 * Peu importe qui fait la requête (user, admin, anonyme)
 */
final class ProductProvider implements ProviderInterface
{
    public function __construct(
        private ProviderInterface $itemProvider,
        private ProductRepository $productRepository,
        private CategoryActiveChecker $categoryActiveChecker
    ) {}

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        // Collection (GET /api/products)
        if ($operation instanceof CollectionOperationInterface) {
            $filters = $context['filters'] ?? [];
            $isFeaturedFilter = $filters['isFeatured'] ?? null;

            if ($isFeaturedFilter !== null) {
                $isFeaturedBool = filter_var($isFeaturedFilter, FILTER_VALIDATE_BOOLEAN);
                if ($isFeaturedBool) {
                    return $this->productRepository->findFeaturedProductsForCatalog(4);
                }
            }

            // Utiliser la méthode optimisée qui filtre sur les catégories actives
            return $this->productRepository->findActiveProductsForCatalog();
        }

        // Item (GET /api/products/{slug})
        $product = $this->itemProvider->provide($operation, $uriVariables, $context);

        if (!$product instanceof Product) {
            return $product;
        }

        // Si produit supprimé ou désactivé → 404
        if ($product->getIsDeleted() || !$product->getIsEnabled()) {
            return null;
        }

        // Vérifier que la catégorie est vraiment active (elle + tous parents)
        $category = $product->getCategory();
        if ($category === null) {
            // Produit sans catégorie → on l'affiche quand même (à décider selon ton besoin)
            return $product;
        }

        if (!$this->categoryActiveChecker->isReallyActive($category)) {
            // Catégorie désactivée (ou un de ses parents) → 404
            return null;
        }

        // Produit actif dans catégorie active
        return $product;
    }
}