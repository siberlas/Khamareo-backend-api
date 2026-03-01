<?php

namespace App\Admin\Controller\Order;

use App\Order\Entity\Order;
use App\Order\Service\Pdf\OrderPdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Gestion des PDFs d'une commande depuis l'interface admin.
 *
 * Routes disponibles :
 *   GET  /admin/orders/{id}/bon-commande/download    → PDF direct (stream)
 *   GET  /admin/orders/{id}/bon-livraison/download   → PDF direct (stream)
 *   POST /admin/orders/{id}/bon-commande/regenerate  → Régénère + Cloudinary → JSON
 *   POST /admin/orders/{id}/bon-livraison/regenerate → Régénère + Cloudinary → JSON
 *   POST /admin/orders/{id}/documents/generate-all   → Les deux → JSON
 */
#[Route('/api/admin/orders/{id}', name: 'admin_order_pdf_')]
#[IsGranted('ROLE_ADMIN')]
#[AsController]
class OrderPdfController extends AbstractController
{
    public function __construct(
        private OrderPdfService $orderPdfService,
    ) {}

    // ─── TÉLÉCHARGEMENT DIRECT ───────────────────────────────────────────────

    #[Route('/bon-commande/download', name: 'purchase_order_download', methods: ['GET'])]
    public function downloadPurchaseOrder(Order $order): Response
    {
        $result = $this->orderPdfService->generatePurchaseOrderForDownload($order);

        if (!$result->success) {
            throw $this->createNotFoundException(
                sprintf('Impossible de générer le bon de commande : %s', $result->error)
            );
        }

        return $this->buildPdfResponse($result->pdfContent, $result->filename);
    }

    #[Route('/bon-livraison/download', name: 'delivery_note_download', methods: ['GET'])]
    public function downloadDeliveryNote(Order $order): Response
    {
        $result = $this->orderPdfService->generateDeliveryNoteForDownload($order);

        if (!$result->success) {
            throw $this->createNotFoundException(
                sprintf('Impossible de générer le bon de livraison : %s', $result->error)
            );
        }

        return $this->buildPdfResponse($result->pdfContent, $result->filename);
    }

    // ─── RÉGÉNÉRATION + STOCKAGE CLOUDINARY ─────────────────────────────────

    #[Route('/bon-commande/regenerate', name: 'purchase_order_regenerate', methods: ['POST'])]
    public function regeneratePurchaseOrder(Order $order): JsonResponse
    {
        $result = $this->orderPdfService->generatePurchaseOrder($order);

        if (!$result->success) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du bon de commande',
                'error'   => $result->error,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success'       => true,
            'message'       => 'Bon de commande régénéré avec succès',
            'filename'      => $result->filename,
            'cloudinaryUrl' => $result->cloudinaryUrl,
            'stored'        => $result->isStoredOnCloudinary(),
        ]);
    }

    #[Route('/bon-livraison/regenerate', name: 'delivery_note_regenerate', methods: ['POST'])]
    public function regenerateDeliveryNote(Order $order): JsonResponse
    {
        $result = $this->orderPdfService->generateDeliveryNote($order);

        if (!$result->success) {
            return $this->json([
                'success' => false,
                'message' => 'Erreur lors de la génération du bon de livraison',
                'error'   => $result->error,
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return $this->json([
            'success'       => true,
            'message'       => 'Bon de livraison régénéré avec succès',
            'filename'      => $result->filename,
            'cloudinaryUrl' => $result->cloudinaryUrl,
            'stored'        => $result->isStoredOnCloudinary(),
        ]);
    }

    #[Route('/documents/generate-all', name: 'generate_all', methods: ['POST'])]
    public function generateAll(Order $order): JsonResponse
    {
        $results       = $this->orderPdfService->generateAll($order);
        $purchaseOrder = $results['purchase_order'];
        $deliveryNote  = $results['delivery_note'];

        return $this->json([
            'success'        => $purchaseOrder->success && $deliveryNote->success,
            'purchase_order' => [
                'success'       => $purchaseOrder->success,
                'filename'      => $purchaseOrder->filename,
                'cloudinaryUrl' => $purchaseOrder->cloudinaryUrl,
                'error'         => $purchaseOrder->error,
            ],
            'delivery_note'  => [
                'success'       => $deliveryNote->success,
                'filename'      => $deliveryNote->filename,
                'cloudinaryUrl' => $deliveryNote->cloudinaryUrl,
                'error'         => $deliveryNote->error,
            ],
        ]);
    }

    // ─── HELPERS ─────────────────────────────────────────────────────────────

    private function buildPdfResponse(string $content, string $filename): Response
    {
        return new Response($content, Response::HTTP_OK, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Content-Length'      => strlen($content),
        ]);
    }
}