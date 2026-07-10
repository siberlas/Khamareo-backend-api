<?php

namespace App\Payment\Controller;

use App\Cart\Entity\Cart;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Payment\Entity\Payment;
use App\User\Entity\Address;
use App\User\Entity\User;
use App\Marketing\Entity\PromoCodeRedemption;
use App\Marketing\Repository\PromoCodeRepository;
use App\Marketing\Service\PromoCodeApplicationService;
use App\Shipping\Repository\CarrierModeRepository;
use App\Shared\Enum\OrderStatus;
use App\Shared\Enum\PaymentStatus;
use App\Shared\Service\ClientContextResolver;
use App\Shared\Service\MailerService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly MailerService $mailerService,
        private readonly CarrierModeRepository $carrierModeRepository,
        private readonly PromoCodeRepository $promoCodeRepository,
        private readonly PromoCodeApplicationService $promoCodeApplicationService,
        private readonly ClientContextResolver $clientContext,
        private readonly string $webhookSecret,
    ) {
        $this->logger->debug('🔧 StripeWebhookController initialisé');
    }

    #[Route('/api/payment/webhook/stripe', name: 'stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY']);

        $payload   = $request->getContent();
        $sigHeader = $request->headers->get('Stripe-Signature');

        $this->logger->info('📥 Webhook Stripe reçu', [
            'content_length' => strlen($payload),
            'has_signature' => !empty($sigHeader),
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent')
        ]);

        // Validation de la signature webhook
        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $this->webhookSecret);
            
            $this->logger->info('✅ Signature webhook validée', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'livemode' => $event->livemode
            ]);

        } catch (SignatureVerificationException $e) {
            $this->logger->error('❌ Signature webhook invalide', [
                'error' => $e->getMessage(),
                'signature_header' => substr($sigHeader ?? '', 0, 50) . '...'
            ]);

            return new JsonResponse([
                'success' => false,
                'error'   => 'invalid_signature',
                'message' => 'Webhook signature verification failed',
            ], 400);

        } catch (\UnexpectedValueException $e) {
            $this->logger->error('❌ Payload webhook invalide', [
                'error' => $e->getMessage()
            ]);

            return new JsonResponse([
                'success' => false,
                'error'   => 'invalid_payload',
                'message' => 'Invalid webhook payload',
            ], 400);

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors validation webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'error'   => 'unexpected_error',
                'message' => 'An unexpected error occurred',
            ], 500);
        }

        // Traitement selon le type d'événement
        try {
            $this->logger->info('🔄 Dispatch événement webhook', [
                'event_type' => $event->type,
                'event_id'   => $event->id,
            ]);

            return match ($event->type) {
                'payment_intent.succeeded'      => $this->handlePaymentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
                'payment_intent.canceled'       => $this->handlePaymentCanceled($event->data->object),
                'charge.refunded'               => $this->handleChargeRefunded($event->data->object),
                'refund.created'                => $this->handleRefundCreated($event->data->object),
                default => $this->handleUnknownEvent($event)
            };

        } catch (\Throwable $e) {
            $this->logger->critical('🔥 Erreur critique lors traitement webhook', [
                'event_type' => $event->type ?? 'unknown',
                'event_id' => $event->id ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new JsonResponse([
                'success' => false,
                'error'   => 'internal_error',
                'message' => 'An error occurred while processing the webhook',
            ], 500);
        }
    }

    /**
     * Gère le succès du paiement
     */
    private function handlePaymentSucceeded(PaymentIntent $pi): JsonResponse
    {
        $this->logger->info('💳 Traitement payment_intent.succeeded', [
            'payment_intent_id' => $pi->id,
            'amount' => $pi->amount / 100,
            'currency' => $pi->currency,
            'status' => $pi->status,
            'metadata' => $pi->metadata->toArray()
        ]);

        try {
            // 1️⃣ Récupérer le Payment, ou reconstruire la commande depuis les
            // metadata du PaymentIntent si /api/cart/checkout n'a jamais été
            // appelé côté front (onglet fermé, 3DS interrompu, etc.)
            $payment = $this->findPaymentByIntentId($pi->id);

            if (!$payment) {
                $this->logger->warning('⚠️ Payment introuvable, tentative de création de la commande depuis les metadata du PaymentIntent', [
                    'payment_intent_id' => $pi->id,
                    'metadata' => $pi->metadata->toArray()
                ]);

                $payment = $this->createOrderFromMetadata($pi);
            }

            if (!$payment) {
                $this->logger->error('❌ Impossible de créer/retrouver le Payment pour ce PaymentIntent', [
                    'payment_intent_id' => $pi->id,
                    'metadata' => $pi->metadata->toArray()
                ]);

                return new JsonResponse([
                    'success' => false,
                    'error'   => 'payment_not_found',
                    'message' => 'Payment record not found for this PaymentIntent and could not be reconstructed from metadata',
                ], 400);
            }

            // Vérification idempotence
            if ($payment->getStatus() === PaymentStatus::PAID) {
                $this->logger->info('ℹ️ Webhook déjà traité (idempotence)', [
                    'payment_id' => $payment->getId(),
                    'payment_intent_id' => $pi->id,
                    'paid_at' => $payment->getPaidAt()?->format('Y-m-d H:i:s')
                ]);

                return new JsonResponse([
                    'success' => true,
                    'status'  => 'already_processed',
                    'payment_id' => (string) $payment->getId(),
                ], 200);
            }

            // 2️⃣ Récupérer la commande
            $order = $payment->getOrder();

            if (!$order) {
                $this->logger->error('❌ Order manquant sur Payment', [
                    'payment_id' => $payment->getId(),
                    'payment_intent_id' => $pi->id
                ]);

                return new JsonResponse([
                    'success' => false,
                    'error'   => 'order_not_found',
                    'message' => 'Order not linked to this payment',
                ], 400);
            }

            $this->logger->info('🔗 Payment et Order récupérés', [
                'payment_id' => $payment->getId(),
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'current_order_status' => $order->getStatus()->value
            ]);

            // 3️⃣ Mettre à jour le Payment
            $amountReceived = ($pi->amount_received ?: $pi->amount) / 100;
            $paidAt = new \DateTimeImmutable();

            $payment
                ->setStatus(PaymentStatus::PAID)
                ->setAmount($amountReceived)
                ->setPaidAt($paidAt);

            $this->logger->info('✅ Payment mis à jour → PAID', [
                'payment_id' => $payment->getId(),
                'amount_received' => $amountReceived,
                'paid_at' => $paidAt->format('Y-m-d H:i:s')
            ]);

            // 4️⃣ Mettre à jour la commande
            $order
                ->setStatus(OrderStatus::PAID)
                ->setPaymentStatus('paid')
                ->setPaidAt($paidAt)
                ->setIsLocked(true);

            $this->logger->info('✅ Order mis à jour → PAID', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'total_amount' => $order->getTotalAmount(),
                'is_locked' => true
            ]);

            // 5️⃣ Décrémenter le stock des produits commandés
            foreach ($order->getItems() as $item) {
                $product = $item->getProduct();
                if ($product === null) {
                    continue;
                }
                $newStock = max(0, ($product->getStock() ?? 0) - $item->getQuantity());
                $product->setStock($newStock);
                $this->logger->info('📦 Stock décrémenté', [
                    'product_id'   => (string) $product->getId(),
                    'product_name' => $product->getName(),
                    'qty_ordered'  => $item->getQuantity(),
                    'stock_before' => $product->getStock() + $item->getQuantity(),
                    'stock_after'  => $newStock,
                ]);
            }

            // 6️⃣ Gérer le panier
            $this->handleCartCleanup($pi);

            // 7️⃣ Sauvegarder en base
            try {
                $this->em->flush();
                
                $this->logger->info('✅ Modifications sauvegardées en base', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $order->getId()
                ]);

            } catch (\Doctrine\DBAL\Exception $e) {
                $this->logger->critical('🔥 Erreur base de données lors sauvegarde', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                    'sql_state' => $e->getSQLState()
                ]);

                throw new \RuntimeException('Database error during payment confirmation', 0, $e);
            }

            // 8️⃣ Envoyer l'email de confirmation
            $this->sendOrderConfirmationEmail($order);

            $this->logger->info('🎉 Paiement traité avec succès', [
                'payment_id' => $payment->getId(),
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber(),
                'payment_intent_id' => $pi->id,
                'amount' => $amountReceived
            ]);

            return new JsonResponse([
                'success'         => true,
                'status'          => 'order_paid',
                'order_id'        => (string) $order->getId(),
                'order_number'    => $order->getOrderNumber(),
                'payment_id'      => (string) $payment->getId(),
                'paymentIntentId' => $pi->id,
            ], 200);

        } catch (\RuntimeException $e) {
            // Erreurs métier déjà loggées
            throw $e;

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors traitement paiement réussi', [
                'payment_intent_id' => $pi->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Gère l'échec du paiement
     */
    private function handlePaymentFailed(PaymentIntent $pi): JsonResponse
    {
        $errorCode    = $pi->last_payment_error?->code ?? null;
        $errorMessage = $pi->last_payment_error?->message ?? 'Unknown error';

        $this->logger->warning('⚠️ Paiement échoué', [
            'payment_intent_id'  => $pi->id,
            'amount'             => $pi->amount / 100,
            'currency'           => $pi->currency,
            'error_code'         => $errorCode,
            'last_payment_error' => $errorMessage,
            'metadata'           => $pi->metadata->toArray(),
        ]);

        try {
            // Stocker l'erreur sur le cart (cart abandonné avec tentative de paiement échouée)
            $cart = $this->em->getRepository(Cart::class)
                ->findOneBy(['paymentIntentId' => $pi->id]);

            if ($cart) {
                $cart->setPaymentLastError($errorCode ?? $errorMessage);
                $this->logger->info('✅ Erreur paiement stockée sur le panier', [
                    'cart_id'    => $cart->getId(),
                    'error_code' => $errorCode,
                ]);
            }

            // Mettre à jour le Payment/Order si la commande existe déjà
            $payment = $this->findPaymentByIntentId($pi->id);
            if ($payment) {
                $payment->setStatus(PaymentStatus::FAILED);
                if ($order = $payment->getOrder()) {
                    $order->setStatus(OrderStatus::FAILED);
                    $order->setPaymentStatus('failed');
                }
                $this->logger->info('✅ Payment et Order marqués comme FAILED', [
                    'payment_id' => $payment->getId(),
                    'order_id'   => $order?->getId(),
                ]);
            }

            $this->em->flush();

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors traitement échec paiement', [
                'payment_intent_id' => $pi->id,
                'error'             => $e->getMessage(),
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'status'  => 'payment_failed_processed',
        ], 200);
    }

    /**
     * Gère l'annulation du paiement
     */
    private function handlePaymentCanceled(PaymentIntent $pi): JsonResponse
    {
        $this->logger->info('🚫 Paiement annulé', [
            'payment_intent_id' => $pi->id,
            'metadata' => $pi->metadata->toArray()
        ]);

        try {
            $payment = $this->findPaymentByIntentId($pi->id);

            if ($payment) {
                $payment->setStatus(PaymentStatus::CANCELLED);
                
                if ($order = $payment->getOrder()) {
                    $order->setStatus(OrderStatus::CANCELLED);
                    $order->setPaymentStatus('cancelled');
                }

                $this->em->flush();

                $this->logger->info('✅ Payment et Order marqués comme CANCELLED', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $order?->getId()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors traitement annulation paiement', [
                'payment_intent_id' => $pi->id,
                'error' => $e->getMessage()
            ]);
        }

        return new JsonResponse([
            'success' => true,
            'status'  => 'payment_canceled_processed',
        ], 200);
    }

    /**
     * Gère un remboursement déclenché depuis le dashboard Stripe (charge.refunded)
     */
    private function handleChargeRefunded(\Stripe\Charge $charge): JsonResponse
    {
        $paymentIntentId = $charge->payment_intent;
        $amountRefunded  = $charge->amount_refunded / 100;
        $amountTotal     = $charge->amount / 100;
        $isFullyRefunded = $charge->refunded; // bool Stripe

        $this->logger->info('💸 charge.refunded reçu depuis Stripe', [
            'charge_id'           => $charge->id,
            'payment_intent_id'   => $paymentIntentId,
            'amount_total'        => $amountTotal,
            'amount_refunded'     => $amountRefunded,
            'is_fully_refunded'   => $isFullyRefunded,
            'refunds_count'       => count($charge->refunds->data ?? []),
        ]);

        if (!$paymentIntentId) {
            $this->logger->warning('⚠️ charge.refunded sans payment_intent_id — ignoré', [
                'charge_id' => $charge->id,
            ]);
            return new JsonResponse(['success' => true, 'status' => 'no_payment_intent'], 200);
        }

        try {
            $payment = $this->findPaymentByIntentId($paymentIntentId);

            if (!$payment) {
                $this->logger->warning('⚠️ Payment introuvable pour charge.refunded', [
                    'payment_intent_id' => $paymentIntentId,
                    'charge_id'         => $charge->id,
                ]);
                return new JsonResponse(['success' => true, 'status' => 'payment_not_found'], 200);
            }

            $order = $payment->getOrder();
            if (!$order) {
                $this->logger->warning('⚠️ Order introuvable pour charge.refunded', [
                    'payment_id' => $payment->getId(),
                ]);
                return new JsonResponse(['success' => true, 'status' => 'order_not_found'], 200);
            }

            $this->logger->info('🔗 Order trouvé pour remboursement Stripe', [
                'order_id'            => $order->getId(),
                'order_number'        => $order->getOrderNumber(),
                'current_status'      => $order->getStatus()->value,
                'current_payment_status' => $order->getPaymentStatus(),
                'amount_refunded'     => $amountRefunded,
                'is_fully_refunded'   => $isFullyRefunded,
            ]);

            // Synchroniser le montant remboursé depuis Stripe
            $order->setRefundedAmount($amountRefunded);

            if ($isFullyRefunded) {
                $order->setStatus(\App\Shared\Enum\OrderStatus::REFUNDED);
                $order->setPaymentStatus('refunded');

                // Restituer le stock
                foreach ($order->getItems() as $item) {
                    $product = $item->getProduct();
                    if ($product) {
                        $product->setStock($product->getStock() + $item->getQuantity());
                        $this->logger->info('📦 Stock restitué (remboursement Stripe)', [
                            'product' => $product->getName(),
                            'qty'     => $item->getQuantity(),
                        ]);
                    }
                }

                $this->logger->info('✅ Order marqué REFUNDED (remboursement total Stripe)', [
                    'order_number'    => $order->getOrderNumber(),
                    'amount_refunded' => $amountRefunded,
                ]);
            } else {
                $order->setPaymentStatus('partially_refunded');

                $this->logger->info('✅ Order marqué partially_refunded (remboursement partiel Stripe)', [
                    'order_number'    => $order->getOrderNumber(),
                    'amount_refunded' => $amountRefunded,
                    'amount_total'    => $amountTotal,
                ]);
            }

            // Note horodatée sur la commande
            $note = sprintf(
                '[REMBOURSEMENT%s VIA STRIPE DASHBOARD - %s] %.2f € remboursés sur %.2f €',
                $isFullyRefunded ? ' TOTAL' : ' PARTIEL',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                $amountRefunded,
                $amountTotal
            );
            $currentNote = $order->getCustomerNote();
            $order->setCustomerNote($currentNote ? $currentNote . "\n\n" . $note : $note);

            $this->em->flush();

            $this->logger->info('✅ charge.refunded traité avec succès', [
                'order_number'  => $order->getOrderNumber(),
                'payment_status' => $order->getPaymentStatus(),
            ]);

            return new JsonResponse([
                'success'      => true,
                'status'       => $isFullyRefunded ? 'fully_refunded' : 'partially_refunded',
                'order_number' => $order->getOrderNumber(),
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors traitement charge.refunded', [
                'charge_id'         => $charge->id,
                'payment_intent_id' => $paymentIntentId,
                'error'             => $e->getMessage(),
                'trace'             => $e->getTraceAsString(),
            ]);

            return new JsonResponse(['success' => false, 'error' => 'internal_error'], 500);
        }
    }

    /**
     * Gère refund.created (granularité fine sur chaque remboursement individuel)
     */
    private function handleRefundCreated(\Stripe\Refund $refund): JsonResponse
    {
        $this->logger->info('🔍 refund.created reçu', [
            'refund_id'         => $refund->id,
            'payment_intent_id' => $refund->payment_intent,
            'amount'            => $refund->amount / 100,
            'status'            => $refund->status,
            'reason'            => $refund->reason,
        ]);

        // Le vrai traitement est fait dans charge.refunded qui a plus d'infos
        // On logue juste pour la traçabilité
        return new JsonResponse(['success' => true, 'status' => 'logged'], 200);
    }

    /**
     * Gère les événements non pris en charge
     */
    private function handleUnknownEvent($event): JsonResponse
    {
        $this->logger->info('ℹ️ Événement webhook non géré (ignoré)', [
            'event_type' => $event->type,
            'event_id' => $event->id
        ]);

        return new JsonResponse([
            'success' => true,
            'status'  => 'event_ignored',
        ], 200);
    }

    /**
     * Récupère un Payment par son PaymentIntent ID
     */
    private function findPaymentByIntentId(string $paymentIntentId): ?Payment
    {
        $this->logger->debug('🔍 Recherche Payment par PaymentIntent ID', [
            'payment_intent_id' => $paymentIntentId
        ]);

        try {
            $payment = $this->em->getRepository(Payment::class)
                ->findOneBy(['providerPaymentId' => $paymentIntentId]);

            if ($payment) {
                $this->logger->debug('✅ Payment trouvé', [
                    'payment_id' => $payment->getId(),
                    'current_status' => $payment->getStatus()->value
                ]);
            } else {
                $this->logger->warning('⚠️ Aucun Payment trouvé', [
                    'payment_intent_id' => $paymentIntentId
                ]);
            }

            return $payment;

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors recherche Payment', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage()
            ]);

            return null;
        }
    }

    /**
     * Reconstruit la commande (Order + Payment PENDING) depuis les metadata du
     * PaymentIntent, quand /api/cart/checkout n'a jamais été appelé côté front
     * (onglet fermé pendant le 3DS, page blanche Safari iOS, refresh utilisateur...).
     *
     * Miroir volontaire de CheckoutController::__invoke et de
     * RecoverOrderFromCartCommand (mêmes snapshots d'adresse, mêmes rédemptions
     * promo), mais piloté par les metadata Stripe au lieu d'un payload front ou
     * d'arguments CLI, et avec gestion du cas invité (guest_token) qui manquait
     * dans RecoverOrderFromCartCommand.
     *
     * Retourne null si la reconstruction est impossible (cart/adresses/carrier
     * introuvables, ou aucun email exploitable pour un invité) — dans ce cas
     * l'appelant renvoie 400 et la récupération manuelle (app:recover-order-from-cart)
     * reste le filet de sécurité.
     */
    private function createOrderFromMetadata(PaymentIntent $pi): ?Payment
    {
        $metadata = $pi->metadata->toArray();
        $cartId   = $metadata['cart_id'] ?? null;

        if (!$cartId) {
            $this->logger->error('❌ cart_id manquant dans metadata, reconstruction impossible', [
                'payment_intent_id' => $pi->id,
            ]);
            return null;
        }

        $cart = $this->em->getRepository(Cart::class)->find($cartId);
        if (!$cart || $cart->getItems()->isEmpty()) {
            $this->logger->error('❌ Cart introuvable ou vide, reconstruction impossible', [
                'payment_intent_id' => $pi->id,
                'cart_id' => $cartId,
            ]);
            return null;
        }

        $billingSource  = isset($metadata['billing_address_id'])
            ? $this->em->getRepository(Address::class)->find((int) $metadata['billing_address_id'])
            : null;
        $deliverySource = isset($metadata['delivery_address_id'])
            ? $this->em->getRepository(Address::class)->find((int) $metadata['delivery_address_id'])
            : null;

        if (!$billingSource || !$deliverySource) {
            $this->logger->error('❌ Adresse(s) introuvable(s), reconstruction impossible', [
                'payment_intent_id' => $pi->id,
                'billing_address_id' => $metadata['billing_address_id'] ?? null,
                'delivery_address_id' => $metadata['delivery_address_id'] ?? null,
            ]);
            return null;
        }

        $carrierMode = isset($metadata['carrier_mode_id'])
            ? $this->carrierModeRepository->find((int) $metadata['carrier_mode_id'])
            : null;

        if (!$carrierMode) {
            $this->logger->error('❌ CarrierMode introuvable, reconstruction impossible', [
                'payment_intent_id' => $pi->id,
                'carrier_mode_id' => $metadata['carrier_mode_id'] ?? null,
            ]);
            return null;
        }

        // Owner : user connecté (user_id) ou invité (fallback sur le guest user attaché au cart)
        $user = null;
        if (!empty($metadata['user_id'])) {
            $user = $this->em->getRepository(User::class)->find($metadata['user_id']);
            if (!$user) {
                $this->logger->error('❌ User introuvable pour user_id metadata, reconstruction impossible', [
                    'payment_intent_id' => $pi->id,
                    'user_id' => $metadata['user_id'],
                ]);
                return null;
            }
        }

        $guestEmail = null;
        if (!$user) {
            $guestUser  = $cart->getOwner();
            $guestEmail = $guestUser?->getEmail();
            if (!$guestEmail) {
                $this->logger->error('❌ Aucun email invité exploitable, reconstruction impossible', [
                    'payment_intent_id' => $pi->id,
                    'guest_token' => $metadata['guest_token'] ?? null,
                ]);
                return null;
            }
        }

        $this->logger->info('🧱 Reconstruction de la commande depuis les metadata PaymentIntent', [
            'payment_intent_id' => $pi->id,
            'cart_id' => $cartId,
            'is_guest' => !$user,
        ]);

        // --- Snapshots d'adresse (mêmes champs que CheckoutController) ---
        $billingSnapshot = (new Address())
            ->setAddressKind($billingSource->getAddressKind())
            ->setStreetAddress($billingSource->getStreetAddress())
            ->setAddressComplement($billingSource->getAddressComplement())
            ->setCity($billingSource->getCity())
            ->setPostalCode($billingSource->getPostalCode())
            ->setCountry($billingSource->getCountry())
            ->setState($billingSource->getState())
            ->setLabel('Billing snapshot (webhook)')
            ->setIsDefault(false)->setOwner(null)
            ->setLatitude($billingSource->getLatitude())
            ->setLongitude($billingSource->getLongitude())
            ->setCivility($billingSource->getCivility())
            ->setFirstName($billingSource->getFirstName())
            ->setLastName($billingSource->getLastName())
            ->setPhone($billingSource->getPhone())
            ->setIsBusiness($billingSource->isBusiness())
            ->setCompanyName($billingSource->getCompanyName());
        $this->em->persist($billingSnapshot);

        $shippingSnapshot = (new Address())
            ->setAddressKind($deliverySource->getAddressKind())
            ->setStreetAddress($deliverySource->getStreetAddress())
            ->setAddressComplement($deliverySource->getAddressComplement())
            ->setCity($deliverySource->getCity())
            ->setPostalCode($deliverySource->getPostalCode())
            ->setCountry($deliverySource->getCountry())
            ->setState($deliverySource->getState())
            ->setLabel('Shipping snapshot (webhook)')
            ->setIsDefault(false)->setOwner(null)
            ->setLatitude($deliverySource->getLatitude())
            ->setLongitude($deliverySource->getLongitude())
            ->setCivility($deliverySource->getCivility())
            ->setFirstName($deliverySource->getFirstName())
            ->setLastName($deliverySource->getLastName())
            ->setPhone($deliverySource->getPhone())
            ->setIsBusiness($deliverySource->isBusiness())
            ->setCompanyName($deliverySource->getCompanyName());

        if ($deliverySource->isRelayPoint()) {
            $shippingSnapshot
                ->setAddressKind('relay')
                ->setIsRelayPoint(true)
                ->setRelayPointId($deliverySource->getRelayPointId())
                ->setRelayCarrier($deliverySource->getRelayCarrier());
        }
        $this->em->persist($shippingSnapshot);

        // --- Order ---
        $piAmount     = ($pi->amount_received ?: $pi->amount) / 100;
        $shippingCost = $cart->getShippingCost() ?? (float) ($metadata['shipping_cost'] ?? 0);

        $order = new Order();
        $order
            ->setStatus(OrderStatus::PENDING)
            ->setCarrier($carrierMode->getCarrier())
            ->setShippingMode($carrierMode->getShippingMode())
            ->setCarrierMode($carrierMode)
            ->setBillingAddress($billingSnapshot)
            ->setShippingAddress($shippingSnapshot)
            ->setShippingCost($shippingCost)
            ->setCarrierShippingCost($cart->getCarrierShippingCost() ?? $shippingCost)
            ->setTotalAmount($piAmount)
            ->setCurrency('EUR')
            ->setLocale('fr');

        // Provenance visiteur : copiée depuis le Cart (capturée à l'ouverture du panier)
        $order
            ->setSource($this->clientContext->resolveSource($cart->getGuestReferrer()))
            ->setCountry($cart->getGuestCountry())
            ->setOsName($cart->getOsName())
            ->setDeviceType($cart->getDeviceType());

        // Codes promo : priorité aux metadata (source authoritative indépendante
        // du cart, qui a pu changer depuis la création du PaymentIntent), sinon
        // fallback sur l'état actuel du cart pour compatibilité ascendante.
        $codesData = [];
        if (!empty($metadata['promo_codes'])) {
            $decoded = json_decode($metadata['promo_codes'], true);
            if (is_array($decoded)) {
                $codesData = $decoded;
            }
        }
        if (empty($codesData)) {
            $codesData = $cart->getPromoCodesData() ?? [];
        }
        if (empty($codesData) && !empty($metadata['promo_code'])) {
            $codesData = [['code' => $metadata['promo_code'], 'discount' => (float) ($metadata['discount_amount'] ?? 0), 'stackable' => false]];
        }

        if (!empty($codesData)) {
            $order->setPromoCode($codesData[0]['code'] ?? null)
                ->setDiscountAmount((float) ($metadata['discount_amount'] ?? $cart->getDiscountAmount() ?? 0))
                ->setPromoCodesData($codesData);
        }

        if ($user) {
            $order->setOwner($user);
        } else {
            $order
                ->setGuestEmail($guestEmail)
                ->setGuestFirstName($billingSource->getFirstName())
                ->setGuestLastName($billingSource->getLastName())
                ->setGuestPhone($billingSource->getPhone());
        }

        if ($deliverySource->isRelayPoint()) {
            $order
                ->setIsRelayPoint(true)
                ->setRelayPointId($deliverySource->getRelayPointId())
                ->setRelayCarrier($deliverySource->getRelayCarrier());
        }

        foreach ($cart->getItems() as $cartItem) {
            $item = (new OrderItem())
                ->setCustomerOrder($order)
                ->setProduct($cartItem->getProduct())
                ->setQuantity($cartItem->getQuantity())
                ->setUnitPrice($cartItem->getUnitPrice());
            $this->em->persist($item);
        }

        $payment = (new Payment())
            ->setOrder($order)
            ->setProvider('stripe')
            ->setStatus(PaymentStatus::PENDING)
            ->setProviderPaymentId($pi->id)
            ->setClientSecret($cart->getPaymentClientSecret())
            ->setAmount($piAmount);
        $this->em->persist($payment);

        // --- Rédemptions promo (mêmes règles que CheckoutController) ---
        $customerEmail = $user ? $user->getEmail() : $guestEmail;
        $customerType  = $user ? 'registered' : 'guest';

        foreach ($codesData as $codeData) {
            try {
                $promoCode = $this->promoCodeRepository->findOneBy(['code' => $codeData['code'] ?? '']);
                if (!$promoCode) {
                    continue;
                }

                $redemption = (new PromoCodeRedemption())
                    ->setPromoCode($promoCode)
                    ->setEmail($customerEmail)
                    ->setCustomerType($customerType)
                    ->setOrder($order)
                    ->setDiscountAmount((string) ($codeData['discount'] ?? 0));
                $this->em->persist($redemption);

                if ($promoCode->isSingleInstance()) {
                    $this->promoCodeApplicationService->markAsUsed($promoCode);
                }
            } catch (\Exception $e) {
                $this->logger->error('❌ Échec traitement rédemption promo (reconstruction webhook)', [
                    'code'  => $codeData['code'] ?? '?',
                    'error' => $e->getMessage(),
                ]);
                // Ne pas bloquer la création de la commande si le promo échoue
            }
        }

        $this->em->persist($order);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Course avec /api/cart/checkout ou une autre livraison webhook
            // concurrente : un Payment existe déjà pour ce PaymentIntent.
            // On ne tente pas de récupérer dans la même requête (l'EntityManager
            // n'est plus fiable après un flush en échec) — Stripe retentera ce
            // webhook automatiquement, et findPaymentByIntentId le trouvera alors.
            $this->logger->warning('⚠️ Course détectée sur provider_payment_id, un Payment existe déjà (contrainte unique) — laisser Stripe retenter', [
                'payment_intent_id' => $pi->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->logger->info('✅ Commande créée depuis le webhook', [
            'payment_intent_id' => $pi->id,
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
        ]);

        return $payment;
    }

    /**
     * Nettoie le panier après paiement réussi
     */
    private function handleCartCleanup(PaymentIntent $pi): void
    {
        $cartId = $pi->metadata['cart_id'] ?? null;

        if (!$cartId) {
            $this->logger->warning('⚠️ cart_id manquant dans metadata PaymentIntent', [
                'payment_intent_id' => $pi->id,
                'metadata' => $pi->metadata->toArray()
            ]);
            return;
        }

        $this->logger->info('🗑️ Nettoyage du panier', [
            'cart_id' => $cartId,
            'payment_intent_id' => $pi->id
        ]);

        try {
            $cart = $this->em->getRepository(Cart::class)->find($cartId);

            if (!$cart) {
                $this->logger->warning('⚠️ Panier introuvable pour nettoyage', [
                    'cart_id' => $cartId
                ]);
                return;
            }

            $itemCount = $cart->getItems()->count();

            // Désactiver le panier
            $cart->setIsActive(false);

            // Supprimer les items
            foreach ($cart->getItems() as $item) {
                $this->em->remove($item);
            }

            // Supprimer le panier
            $this->em->remove($cart);

            $this->logger->info('✅ Panier nettoyé', [
                'cart_id' => $cartId,
                'items_removed' => $itemCount
            ]);

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors nettoyage panier', [
                'cart_id' => $cartId,
                'error' => $e->getMessage()
            ]);
            // Ne pas bloquer le processus si le nettoyage échoue
        }
    }

    /**
     * Envoie l'email de confirmation de commande
     */
    private function sendOrderConfirmationEmail(Order $order): void
    {
        if ($order->isConfirmationEmailSent()) {
            $this->logger->info('ℹ️ Email confirmation déjà envoyé, on ignore (idempotence)', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber()
            ]);
            return;
        }

        $this->logger->info('📧 Envoi email confirmation commande', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'recipient' => $order->getOwner()?->getEmail() ?? $order->getGuestEmail()
        ]);

        try {
            $this->mailerService->sendOrderConfirmation($order);

            $order->setConfirmationEmailSent(true);
            $this->em->flush();

            $this->logger->info('✅ Email confirmation envoyé avec succès', [
                'order_id' => $order->getId(),
                'order_number' => $order->getOrderNumber()
            ]);

        } catch (\Symfony\Component\Mailer\Exception\TransportException $e) {
            $this->logger->error('❌ Erreur transport email', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage()
            ]);
            // Ne pas bloquer le webhook si l'email échoue

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur inattendue lors envoi email', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            // Ne pas bloquer le webhook si l'email échoue
        }
    }
}