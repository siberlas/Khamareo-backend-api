<?php

namespace App\Order\Service\Pdf;

use App\Order\Entity\Order;
use App\Order\Service\Pdf\Generator\DeliveryNoteGenerator;
use App\Order\Service\Pdf\Generator\PdfGenerationResult;
use App\Order\Service\Pdf\Generator\PurchaseOrderGenerator;
use App\Shipping\Entity\Parcel;
use Psr\Log\LoggerInterface;

/**
 * Point d'entrée unique pour la génération de PDFs liés à une commande.
 *
 * Exemples d'usage :
 *
 *   // À la confirmation de commande
 *   $results = $this->orderPdfService->generateAll($order);
 *
 *   // Après étiquette Colissimo
 *   if ($labelResult->success) {
 *       $pdf = $this->orderPdfService->generateDeliveryNoteForParcel($parcel);
 *       $parcel->setDeliveryNoteUrl($pdf->cloudinaryUrl);
 *   }
 *
 *   // Téléchargement admin (sans upload Cloudinary)
 *   $pdf = $this->orderPdfService->generatePurchaseOrderForDownload($order);
 *   return new Response($pdf->pdfContent, 200, ['Content-Type' => 'application/pdf']);
 */
class OrderPdfService
{
    public function __construct(
        private PurchaseOrderGenerator $purchaseOrderGenerator,
        private DeliveryNoteGenerator $deliveryNoteGenerator,
        private PdfStorageService $storage,
        private LoggerInterface $logger,
    ) {}

    // ─── AVEC STOCKAGE CLOUDINARY ────────────────────────────────────────────

    public function generatePurchaseOrder(Order $order): PdfGenerationResult
    {
        $this->logger->info('Generating purchase order', ['order_id' => $order->getId()->toRfc4122()]);

        $result = $this->purchaseOrderGenerator->generate($order);

        return $result->success
            ? $this->storage->store($result, 'khamareo/documents/purchase-order', [
                'purchase-order',
                'order-' . $order->getOrderNumber(),
            ])
            : $result;
    }

    public function generateDeliveryNote(Order $order): PdfGenerationResult
    {
        $this->logger->info('Generating delivery note', ['order_id' => $order->getId()->toRfc4122()]);

        $result = $this->deliveryNoteGenerator->generate($order);

        return $result->success
            ? $this->storage->store($result, 'khamareo/documents/delivery-note', [
                'delivery-note',
                'order-' . $order->getOrderNumber(),
            ])
            : $result;
    }

    public function generateDeliveryNoteForParcel(Parcel $parcel): PdfGenerationResult
    {
        $order = $parcel->getOrder();

        $this->logger->info('Generating delivery note for parcel', [
            'order_id'  => $order->getId()->toRfc4122(),
            'parcel_id' => $parcel->getId()->toRfc4122(),
        ]);

        $result = $this->deliveryNoteGenerator->generateForParcel($parcel);

        return $result->success
            ? $this->storage->store($result, 'khamareo/documents/delivery-note', [
                'delivery-note',
                'order-' . $order->getOrderNumber(),
                'parcel-' . $parcel->getId()->toRfc4122(),
            ])
            : $result;
    }

    /**
     * Génère le bon de livraison d'un colis pour téléchargement direct (sans stockage).
     */
    public function generateDeliveryNoteForParcelDownload(Parcel $parcel): PdfGenerationResult
    {
        return $this->deliveryNoteGenerator->generateForParcel($parcel);
    }

    /**
     * Génère les deux documents en une seule fois.
     *
     * @return array{purchase_order: PdfGenerationResult, delivery_note: PdfGenerationResult}
     */
    public function generateAll(Order $order): array
    {
        return [
            'purchase_order' => $this->generatePurchaseOrder($order),
            'delivery_note'  => $this->generateDeliveryNote($order),
        ];
    }

    // ─── SANS STOCKAGE (téléchargement direct depuis l'admin) ────────────────

    public function generatePurchaseOrderForDownload(Order $order): PdfGenerationResult
    {
        return $this->purchaseOrderGenerator->generate($order);
    }

    public function generateDeliveryNoteForDownload(Order $order): PdfGenerationResult
    {
        return $this->deliveryNoteGenerator->generate($order);
    }
}
