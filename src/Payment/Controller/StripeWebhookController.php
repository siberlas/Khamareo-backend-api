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
            $this->logger->debug('🔄 Dispatch événement webhook', [
                'event_type' => $event->type
            ]);

            return match ($event->type) {
                'payment_intent.succeeded' => $this->handlePaymentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentFailed($event->data->object),
                'payment_intent.canceled' => $this->handlePaymentCanceled($event->data->object),
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

            // 5️⃣ Gérer le panier
            $this->handleCartCleanup($pi);

            // 6️⃣ Sauvegarder en base
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

            // 7️⃣ Envoyer l'email de confirmation
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
        $this->logger->warning('⚠️ Paiement échoué', [
            'payment_intent_id' => $pi->id,
            'amount' => $pi->amount / 100,
            'currency' => $pi->currency,
            'last_payment_error' => $pi->last_payment_error?->message ?? 'Unknown error',
            'metadata' => $pi->metadata->toArray()
        ]);

        try {
            $payment = $this->findPaymentByIntentId($pi->id);

            if ($payment) {
                $payment->setStatus(PaymentStatus::FAILED);
                
                if ($order = $payment->getOrder()) {
                    $order->setStatus(OrderStatus::FAILED);
                    $order->setPaymentStatus('failed');
                }

                $this->em->flush();

                $this->logger->info('✅ Payment et Order marqués comme FAILED', [
                    'payment_id' => $payment->getId(),
                    'order_id' => $order?->getId()
                ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('❌ Erreur lors traitement échec paiement', [
                'payment_intent_id' => $pi->id,
                'error' => $e->getMessage()
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