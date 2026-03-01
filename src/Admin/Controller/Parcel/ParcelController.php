<?php

namespace App\Admin\Controller\Parcel;

use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Order\Repository\OrderRepository;
use App\Shared\Enum\OrderStatus;
use App\Shipping\Entity\Parcel;
use App\Shipping\Entity\ParcelItem;
use App\Shipping\Repository\ParcelRepository;
use App\Shipping\Service\ParcelManager;
use App\Shipping\Service\LabelGenerator\LabelGeneratorFactory;
use App\Order\Service\Pdf\OrderPdfService;
use App\Shared\Service\MailerService;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin', name: 'admin_parcels_')]
class ParcelController extends AbstractController
{
    public function __construct(
        private ParcelRepository $parcelRepository,
        private OrderRepository $orderRepository,
        private ParcelManager $parcelManager,
        private LabelGeneratorFactory $labelGeneratorFactory,
        private OrderPdfService $orderPdfService,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private MailerService $mailerService
    ) {}

    #[Route('/parcels/{parcelId}/delivery-note', name: 'generate_parcel_delivery_note', methods: ['POST'])]
    public function generateParcelDeliveryNote(string $parcelId): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        if (!in_array($parcel->getStatus(), ['confirmed', 'labeled', 'shipped'], true)) {
            return $this->json([
                'success' => false,
                'error' => 'Le colis doit être confirmé pour générer le bon de livraison'
            ], 400);
        }

        try {
            $result = $this->orderPdfService->generateDeliveryNoteForParcel($parcel);
            if (!$result->success) {
                return $this->json([
                    'success' => false,
                    'error' => $result->error ?? 'Erreur génération bon de livraison'
                ], 500);
            }

            // Enregistrer l'URL sur le colis
            $parcel->setDeliverySlipPdfPath($result->cloudinaryUrl ?? null);
            $this->em->flush();

            return $this->json([
                'success' => true,
                'deliverySlipPdfPath' => $result->cloudinaryUrl,
                'filename' => $result->filename,
                'stored' => $result->isStoredOnCloudinary(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Parcel delivery note generation failed', [
                'parcel_id' => $parcelId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la génération du bon de livraison',
            ], 500);
        }
    }

    #[Route('/parcels/{parcelId}/delivery-note/download', name: 'download_parcel_delivery_note', methods: ['GET'])]
    public function downloadParcelDeliveryNote(string $parcelId): Response
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        if (!in_array($parcel->getStatus(), ['confirmed', 'labeled', 'shipped'], true)) {
            return $this->json([
                'error' => 'Le colis doit être confirmé pour générer le bon de livraison'
            ], 400);
        }

        $result = $this->orderPdfService->generateDeliveryNoteForParcelDownload($parcel);

        if (!$result->success || !$result->pdfContent) {
            return $this->json([
                'error' => $result->error ?? 'Impossible de générer le bon de livraison'
            ], 500);
        }

