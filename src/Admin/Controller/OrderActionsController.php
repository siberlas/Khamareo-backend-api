<?php

namespace App\Admin\Controller;

use App\Order\Entity\Order;
use App\Order\Repository\OrderRepository;
use App\Shared\Enum\OrderStatus;
use App\Shipping\Service\LabelGenerator\LabelGeneratorFactory;
use App\Shared\Service\MailerService;
use App\Shipping\Entity\ShippingLabel;
use App\Shipping\Service\OrderDocumentPdfService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Route('/api/admin/orders', name: 'admin_order_actions_')]
class OrderActionsController extends AbstractController
{
    public function __construct(
        private OrderRepository $orderRepository,
        private EntityManagerInterface $em,
        private LabelGeneratorFactory $labelGeneratorFactory,
        private MailerService $mailerService,
        private LoggerInterface $logger,
        private OrderDocumentPdfService $orderDocumentPdfService
    ) {}

    /**
     * Génère le bon de préparation et le bordereau de livraison pour une commande
     * POST /api/admin/orders/{id}/generate-documents
     */
    #[Route('/{id}/generate-documents', name: 'generate_documents', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function generateDocuments(string $id): JsonResponse
    {
        $order = $this->getOrder($id);
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        $shippingLabel = $order->getShippingLabel();
        if (!$shippingLabel) {
            $shippingLabel = new ShippingLabel();
            $shippingLabel->setOrder($order);
            $this->em->persist($shippingLabel);
        }

        try {
            $prepPath = $this->orderDocumentPdfService->generatePreparationSheet($order);
            $deliveryPath = $this->orderDocumentPdfService->generateDeliverySlip($order);
            $shippingLabel->setPreparationSheetUrl($prepPath);
            $shippingLabel->setDeliverySlipUrl($deliveryPath);
            $this->em->flush();
            return $this->json([
                'success' => true,
                'preparationSheetUrl' => $prepPath,
                'deliverySlipUrl' => $deliveryPath
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur génération documents commande', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            return $this->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupère l'URL de l'étiquette de livraison
     * 
     * GET /api/admin/orders/{id}/label
     */
    #[Route('/{id}/label', name: 'download_label', methods: ['GET'],requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function downloadLabel(string $id): JsonResponse
    {
        $order = $this->getOrder($id);
        
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        $shippingLabel = $order->getShippingLabel();
        
        if (!$shippingLabel || !$shippingLabel->getLabelUrl()) {
            return $this->json([
                'success' => false,
                'error' => 'Aucune étiquette disponible pour cette commande'
            ], 404);
        }

        return $this->json([
            'success' => true,
            'labelUrl' => $shippingLabel->getLabelUrl(),
            'trackingNumber' => $shippingLabel->getTrackingNumber(),
        ]);
    }

    #[Route('/{id}/cn23', name: 'admin_order_cn23', methods: ['GET'])]
    public function downloadCn23(Order $order): Response
    {
        $shippingLabel = $order->getShippingLabel();

        if (!$shippingLabel || !$shippingLabel->getCn23Url()) {
            return $this->json([
                'error' => 'CN23 not available for this order',
            ], 404);
        }

        try {
            return $this->redirect($shippingLabel->getCn23Url());
        } catch (\Exception $e) {
            $this->logger->error('Failed to download CN23', [
                'order' => $order->getOrderNumber(),
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'error' => 'Failed to download CN23',
            ], 500);
        }
    }

    /**
     * Récupère tous les documents disponibles
     * Note : Pour l'instant, seule l'étiquette est disponible
     * 
     * GET /api/admin/orders/{id}/all-documents
     */
    #[Route('/{id}/all-documents', name: 'download_all', methods: ['GET'],requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function downloadAllDocuments(string $id): JsonResponse
    {
        $order = $this->getOrder($id);
        
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        $shippingLabel = $order->getShippingLabel();
        
        if (!$shippingLabel) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun document disponible pour cette commande'
            ], 404);
        }

        $documents = [];
        
        if ($shippingLabel->getLabelUrl()) {
            $documents['labelUrl'] = $shippingLabel->getLabelUrl();
        }
        
        if ($shippingLabel->getPreparationSheetUrl()) {
            $documents['preparationSheetUrl'] = $shippingLabel->getPreparationSheetUrl();
        }

        if ($shippingLabel->getCn23Url()) {
            $documents['cn23'] = $shippingLabel->getCn23Url();
        }
                
        if ($shippingLabel->getDeliverySlipUrl()) {
            $documents['deliverySlipUrl'] = $shippingLabel->getDeliverySlipUrl();
        }

        if (empty($documents)) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun document disponible'
            ], 404);
        }

        return $this->json([
            'success' => true,
            'documents' => $documents,
            'trackingNumber' => $shippingLabel->getTrackingNumber(),
        ]);
    }

    /**
     * Change le statut d'une commande et envoie les emails appropriés
     * 
     * PATCH /api/admin/orders/{id}/status
     */
    #[Route('/{id}/status', name: 'update_status', methods: ['PATCH'],requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        $order = $this->getOrder($id);
        
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

       $data = json_decode($request->getContent(), true);

        if (!isset($data['status']) || !is_string($data['status'])) {
            return $this->json(['error' => 'Le champ "status" est requis'], 400);
        }

        // On accepte "PAID" ou "paid"
        $rawStatus = trim($data['status']);
        $normalizedStatus = strtolower($rawStatus);

        // Validation + conversion enum (safe)
        $newStatusEnum = OrderStatus::tryFrom($normalizedStatus);
        if (!$newStatusEnum) {
            $allowed = array_map(static fn(OrderStatus $s) => $s->value, OrderStatus::cases());
            return $this->json([
                'error' => 'Statut invalide',
                'allowed' => $allowed,
                'received' => $rawStatus,
            ], 400);
        }

        $oldStatus = $order->getStatus();
        $order->setStatus($newStatusEnum);

        // Mettre à jour les dates selon le statut
        $now = new \DateTimeImmutable();

        if ($newStatusEnum === OrderStatus::SHIPPED && !$order->getShippedAt()) {
            $order->setShippedAt($now);
        }

        if ($newStatusEnum === OrderStatus::DELIVERED && !$order->getDeliveredAt()) {
            $order->setDeliveredAt($now);
        }

        $this->em->flush();

        // Emails de notification (après flush pour avoir les données à jour)
        if ($oldStatus !== $newStatusEnum) {
            if ($newStatusEnum === OrderStatus::PREPARING) {
                $this->sendPreparingNotification($order);
            }
            if ($newStatusEnum === OrderStatus::SHIPPED) {
                $this->sendShippingNotification($order);
            }
            if ($newStatusEnum === OrderStatus::DELIVERED) {
                $this->sendDeliveryNotification($order);
            }
        }

        $this->logger->info('Order status updated', [
            'order_id' => (string) $order->getId(),
            'old_status' => $oldStatus->value,
            'new_status' => $newStatusEnum->value,
        ]);

        return $this->json([
            'success' => true,
            'message' => 'Statut mis à jour avec succès',
            'status' => $newStatusEnum->value,
            'shippedAt' => $order->getShippedAt()?->format('Y-m-d H:i:s'),
            'deliveredAt' => $order->getDeliveredAt()?->format('Y-m-d H:i:s'),
        ]);

    }

    /**
     * Regénère l'étiquette de livraison
     * 
     * POST /api/admin/orders/{id}/regenerate-label
     */
    #[Route('/{id}/regenerate-label', name: 'regenerate_label', methods: ['POST'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function regenerateLabel(string $id): JsonResponse
    {
        $order = $this->getOrder($id);
        
        if (!$order) {
            return $this->json(['error' => 'Commande introuvable'], 404);
        }

        $carrier = $order->getCarrier();
        
        if (!$carrier) {
            return $this->json([
                'success' => false,
                'error' => 'Aucun transporteur défini pour cette commande'
            ], 400);
        }

        // Vérifier que le transporteur est supporté
        if (!$this->labelGeneratorFactory->isSupported($carrier)) {
            return $this->json([
                'success' => false,
                'error' => sprintf(
                    'La génération automatique n\'est pas encore disponible pour %s',
                    $carrier->getName()
                ),
                'carrier' => $carrier->getCode(),
            ], 400);
        }

        try {
            $this->logger->info('Regenerating shipping label', [
                'order_id' => $order->getId(),
                'carrier' => $carrier->getCode()
            ]);

            // Supprimer l'ancienne étiquette si elle existe
            $oldLabel = $order->getShippingLabel();
            if ($oldLabel) {
                $this->em->remove($oldLabel);
                $this->em->flush();
            }

            // Obtenir le générateur approprié
            $generator = $this->labelGeneratorFactory->getGenerator($carrier);
            
            // Générer la nouvelle étiquette
            $result = $generator->generateLabel($order);

            if (!$result->success) {
                return $this->json([
                    'success' => false,
                    'error' => $result->error,
                ], 500);
            }

            // Créer et persister la nouvelle ShippingLabel
            $shippingLabel = new ShippingLabel();
            $shippingLabel->setOrder($order);
            $shippingLabel->setTrackingNumber($result->trackingNumber);
            $shippingLabel->setLabelUrl($result->labelUrl);
            $shippingLabel->setCn23Url($result->cn23Url);
            $shippingLabel->setCarrier($carrier);
            $shippingLabel->setProvider($carrier->getName());
            $shippingLabel->setGeneratedAt(new \DateTimeImmutable());
            
            if ($result->rawData) {
                $shippingLabel->setLabelData($result->rawData);
            }

            $this->em->persist($shippingLabel);
            
            // Mettre à jour le tracking number sur la commande
            $order->setTrackingNumber($result->trackingNumber);
            
            $this->em->flush();

            $this->logger->info('Label regenerated successfully', [
                'order_id' => $order->getId(),
                'tracking_number' => $result->trackingNumber,
                'label_url' => $result->labelUrl
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Étiquette régénérée avec succès',
                'labelUrl' => $result->labelUrl,
                'cn23Url' => $result->cn23Url,
                'trackingNumber' => $result->trackingNumber,
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to regenerate label', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la génération de l\'étiquette',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Récupère une commande par son ID (UUID)
     */
    private function getOrder(string $id): ?Order
    {
        try {
            $uuid = Uuid::fromString($id);
            return $this->orderRepository->find($uuid);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Envoie un email de notification de mise en préparation
     */
    private function sendPreparingNotification(Order $order): void
    {
        try {
            $this->mailerService->sendPreparingNotification($order);
        } catch (\Exception $e) {
            $this->logger->error('Erreur envoi email préparation', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Envoie un email de notification d'expédition
     */
    private function sendShippingNotification(Order $order): void
    {
        try {
            $this->mailerService->sendShippingNotification($order);
        } catch (\Exception $e) {
            // Log l'erreur mais ne bloque pas le changement de statut
            $this->logger->error('Erreur envoi email expédition', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Envoie un email de confirmation de livraison
     */
    private function sendDeliveryNotification(Order $order): void
    {
        try {
            $this->mailerService->sendDeliveryNotification($order);
        } catch (\Exception $e) {
            $this->logger->error('Erreur envoi email livraison', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
        }
    }
}