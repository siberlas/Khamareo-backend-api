<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Entity\Product;
use App\Catalog\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/products', name: 'admin_products_trash_')]
class ProductTrashController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Liste tous les produits supprimés (corbeille)
     * 
     * GET /api/admin/products/trash
     * 
     * Response:
     * {
     *   "success": true,
     *   "products": [...],
     *   "total": 15
     * }
     */
    #[Route('/trash', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $deletedProducts = $this->productRepository->findDeleted();
        
        $products = [];
        foreach ($deletedProducts as $product) {
            $products[] = [
                'id' => $product->getId()->toRfc4122(),
                'slug' => $product->getSlug(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'stock' => $product->getStock(),
                'imageUrl' => $product->getImageUrl(),
                'categoryName' => $product->getCategory()?->getName(),
                'deletedAt' => $product->getUpdatedAt()?->format('c'), // Date de suppression approx
            ];
        }

        return $this->json([
            'success' => true,
            'products' => $products,
            'total' => count($products),
        ]);
    }

    /**
     * Restaure un produit supprimé
     * 
     * POST /api/admin/products/{slug}/restore
     * 
     * Response:
     * {
     *   "success": true,
     *   "product": {...},
     *   "message": "Produit restauré avec succès"
     * }
     */
    #[Route('/{slug}/restore', name: 'restore', methods: ['POST'])]
    public function restore(string $slug): JsonResponse
    {
        try {
            // Chercher le produit MÊME s'il est supprimé
            $product = $this->productRepository->findOneBySlugIncludingDeleted($slug);

            if (!$product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            if (!$product->getIsDeleted()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Ce produit n\'est pas supprimé'
                ], 400);
            }

            // Restaurer
            $product->setIsDeleted(false);
            $product->setIsEnabled(true); // Réactiver aussi
            
            $this->em->flush();

            $this->logger->info('Product restored', [
                'product_id' => $product->getId()->toRfc4122(),
                'product_slug' => $slug,
            ]);

            return $this->json([
                'success' => true,
                'product' => [
                    'id' => $product->getId()->toRfc4122(),
                    'slug' => $product->getSlug(),
                    'name' => $product->getName(),
                    'isEnabled' => $product->getIsEnabled(),
                    'isDeleted' => $product->getIsDeleted(),
                ],
                'message' => 'Produit restauré avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Product restore failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la restauration : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime définitivement un produit (HARD DELETE)
     * 
     * ⚠️ ATTENTION : Suppression définitive de la BDD
     * À utiliser UNIQUEMENT pour les produits jamais commandés
     * 
     * DELETE /api/admin/products/{slug}/permanent
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Produit supprimé définitivement"
     * }
     */
    #[Route('/{slug}/permanent', name: 'permanent_delete', methods: ['DELETE'])]
    public function permanentDelete(string $slug): JsonResponse
    {
        try {
            $product = $this->productRepository->findOneBySlugIncludingDeleted($slug);

            if (!$product) {
                return $this->json([
                    'success' => false,
                    'error' => 'Produit introuvable'
                ], 404);
            }

            // Vérifier si le produit est dans des commandes
            $conn = $this->em->getConnection();
            $orderItemsCount = (int) $conn->executeQuery(
                'SELECT COUNT(*) FROM order_item WHERE product_id = :productId',
                ['productId' => $product->getId()->toBinary()]
            )->fetchOne();

            if ($orderItemsCount > 0) {
                return $this->json([
                    'success' => false,
                    'error' => sprintf(
                        'Impossible de supprimer définitivement : ce produit est présent dans %d commande(s)',
                        $orderItemsCount
                    ),
                    'orderItemsCount' => $orderItemsCount,
                ], 409);
            }

            // Suppression définitive
            $productName = $product->getName();
            $this->em->remove($product);
            $this->em->flush();

            $this->logger->warning('Product permanently deleted', [
                'product_slug' => $slug,
                'product_name' => $productName,
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Produit supprimé définitivement de la base de données'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Permanent delete failed', [
                'slug' => $slug,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la suppression définitive : ' . $e->getMessage()
            ], 500);
        }
    }

   #[Route('/trash/empty', name: 'empty', methods: ['DELETE'])]
    public function emptyTrash(): JsonResponse
    {
        try {
            $totalDeleted = $this->productRepository->countDeleted();

            if ($totalDeleted === 0) {
                return $this->json([
                    'success' => true,
                    'deletedCount' => 0,
                    'keptCount' => 0,
                    'message' => 'Corbeille déjà vide.',
                ]);
            }

            $deletableProducts = $this->productRepository->findDeletedNotUsedInOrders();

            foreach ($deletableProducts as $product) {
                // hard delete (suppression définitive)
                $this->em->remove($product);
            }

            $this->em->flush();

            $deletedCount = \count($deletableProducts);
            $keptCount = max(0, $totalDeleted - $deletedCount);

            return $this->json([
                'success' => true,
                'deletedCount' => $deletedCount,
                'keptCount' => $keptCount,
                'message' => sprintf(
                    '%d produit(s) supprimé(s) définitivement. %d produit(s) conservé(s) (présents dans des commandes).',
                    $deletedCount,
                    $keptCount
                ),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Empty trash failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors du vidage de la corbeille : ' . $e->getMessage(),
            ], 500);
        }
    }
}