<?php
namespace App\Payment\Provider;

use App\Order\Entity\Order;
use App\Payment\Response\PaymentResponse;
use App\User\Entity\User;

/**
 * Interface de base pour tous les fournisseurs de paiement (Stripe, PayPal, etc.)
 */
interface PaymentProviderInterface
{
    /** Nom stable du provider (ex. "stripe", "paypal") */
    public function getName(): string;

    // --- Création des paiements ---
    /** Crée un PaymentIntent pour un paiement standard (nouvelle carte) */
    public function createPaymentIntentForNewCard(Order $order): PaymentResponse;

    /**
     * Crée un PaymentIntent à partir d'une carte enregistrée (PaymentMethod déjà attaché au Customer)
     */
    public function createPaymentIntentWithSavedCard(
        Order $order,
        string $customerId,
        string $paymentMethodId,
        bool $offSession = false,
        bool $confirmNow = false
    ): PaymentResponse;

    // --- Gestion du client / carte ---
    /** Crée ou récupère le Customer Stripe lié à un utilisateur */
    public function ensureCustomerFor(User $user): string;

    /** Crée un SetupIntent pour enregistrer une nouvelle carte */
    public function createSetupIntent(string $customerId, string $usage = 'on_session'): PaymentResponse;

    /** Liste les cartes (PaymentMethods) associées au client */
    public function listCards(string $customerId): array;

    /** Détache une carte (supprime du customer) */
    public function detachPaymentMethod(string $paymentMethodId): void;

    // --- Webhooks ---
    /**
     * Vérifie la signature Stripe (ou autre) et construit un événement webhook sécurisé.
     * @return mixed
     */
    public function constructEventFromWebhook(string $payload, string $sigHeader, string $endpointSecret);

    /**
     * Analyse l’événement webhook et retourne un tableau avec le type et l’objet (pour traitement dans le contrôleur)
     * @return array{0: ?string, 1: ?array}
     */
    public function handleWebhook(array $event): array;
}
