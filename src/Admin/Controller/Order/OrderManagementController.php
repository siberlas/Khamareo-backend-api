<?php

namespace App\Admin\Controller\Order;

use App\Order\Entity\Order;
use App\Order\Repository\OrderRepository;
use App\Shared\Enum\OrderStatus;
use App\User\Entity\Address;
use App\Payment\Provider\StripePaymentProvider;
use App\Shared\Service\MailerService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AsController]
#[Route('/api/admin/orders', name: 'admin_orders_management_')]
class OrderManagementController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private OrderRepository $orderRepository,
        private LoggerInterface $logger,
        private StripePaymentProvider $stripeService,
        private MailerService $mailerService
    ) {}

    /**
     * Modifier une commande
     * 
     * PATCH /api/admin/orders/{id}
     * 
     * Body (JSON):
     * {
     *   "status": "preparing",
     *   "trackingNumber": "ABC123456",
     *   "customerNote": "Client a demandé livraison après 18h",
     *   "guestEmail": "newemail@example.com",
     *   "guestFirstName": "John",
     *   "guestLastName": "Doe",
     *   "guestPhone": "+33612345678"
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "order": {...},
     *   "message": "Commande mise à jour avec succès"
     * }
     */
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(string $id, Request $request): JsonResponse
    {
        try {
            // Récupérer la commande
            $uuid = Uuid::fromString($id);
            $order = $this->orderRepository->find($uuid);

            if (!$order) {
                return $this->json([
                    'success' => false,
                    'error' => 'Commande introuvable'
                ], 404);
            }

            // Vérifier si la commande peut être modifiée
            if ($order->getStatus()->isFinal()) {
                return $this->json([
                    'success' => false,
                    'error' => 'Impossible de modifier une commande dans un état final'
                ], 400);
            }

            // Décoder les données
            $data = json_decode($request->getContent(), true);

            if (!$data) {
                return $this->json([
                    'success' => false,
                    'error' => 'Données JSON invalides'
                ], 400);
            }

            // Mettre à jour le statut
            $statusChanged = false;
            if (isset($data['status'])) {
                $newStatus = OrderStatus::tryFrom($data['status']);
                if (!$newStatus) {
                    return $this->json([
                        'success' => false,
                        'error' => 'Statut invalide'
                    ], 400);
                }
                
                $oldStatus = $order->getStatus();
                $order->setStatus($newStatus);
                $statusChanged = $oldStatus !== $newStatus;

                // Mettre à jour les dates selon le nouveau statut
                if ($newStatus === OrderStatus::SHIPPED && !$order->getShippedAt()) {
                    $order->setShippedAt(new \DateTimeImmutable());
                }
                if ($newStatus === OrderStatus::DELIVERED && !$order->getDeliveredAt()) {
                    $order->setDeliveredAt(new \DateTimeImmutable());
                }

                $this->logger->info('Order status changed', [
                    'order_id' => $order->getId()->toRfc4122(),
                    'old_status' => $oldStatus->value,
                    'new_status' => $newStatus->value,
                ]);
            }

            // Mettre à jour le numéro de suivi
            if (isset($data['trackingNumber'])) {
                $order->setTrackingNumber($data['trackingNumber']);
            }

            // Mettre à jour la note client
            if (isset($data['customerNote'])) {
                $order->setCustomerNote($data['customerNote']);
            }

            // Mettre à jour les infos invité
            if (isset($data['guestEmail'])) {
                $order->setGuestEmail($data['guestEmail']);
            }
            if (isset($data['guestFirstName'])) {
                $order->setGuestFirstName($data['guestFirstName']);
            }
            if (isset($data['guestLastName'])) {
                $order->setGuestLastName($data['guestLastName']);
            }
            if (isset($data['guestPhone'])) {
                $order->setGuestPhone($data['guestPhone']);
            }

            // Sauvegarder
            $this->em->flush();

            if ($statusChanged) {
                if ($newStatus === OrderStatus::PREPARING) {
                    $this->mailerService->sendPreparingNotification($order);
                }
                if ($newStatus === OrderStatus::SHIPPED) {
                    $this->mailerService->sendShippingNotification($order);
                }
                if ($newStatus === OrderStatus::DELIVERED) {
                    $this->mailerService->sendDeliveryNotification($order);
                }
            }

            return $this->json([
                'success' => true,
                'order' => [
                    'id' => $order->getId()->toRfc4122(),
                    'reference' => $order->getReference(),
                    'status' => $order->getStatus()->value,
                    'statusLabel' => $order->getStatus()->label(),
                    'trackingNumber' => $order->getTrackingNumber(),
                ],
                'message' => 'Commande mise à jour avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Order update failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Modifier les adresses de livraison et facturation
     *
     * PATCH /api/admin/orders/{id}/addresses
     *
     * Body (JSON):
     * {
     *   "shippingAddress": { ... },
     *   "billingAddress": { ... }
     * }
     */
    #[Route('/{id}/addresses', name: 'update_addresses', methods: ['PATCH'])]
    public function updateAddresses(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
            $order = $this->orderRepository->find($uuid);

            if (!$order) {
                return $this->json([
                    'success' => false,
                    'error' => 'Commande introuvable'
                ], 404);
            }

            if ($order->getShippedAt() || $order->isParcelsConfirmed() || $order->getParcels()->count() > 0) {
                return $this->json([
                    'success' => false,
                    'error' => 'Impossible de modifier les adresses après création des colis ou expédition'
                ], 400);
            }

            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json([
                    'success' => false,
                    'error' => 'Données JSON invalides'
                ], 400);
            }

            $shippingData = $data['shippingAddress'] ?? null;
            $billingData = $data['billingAddress'] ?? null;

            if (!$shippingData && !$billingData) {
                return $this->json([
                    'success' => false,
                    'error' => 'Aucune adresse fournie'
                ], 400);
            }

            if (is_array($shippingData)) {
                $shippingAddress = $this->applyAddressUpdate($order->getShippingAddress(), $shippingData, true);
                $order->setShippingAddress($shippingAddress);
            }

            if (is_array($billingData)) {
                $billingAddress = $this->applyAddressUpdate($order->getBillingAddress(), $billingData, true);
                $order->setBillingAddress($billingAddress);
            }

            $this->em->flush();

            return $this->json([
                'success' => true,
                'order' => [
                    'id' => $order->getId()->toRfc4122(),
                    'shippingAddress' => $this->buildAddressData($order->getShippingAddress()),
                    'billingAddress' => $this->buildAddressData($order->getBillingAddress()),
                ],
                'message' => 'Adresses mises à jour avec succès'
            ]);

        } catch (BadRequestHttpException $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            $this->logger->error('Order address update failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de la mise à jour des adresses : ' . $e->getMessage()
            ], 500);
        }
    }

    private function applyAddressUpdate(?Address $existing, array $data, bool $createIfOwned): Address
    {
        $address = $existing;
        if (!$address || ($createIfOwned && $address->getOwner() !== null)) {
            $address = new Address();
            $address->setOwner(null);
            $this->em->persist($address);
        }

        if (!empty($data['street']) || !empty($data['streetAddress'])) {
            $address->setStreetAddress($data['street'] ?? $data['streetAddress']);
        }
        if (!empty($data['city'])) {
            $address->setCity($data['city']);
        }
        if (!empty($data['postalCode'])) {
            $address->setPostalCode($data['postalCode']);
        }
        if (!empty($data['country'])) {
            $address->setCountry($data['country']);
        }

        if (array_key_exists('state', $data)) {
            $address->setState($data['state']);
        }
        if (array_key_exists('label', $data)) {
            $address->setLabel($data['label']);
        }
        if (array_key_exists('civility', $data)) {
            $address->setCivility($data['civility']);
        }
        if (array_key_exists('firstName', $data)) {
            $address->setFirstName($data['firstName']);
        }
        if (array_key_exists('lastName', $data)) {
            $address->setLastName($data['lastName']);
        }
        if (array_key_exists('phone', $data)) {
            $address->setPhone($data['phone']);
        }
        if (array_key_exists('addressKind', $data)) {
            $address->setAddressKind($data['addressKind']);
        }
        if (array_key_exists('isBusiness', $data)) {
            $address->setIsBusiness((bool) $data['isBusiness']);
        }
        if (array_key_exists('companyName', $data)) {
            $address->setCompanyName($data['companyName']);
        }
        if (array_key_exists('isRelayPoint', $data)) {
            $address->setIsRelayPoint((bool) $data['isRelayPoint']);
        }
        if (array_key_exists('relayPointId', $data)) {
            $address->setRelayPointId($data['relayPointId']);
        }
        if (array_key_exists('relayCarrier', $data)) {
            $address->setRelayCarrier($data['relayCarrier']);
        }

        if (!$address->getStreetAddress() || !$address->getCity() || !$address->getPostalCode() || !$address->getCountry()) {
            throw new BadRequestHttpException('Adresse incomplète (street, city, postalCode, country)');
        }

        return $address;
    }

    private function buildAddressData(?Address $address): ?array
    {
        if (!$address) {
            return null;
        }

        return [
            'id' => $address->getId(),
            'addressKind' => $address->getAddressKind(),
            'isRelayPoint' => $address->isRelayPoint(),
            'relayPointId' => $address->getRelayPointId(),
            'relayCarrier' => $address->getRelayCarrier(),
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'street' => $address->getStreetAddress(),
            'city' => $address->getCity(),
            'postalCode' => $address->getPostalCode(),
            'country' => $address->getCountry(),
            'state' => $address->getState(),
            'phone' => $address->getPhone(),
            'isBusiness' => $address->getIsBusiness(),
            'companyName' => $address->getCompanyName(),
        ];
    }

    /**
     * Annuler une commande
     * 
     * POST /api/admin/orders/{id}/cancel
     * 
     * Body (JSON - optionnel):
     * {
     *   "reason": "Stock insuffisant",
     *   "refund": true
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "order": {...},
     *   "message": "Commande annulée avec succès"
     * }
     */
    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        try {
            // Récupérer la commande
            $uuid = Uuid::fromString($id);
            $order = $this->orderRepository->find($uuid);

            if (!$order) {
                return $this->json([
                    'success' => false,
                    'error' => 'Commande introuvable'
                ], 404);
            }

            // Vérifier si la commande peut être annulée
            if (!$order->getStatus()->canBeCancelled()) {
                return $this->json([
                    'success' => false,
                    'error' => sprintf(
                        'Impossible d\'annuler une commande avec le statut "%s"',
                        $order->getStatus()->label()
                    )
                ], 400);
            }

            // Récupérer les données optionnelles
            $data = json_decode($request->getContent(), true) ?? [];
            $reason = $data['reason'] ?? 'Annulée par l\'administrateur';
            $shouldRefund = $data['refund'] ?? false;

            // Annuler la commande
            $oldStatus = $order->getStatus();
            $order->setStatus(OrderStatus::CANCELLED);

            // Ajouter la raison dans les notes
            $currentNote = $order->getCustomerNote();
            $cancellationNote = sprintf(
                "[ANNULATION] %s - Raison : %s",
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $reason
            );
            $order->setCustomerNote($currentNote ? $currentNote . "\n\n" . $cancellationNote : $cancellationNote);

            // Remettre le stock (si pas déjà fait)
            foreach ($order->getItems() as $item) {
                $product = $item->getProduct();
                if ($product) {
                    $product->setStock($product->getStock() + $item->getQuantity());
                }
            }

            $this->em->flush();

            $this->logger->info('Order cancelled', [
                'order_id' => $order->getId()->toRfc4122(),
                'old_status' => $oldStatus->value,
                'reason' => $reason,
                'refund_requested' => $shouldRefund,
            ]);

            $message = 'Commande annulée avec succès';
            if ($shouldRefund) {
                $message .= '. Un remboursement doit être effectué manuellement via Stripe.';
            }

            return $this->json([
                'success' => true,
                'order' => [
                    'id' => $order->getId()->toRfc4122(),
                    'reference' => $order->getReference(),
                    'status' => $order->getStatus()->value,
                    'statusLabel' => $order->getStatus()->label(),
                ],
                'message' => $message,
                'refundRequired' => $shouldRefund
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Order cancellation failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de l\'annulation : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Ajouter une note admin à la commande
     * 
     * POST /api/admin/orders/{id}/add-note
     * 
     * Body (JSON):
     * {
     *   "note": "Client a appelé pour confirmer l'adresse de livraison"
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "message": "Note ajoutée avec succès"
     * }
     */
    #[Route('/{id}/add-note', name: 'add_note', methods: ['POST'])]
    public function addNote(string $id, Request $request): JsonResponse
    {
        try {
            // Récupérer la commande
            $uuid = Uuid::fromString($id);
            $order = $this->orderRepository->find($uuid);

            if (!$order) {
                return $this->json([
                    'success' => false,
                    'error' => 'Commande introuvable'
                ], 404);
            }

            // Récupérer la note
            $data = json_decode($request->getContent(), true);
            $note = $data['note'] ?? null;

            if (!$note) {
                return $this->json([
                    'success' => false,
                    'error' => 'La note ne peut pas être vide'
                ], 400);
            }

            // Ajouter la note avec timestamp
            $adminNote = sprintf(
                "[ADMIN - %s] %s",
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $note
            );

            $currentNote = $order->getCustomerNote();
            $order->setCustomerNote($currentNote ? $currentNote . "\n\n" . $adminNote : $adminNote);

            $this->em->flush();

            $this->logger->info('Admin note added to order', [
                'order_id' => $order->getId()->toRfc4122(),
                'note_length' => strlen($note),
            ]);

            return $this->json([
                'success' => true,
                'message' => 'Note ajoutée avec succès'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Add note failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur lors de l\'ajout de la note : ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Gérer un remboursement
     * 
     * POST /api/admin/orders/{id}/refund
     * 
     * Body (JSON):
     * {
     *   "amount": 25.50,
     *   "reason": "Produit défectueux",
     *   "fullRefund": false
     * }
     * 
     * Response:
     * {
     *   "success": true,
     *   "order": {...},
     *   "message": "Commande remboursée avec succès"
     * }
     */
    #[Route('/{id}/refund', name: 'refund', methods: ['POST'])]
    public function refund(string $id, Request $request): JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
            $order = $this->orderRepository->find($uuid);

            if (!$order) {
                return $this->json(['success' => false, 'error' => 'Commande introuvable'], 404);
            }

            if ($order->getPaymentStatus() !== 'paid') {
                return $this->json(['success' => false, 'error' => 'La commande n\'a pas été payée'], 400);
            }

            $data = json_decode($request->getContent(), true);
            $amount = $data['amount'] ?? null;
            $adminReason = $data['reason'] ?? 'Remboursement administrateur';
            $fullRefund = $data['fullRefund'] ?? false;

            if ($fullRefund) {
                $amount = $order->getTotalAmount();
            }

            if (!$amount || $amount <= 0) {
                return $this->json(['success' => false, 'error' => 'Montant invalide'], 400);
            }

            if ($amount > $order->getTotalAmount()) {
                return $this->json(['success' => false, 'error' => 'Montant trop élevé'], 400);
            }

            // ✅ MAPPER vers raison Stripe valide
            $stripeReason = 'requested_by_customer';

            // ✅ APPELER STRIPE
            $refundResult = $this->stripeService->refundPayment(
                $order->getPayment()->getProviderPaymentId(),
                (int) ($amount * 100),
                $stripeReason,
                [
                    'order_id' => (string) $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'full_refund' => $fullRefund ? 'true' : 'false',
                    'admin_reason' => $adminReason
                ]
            );

            // ✅ VÉRIFIER LE RÉSULTAT
            if (!$refundResult['success']) {
                $this->logger->error('Stripe refund failed', [
                    'order_id' => $order->getId()->toRfc4122(),
                    'error' => $refundResult['error']
                ]);

                return $this->json([
                    'success' => false,
                    'error' => 'Échec du remboursement : ' . $refundResult['error']
                ], 500);
            }

            $this->logger->info('Stripe refund successful', [
                'order_id' => $order->getId()->toRfc4122(),
                'refund_id' => $refundResult['refund_id']
            ]);

            // ✅ MAINTENANT on peut changer le statut
            $oldStatus = $order->getStatus();
            $order->setStatus(OrderStatus::REFUNDED);
            $order->setPaymentStatus('refunded');

            // Note de remboursement
            $refundNote = sprintf(
                "[REMBOURSEMENT - %s] Montant : %.2f %s - Raison : %s - Stripe Refund ID: %s",
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $amount,
                $order->getCurrency(),
                $adminReason,
                $refundResult['refund_id']
            );

            $currentNote = $order->getCustomerNote();
            $order->setCustomerNote($currentNote ? $currentNote . "\n\n" . $refundNote : $refundNote);

            // Stock
            if ($fullRefund) {
                foreach ($order->getItems() as $item) {
                    $product = $item->getProduct();
                    if ($product) {
                        $product->setStock($product->getStock() + $item->getQuantity());
                    }
                }
            }

            $this->em->flush();

            // Notifier le client par email
            try {
                $this->mailerService->sendRefundNotification($order, $amount, $refundResult['refund_id']);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send refund email', [
                    'order_id' => $order->getId()->toRfc4122(),
                    'error'    => $e->getMessage(),
                ]);
            }

            return $this->json([
                'success' => true,
                'order' => [
                    'id' => $order->getId()->toRfc4122(),
                    'reference' => $order->getReference(),
                    'status' => $order->getStatus()->value,
                    'statusLabel' => $order->getStatus()->label(),
                    'paymentStatus' => $order->getPaymentStatus(),
                ],
                'message' => sprintf('Remboursement de %.2f %s effectué avec succès', $amount, $order->getCurrency()),
                'refundAmount' => $amount,
                'stripeRefundId' => $refundResult['refund_id']
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Order refund failed', [
                'order_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Erreur : ' . $e->getMessage()
            ], 500);
        }
    }
}