        return new Response($result->pdfContent, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename=\"%s\"', $result->filename ?? 'bon-de-livraison.pdf'),
            'Content-Length' => strlen($result->pdfContent),
        ]);
    }

    // ====== ✅ NOUVEAU : CONFIRMER UN COLIS INDIVIDUEL ======

    /**
     * Confirmer un colis individuel
     * 
     * POST /api/admin/parcels/{parcelId}/confirm
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Colis #1 confirmé",
     *   "parcel": {
     *     "id": "uuid",
     *     "status": "confirmed",
     *     "confirmedAt": "2026-02-02T14:30:00+00:00"
     *   }
     * }
     */
    #[Route('/parcels/{parcelId}/confirm', name: 'confirm_parcel', methods: ['POST'])]
    public function confirmParcel(string $parcelId): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        $order = $parcel->getOrder();

        // Vérifications
        if ($parcel->getStatus() !== 'pending') {
            return $this->json([
                'success' => false,
                'error' => 'Ce colis est déjà confirmé ou dans un état ultérieur'
            ], 400);
        }

        if ($parcel->getWeightGrams() === null || $parcel->getWeightGrams() <= 0) {
            return $this->json([
                'success' => false,
                'error' => 'Le colis doit contenir au moins un produit'
            ], 400);
        }

        try {
            // Confirmer le colis
            $parcel->setStatus('confirmed');

            // Si la commande n'est pas en préparation, la passer en préparation
            $movedToPreparing = false;
            if ($order->getStatus() === OrderStatus::PAID) {
                $order->setStatus(OrderStatus::PREPARING);
                $movedToPreparing = true;

                $this->logger->info('Order moved to preparing (first parcel confirmed)', [
                    'order_id' => $order->getId()->toRfc4122(),
                ]);
            }

            $this->em->flush();

            // Envoyer l'email de préparation si c'est le premier colis confirmé
            if ($movedToPreparing) {
                try {
                    $this->mailerService->sendPreparingNotification($order);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send preparing email', [
                        'order_id' => $order->getId()->toRfc4122(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logger->info('Parcel confirmed', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'parcel_number' => $parcel->getParcelNumber(),
                'order_id' => $order->getId()->toRfc4122(),
            ]);

            return $this->json([
                'success' => true,
                'message' => sprintf('Colis #%d confirmé', $parcel->getParcelNumber()),
                'parcel' => [
                    'id' => $parcel->getId()->toRfc4122(),
                    'parcelNumber' => $parcel->getParcelNumber(),
                    'status' => $parcel->getStatus(),
                ],
                'order' => [
                    'id' => $order->getId()->toRfc4122(),
                    'status' => $order->getStatus()->value,
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Confirm parcel failed', [
                'parcel_id' => $parcelId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la confirmation : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enregistrer manuellement une étiquette pour un colis
     * 
     * POST /api/admin/parcels/{parcelId}/register-label
     */
    #[Route('/parcels/{parcelId}/register-label', name: 'register_manual_label', methods: ['POST'])]
    public function registerManualLabel(string $parcelId, Request $request): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        $order = $parcel->getOrder();

        if ($parcel->getStatus() !== 'confirmed') {
            return $this->json([
                'success' => false,
                'error' => 'Le colis doit être confirmé avant d\'enregistrer une étiquette'
            ], 400);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $trackingNumber = $data['trackingNumber'] ?? null;
        $labelPdfPath = $data['labelPdfPath'] ?? null;

        if (!$trackingNumber || trim($trackingNumber) === '') {
            return $this->json([
                'success' => false,
                'error' => 'Le numéro de tracking est obligatoire'
            ], 400);
        }

        try {
            $parcel->setTrackingNumber(trim($trackingNumber));
            
            if ($labelPdfPath && trim($labelPdfPath) !== '') {
                $parcel->setLabelPdfPath(trim($labelPdfPath));
            }
            
            $parcel->setStatus('labeled');
            $parcel->setLabelGeneratedAt(new \DateTimeImmutable());

            $this->em->flush();

            $this->logger->info('Manual label registered for parcel', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'tracking_number' => $trackingNumber,
                'carrier' => $order->getCarrier()?->getCode(),
            ]);

            return $this->json([
                'success' => true,
                'message' => sprintf('Étiquette enregistrée pour le colis #%d', $parcel->getParcelNumber()),
                'parcel' => [
                    'id' => $parcel->getId()->toRfc4122(),
                    'parcelNumber' => $parcel->getParcelNumber(),
                    'status' => $parcel->getStatus(),
                    'trackingNumber' => trim($trackingNumber),
                    'labelPdfPath' => $labelPdfPath ? trim($labelPdfPath) : null,
                    'deliverySlipPdfPath' => $parcel->getDeliverySlipPdfPath(),
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Manual label registration failed', [
                'parcel_id' => $parcelId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de l\'enregistrement : ' . $e->getMessage()
            ], 500);
        }
    }

    // ====== ✅ NOUVEAU : DÉCONFIRMER UN COLIS ======

    /**
     * Déconfirmer un colis (retour en brouillon)
     * 
     * POST /api/admin/parcels/{parcelId}/unconfirm
     */
    #[Route('/parcels/{parcelId}/unconfirm', name: 'unconfirm_parcel', methods: ['POST'])]
    public function unconfirmParcel(string $parcelId): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        // Vérifications
        if ($parcel->getStatus() !== 'confirmed') {
            return $this->json([
                'success' => false,
                'error' => 'Seul un colis confirmé peut être déconfirmé'
            ], 400);
        }

        try {
            $parcel->setStatus('pending');
            $parcel->setDeliverySlipPdfPath(null);
            $this->em->flush();

            $this->logger->info('Parcel unconfirmed', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'parcel_number' => $parcel->getParcelNumber(),
            ]);

            return $this->json([
                'success' => true,
                'message' => sprintf('Colis #%d remis en brouillon', $parcel->getParcelNumber()),
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

     /**
     * Produits disponibles pour la répartition manuelle
     * GET /api/admin/orders/{orderId}/parcels/available-products
     */
    #[Route('/orders/{orderId}/parcels/available-products', name: 'order_available_products', methods: ['GET'])]
    public function availableProducts(string $orderId): JsonResponse
    {
        $order = $this->getOrder($orderId);

        // 1) Calcul des quantités déjà allouées par OrderItem
        $allocatedByOrderItemId = [];

        foreach ($order->getParcels() as $parcel) {
            foreach ($parcel->getItems() as $parcelItem) {
                $orderItem = $parcelItem->getOrderItem();
                if (!$orderItem) {
                    continue;
                }

                $oid = $orderItem->getId()->toRfc4122();
                $allocatedByOrderItemId[$oid] = ($allocatedByOrderItemId[$oid] ?? 0) + (int) $parcelItem->getQuantity();
            }
        }

        // 2) Construire la liste des produits disponibles
        $result = [];

        foreach ($order->getItems() as $orderItem) {
            $oid = $orderItem->getId()->toRfc4122();

            $totalQty = (int) $orderItem->getQuantity();
            $allocatedQty = (int) ($allocatedByOrderItemId[$oid] ?? 0);
            $remainingQty = max(0, $totalQty - $allocatedQty);

            $product = $orderItem->getProduct();

            $result[] = [
                'orderItemId' => $oid,
                'productId' => $product?->getId()?->toRfc4122(),
                'productName' => $product?->getName() ??  'Produit',
                'unitWeightGrams' => (int) ($product?->getWeightGrams() ?? 0),
                'totalQuantity' => $totalQty,
                'allocatedQuantity' => $allocatedQty,
                'remainingQuantity' => $remainingQty,
                'unitPrice' => (float) ($orderItem->getUnitPrice() ?? 0),
            ];
        }

        return $this->json([
            'orderId' => $order->getId()->toRfc4122(),
            'products' => $result,
        ]);
    }

    /**
     * Liste les colis d'une commande
     * GET /admin/orders/{orderId}/parcels
     */
    #[Route('/orders/{orderId}/parcels', name: 'list_order_parcels', methods: ['GET'])]
    public function listOrderParcels(string $orderId): JsonResponse
    {
        $order = $this->getOrder($orderId);
        $carrier = $order->getCarrier();
        $supportsAutoGeneration = $carrier && $this->labelGeneratorFactory->isSupported($carrier);


        return $this->json([
            'orderId' => $order->getId()->toRfc4122(),
            'status' => $order->getStatus()->value,
            'canEditParcels' => $order->canEditParcels(),
            'parcelsConfirmed' => $order->isParcelsConfirmed(),
            'supportsAutoGeneration' => $supportsAutoGeneration, 
            'carrier' => [
                'id' => $carrier?->getId(),
                'name' => $carrier?->getName(),
                'code' => $carrier?->getCode(),
            ],
            'parcels' => array_map(static function (Parcel $parcel) {
                return [
                    'id' => $parcel->getId()->toRfc4122(),
                    'parcelNumber' => $parcel->getParcelNumber(),
                    'weightGrams' => $parcel->getWeightGrams(),
                    'trackingNumber' => $parcel->getTrackingNumber(),
                    'labelPdfPath' => $parcel->getLabelPdfPath(),
                    'deliverySlipPdfPath' => $parcel->getDeliverySlipPdfPath(),
                    'cn23PdfPath' => $parcel->getCn23PdfPath(),
                    'items' => array_map(static function (ParcelItem $item) {
                        return [
                            'id' => $item->getId()->toRfc4122(),
                            'orderItemId' => $item->getOrderItem()->getId()->toRfc4122(),
                            'productName' => $item->getProductName(),
                            'quantity' => $item->getQuantity(),
                            'unitWeightGrams' => $item->getUnitWeightGrams(),
                            'totalWeightGrams' => $item->getTotalWeightGrams(),
                        ];
                    }, $parcel->getItems()->toArray()),
                    'status' => $parcel->getStatus(),
                    'labelGeneratedAt' => $parcel->getLabelGeneratedAt()?->format(\DateTime::ATOM),
                    'shippedAt' => $parcel->getShippedAt()?->format(\DateTime::ATOM),
                ];
            }, $order->getParcels()->toArray()),
        ]);
    }


    #[Route('/orders/{orderId}/parcels/create', name: 'create_for_order', methods: ['POST'])]
    public function createParcelsForOrder(string $orderId, Request $request): JsonResponse
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        if (!$order->canEditParcels()) {
            return $this->json(['error' => 'Commande verrouillée : modification impossible'], 409);
        }

        // if ($order->isParcelsConfirmed()) {
        //     return $this->json(['error' => 'Répartition confirmée : déconfirmez d\'abord'], 409);
        // }

        $data = json_decode($request->getContent(), true) ?? [];
        $maxWeightGrams = (int) ($data['maxWeightGrams'] ?? 30000);

        try {
            $validation = $this->parcelManager->validateOrderWeight($order);
            if (!$validation['valid']) {
                return $this->json([
                    'success' => false,
                    'error' => $validation['error'],
                ], 400);
            }

            $parcels = $this->parcelManager->autoDistribute($order, $maxWeightGrams);

            return $this->json([
                'success' => true,
                'message' => sprintf('%d colis créé(s) avec succès', count($parcels)),
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    #[Route('/orders/{orderId}/parcels/confirm', name: 'confirm_parcels', methods: ['POST'])]
    public function confirmParcels(string $orderId): JsonResponse
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        try {
            $this->parcelManager->confirmParcels($order);

            return $this->json([
                'success' => true,
                'message' => 'Répartition confirmée',
                'parcelsConfirmed' => $order->isParcelsConfirmed(),
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    #[Route('/orders/{orderId}/parcels/unconfirm', name: 'unconfirm_parcels', methods: ['POST'])]
    public function unconfirmParcels(string $orderId): JsonResponse
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        try {
            $this->parcelManager->unconfirmParcels($order);

            return $this->json([
                'success' => true,
                'message' => 'Répartition repassée en brouillon',
                'parcelsConfirmed' => $order->isParcelsConfirmed(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    // ====== MANUEL ======

    #[Route('/orders/{orderId}/parcels', name: 'create_empty_parcel', methods: ['POST'])]
    public function createEmptyParcel(string $orderId): JsonResponse
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        try {
            $parcel = $this->parcelManager->createEmptyParcel($order);

            return $this->json([
                'success' => true,
                'parcel' => [
                    'id' => $parcel->getId()->toRfc4122(),
                    'parcelNumber' => $parcel->getParcelNumber(),
                    'weightGrams' => $parcel->getWeightGrams(),
                    'status' => $parcel->getStatus(),
                ],
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    #[Route('/parcels/{parcelId}/items', name: 'add_item_to_parcel', methods: ['POST'])]
    public function addItemToParcel(string $parcelId, Request $request): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $orderItemId = $data['orderItemId'] ?? null;
        $quantity = isset($data['quantity']) ? (int) $data['quantity'] : null;

        if (!$orderItemId || $quantity === null) {
            return $this->json(['error' => 'orderItemId et quantity requis'], 400);
        }

        $orderItem = $this->em->find(OrderItem::class, Uuid::fromString($orderItemId));
        if (!$orderItem) {
            return $this->json(['error' => 'Produit (OrderItem) introuvable'], 404);
        }

        try {
            $parcelItem = $this->parcelManager->addItemToParcel($parcel, $orderItem, $quantity);

            $order = $parcel->getOrder();

            return $this->json([
                'success' => true,
                'parcelItem' => [
                    'id' => $parcelItem->getId()->toRfc4122(),
                    'productName' => $parcelItem->getProductName(),
                    'quantity' => $parcelItem->getQuantity(),
                    'unitWeightGrams' => $parcelItem->getUnitWeightGrams(),
                    'totalWeightGrams' => $parcelItem->getTotalWeightGrams(),
                ],
                'parcel' => [
                    'id' => $parcel->getId()->toRfc4122(),
                    'weightGrams' => $parcel->getWeightGrams(),
                ],
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    #[Route('/parcel-items/{parcelItemId}', name: 'update_parcel_item_qty', methods: ['PATCH'])]
    public function updateParcelItemQuantity(string $parcelItemId, Request $request): JsonResponse
    {
        $parcelItem = $this->em->find(ParcelItem::class, Uuid::fromString($parcelItemId));
        if (!$parcelItem) {
            return $this->json(['error' => 'Item introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!isset($data['quantity'])) {
            return $this->json(['error' => 'quantity requis'], 400);
        }

        $qty = (int) $data['quantity'];

        try {
            $this->parcelManager->updateParcelItemQuantity($parcelItem, $qty);
            $order = $parcelItem->getParcel()->getOrder();

            return $this->json([
                'success' => true,
                'message' => 'Quantité mise à jour',
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    #[Route('/parcel-items/{parcelItemId}', name: 'remove_item_from_parcel', methods: ['DELETE'])]
    public function removeItemFromParcel(string $parcelItemId): JsonResponse
    {
        $parcelItem = $this->em->find(ParcelItem::class, Uuid::fromString($parcelItemId));
        if (!$parcelItem) {
            return $this->json(['error' => 'Item introuvable'], 404);
        }

        try {
            $order = $parcelItem->getParcel()->getOrder();
            $this->parcelManager->removeItemFromParcel($parcelItem);

            return $this->json([
                'success' => true,
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    #[Route('/parcels/{parcelId}', name: 'delete_parcel', methods: ['DELETE'])]
    public function deleteParcel(string $parcelId): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        try {
            $order = $parcel->getOrder();
            $this->parcelManager->deleteParcel($parcel);

            return $this->json([
                'success' => true,
                'labelsInvalidated' => $order->isLabelsInvalidated(),
                'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], $e->getCode() ?: 400);
        }
    }

    // ====== LABEL ======

   #[Route('/parcels/{parcelId}/generate-label', name: 'generate_label', methods: ['POST'])]
    public function generateLabel(string $parcelId): JsonResponse
    {
            $this->logger->info('🧾 [LABEL GENERATION] Start', [
                'parcel_id' => $parcelId,
            ]);
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
                $this->logger->warning('🧾 [LABEL GENERATION] Parcel not found', [
                    'parcel_id' => $parcelId,
                ]);
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        $order = $parcel->getOrder();

        // ✅ VÉRIFICATION : Ce colis doit être confirmé
        if ($parcel->getStatus() !== 'confirmed') {
            $this->logger->warning('🧾 [LABEL GENERATION] Parcel not confirmed', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'status' => $parcel->getStatus(),
            ]);
            return $this->json([
                'success' => false,
                'error' => 'Le colis doit être confirmé avant de générer l\'étiquette'
            ], 400);
        }

        // ✅ SUPPRIMÉ : Vérification "tous les colis confirmés"

        $carrier = $order->getCarrier();
        if (!$carrier) {
            $this->logger->warning('🧾 [LABEL GENERATION] No carrier defined', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'order_id' => $order->getId()->toRfc4122(),
            ]);
            return $this->json(['error' => 'Aucun transporteur défini'], 400);
        }

        if (!$this->labelGeneratorFactory->isSupported($carrier)) {
            $this->logger->warning('🧾 [LABEL GENERATION] Carrier not supported', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'carrier' => $carrier->getCode(),
            ]);
            return $this->json([
                'error' => sprintf('Génération non disponible pour %s', $carrier->getName())
            ], 400);
        }

        try {
            $generator = $this->labelGeneratorFactory->getGenerator($carrier);
            $this->logger->info('🧾 [LABEL GENERATION] Generator resolved', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'carrier' => $carrier->getCode(),
                'generator' => $generator::class,
            ]);
            $result = $generator->generateLabelForParcel($parcel);

            if (!$result->success) {
                $this->logger->error('🧾 [LABEL GENERATION] Generator error', [
                    'parcel_id' => $parcel->getId()->toRfc4122(),
                    'carrier' => $carrier->getCode(),
                    'error' => $result->error,
                ]);
                return $this->json(['error' => $result->error], 500);
            }

            $parcel->setTrackingNumber($result->trackingNumber);
            $parcel->setLabelPdfPath($result->labelUrl);
            $parcel->setCn23PdfPath($result->cn23Url);
            $parcel->setStatus('labeled');
            $parcel->setLabelGeneratedAt(new \DateTimeImmutable());

            $deliveryNoteResult = $this->orderPdfService->generateDeliveryNoteForParcel($parcel);
            if ($deliveryNoteResult->success && $deliveryNoteResult->cloudinaryUrl) {
                $parcel->setDeliverySlipPdfPath($deliveryNoteResult->cloudinaryUrl);
            } else {
                $parcel->setDeliverySlipPdfPath(null);
                $this->logger->warning('🧾 [LABEL GENERATION] Delivery note generation failed for parcel', [
                    'parcel_id' => $parcel->getId()->toRfc4122(),
                    'order_id' => $order->getId()->toRfc4122(),
                    'error' => $deliveryNoteResult->error,
                ]);
            }

            $this->em->flush();

            $this->logger->info('🧾 [LABEL GENERATION] Label generated', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'tracking_number' => $result->trackingNumber,
                'label_url' => $result->labelUrl,
                'cn23_url' => $result->cn23Url,
                'delivery_slip_url' => $parcel->getDeliverySlipPdfPath(),
            ]);

            return $this->json([
                'success' => true,
                'message' => sprintf('Étiquette générée pour le colis #%d', $parcel->getParcelNumber()),
                'parcel' => [
                    'id' => $parcel->getId()->toRfc4122(),
                    'status' => $parcel->getStatus(),
                    'trackingNumber' => $result->trackingNumber,
                    'labelUrl' => $result->labelUrl,
                    'cn23Url' => $result->cn23Url,
                    'deliverySlipPdfPath' => $parcel->getDeliverySlipPdfPath(),
                ],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Label generation failed', [
                'parcel_id' => $parcelId,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // ====== ✅ NOUVEAU : EXPÉDIER UN COLIS INDIVIDUEL ======

    /**
     * Marquer un colis comme expédié
     * 
     * POST /api/admin/parcels/{parcelId}/ship
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Colis #1 marqué comme expédié",
     *   "parcel": {
     *     "id": "uuid",
     *     "status": "shipped",
     *     "shippedAt": "2026-02-02T14:30:00+00:00"
     *   },
     *   "order": {
     *     "status": "preparing",  // Reste preparing si d'autres colis en attente
     *     "allParcelsShipped": false
     *   }
     * }
     */
   #[Route('/parcels/{parcelId}/ship', name: 'ship_parcel', methods: ['POST'])]
    public function shipParcel(string $parcelId): JsonResponse
    {
        $parcel = $this->getParcel($parcelId);
        if (!$parcel) {
            return $this->json(['error' => 'Colis introuvable'], 404);
        }

        $order = $parcel->getOrder();

        // Vérifications
        if ($parcel->getStatus() === 'shipped') {
            return $this->json([
                'success' => false,
                'error' => 'Ce colis est déjà expédié'
            ], 400);
        }

        if ($parcel->getStatus() !== 'labeled') {
            return $this->json([
                'success' => false,
                'error' => 'L\'étiquette doit être générée avant expédition'
            ], 400);
        }

        try {
            // 1. Marquer le colis comme expédié
            $parcel->setStatus('shipped');
            $parcel->setShippedAt(new \DateTimeImmutable());

            $this->logger->info('Parcel shipped', [
                'parcel_id' => $parcel->getId()->toRfc4122(),
                'parcel_number' => $parcel->getParcelNumber(),
            ]);

            // 2. Vérifier si TOUS les colis sont expédiés
            $allShipped = true;
            foreach ($order->getParcels() as $p) {
                if ($p->getStatus() !== 'shipped') {
                    $allShipped = false;
                    break;
                }
            }

            // 3. Si tous expédiés : mettre à jour la commande
            if ($allShipped) {
                $order->setStatus(OrderStatus::SHIPPED);
                $order->setShippedAt(new \DateTimeImmutable());

                $this->logger->info('Order fully shipped', [
                    'order_id' => $order->getId()->toRfc4122(),
                ]);
            }

            $this->em->flush();

            // Envoyer l'email d'expédition si tous les colis sont expédiés
            if ($allShipped) {
                try {
                    $this->mailerService->sendShippingNotification($order);
                } catch (\Throwable $e) {
                    $this->logger->error('Failed to send shipping email', [
                        'order_id' => $order->getId()->toRfc4122(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $message = sprintf('Colis #%d marqué comme expédié', $parcel->getParcelNumber());
            if ($allShipped) {
                $message .= ' - Tous les colis sont expédiés';
            }

            return $this->json([
                'success' => true,
                'message' => $message,
                'parcel' => [
                    'id' => $parcel->getId()->toRfc4122(),
                    'status' => $parcel->getStatus(),
                    'shippedAt' => $parcel->getShippedAt()?->format(\DateTime::ATOM),
                ],
                'order' => [
                    'status' => $order->getStatus()->value,
                    'allParcelsShipped' => $allShipped,
                ],
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ====== ⚠️ ANCIEN ENDPOINT GLOBAL (DÉPRÉCIÉ) ======

    /**
     * @deprecated Utiliser shipParcel() pour expédier les colis individuellement
     */
    #[Route('/orders/{orderId}/ship', name: 'ship_order', methods: ['PATCH'])]
    public function shipOrder(string $orderId): JsonResponse
    {
        $order = $this->getOrder($orderId);
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        // Tous les colis shipped d'un coup
        $now = new \DateTimeImmutable();
        foreach ($order->getParcels() as $parcel) {
            if ($parcel->getStatus() === 'labeled') {
                $parcel->setStatus('shipped');
                $parcel->setShippedAt($now);
            }
        }

        $order->setStatus(OrderStatus::SHIPPED);
        $order->setShippedAt($now);

        $this->em->flush();

        return $this->json([
            'success' => true,
            'message' => 'Tous les colis marqués comme expédiés',
        ]);
    }

    // ====== HELPERS ======

    private function getOrder(string $id): ?Order
    {
        try {
            return $this->orderRepository->find(Uuid::fromString($id));
        } catch (\Exception) {
            return null;
        }
    }

    private function getParcel(string $id): ?Parcel
    {
        try {
            return $this->parcelRepository->find(Uuid::fromString($id));
        } catch (\Exception) {
            return null;
        }
    }
}
