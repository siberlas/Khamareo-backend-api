<?php

namespace App\Admin\Controller\Order;

use App\Order\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;

#[AsController]
#[Route('/api/admin/orders', name: 'admin_orders_details_')]
class OrderDetailsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderRepository $orderRepository,
        private LoggerInterface $logger
    ) {}

    /**
     * Récupérer les détails complets d'une commande
     * 
     * GET /api/admin/orders/{id}
     * 
     * Response:
     * {
     *   "success": true,
     *   "order": {
     *     "id": "uuid",
     *     "reference": "ORD-2024-001",
     *     "orderNumber": "20240001",
     *     "status": "preparing",
     *     "statusLabel": "En préparation",
     *     "totalAmount": 125.50,
     *     "shippingCost": 5.50,
     *     "currency": "EUR",
     *     "customer": {...},
     *     "items": [...],
     *     "shippingAddress": {...},
     *     "billingAddress": {...},
     *     "payment": {...},
     *     "carrier": {...},
     *     "shippingMode": {...},
     *     "parcels": [...],
     *     "shippingLabel": {...},
     *     "trackingNumber": "ABC123",
     *     "customerNote": "...",
     *     "createdAt": "2024-01-10T12:00:00+00:00",
     *     "updatedAt": "2024-01-11T15:30:00+00:00",
     *     "paidAt": "2024-01-10T12:05:00+00:00",
     *     "shippedAt": "2024-01-10T18:00:00+00:00",
     *     "deliveredAt": null
     *   }
     * }
     */
    #[Route('/{id}', name: 'get', methods: ['GET'], requirements: ['id' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function getDetails(string $id): JsonResponse
    {
        try {
            // Récupérer la commande avec toutes ses relations
            $uuid = Uuid::fromString($id);
            $order = $this->orderRepository->createQueryBuilder('o')
                ->leftJoin('o.owner', 'u')->addSelect('u')
                ->leftJoin('o.items', 'i')->addSelect('i')
                ->leftJoin('i.product', 'p')->addSelect('p')
                ->leftJoin('o.shippingAddress', 'sa')->addSelect('sa')
                ->leftJoin('o.billingAddress', 'ba')->addSelect('ba')
                ->leftJoin('o.payment', 'pay')->addSelect('pay')
                ->leftJoin('o.carrier', 'c')->addSelect('c')
                ->leftJoin('o.shippingMode', 'sm')->addSelect('sm')
                ->leftJoin('o.parcels', 'pa')->addSelect('pa')
                ->leftJoin('o.shippingLabel', 'sl')->addSelect('sl')
                ->where('o.id = :id')
                ->setParameter('id', $uuid)
                ->getQuery()
                ->getOneOrNullResult();

            if (!$order) {
                return $this->json([
                    'success' => false,
                    'error' => 'Commande introuvable'
                ], 404);
            }

            // Construire les données client
            $customerData = null;
            if ($order->getOwner()) {
                // Client enregistré
                $user = $order->getOwner();
                $customerData = [
                    'type' => 'registered',
                    'id' => $user->getId(),
                    'email' => $user->getEmail(),
                    'firstName' => $user->getFirstName(),
                    'lastName' => $user->getLastName(),
                    'phone' => $user->getPhone(),
                ];
            } else {
                // Client invité
                $customerData = [
                    'type' => 'guest',
                    'email' => $order->getGuestEmail(),
                    'firstName' => $order->getGuestFirstName(),
                    'lastName' => $order->getGuestLastName(),
                    'phone' => $order->getGuestPhone(),
                ];
            }

            // Construire les items
            $itemsData = [];
            foreach ($order->getItems() as $item) {
                $product = $item->getProduct();
                $itemsData[] = [
                    'id' => $item->getId(),
                    'quantity' => $item->getQuantity(),
                    'price' => $item->getUnitPrice(),
                    'total' => $item->getUnitPrice() * $item->getQuantity(),
                    'product' => $product ? [
                        'id' => $product->getId(),
                        'name' => $product->getName(),
                        'slug' => $product->getSlug(),
                        'imageUrl' => $product->getImageUrl(),
                    ] : [
                        'name' => $item->getProductName(),
                        'deleted' => true,
                    ],
                ];
            }

            // Construire l'adresse de livraison
            $shippingAddressData = null;
            if ($shippingAddress = $order->getShippingAddress()) {
                $shippingAddressData = [
                    'id' => $shippingAddress->getId(),
                    'firstName' => $shippingAddress->getFirstName(),
                    'lastName' => $shippingAddress->getLastName(),
                    'street' => $shippingAddress->getStreetAddress(),
                    'city' => $shippingAddress->getCity(),
                    'postalCode' => $shippingAddress->getPostalCode(),
                    'country' => $shippingAddress->getCountry(),
                    'phone' => $shippingAddress->getPhone(),
                ];
            }

            // Construire l'adresse de facturation
            $billingAddressData = null;
            if ($billingAddress = $order->getBillingAddress()) {
                $billingAddressData = [
                    'id' => $billingAddress->getId(),
                    'firstName' => $billingAddress->getFirstName(),
                    'lastName' => $billingAddress->getLastName(),
                    'street' => $billingAddress->getStreetAddress(),
                    'city' => $billingAddress->getCity(),
                    'postalCode' => $billingAddress->getPostalCode(),
                    'country' => $billingAddress->getCountry(),
                    'phone' => $billingAddress->getPhone(),
                ];
            }

            // Construire les données paiement
            $paymentData = null;
            if ($payment = $order->getPayment()) {
                $paymentData = [
                    'id' => $payment->getId(),
                    'method' => $payment->getMethod(),
                    'status' => $payment->getStatus(),
                    'amount' => $payment->getAmount(),
                    'transactionId' => $payment->getProviderPaymentId(),
                    'paidAt' => $payment->getPaidAt()?->format(\DateTime::ATOM),
                ];
            }

            // Construire transporteur
            $carrierData = null;
            if ($carrier = $order->getCarrier()) {
                $carrierData = [
                    'id' => $carrier->getId(),
                    'name' => $carrier->getName(),
                    'code' => $carrier->getCode(),
                ];
            }

            // Construire mode de livraison
            $shippingModeData = null;
            if ($shippingMode = $order->getShippingMode()) {
                $shippingModeData = [
                    'id' => $shippingMode->getId(),
                    'name' => $shippingMode->getName(),
                    'code' => $shippingMode->getCode(),
                ];
            }

            // ✅ CONSTRUIRE LES COLIS AVEC TOUS LES DÉTAILS
            $parcelsData = [];
            foreach ($order->getParcels() as $parcel) {
                $parcelData = [
                    'id' => $parcel->getId()->toRfc4122(),
                    'parcelNumber' => $parcel->getParcelNumber(),
                    'trackingNumber' => $parcel->getTrackingNumber(),
                    'weightGrams' => $parcel->getWeightGrams(),
                    'weightKg' => $parcel->getWeightKg(),
                    'status' => $parcel->getStatus(),
                    'labelPdfPath' => $parcel->getLabelPdfPath(),
                    'deliverySlipPdfPath' => $parcel->getDeliverySlipPdfPath(),
                    'cn23PdfPath' => $parcel->getCn23PdfPath(),
                    'invoicePdfPath' => $parcel->getInvoicePdfPath(),
                    'labelGeneratedAt' => $parcel->getLabelGeneratedAt()?->format(\DateTime::ATOM),
                    'shippedAt' => $parcel->getShippedAt()?->format(\DateTime::ATOM),
                    'deliveredAt' => $parcel->getDeliveredAt()?->format(\DateTime::ATOM),
                ];
                
                $parcelsData[] = $parcelData;
            }

            // Construire l'étiquette GLOBALE (pour bon de préparation, bordereau)
            $shippingLabelData = null;
            if ($shippingLabel = $order->getShippingLabel()) {
                $shippingLabelData = [
                    'id' => $shippingLabel->getId(),
                    'provider' => $shippingLabel->getProvider(),
                    'trackingNumber' => $shippingLabel->getTrackingNumber(),
                    'labelUrl' => $shippingLabel->getLabelUrl(),
                    'cn23Url' => $shippingLabel->getCn23Url(),
                    'documentUrl' => $shippingLabel->getDocumentUrl(),
                    'preparationSheetUrl' => $shippingLabel->getPreparationSheetUrl(),
                    'deliverySlipUrl' => $shippingLabel->getDeliverySlipUrl(),
                    'generatedAt' => $shippingLabel->getGeneratedAt()?->format(\DateTime::ATOM),
                    'createdAt' => $shippingLabel->getCreatedAt()?->format(\DateTime::ATOM),
                ];
            }

            // Point relais
            $relayPointData = null;
            if ($order->isRelayPoint()) {
                $relayPointData = [
                    'id' => $order->getRelayPointId(),
                    'carrier' => $order->getRelayCarrier(),
                ];
            }

            // Code promo
            $promoData = null;
            if ($order->getPromoCode()) {
                $promoData = [
                    'code' => $order->getPromoCode(),
                    'discountAmount' => $order->getDiscountAmount(),
                ];
            }

            // Retourner les données complètes
            return $this->json([
                'success' => true,
                'order' => [
                    'id' => $order->getId()->toRfc4122(),
                    'reference' => $order->getReference(),
                    'orderNumber' => $order->getOrderNumber(),
                    'status' => $order->getStatus()->value,
                    'statusLabel' => $order->getStatus()->label(),
                    'paymentStatus' => $order->getPaymentStatus(),
                    'totalAmount' => $order->getTotalAmount(),
                    'shippingCost' => $order->getShippingCost(),
                    'currency' => $order->getCurrency(),
                    'locale' => $order->getLocale(),
                    'customer' => $customerData,
                    'items' => $itemsData,
                    'itemsCount' => count($itemsData),
                    'shippingAddress' => $shippingAddressData,
                    'billingAddress' => $billingAddressData,
                    'payment' => $paymentData,
                    'carrier' => $carrierData,
                    'shippingMode' => $shippingModeData,
                    
                    // ✅ COLIS COMPLETS
                    'parcels' => $parcelsData,
                    'parcelsCount' => count($parcelsData),
                    
                    // ✅ SHIPPING LABEL GLOBAL (documents généraux)
                    'shippingLabel' => $shippingLabelData,
                    
                    'relayPoint' => $relayPointData,
                    'promo' => $promoData,
                    'trackingNumber' => $order->getTrackingNumber(),  // Deprecated ?
                    'customerNote' => $order->getCustomerNote(),
                    'deliveryIssueType' => $order->getDeliveryIssueType()?->value,
                    'isLocked' => $order->isLocked(),
                    'parcelsConfirmed' => $order->isParcelsConfirmed(),
                    'parcelsConfirmedAt' => $order->getParcelsConfirmedAt()?->format(\DateTime::ATOM),
                    'labelsInvalidated' => $order->isLabelsInvalidated(),
                    'labelsInvalidatedMessage' => $order->getLabelsInvalidatedMessage(),
                    'labelsInvalidatedAt' => $order->getLabelsInvalidatedAt()?->format(\DateTime::ATOM),
                    'createdAt' => $order->getCreatedAt()?->format(\DateTime::ATOM),
                    'updatedAt' => $order->getUpdatedAt()?->format(\DateTime::ATOM),
                    'paidAt' => $order->getPaidAt()?->format(\DateTime::ATOM),
                    'shippedAt' => $order->getShippedAt()?->format(\DateTime::ATOM),
                    'deliveredAt' => $order->getDeliveredAt()?->format(\DateTime::ATOM),
                ]
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Get order details failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la récupération des détails'
            ], 500);
        }
    }
}
