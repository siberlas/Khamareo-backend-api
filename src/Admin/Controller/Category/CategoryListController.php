<?php

namespace App\Admin\Controller\Category;

use App\Catalog\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/categories', name: 'admin_categories_list_')]
class CategoryListController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CategoryRepository $categoryRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Liste plate des catégories avec pagination et filtres
     * 
     * GET /api/admin/categories?page=1&itemsPerPage=20&search=plante&includeDisabled=false
     * 
     * Query params:
     *   page: int (défaut: 1)
     *   itemsPerPage: int (défaut: 20, max: 100)
     *   search: string (recherche sur name, slug)
     *   includeDisabled: boolean (défaut: false)
     *   parentId: uuid | "root" (filtrer par parent)
     * 
     * Response:
     * {
     *   "success": true,
     *   "categories": [...],
     *   "pagination": {
     *     "currentPage": 1,
     *     "itemsPerPage": 20,
     *     "totalItems": 50,
     *     "totalPages": 3
     *   }
     * }
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            // Pagination
            $page = max(1, $request->query->getInt('page', 1));
            $itemsPerPage = min(100, max(1, $request->query->getInt('itemsPerPage', 20)));
            $offset = ($page - 1) * $itemsPerPage;

            // Filtres
            $search = $request->query->get('search');
            $includeDisabled = $request->query->getBoolean('includeDisabled', false);
            $parentId = $request->query->get('parentId');

            // Construire la requête de base
            $qb = $this->categoryRepository->createQueryBuilder('c')
                ->leftJoin('c.categoryMedias', 'cm')
                ->leftJoin('cm.media', 'm')
                ->leftJoin('c.parent', 'p')
                ->addSelect('cm', 'm', 'p');

            // Filtre enabled
            if (!$includeDisabled) {
                $qb->andWhere('c.isEnabled = true');
            }

            // Recherche
            if ($search) {
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->like('c.name', ':search'),
                        $qb->expr()->like('c.slug', ':search')
                    )
                )->setParameter('search', '%' . $search . '%');
            }

            // Filtre parent
            if ($parentId) {
                if ($parentId === 'root') {
                    $qb->andWhere('c.parent IS NULL');
                } else {
                    try {
                        $parentUuid = Uuid::fromString($parentId);
                        $qb->andWhere('c.parent = :parentId')
                        ->setParameter('parentId', $parentUuid);
                    } catch (\Exception $e) {
                        // Ignorer si UUID invalide
                    }
                }
            }

            // ✅ 1. COMPTER D'ABORD (avant ORDER BY)
            $totalItems = (int) (clone $qb)
                ->select('COUNT(c.id)')
                ->getQuery()
                ->getSingleScalarResult();

            // ✅ 2. PUIS ajouter ORDER BY pour les résultats
            $qb->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC');

            // ✅ 3. Récupérer les catégories
            $categories = $qb->setFirstResult($offset)
                ->setMaxResults($itemsPerPage)
                ->getQuery()
                ->getResult();

            // Formater les données
            $categoriesData = [];
            foreach ($categories as $category) {
                $categoriesData[] = [
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
                    'parent' => $category->getParent() ? [
                        'id' => $category->getParent()->getId()->toRfc4122(),
                        'name' => $category->getParent()->getName(),
                        'slug' => $category->getParent()->getSlug(),
                    ] : null,
                    'mainImage' => $category->getMainMedia()?->getUrl(),
                    'bannerImage' => $category->getBannerMedia()?->getUrl(),
                    'createdAt' => $category->getCreatedAt()?->format(\DateTime::ATOM),
                    'updatedAt' => $category->getUpdatedAt()?->format(\DateTime::ATOM),
                ];
            }

            return $this->json([
                'success' => true,
                'categories' => $categoriesData,
                'pagination' => [
                    'currentPage' => $page,
                    'itemsPerPage' => $itemsPerPage,
                    'totalItems' => $totalItems,
                    'totalPages' => (int) ceil($totalItems / $itemsPerPage),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get categories list failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des catégories'
            ], 500);
        }
    }
    /**
     * Récupérer les détails complets d'une catégorie
     * 
     * GET /api/admin/categories/{id}/details
     * 
     * Response:
     * {
     *   "success": true,
     *   "category": {
     *     "id": "uuid",
     *     "name": "Plantes",
     *     "slug": "plantes",
     *     "description": "...",
     *     "displayOrder": 0,
     *     "isEnabled": true,
     *     "level": 0,
     *     "path": ["Plantes"],
     *     "productsCount": 5,
     *     "totalProductsCount": 25,
     *     "hasChildren": true,
     *     "parent": {...},
     *     "children": [...],
     *     "mainImage": {...},
     *     "bannerImage": {...},
     *     "createdAt": "...",
     *     "updatedAt": "..."
     *   }
     * }
     */
    #[Route('/{id}/details', name: 'details', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function getDetails(string $id): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
            
            // Récupérer la catégorie avec toutes ses relations
            $category = $this->categoryRepository->createQueryBuilder('c')
                ->leftJoin('c.categoryMedias', 'cm')->addSelect('cm')
                ->leftJoin('cm.media', 'm')->addSelect('m')
                ->leftJoin('c.parent', 'p')->addSelect('p')
                ->leftJoin('c.children', 'ch')->addSelect('ch')
                ->where('c.id = :id')
                ->setParameter('id', $uuid)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$category) {
                return $this->json([
                    'success' => false,
                    'error' => 'Catégorie introuvable'
                ], 404);
            }

            // Construire les enfants
            $childrenData = [];
            foreach ($category->getChildren() as $child) {
                $childrenData[] = [
                    'id' => $child->getId()->toRfc4122(),
                    'name' => $child->getName(),
                    'slug' => $child->getSlug(),
                    'displayOrder' => $child->getDisplayOrder(),
                    'isEnabled' => $child->isEnabled(),
                    'productsCount' => $child->getProductsCount(),
                ];
            }

            // Construire mainImage détaillée
            $mainImageData = null;
            if ($mainMedia = $category->getMainMedia()) {
                $mainImageData = [
                    'id' => $mainMedia->getId()->toRfc4122(),
                    'url' => $mainMedia->getUrl(),
                    'thumbnailUrl' => $mainMedia->getThumbnailUrl(),
                    'altText' => $mainMedia->getAltText(),
                    'width' => $mainMedia->getWidth(),
                    'height' => $mainMedia->getHeight(),
                ];
            }

            // Construire bannerImage détaillée
            $bannerImageData = null;
            if ($bannerMedia = $category->getBannerMedia()) {
                $bannerImageData = [
                    'id' => $bannerMedia->getId()->toRfc4122(),
                    'url' => $bannerMedia->getUrl(),
                    'thumbnailUrl' => $bannerMedia->getThumbnailUrl(),
                    'altText' => $bannerMedia->getAltText(),
                    'width' => $bannerMedia->getWidth(),
                    'height' => $bannerMedia->getHeight(),
                ];
            }

            return $this->json([
                'success' => true,
                'category' => [
                    'id' => $category->getId()->toRfc4122(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                    'description' => $category->getDescription(),
                    'displayOrder' => $category->getDisplayOrder(),
                    'isEnabled' => $category->isEnabled(),
                    'level' => $category->getLevel(),
                    'path' => $category->getPath(),
                    'productsCount' => $category->getProductsCount(),
                    'totalProductsCount' => $category->getTotalProductsCount(),
                    'hasChildren' => $category->hasChildren(),
                    'parent' => $category->getParent() ? [
                        'id' => $category->getParent()->getId()->toRfc4122(),
                        'name' => $category->getParent()->getName(),
                        'slug' => $category->getParent()->getSlug(),
                    ] : null,
                    'children' => $childrenData,
                    'mainImage' => $mainImageData,
                    'bannerImage' => $bannerImageData,
                    'createdAt' => $category->getCreatedAt()?->format(\DateTime::ATOM),
                    'updatedAt' => $category->getUpdatedAt()?->format(\DateTime::ATOM),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get category details failed', [
                'category_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des détails'
            ], 500);
        }
    }
}