<?php

namespace App\Admin\Controller\Product;

use App\Catalog\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/products', name: 'admin_products_')]
class ProductBulkActionsController extends AbstractController
{
    public function __construct(
        private ProductRepository $productRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger
    ) {}

    /**
     * Suppression en masse (SOFT DELETE)
     * 
     * PATCH /api/admin/products/bulk-delete
     * Body: { "productIds": ["uuid1", "uuid2"] }
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "5 produit(s) supprimé(s)",
     *   "deletedCount": 5
     * }
     */
    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['PATCH'])]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!isset($data['productIds']) || !is_array($data['productIds']) || count($data['productIds']) === 0) {
            return $this->json([
                'success' => false,
                'error' => 'Le champ "productIds" est requis et doit être un tableau non vide'
            ], 400);
        }

        // Convertir en UUID
        $uuids = [];
        foreach ($data['productIds'] as $id) {
            try {
                $uuids[] = Uuid::fromString((string) $id);
            } catch (\Throwable) {
                return $this->json([
                    'success' => false,
                    'error' => 'ID de produit invalide : ' . (string) $id
                ], 400);
            }
        }

        $products = $this->productRepository->findBy(['id' => $uuids]);
        if (!$products) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun produit trouvé avec les IDs fournis'
            ], 404);
        }

        $deletedCount = 0;

        try {
            foreach ($products as $product) {
                // Soft delete (toujours, même si le produit est dans des commandes)
                $product->setIsDeleted(true);
                $deletedCount++;
            }

            $this->em->flush();

            $this->logger->info('Bulk soft delete products', [
                'deleted' => $deletedCount,
                'product_ids' => $data['productIds'],
            ]);

            return $this->json([
                'success' => true,
                'message' => sprintf('%d produit(s) supprimé(s)', $deletedCount),
                'deletedCount' => $deletedCount,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Bulk delete failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => "Erreur lors de la suppression : " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Activation/désactivation en masse
     * 
     * PATCH /api/admin/products/bulk-toggle
     * Body: { "productIds": ["uuid1", "uuid2"], "isEnabled": true }
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "5 produit(s) activé(s)",
     *   "updatedCount": 5
     * }
     */
    #[Route('/bulk-toggle', name: 'bulk_toggle', methods: ['PATCH'])]
    public function bulkToggle(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        if (!isset($data['productIds']) || !is_array($data['productIds']) || count($data['productIds']) === 0) {
            return $this->json([
                'success' => false,
                'error' => 'Le champ "productIds" est requis'
            ], 400);
        }

        if (!isset($data['isEnabled'])) {
            return $this->json([
                'success' => false,
                'error' => 'Le champ "isEnabled" est requis (true ou false)'
            ], 400);
        }

        $isEnabled = (bool) $data['isEnabled'];

        // Convertir en UUID
        $uuids = [];
        foreach ($data['productIds'] as $id) {
            try {
                $uuids[] = Uuid::fromString((string) $id);
            } catch (\Throwable) {
                return $this->json([
                    'success' => false,
                    'error' => 'ID de produit invalide : ' . (string) $id
                ], 400);
            }
        }

        $products = $this->productRepository->findBy(['id' => $uuids]);
        if (!$products) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun produit trouvé'
            ], 404);
        }

        $updatedCount = 0;

        try {
            foreach ($products as $product) {
                // Skip les produits supprimés
                if ($product->getIsDeleted()) {
                    continue;
                }

                $product->setIsEnabled($isEnabled);
                $updatedCount++;
            }

            $this->em->flush();

            $this->logger->info('Bulk toggle products', [
                'updated' => $updatedCount,
                'is_enabled' => $isEnabled,
            ]);

            $action = $isEnabled ? 'activé(s)' : 'désactivé(s)';

            return $this->json([
                'success' => true,
                'message' => sprintf('%d produit(s) %s', $updatedCount, $action),
                'updatedCount' => $updatedCount,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('Bulk toggle failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => "Erreur lors de la modification : " . $e->getMessage(),
            ], 500);
        }
    }
}