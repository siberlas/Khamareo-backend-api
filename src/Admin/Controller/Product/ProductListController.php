<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Psr\Log\LoggerInterface;

#[AsController]
#[Route('/api/admin/products', name: 'admin_products_list_')]
class ProductListController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Liste tous les produits non supprimés (pour l'interface admin)
     * Inclut les produits actifs ET désactivés
     * 
     * OPTIMISÉ : Version ARRAY sans hydratation (plus rapide) + PAGINATION
     * 
     * GET /api/admin/products?page=1&itemsPerPage=20&search=moringa
     * 
     * Response:
     * {
     *   "success": true,
     *   "products": [...],
     *   "pagination": {
     *     "currentPage": 1,
     *     "itemsPerPage": 20,
     *     "totalItems": 145,
     *     "totalPages": 8
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
            $categoryId = $request->query->get('categoryId');
            $isEnabled = $request->query->get('isEnabled'); // "true", "false", ou null (tous)
            $isFeatured = $request->query->get('isFeatured'); // "true", "false", ou null (tous)

            // Construire la requête de base
            $qb = $this->productRepository->createQueryBuilder('p')
                ->leftJoin('p.category', 'c')->addSelect('c')
                ->leftJoin('p.badge', 'b')->addSelect('b')
                ->leftJoin('p.productMedias', 'pm')->addSelect('pm')
                ->leftJoin('pm.media', 'm')->addSelect('m')
                ->where('p.isDeleted = false');

            // Filtre recherche (BASIQUE : name + slug uniquement)
            if ($search) {
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->like('p.name', ':search'),
                        $qb->expr()->like('p.slug', ':search')
                    )
                )->setParameter('search', '%' . $search . '%');
            }

            // Filtre catégorie
            if ($categoryId) {
                try {
                    $categoryUuid = \Symfony\Component\Uid\Uuid::fromString($categoryId);
                    $qb->andWhere('p.category = :categoryId')
                       ->setParameter('categoryId', $categoryUuid);
                } catch (\Exception $e) {
                    // UUID invalide, ignorer le filtre
                }
            }

            // Filtre isEnabled
            if ($isEnabled !== null) {
                $isEnabledBool = filter_var($isEnabled, FILTER_VALIDATE_BOOLEAN);
                $qb->andWhere('p.isEnabled = :isEnabled')
                   ->setParameter('isEnabled', $isEnabledBool);
            }

            // Filtre isFeatured
            if ($isFeatured !== null) {
                $isFeaturedBool = filter_var($isFeatured, FILTER_VALIDATE_BOOLEAN);
                $qb->andWhere('p.isFeatured = :isFeatured')
                   ->setParameter('isFeatured', $isFeaturedBool);
            }

            // ✅ Compter AVANT d'ajouter ORDER BY
            $totalItems = (int) (clone $qb)
                ->select('COUNT(DISTINCT p.id)')
                ->getQuery()
                ->getSingleScalarResult();

            // ✅ TRI PARAMÉTRABLE
            $orderBy = $request->query->get('orderBy', 'createdAt'); // name, price, createdAt, stock
            $orderDir = strtoupper($request->query->get('orderDir', 'DESC')); // ASC, DESC
            
            // Validation
            $allowedOrderBy = ['name', 'price', 'createdAt', 'stock', 'rating'];
            $orderBy = in_array($orderBy, $allowedOrderBy) ? $orderBy : 'createdAt';
            $orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';
            
            $qb->orderBy('p.' . $orderBy, $orderDir);

            // Récupérer les produits
            $products = $qb->setFirstResult($offset)
                ->setMaxResults($itemsPerPage)
                ->getQuery()
                ->getResult();

            // Formater les données
            $productsData = [];
            foreach ($products as $product) {
                // Image principale
                $primaryImage = null;
                foreach ($product->getProductMedias() as $pm) {
                    if ($pm->isPrimary()) {
                        $primaryImage = $pm->getMedia()->getUrl();
                        break;
                    }
                }

                $productsData[] = [
                    'id' => $product->getId()->toRfc4122(),
                    'slug' => $product->getSlug(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
                    'originalPrice' => $product->getOriginalPrice(),
                    'stock' => $product->getStock(),
                    'isEnabled' => $product->getIsEnabled(),
                    'isFeatured' => $product->getIsFeatured(),
                    'rating' => $product->getRating(),
                    'reviewsCount' => $product->getReviewsCount(),
                    'primaryImage' => $primaryImage,
                    'category' => $product->getCategory() ? [
                        'id' => $product->getCategory()->getId()->toRfc4122(),
                        'name' => $product->getCategory()->getName(),
                        'slug' => $product->getCategory()->getSlug(),
                        'isEnabled' => $product->getCategory()->isEnabled(),
                    ] : null,
                    'badge' => $product->getBadge() ? [
                        'id' => $product->getBadge()->getId()->toRfc4122(),
                        'label' => $product->getBadge()->getLabel(),
                        'code' => $product->getBadge()->getCode(),
                    ] : null,
                    'createdAt' => $product->getCreatedAt()?->format(\DateTime::ATOM),
                ];
            }

            return $this->json([
                'success' => true,
                'products' => $productsData,
                'pagination' => [
                    'currentPage' => $page,
                    'itemsPerPage' => $itemsPerPage,
                    'totalItems' => $totalItems,
                    'totalPages' => (int) ceil($totalItems / $itemsPerPage),
                ],
                'filters' => [
                    'search' => $search,
                    'categoryId' => $categoryId,
                    'isEnabled' => $isEnabled,
                    'isFeatured' => $isFeatured,
                    'orderBy' => $orderBy,
                    'orderDir' => $orderDir,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get products list failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des produits'
            ], 500);
        }
    }

    /**
     * Récupérer les détails complets d'un produit
     * 
     * OPTIMISÉ : Eager loading pour éviter N+1
     * 
     * GET /api/admin/products/{id}
     */
    #[Route('/{id}', name: 'details', methods: ['GET'], requirements: ['id' => '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'])]
    public function getDetails(string $id): JsonResponse
    {
        try {
            // Convertir en UUID
            try {
                $uuid = \Symfony\Component\Uid\Uuid::fromString($id);
            } catch (\Exception $e) {
                return $this->json([
                    'success' => false,
                    'error' => 'ID invalide'
                ], 400);
            }

            // Récupérer le produit avec eager loading
            $product = $this->productRepository->createQueryBuilder('p')
                ->leftJoin('p.category', 'c')->addSelect('c')
                ->leftJoin('c.parent', 'cp')->addSelect('cp') // Parent de la catégorie aussi
                ->leftJoin('p.badge', 'b')->addSelect('b')
                ->leftJoin('p.productMedias', 'pm')->addSelect('pm')
                ->leftJoin('pm.media', 'm')->addSelect('m')
                ->where('p.id = :id')
                ->setParameter('id', $uuid)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            // Construire les données images
            $images = [];
            $primaryImageUrl = null;

            foreach ($product->getProductMedias() as $productMedia) {
                $media = $productMedia->getMedia();
                
                $imageData = [
                    'id' => $productMedia->getId()->toRfc4122(),
                    'url' => $media->getUrl(),
                    'thumbnailUrl' => $media->getThumbnailUrl(),
                    'altText' => $media->getAltText(),
                    'width' => $media->getWidth(),
                    'height' => $media->getHeight(),
                    'isPrimary' => $productMedia->isPrimary(),
                    'displayOrder' => $productMedia->getDisplayOrder(),
                ];

                $images[] = $imageData;

                if ($productMedia->isPrimary()) {
                    $primaryImageUrl = $media->getUrl();
                }
            }

            // Trier par displayOrder
            usort($images, fn($a, $b) => $a['displayOrder'] <=> $b['displayOrder']);

            // Construire la catégorie avec path complet
            $categoryData = null;
            if ($category = $product->getCategory()) {
                $categoryData = [
                    'id' => $category->getId()->toRfc4122(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                    'description' => $category->getDescription(),
                    'isEnabled' => $category->isEnabled(),
                    'path' => $category->getPath(), // ["Plantes", "Afrique", "Moringa"]
                    'parent' => $category->getParent() ? [
                        'id' => $category->getParent()->getId()->toRfc4122(),
                        'name' => $category->getParent()->getName(),
                        'isEnabled' => $category->getParent()->isEnabled(),
                    ] : null,
                ];
            }

            // Retourner les données complètes
            return $this->json([
                'success' => true,
                'product' => [
                    'id' => $product->getId()->toRfc4122(),
                    'slug' => $product->getSlug(),
                    'name' => $product->getName(),
                    'description' => $product->getDescription(),
                    'price' => $product->getPrice(),
                    'originalPrice' => $product->getOriginalPrice(),
                    'stock' => $product->getStock(),
                    'weightGrams' => $product->getWeightGrams(),
                    'benefits' => $product->getBenefits(),
                    'ingredients' => $product->getIngredients(),
                    'usage' => $product->getUsage(),
                    'preparation' => $product->getPreparation(),
                    'faq' => $product->getFaq(),
                    'isEnabled' => $product->getIsEnabled(),
                    'isFeatured' => $product->getIsFeatured(),
                    'isDeleted' => $product->getIsDeleted(),
                    'rating' => $product->getRating(),
                    'reviewsCount' => $product->getReviewsCount(),
                    'relatedProducts' => $product->getRelatedProducts(),
                    'category' => $categoryData,
                    'badge' => $product->getBadge() ? [
                        'id' => $product->getBadge()->getId()->toRfc4122(),
                        'label' => $product->getBadge()->getLabel(),
                        'code' => $product->getBadge()->getCode(),
                    ] : null,
                    'images' => $images,
                    'primaryImage' => $primaryImageUrl,
                    'imagesCount' => count($images),
                    'createdAt' => $product->getCreatedAt()?->format(\DateTime::ATOM),
                    'updatedAt' => $product->getUpdatedAt()?->format(\DateTime::ATOM),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get product details failed', [
                'product_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération : ' . $e->getMessage()
            ], 500);
        }
    }
}