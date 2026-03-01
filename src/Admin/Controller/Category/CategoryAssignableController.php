<?php

namespace App\Admin\Controller\Category;

use App\Catalog\Repository\CategoryRepository;
use App\Catalog\Service\CategoryActiveChecker;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class CategoryAssignableController extends AbstractController
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CategoryActiveChecker $categoryActiveChecker
    ) {}

    /**
     * Retourne les catégories assignables pour les produits
     * 
     * Règles :
     * - Uniquement les feuilles (pas d'enfants)
     * - isEnabled = true
     * - Tous les parents sont isEnabled = true
     * 
     * GET /api/admin/products/categories/assignable
     * 
     * Response:
     * {
     *   "success": true,
     *   "categories": [
     *     {
     *       "id": "uuid",
     *       "slug": "moringa",
     *       "name": "Moringa",
     *       "path": ["Plantes", "Afrique", "Moringa"],
     *       "displayOrder": 1
     *     }
     *   ]
     * }
     */
    #[Route('/api/admin/products/categories/assignable', name: 'categories_assignable', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        // 1. Récupérer toutes les feuilles activées
        $leaves = $this->categoryRepository->findEnabledLeavesOrderByDisplay();

        // 2. Récupérer tous les IDs vraiment actifs (avec CTE optimisé)
        $activeIds = $this->categoryActiveChecker->findAllActiveIds();
        $activeIdsMap = array_flip($activeIds); // Pour lookup O(1)

        // 3. Filtrer uniquement celles qui sont vraiment actives
        $data = [];
        foreach ($leaves as $category) {
            $id = $category->getId()?->toRfc4122();
            
            // Vérifier si cette catégorie est dans la liste des vraiment actives
            if ($id && isset($activeIdsMap[$id])) {
                $data[] = [
                    'id' => $id,
                    'slug' => $category->getSlug(),
                    'name' => $category->getName(),
                    'path' => $category->getPath(), // ["Plantes", "Afrique", "Moringa"]
                    'displayOrder' => $category->getDisplayOrder(),
                ];
            }
        }

        return $this->json([
            'success' => true,
            'categories' => $data,
        ]);
    }
}