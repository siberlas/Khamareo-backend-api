<?php

namespace App\Catalog\Controller;

use App\Catalog\Repository\CategoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/menu/categories', name: 'categories_menu_')]
class CategoryMenuController extends AbstractController
{
    public function __construct(
        private CategoryRepository $categoryRepository,
    ) {}

    /**
     * Menu public des catégories (arbre hiérarchique optimisé)
     * 
     * OPTIMISÉ avec CTE PostgreSQL - Renvoie tout en 1 requête
     * 
     * GET /api/menu/categories/menu?onlyEnabled=true
     * 
     * - onlyEnabled: (bool) default true -> ne renvoie que les catégories activées (elle + tous parents)
     * 
     * Response:
     * {
     *   "success": true,
     *   "menu": [
     *     {
     *       "id": "uuid",
     *       "name": "Plantes",
     *       "slug": "plantes",
     *       "displayOrder": 0,
     *       "mainImage": "https://...",
     *       "children": [
     *         {
     *           "id": "uuid",
     *           "name": "Afrique",
     *           "slug": "afrique",
     *           "displayOrder": 1,
     *           "mainImage": "https://...",
     *           "children": [...]
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    #[Route('/menu', name: 'menu', methods: ['GET'])]
    public function menu(Request $request): JsonResponse
    {
        $onlyEnabled = $request->query->getBoolean('onlyEnabled', true);

        // Utiliser la version optimisée avec CTE
        $menu = $this->categoryRepository->findMenuCategoriesOptimized($onlyEnabled);

        $response = $this->json([
            'success' => true,
            'menu' => $menu,
        ]);
        $response->headers->set('Cache-Control', 'public, max-age=600, s-maxage=1800');

        return $response;
    }

    /**
     * Version alternative avec Doctrine (moins performante mais plus flexible)
     * Utile si besoin de méthodes d'entité ou de logique complexe
     */
    #[Route('/menu/doctrine', name: 'menu_doctrine', methods: ['GET'])]
    public function menuDoctrine(Request $request): JsonResponse
    {
        $onlyEnabled = $request->query->getBoolean('onlyEnabled', true);

        // Utiliser la version Doctrine avec eager loading
        $categories = $this->categoryRepository->findMenuCategories($onlyEnabled);

        $menu = [];
        foreach ($categories as $category) {
            $menu[] = $this->normalizeCategory($category, $onlyEnabled);
        }

        return $this->json([
            'success' => true,
            'menu' => $menu,
        ]);
    }

    /**
     * Normalise une catégorie pour le JSON
     */
    private function normalizeCategory($category, bool $onlyEnabled): array
    {
        $children = [];

        foreach ($category->getChildren() as $child) {
            if ($onlyEnabled && !$child->isEnabled()) {
                continue;
            }

            // Récursion pour sous-enfants (max 2 niveaux pour le menu)
            $subChildren = [];
            foreach ($child->getChildren() as $subChild) {
                if ($onlyEnabled && !$subChild->isEnabled()) {
                    continue;
                }

                $subChildren[] = [
                    'id' => $subChild->getId()?->toRfc4122(),
                    'name' => $subChild->getName(),
                    'slug' => $subChild->getSlug(),
                    'displayOrder' => $subChild->getDisplayOrder(),
                    'mainImage' => $subChild->getMainMedia()?->getUrl(),
                    'children' => [], // Max 2 niveaux
                ];
            }

            $children[] = [
                'id' => $child->getId()?->toRfc4122(),
                'name' => $child->getName(),
                'slug' => $child->getSlug(),
                'displayOrder' => $child->getDisplayOrder(),
                'mainImage' => $child->getMainMedia()?->getUrl(),
                'children' => $subChildren,
            ];
        }

        return [
            'id' => $category->getId()?->toRfc4122(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'displayOrder' => $category->getDisplayOrder(),
            'mainImage' => $category->getMainMedia()?->getUrl(),
            'children' => $children,
        ];
    }
}