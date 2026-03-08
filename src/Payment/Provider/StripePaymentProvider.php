<?php

namespace App\Payment\Provider;

use App\Order\Entity\Order;
use App\Payment\Response\PaymentResponse;
use App\User\Entity\User;
use App\Cart\Entity\Cart;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\CardException;
use Psr\Log\LoggerInterface;

class StripePaymentProvider implements PaymentProviderInterface
{
    private StripeClient $client;

    public function __construct(
        string $stripeSecretKey,
        private LoggerInterface $logger
    ) {
        $this->client = new StripeClient($stripeSecretKey);
        
        $this->logger->debug('🔧 StripePaymentProvider initialisé', [
            'api_key_prefix' => substr($stripeSecretKey, 0, 7) . '...'
        ]);
    }

    public function getName(): string
    {
        return 'stripe';
    }

    /**
     * Crée ou récupère un customer Stripe pour un user
     */
    public function ensureCustomerFor(User $user): string
    {
        // Customer déjà existant
        if ($user->getStripeCustomerId()) {
            $this->logger->debug('✅ Customer Stripe existant récupéré', [
                'user_id' => $user->getId(),
                'stripe_customer_id' => $user->getStripeCustomerId()
            ]);
            
            return $user->getStripeCustomerId();
        }

        $this->logger->info('🆕 Création nouveau customer Stripe', [
            'user_id' => $user->getId(),
            'email' => $user->getEmail()
        ]);

        try {
            $customerName = trim(($user->getFirstname() ?? '') . ' ' . ($user->getLastname() ?? '')) 
                ?: $user->getEmail();

            $c = $this->client->customers->create([
                'email' => $user->getEmail(),
                'name'  => $customerName,
                'metadata' => [
                    'user_id' => (string) $user->getId(),
                    'created_from' => 'khamareo_app'
                ],
            ]);

            $user->setStripeCustomerId($c->id);

            $this->logger->info('✅ Customer Stripe créé avec succès', [
                'user_id' => $user->getId(),
                'stripe_customer_id' => $c->id,
                'email' => $user->getEmail()
            ]);

            return $c->id;

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Erreur validation Stripe lors création customer', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode()
            ]);
            throw new \RuntimeException('Impossible de créer le customer Stripe : ' . $e->getMessage(), 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->critical('🔥 Erreur API Stripe lors création customer', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode(),
                'http_status' => $e->getHttpStatus()
            ]);
            throw new \RuntimeException('Erreur de communication avec Stripe', 0, $e);

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors création customer Stripe', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * SetupIntent pour enregistrer une carte
     */
    public function createSetupIntent(string $customerId, string $usage = 'on_session'): PaymentResponse
    {
        $this->logger->info('🔐 Création SetupIntent pour enregistrement carte', [
            'customer_id' => $customerId,
            'usage' => $usage
        ]);

        try {
            $si = $this->client->setupIntents->create([
                'customer' => $customerId,
                'payment_method_types' => ['card'],
                'usage' => $usage,
            ]);

            $this->logger->info('✅ SetupIntent créé avec succès', [
                'setup_intent_id' => $si->id,
                'customer_id' => $customerId,
                'status' => $si->status
            ]);

            return new PaymentResponse('stripe', $si->id, $si->client_secret);

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Erreur validation lors création SetupIntent', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode()
            ]);
            throw new \RuntimeException('Customer invalide : ' . $e->getMessage(), 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->critical('🔥 Erreur API Stripe lors création SetupIntent', [
                'customer_id' => $customerId,
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus()
            ]);
            throw new \RuntimeException('Erreur de communication avec Stripe', 0, $e);
        }
    }

    /**
     * Liste des cartes enregistrées
     */
    public function listCards(string $customerId): array
    {
        $this->logger->debug('📋 Récupération liste des cartes', [
            'customer_id' => $customerId
        ]);

        try {
            $res = $this->client->paymentMethods->all([
                'customer' => $customerId,
                'type' => 'card',
            ]);

            $cards = array_map(static function ($pm) {
                return [
                    'id'        => $pm->id,
                    'brand'     => $pm->card->brand,
                    'last4'     => $pm->card->last4,
                    'exp_month' => $pm->card->exp_month,
                    'exp_year'  => $pm->card->exp_year,
                ];
            }, $res->data);

            $this->logger->info('✅ Cartes récupérées', [
                'customer_id' => $customerId,
                'card_count' => count($cards)
            ]);

            return $cards;

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Customer invalide lors récupération cartes', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Customer invalide', 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->error('❌ Erreur API lors récupération cartes', [
                'customer_id' => $customerId,
                'error' => $e->getMessage()
            ]);
            // Retourner tableau vide plutôt que crasher
            return [];
        }
    }

    /**
     * Détache une carte
     */
    public function detachPaymentMethod(string $paymentMethodId): void
    {
        $this->logger->info('🗑️ Détachement méthode de paiement', [
            'payment_method_id' => $paymentMethodId
        ]);

        try {
            $this->client->paymentMethods->detach($paymentMethodId);

            $this->logger->info('✅ Méthode de paiement détachée avec succès', [
                'payment_method_id' => $paymentMethodId
            ]);

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Méthode de paiement invalide lors détachement', [
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Méthode de paiement introuvable', 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->error('❌ Erreur API lors détachement méthode paiement', [
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Impossible de détacher la carte', 0, $e);
        }
    }

    /**
     * Paiement avec nouvelle carte
     */
    public function createPaymentIntentForNewCard(Order $order, ?string $customerId = null): PaymentResponse
    {
        $amount = (int) \round($order->getTotalAmount() * 100);
        
        $this->logger->info('💳 Création PaymentIntent pour nouvelle carte', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'amount_cents' => $amount,
            'currency' => $order->getCurrency() ?? 'eur',
            'customer_id' => $customerId
        ]);

        try {
            $payload = [
                'amount'   => $amount,
                'currency' => \strtolower($order->getCurrency() ?? 'eur'),
                'payment_method_types' => ['card'],
                'metadata' => [
                    'order_id'     => (string) $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'source'       => 'new_card'
                ],
                'description' => \sprintf('Commande #%s', $order->getOrderNumber()),
                'automatic_payment_methods' => ['enabled' => true],
            ];

            if ($customerId) {
                $payload['customer'] = $customerId;
                $payload['setup_future_usage'] = 'on_session';
                
                $this->logger->debug('🔗 Customer attaché au PaymentIntent', [
                    'customer_id' => $customerId
                ]);
            }

            $pi = $this->client->paymentIntents->create($payload);

            $this->logger->info('✅ PaymentIntent créé avec succès', [
                'payment_intent_id' => $pi->id,
                'order_id' => $order->getId(),
                'amount' => $pi->amount,
                'status' => $pi->status
            ]);

            return new PaymentResponse('stripe', $pi->id, $pi->client_secret);

        } catch (CardException $e) {
            $this->logger->warning('⚠️ Erreur carte lors création PaymentIntent', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'decline_code' => $e->getDeclineCode()
            ]);
            throw new \RuntimeException('Carte refusée : ' . $e->getMessage(), 0, $e);

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Paramètres invalides lors création PaymentIntent', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode()
            ]);
            throw new \RuntimeException('Données de paiement invalides', 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->critical('🔥 Erreur API Stripe lors création PaymentIntent', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus()
            ]);
            throw new \RuntimeException('Erreur de communication avec Stripe', 0, $e);
        }
    }

    /**
     * Paiement avec carte enregistrée
     */
    public function createPaymentIntentWithSavedCard(
        Order $order,
        string $customerId,
        string $paymentMethodId,
        bool $offSession = false,
        bool $confirmNow = false
    ): PaymentResponse {
        $amount = (int) \round($order->getTotalAmount() * 100);
        
        $this->logger->info('💳 Création PaymentIntent avec carte enregistrée', [
            'order_id' => $order->getId(),
            'order_number' => $order->getOrderNumber(),
            'amount_cents' => $amount,
            'customer_id' => $customerId,
            'payment_method_id' => $paymentMethodId,
            'off_session' => $offSession,
            'confirm_now' => $confirmNow
        ]);

        try {
            $pi = $this->client->paymentIntents->create([
                'amount'   => $amount,
                'currency' => \strtolower($order->getCurrency() ?? 'eur'),
                'customer' => $customerId,
                'payment_method' => $paymentMethodId,
                'off_session' => $offSession,
                'confirm'     => $confirmNow,
                'metadata' => [
                    'order_id'     => (string) $order->getId(),
                    'order_number' => $order->getOrderNumber(),
                    'source'       => 'saved_card'
                ],
                'description' => \sprintf('Commande #%s', $order->getOrderNumber()),
            ]);

            $this->logger->info('✅ PaymentIntent avec carte enregistrée créé', [
                'payment_intent_id' => $pi->id,
                'order_id' => $order->getId(),
                'status' => $pi->status
            ]);

            return new PaymentResponse('stripe', $pi->id, $pi->client_secret ?? null);

        } catch (CardException $e) {
            $this->logger->warning('⚠️ Carte refusée', [
                'order_id' => $order->getId(),
                'payment_method_id' => $paymentMethodId,
                'decline_code' => $e->getDeclineCode(),
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Carte refusée : ' . $e->getMessage(), 0, $e);

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Carte ou customer invalide', [
                'order_id' => $order->getId(),
                'customer_id' => $customerId,
                'payment_method_id' => $paymentMethodId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Méthode de paiement invalide', 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->critical('🔥 Erreur API Stripe', [
                'order_id' => $order->getId(),
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus()
            ]);
            throw new \RuntimeException('Erreur de paiement', 0, $e);
        }
    }

    /**
     * Utilitaire webhook
     */
    public function constructEventFromWebhook(string $payload, string $sigHeader, string $endpointSecret): \Stripe\Event
    {
        $this->logger->debug('🔐 Vérification signature webhook Stripe');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
            
            $this->logger->info('✅ Webhook Stripe validé', [
                'event_type' => $event->type,
                'event_id' => $event->id
            ]);
            
            return $event;

        } catch (\UnexpectedValueException $e) {
            $this->logger->error('❌ Payload webhook invalide', [
                'error' => $e->getMessage()
            ]);
            throw $e;

        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->error('❌ Signature webhook invalide', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function handleWebhook(array $event): array
    {
        $type   = $event['type'] ?? null;
        $object = $event['data']['object'] ?? null;

        $this->logger->debug('📥 Traitement webhook', [
            'type' => $type,
            'has_object' => $object !== null
        ]);

        return [$type, $object];
    }

    /**
     * Crée ou met à jour un PaymentIntent pour un panier
     */
    public function createOrUpdateCartPaymentIntent(
        Cart $cart,
        int $amountCents,
        string $currency = 'eur',
        float $shippingAmount = 0.0,
        string $shippingLabel = 'Shipping',
        array $extraMetadata = [],
    ): PaymentResponse {
        $currency = \strtolower($currency);
        $customerId = $cart->getOwner()?->getStripeCustomerId();

        $this->logger->info('🛒 Création/mise à jour PaymentIntent pour panier', [
            'cart_id' => $cart->getId(),
            'existing_pi' => $cart->getPaymentIntentId(),
            'amount_cents' => $amountCents,
            'currency' => $currency,
            'shipping_amount' => $shippingAmount,
            'customer_id' => $customerId,
            'is_guest' => !$cart->getOwner()
        ]);

        // Metadata de base
        $baseMetadata = [
            'cart_id'          => (string) $cart->getId(),
            'user_id'          => $cart->getOwner()?->getId(),
            'guest_token'      => $cart->getOwner() ? null : $cart->getGuestToken(),
            'step'             => 'payment_intent_created',
            'total_items'      => \count($cart->getItems()),
            'shipping_amount'  => $shippingAmount > 0 ? (string) $shippingAmount : null,
            'shipping_label'   => $shippingAmount > 0 ? $shippingLabel : null,
        ];

        $metadata = array_merge($baseMetadata, $extraMetadata);
        
        // Nettoyer les metadata (Stripe exige scalar uniquement)
        foreach ($metadata as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                $this->logger->debug('🔧 Conversion metadata non-scalaire', [
                    'key' => $key,
                    'type' => gettype($value)
                ]);
                $metadata[$key] = (string) $value;
            }
        }

        try {
            // UPDATE d'un PaymentIntent existant
            if ($cart->getPaymentIntentId()) {
                $updated = $this->updateExistingPaymentIntent(
                    $cart,
                    $amountCents,
                    $currency,
                    $shippingAmount,
                    $metadata,
                    $customerId
                );

                if ($updated !== null) {
                    return $updated;
                }

                // PI en état terminal (succeeded/canceled) → créer un nouveau
                $this->logger->info('🔄 Ancien PI en état terminal, création d\'un nouveau', [
                    'cart_id' => $cart->getId(),
                    'old_pi' => $cart->getPaymentIntentId(),
                ]);
            }

            // CRÉATION d'un nouveau PaymentIntent
            return $this->createNewPaymentIntent(
                $cart,
                $amountCents,
                $currency,
                $shippingAmount,
                $metadata,
                $customerId
            );

        } catch (ApiErrorException $e) {
            $this->logger->critical('🔥 Erreur API Stripe lors gestion PaymentIntent panier', [
                'cart_id' => $cart->getId(),
                'payment_intent_id' => $cart->getPaymentIntentId(),
                'error' => $e->getMessage(),
                'http_status' => $e->getHttpStatus(),
                'stripe_error_code' => $e->getStripeCode()
            ]);
            throw new \RuntimeException('Erreur lors de la création du paiement', 0, $e);

        } catch (\Exception $e) {
            $this->logger->critical('🔥 Erreur inattendue lors gestion PaymentIntent', [
                'cart_id' => $cart->getId(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Met à jour un PaymentIntent existant.
     * Retourne null si le PI est en état terminal (succeeded/canceled) → le caller doit en créer un nouveau.
     */
    private function updateExistingPaymentIntent(
        Cart $cart,
        int $amountCents,
        string $currency,
        float $shippingAmount,
        array $metadata,
        ?string $customerId
    ): ?PaymentResponse {
        $existingPiId = $cart->getPaymentIntentId();
        
        $this->logger->info('🔄 Mise à jour PaymentIntent existant', [
            'payment_intent_id' => $existingPiId,
            'cart_id' => $cart->getId(),
            'new_amount_cents' => $amountCents
        ]);

        try {
            $existingPi = $this->client->paymentIntents->retrieve($existingPiId, []);

            $this->logger->debug('📊 État PaymentIntent actuel', [
                'payment_intent_id' => $existingPi->id,
                'status' => $existingPi->status,
                'current_amount' => $existingPi->amount,
                'new_amount' => $amountCents
            ]);

            // Vérifier si le PaymentIntent est modifiable
            $updatableStatuses = [
                'requires_payment_method',
                'requires_confirmation',
                'requires_action',
                'processing',
                'requires_capture',
            ];

            if (!\in_array($existingPi->status, $updatableStatuses, true)) {
                $this->logger->warning('⚠️ PaymentIntent en état terminal, création d\'un nouveau requis', [
                    'payment_intent_id' => $existingPi->id,
                    'status' => $existingPi->status,
                ]);

                return null;
            }

            // Récupérer et nettoyer les metadata existantes
            $existingMetadata = $existingPi->metadata?->toArray() ?? [];
            
            foreach ($existingMetadata as $key => $value) {
                if (!is_scalar($value) && $value !== null) {
                    $existingMetadata[$key] = (string) $value;
                }
            }

            $mergedMetadata = array_merge($existingMetadata, $metadata);

            // ✅ CORRECTION : Retirer shipping_cost qui n'existe pas dans l'API Stripe
            $updatePayload = [
                'amount'   => $amountCents,  // Le montant TOTAL (items + shipping)
                'currency' => $currency,
                'metadata' => $mergedMetadata,
            ];

            if ($customerId) {
                $updatePayload['customer'] = $customerId;
            }

            $pi = $this->client->paymentIntents->update($existingPiId, $updatePayload);

            $this->logger->info('✅ PaymentIntent mis à jour avec succès', [
                'payment_intent_id' => $pi->id,
                'cart_id' => $cart->getId(),
                'amount' => $pi->amount,
                'status' => $pi->status
            ]);

            return new PaymentResponse(
                provider: 'stripe',
                paymentId: $pi->id,
                clientSecret: $pi->client_secret
            );

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ Erreur validation lors mise à jour PaymentIntent', [
                'payment_intent_id' => $existingPiId,
                'cart_id' => $cart->getId(),
                'error' => $e->getMessage(),
                'stripe_error_code' => $e->getStripeCode()
            ]);
            throw new \RuntimeException('Données de paiement invalides', 0, $e);
        }
    }

    /**
     * Crée un nouveau PaymentIntent
     */
    private function createNewPaymentIntent(
        Cart $cart,
        int $amountCents,
        string $currency,
        float $shippingAmount,
        array $metadata,
        ?string $customerId
    ): PaymentResponse {
        $this->logger->info('🆕 Création nouveau PaymentIntent pour panier', [
            'cart_id' => $cart->getId(),
            'amount_cents' => $amountCents,  // Total incluant shipping
            'currency' => $currency,
            'customer_id' => $customerId,
            'shipping_amount' => $shippingAmount
        ]);

        // ✅ CORRECTION : Retirer shipping_cost qui n'existe pas dans l'API Stripe
        $piPayload = [
            'amount'   => $amountCents,  // Le montant TOTAL (items + shipping)
            'currency' => $currency,
            'metadata' => $metadata,
            'automatic_payment_methods' => ['enabled' => true],
        ];

        if ($customerId) {
            $piPayload['customer'] = $customerId;
            $piPayload['setup_future_usage'] = 'on_session';
            
            $this->logger->debug('🔗 Customer attaché au nouveau PaymentIntent', [
                'customer_id' => $customerId
            ]);
        }

        $pi = $this->client->paymentIntents->create($piPayload);

        $this->logger->info('✅ Nouveau PaymentIntent créé avec succès', [
            'payment_intent_id' => $pi->id,
            'cart_id' => $cart->getId(),
            'amount' => $pi->amount,
            'status' => $pi->status,
            'client_secret_exists' => !empty($pi->client_secret)
        ]);

        return new PaymentResponse(
            provider: 'stripe',
            paymentId: $pi->id,
            clientSecret: $pi->client_secret
        );
    }

    /**
     * Récupère un PaymentIntent
     */
    public function retrievePaymentIntent(string $paymentIntentId): \Stripe\PaymentIntent
    {
        $this->logger->debug('🔍 Récupération PaymentIntent', [
            'payment_intent_id' => $paymentIntentId
        ]);

        try {
            $pi = $this->client->paymentIntents->retrieve($paymentIntentId, []);
            
            $this->logger->debug('✅ PaymentIntent récupéré', [
                'payment_intent_id' => $pi->id,
                'status' => $pi->status,
                'amount' => $pi->amount
            ]);
            
            return $pi;

        } catch (InvalidRequestException $e) {
            $this->logger->error('❌ PaymentIntent introuvable', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('PaymentIntent introuvable', 0, $e);

        } catch (ApiErrorException $e) {
            $this->logger->error('❌ Erreur API lors récupération PaymentIntent', [
                'payment_intent_id' => $paymentIntentId,
                'error' => $e->getMessage()
            ]);
            throw new \RuntimeException('Erreur lors de la récupération du paiement', 0, $e);
        }
    }

    /**
     * Crée un remboursement Stripe sur un PaymentIntent
     *
     * @param string $paymentIntentId ID du PaymentIntent Stripe
     * @param int    $amountCents     Montant en centimes
     * @param string $reason          requested_by_customer | duplicate | fraudulent
     * @param array  $metadata        Métadonnées optionnelles
     */
    public function refundPayment(
        string $paymentIntentId,
        int $amountCents,
        string $reason = 'requested_by_customer',
        array $metadata = []
    ): array {
        try {
            $this->logger->info('💸 Creating Stripe refund', [
                'payment_intent_id' => $paymentIntentId,
                'amount_cents'      => $amountCents,
                'reason'            => $reason,
            ]);

            $refund = $this->client->refunds->create([
                'payment_intent' => $paymentIntentId,
                'amount'         => $amountCents,
                'reason'         => $reason,
                'metadata'       => $metadata,
            ]);

            $this->logger->info('✅ Stripe refund created', [
                'refund_id' => $refund->id,
                'status'    => $refund->status,
            ]);

            return [
                'success'   => true,
                'refund_id' => $refund->id,
                'status'    => $refund->status,
                'amount'    => $refund->amount,
            ];

        } catch (ApiErrorException $e) {
            $this->logger->error('❌ Stripe refund failed', [
                'payment_intent_id' => $paymentIntentId,
                'error'             => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}