<?php

namespace App\Catalog\Controller;

use App\Catalog\Repository\ProductRepository;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/catalog', name: 'catalog_')]
class ProductCatalogController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private Connection $db,
    ) {}

    /**
     * Catalogue public des produits (VERSION ULTRA-OPTIMISÉE avec filtres)
     * 
     * GET /api/catalog/products?page=1&itemsPerPage=12&currency=EUR&search=moringa&category=plantes&minRating=4
     * 
     * Paramètres :
     * - page: int (défaut 1)
     * - itemsPerPage: int (défaut 12, max 100)
     * - currency: string (défaut EUR)
     * - search: string (recherche dans nom/description)
     * - category: string (slug de catégorie)
     * - minRating: float (note minimale, ex: 4.0)
     * - order[rating]: string (asc|desc pour trier par note)
     * - order[price]: string (asc|desc pour trier par prix)
     * - order[createdAt]: string (asc|desc pour trier par date)
     * 
     * Response:
     * {
     *   "success": true,
     *   "data": [...],
     *   "pagination": { ... },
     *   "filters": { ... }
     * }
     */
    #[Route('/products', name: 'products', methods: ['GET'])]
    public function products(Request $request): JsonResponse
    {
        // Pagination
        $page = max(1, (int) $request->query->get('page', 1));
        $itemsPerPage = min(100, max(1, (int) $request->query->get('itemsPerPage', 12)));
        $offset = ($page - 1) * $itemsPerPage;

        // Filtres - Support 2 formats (REST standard + frontend actuel)
        // Format 1: ?search=moringa
        // Format 2: ?name=moringa
        $search = $request->query->get('search') ?: $request->query->get('name');
        
        // Format 1: ?category=plantes
        // Format 2: ?category.slug=plantes
        $categorySlug = $request->query->get('category') ?: $request->query->all()['category']['slug'] ?? null;
        
        // Format 1: ?minRating=4
        $minRating = $request->query->get('minRating') ? (float) $request->query->get('minRating') : null;
        
        // Tri - Support 2 formats
        // Format 1: ?order[rating]=desc
        // Format 2: ?orderBy=rating&orderDir=desc
        $orderBy = $request->query->get('orderBy');
        $orderDir = strtoupper($request->query->get('orderDir', 'DESC'));
        
        // Normaliser le sens du tri
        $orderDir = in_array($orderDir, ['ASC', 'DESC']) ? $orderDir : 'DESC';
        
        // Variables de tri finales
        $orderRating = null;
        $orderPrice = null;
        $orderCreatedAt = null;
        
        if ($orderBy) {
            // Format 2 (frontend actuel)
            switch ($orderBy) {
                case 'rating':
                    $orderRating = $orderDir;
                    break;
                case 'price':
                    $orderPrice = $orderDir;
                    break;
                case 'createdAt':
                case 'created_at':
                    $orderCreatedAt = $orderDir;
                    break;
            }
        } else {
            // Format 1 (REST standard)
            $orderParams = $request->query->all()['order'] ?? [];
            $orderRating = $orderParams['rating'] ?? null;
            $orderPrice = $orderParams['price'] ?? null;
            $orderCreatedAt = $orderParams['createdAt'] ?? null;
        }

        // Construire les conditions WHERE
        $whereConditions = [
            'p.is_enabled = true',
            'p.is_deleted = false',
        ];
        $parameters = [
            'limit' => $itemsPerPage,
            'offset' => $offset,
        ];

        // Filtre recherche
        if ($search) {
            $whereConditions[] = "(p.name ILIKE :search OR p.description ILIKE :search)";
            $parameters['search'] = '%' . $search . '%';
        }

        // Filtre note minimale
        if ($minRating !== null) {
            $whereConditions[] = "p.rating >= :minRating";
            $parameters['minRating'] = $minRating;
        }

        // Filtre catégorie (par slug)
        // Si c'est un parent → inclure tous ses enfants
        // Si c'est une feuille → uniquement cette catégorie
        $categoryFilter = '';
        if ($categorySlug) {
            // Récupérer la catégorie + tous ses descendants via CTE
            $categoryFilter = <<<SQL
AND p.category_id IN (
    WITH RECURSIVE category_descendants AS (
        -- Catégorie de départ
        SELECT c.id
        FROM category c
        WHERE c.slug = :categorySlug
        
        UNION ALL
        
        -- Tous les enfants récursifs
        SELECT child.id
        FROM category child
        INNER JOIN category_descendants cd ON child.parent_id = cd.id
    )
    SELECT id FROM category_descendants
)
SQL;
            $parameters['categorySlug'] = $categorySlug;
        }

        // Construire ORDER BY
        $orderBy = [];
        if ($orderRating) {
            $orderBy[] = 'p.rating ' . (strtoupper($orderRating) === 'ASC' ? 'ASC' : 'DESC');
        }
        if ($orderPrice) {
            $orderBy[] = 'p.price ' . (strtoupper($orderPrice) === 'ASC' ? 'ASC' : 'DESC');
        }
        if ($orderCreatedAt) {
            $orderBy[] = 'p.created_at ' . (strtoupper($orderCreatedAt) === 'ASC' ? 'ASC' : 'DESC');
        }
        // Tri par défaut si aucun tri spécifié
        if (empty($orderBy)) {
            $orderBy[] = 'p.created_at DESC';
        }
        $orderByClause = implode(', ', $orderBy);

        // Requête SQL complète avec CTE
        $sql = <<<SQL
WITH RECURSIVE active_categories AS (
    -- Racines actives
    SELECT 
        c.id,
        c.is_enabled,
        true as all_parents_enabled
    FROM category c
    WHERE c.parent_id IS NULL
    
    UNION ALL
    
    -- Enfants récursifs
    SELECT 
        child.id,
        child.is_enabled,
        tree.all_parents_enabled AND tree.is_enabled as all_parents_enabled
    FROM category child
    INNER JOIN active_categories tree ON child.parent_id = tree.id
)
SELECT 
    p.id::text as id,
    p.slug,
    p.name,
    p.description,
    p.price,
    p.original_price as "originalPrice",
    p.stock,
    p.weight_grams as "weightGrams",
    p.rating,
    p.reviews_count as "reviewsCount",
    p.created_at as "createdAt",
    c.id::text as "categoryId",
    c.name as "categoryName",
    c.slug as "categorySlug",
    b.id::text as "badgeId",
    b.label as "badgeLabel",
    b.code as "badgeCode",
    m.url as "imageUrl",
    m.thumbnail_url as "thumbnailUrl"
FROM product p
INNER JOIN active_categories ac ON p.category_id = ac.id 
    AND ac.is_enabled = true 
    AND ac.all_parents_enabled = true
LEFT JOIN category c ON c.id = p.category_id
LEFT JOIN badge b ON b.id = p.badge_id
LEFT JOIN product_media pm ON pm.product_id = p.id AND pm.is_primary = true
LEFT JOIN media m ON m.id = pm.media_id
WHERE {WHERE_CONDITIONS}
$categoryFilter
ORDER BY $orderByClause
LIMIT :limit OFFSET :offset;
SQL;

        // Remplacer le placeholder WHERE_CONDITIONS
        $sql = str_replace('{WHERE_CONDITIONS}', implode(' AND ', $whereConditions), $sql);

        // Exécuter la requête
        $products = $this->db->executeQuery($sql, $parameters)->fetchAllAssociative();

        // Compter le total (même requête sans LIMIT/OFFSET)
        $countSql = <<<SQL
WITH RECURSIVE active_categories AS (
    SELECT 
        c.id,
        c.is_enabled,
        true as all_parents_enabled
    FROM category c
    WHERE c.parent_id IS NULL
    
    UNION ALL
    
    SELECT 
        child.id,
        child.is_enabled,
        tree.all_parents_enabled AND tree.is_enabled as all_parents_enabled
    FROM category child
    INNER JOIN active_categories tree ON child.parent_id = tree.id
)
SELECT COUNT(*)
FROM product p
INNER JOIN active_categories ac ON p.category_id = ac.id 
    AND ac.is_enabled = true 
    AND ac.all_parents_enabled = true
WHERE {WHERE_CONDITIONS}
$categoryFilter;
SQL;

        $countSql = str_replace('{WHERE_CONDITIONS}', implode(' AND ', $whereConditions), $countSql);
        
        // Retirer les paramètres de pagination pour le count
        $countParams = array_diff_key($parameters, ['limit' => '', 'offset' => '']);
        $totalItems = (int) $this->db->executeQuery($countSql, $countParams)->fetchOne();
        
        $totalPages = (int) ceil($totalItems / $itemsPerPage);

        $response = $this->json([
            'success' => true,
            'data' => $products,
            'pagination' => [
                'page' => $page,
                'itemsPerPage' => $itemsPerPage,
                'totalItems' => $totalItems,
                'totalPages' => $totalPages,
            ],
            'filters' => [
                'search' => $search,
                'category' => $categorySlug,
                'minRating' => $minRating,
                'order' => [
                    'rating' => $orderRating,
                    'price' => $orderPrice,
                    'createdAt' => $orderCreatedAt,
                ],
            ],
        ]);
        $response->headers->set('Cache-Control', 'public, max-age=120, s-maxage=300');

        return $response;
    }

    /**
     * Récupérer les catégories disponibles pour le filtre
     * 
     * GET /api/catalog/categories
     * 
     * Retourne les catégories actives (feuilles uniquement) avec le nombre de produits
     */
    #[Route('/categories', name: 'categories_filter', methods: ['GET'])]
    public function categories(): JsonResponse
    {
        $sql = <<<SQL
WITH RECURSIVE active_categories AS (
    SELECT 
        c.id,
        c.is_enabled,
        c.slug,
        c.name,
        true as all_parents_enabled
    FROM category c
    WHERE c.parent_id IS NULL
    
    UNION ALL
    
    SELECT 
        child.id,
        child.is_enabled,
        child.slug,
        child.name,
        tree.all_parents_enabled AND tree.is_enabled as all_parents_enabled
    FROM category child
    INNER JOIN active_categories tree ON child.parent_id = tree.id
)
SELECT 
    ac.id::text as id,
    ac.slug,
    ac.name,
    COUNT(DISTINCT p.id) as "productsCount"
FROM active_categories ac
LEFT JOIN product p ON p.category_id = ac.id 
    AND p.is_enabled = true 
    AND p.is_deleted = false
WHERE ac.is_enabled = true 
  AND ac.all_parents_enabled = true
GROUP BY ac.id, ac.slug, ac.name
HAVING COUNT(DISTINCT p.id) > 0
ORDER BY ac.name ASC;
SQL;

        $categories = $this->db->executeQuery($sql)->fetchAllAssociative();

        return $this->json([
            'success' => true,
            'categories' => $categories,
        ]);
    }

    /**
     * Détails d'un produit (VERSION OPTIMISÉE)
     * 
     * GET /api/catalog/products/{slug}
     */
    #[Route('/products/{slug}', name: 'product_details', methods: ['GET'])]
    public function productDetails(string $slug): JsonResponse
    {
        // Utiliser la méthode du repository
        $product = $this->productRepository->findOneBySlugNotDeleted($slug);

        if (!$product) {
            return $this->json([
                'success' => false,
                'error' => 'Product not found',
            ], 404);
        }

        // Vérifier isEnabled
        if (!$product->getIsEnabled()) {
            return $this->json([
                'success' => false,
                'error' => 'Product not available',
            ], 404);
        }

        // TODO: Vérifier catégorie active avec CategoryActiveChecker

        // Construire la réponse manuellement
        $category = $product->getCategory();
        $badge = $product->getBadge();

        $images = [];
        foreach ($product->getProductMedias() as $pm) {
            $media = $pm->getMedia();
            $images[] = [
                'id' => $pm->getId()->toRfc4122(),
                'url' => $media->getUrl(),
                'thumbnailUrl' => $media->getThumbnailUrl(),
                'isPrimary' => $pm->isPrimary(),
                'displayOrder' => $pm->getDisplayOrder(),
            ];
        }

        usort($images, fn($a, $b) => $a['displayOrder'] <=> $b['displayOrder']);

        $response = $this->json([
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
                'rating' => $product->getRating(),
                'reviewsCount' => $product->getReviewsCount(),
                'category' => $category ? [
                    'id' => $category->getId()->toRfc4122(),
                    'name' => $category->getName(),
                    'slug' => $category->getSlug(),
                ] : null,
                'badge' => $badge ? [
                    'id' => $badge->getId()->toRfc4122(),
                    'label' => $badge->getLabel(),
                    'code' => $badge->getCode(),
                ] : null,
                'images' => $images,
                'primaryImage' => $images[0]['url'] ?? null,
            ],
        ]);
        $response->headers->set('Cache-Control', 'public, max-age=300, s-maxage=600');

        return $response;
    }
}