<?php

namespace App\Admin\Controller\Category;

use App\Catalog\Entity\Category;
use App\Catalog\Repository\CategoryRepository;
use App\Admin\Service\CategoryStatusManager;
use App\Admin\Service\CategoryPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/categories', name: 'admin_categories_tree_')]
class CategoryTreeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CategoryRepository $categoryRepository,
        private LoggerInterface $logger,
        private CategoryStatusManager $categoryStatusManager,
        private CategoryPolicy $categoryPolicy,
    ) {}

    /**
     * Récupérer l'arbre complet des catégories (illimité en profondeur)
     * 
     * GET /api/admin/categories/tree?includeDisabled=false
     * 
     * Query params:
     *   includeDisabled: boolean (défaut: false) - inclure les catégories désactivées
     * 
     * Response:
     * {
     *   "success": true,
     *   "tree": [
     *     {
     *       "id": "uuid",
     *       "name": "Plantes",
     *       "slug": "plantes",
     *       "description": "...",
     *       "displayOrder": 0,
     *       "isEnabled": true,
     *       "level": 0,
     *       "productsCount": 5,
     *       "totalProductsCount": 25,
     *       "hasChildren": true,
     *       "mainImage": "https://...",
     *       "bannerImage": "https://...",
     *       "children": [
     *         {
     *           "id": "uuid",
     *           "name": "Afrique",
     *           "slug": "afrique",
     *           "level": 1,
     *           "productsCount": 10,
     *           "children": [...]
     *         }
     *       ]
     *     }
     *   ]
     * }
     */
    #[Route('/tree', name: 'get_tree', methods: ['GET'])]
    public function getTree(Request $request): JsonResponse
    {
        try {
            $includeDisabled = $request->query->getBoolean('includeDisabled', false);

            // Récupérer toutes les catégories racines (sans parent)
            $qb = $this->categoryRepository->createQueryBuilder('c')
                ->leftJoin('c.categoryMedias', 'cm')
                ->leftJoin('cm.media', 'm')
                ->addSelect('cm', 'm')
                ->where('c.parent IS NULL')
                ->orderBy('c.displayOrder', 'ASC')
                ->addOrderBy('c.name', 'ASC');

            if (!$includeDisabled) {
                $qb->andWhere('c.isEnabled = true');
            }

            $rootCategories = $qb->getQuery()->getResult();

            // Construire l'arbre récursivement
            $tree = [];
            foreach ($rootCategories as $category) {
                $tree[] = $this->buildCategoryNode($category, $includeDisabled);
            }

            return $this->json([
                'success' => true,
                'tree' => $tree
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get category tree failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération de l\'arbre'
            ], 500);
        }
    }

    /**
     * Réorganiser l'arbre des catégories (drag & drop)
     * 
     * PUT /api/admin/categories/reorder
     * 
     * Body (JSON):
     * {
     *   "categories": [
     *     {
     *       "id": "uuid",
     *       "parentId": "uuid" | null,
     *       "displayOrder": 0
     *     },
     *     {
     *       "id": "uuid",
     *       "parentId": "uuid" | null,
     *       "displayOrder": 1
     *     }
     *   ]
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Arbre réorganisé avec succès",
     *   "updated": 5
     * }
     */
    #[Route('/reorder', name: 'reorder', methods: ['PUT'])]
    public function reorder(Request $request): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true);

            if (!isset($data['categories']) || !is_array($data['categories'])) {
                return $this->json([
                    'success' => false,
                    'error' => 'Format de données invalide'
                ], 400);
            }

            $updatedCount = 0;

            foreach ($data['categories'] as $categoryData) {
                if (!isset($categoryData['id'])) {
                    continue;
                }

                try {
                    $uuid = Uuid::fromString($categoryData['id']);
                    $category = $this->categoryRepository->find($uuid);

                    if (!$category) {
                        $this->logger->warning('Category not found during reorder', [
                            'id' => $categoryData['id'],
                        ]);
                        continue;
                    }

                    // Mettre à jour le parent
                    if (isset($categoryData['parentId'])) {
                        if ($categoryData['parentId'] === null) {
                            $category->setParent(null);
                        } else {
                            $parentUuid = Uuid::fromString($categoryData['parentId']);
                            $parent = $this->categoryRepository->find($parentUuid);
                            
                            if ($parent) {
                                // Vérifier qu'on ne crée pas de cycle
                                if ($this->wouldCreateCycle($category, $parent)) {
                                    $this->logger->warning('Cycle detected, skipping parent assignment', [
                                        'category_id' => $category->getId()->toRfc4122(),
                                        'parent_id' => $parent->getId()->toRfc4122(),
                                    ]);
                                    continue;
                                }

                                if ($parent) {
                                    try {
                                        $this->categoryPolicy->assertCanAttachToParent($parent);
                                    } catch (\DomainException $e) {
                                        // skip ou retourne une erreur globale selon ton choix
                                        continue;
                                    }
                                }
                                $category->setParent($parent);
                                $this->categoryStatusManager->onParentChanged($category);

                            }
                        }
                    }

                    // Mettre à jour displayOrder
                    if (isset($categoryData['displayOrder'])) {
                        $category->setDisplayOrder((int) $categoryData['displayOrder']);
                    }

                    $updatedCount++;

                } catch (\Exception $e) {
                    $this->logger->error('Error updating category during reorder', [
                        'category_data' => $categoryData,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            $this->em->flush();

            $this->logger->info('Categories reordered', [
                'updated_count' => $updatedCount,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Arbre réorganisé avec succès',
                'updated' => $updatedCount
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Categories reorder failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la réorganisation : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Construire un nœud de l'arbre récursivement
     */
    private function buildCategoryNode(Category $category, bool $includeDisabled): array
    {
        $children = [];

        // Filtrer les enfants selon includeDisabled
        $childCategories = $category->getChildren()->filter(function($child) use ($includeDisabled) {
            return $includeDisabled || $child->isEnabled();
        });

        // Trier par displayOrder puis nom
        $childrenArray = $childCategories->toArray();
        usort($childrenArray, function($a, $b) {
            if ($a->getDisplayOrder() === $b->getDisplayOrder()) {
                return strcmp($a->getName(), $b->getName());
            }
            return $a->getDisplayOrder() <=> $b->getDisplayOrder();
        });

        // Construire récursivement
        foreach ($childrenArray as $child) {
            $children[] = $this->buildCategoryNode($child, $includeDisabled);
        }

        return [
            'id' => $category->getId()->toRfc4122(),
            'name' => $category->getName(),
            'slug' => $category->getSlug(),
            'description' => $category->getDescription(),
            'displayOrder' => $category->getDisplayOrder(),
            'isEnabled' => $category->isEnabled(),
            'level' => $category->getLevel(),
            'productsCount' => $category->getProductsCount(),
            'totalProductsCount' => $category->getTotalProductsCount(),
            'hasChildren' => $category->hasChildren(),
            'mainImage' => $category->getMainMedia()?->getUrl(),
            'bannerImage' => $category->getBannerMedia()?->getUrl(),
            'children' => $children,
            'createdAt' => $category->getCreatedAt()?->format(\DateTime::ATOM),
            'updatedAt' => $category->getUpdatedAt()?->format(\DateTime::ATOM),
        ];
    }

    /**
     * Vérifier si l'assignation d'un parent créerait un cycle
     */
    private function wouldCreateCycle(Category $category, Category $newParent): bool
    {
        $current = $newParent;
        
        while ($current !== null) {
            if ($current->getId()->equals($category->getId())) {
                return true; // Cycle détecté
            }
            $current = $current->getParent();
        }
        
        return false;
    }
}