<?php

namespace App\Payment\Controller;

use App\Cart\Entity\Cart;
use App\Payment\Entity\Payment;
use App\Shared\Enum\OrderStatus;
use App\Shared\Enum\PaymentStatus;
use App\Shared\Service\MailerService;
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
            // 1️⃣ Récupérer le Payment
            $payment = $this->findPaymentByIntentId($pi->id);

            if (!$payment) {
                $this->logger->error('❌ Payment introuvable pour PaymentIntent', [
                    'payment_intent_id' => $pi->id,
                    'metadata' => $pi->metadata->toArray()
                ]);

                return new JsonResponse([
                    'success' => false,
                    'error'   => 'payment_not_found',
                    'message' => 'Payment record not found for this PaymentIntent',
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
    private function sendOrderConfirmationEmail($order): void
    {
        $this->logger->info('📧 Envoi email confirmation commande', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'recipient' => $order->getOwner()?->getEmail() ?? $order->getGuestEmail()
        ]);

        try {
            $this->mailerService->sendOrderConfirmation($order);

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