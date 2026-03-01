<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/products', name: 'admin_products_')]
class ProductStatsController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private RequestStack $requestStack
    ) {}

    /**
     * Statistiques globales des produits
     * 
     * GET /api/admin/products/stats
     */
    #[Route('/stats', name: 'stats', methods: ['GET'])]
    public function getStats(): JsonResponse
    {
        $stats = [
            'totalProducts' => $this->productRepository->countAll(),
            'lowStockProducts' => $this->productRepository->countLowStock(10),
            'outOfStockProducts' => $this->productRepository->countOutOfStock(),
            'averagePrice' => $this->productRepository->getAveragePrice(),
            'totalInventoryValue' => $this->productRepository->getTotalInventoryValue(),
            'topSellingProducts' => $this->productRepository->getTopSelling(5),
            'recentlyAdded' => $this->productRepository->getRecentlyAdded(5),
        ];

        return $this->json($stats);
    }

    /**
     * Produits avec stock faible
     * 
     * GET /api/admin/products/low-stock?threshold=10
     * 
     * ULTRA-OPTIMISÉ : Utilise SQL raw, pas de serialization groups
     */
    #[Route('/low-stock', name: 'low_stock', methods: ['GET'])]
    public function getLowStock(): JsonResponse
    {
        $request = $this->requestStack->getCurrentRequest();
        $threshold = $request ? $request->query->getInt('threshold', 10) : 10;
        
        // Utilise la version SQL optimisée
        $products = $this->productRepository->findLowStockOptimized($threshold);

        return $this->json([
            'threshold' => $threshold,
            'count' => count($products),
            'products' => $products,
        ]);
    }

    /**
     * Produits en rupture de stock
     * 
     * GET /api/admin/products/out-of-stock
     * 
     * ULTRA-OPTIMISÉ : Utilise SQL raw, retourne tableau simple
     */
    #[Route('/out-of-stock', name: 'out_of_stock', methods: ['GET'])]
    public function getOutOfStock(): JsonResponse
    {
        // Utilise la version SQL optimisée (raw SQL, pas Doctrine)
        $products = $this->productRepository->findOutOfStockOptimized();

        return $this->json([
            'count' => count($products),
            'products' => $products,
        ]);
    }
}