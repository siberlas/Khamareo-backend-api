<?php

namespace App\Payment\Controller;

use App\Cart\Entity\Cart;
use App\Order\Entity\Order;
use App\Order\Entity\OrderItem;
use App\Payment\Entity\Payment;
use App\User\Entity\Address;
use App\Shipping\Entity\ShippingMethod;
use App\Shared\Enum\OrderStatus;
use App\Shared\Enum\PaymentStatus;
use App\Marketing\Repository\PromoCodeRepository;
use App\Shared\Repository\CurrencyRepository;
use App\Marketing\Service\PromoCodeApplicationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use App\Payment\StripePaymentProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

#[AsController]
class CheckoutController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Security $security,
        private readonly StripePaymentProvider   $stripeProvider,
        private PromoCodeRepository $promoCodeRepository,
        private PromoCodeApplicationService $promoCodeApplicationService,
        private LoggerInterface $logger,
        private RequestStack $requestStack,
        private readonly CurrencyRepository $currencyRepository, // 🆕
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user       = $this->security->getUser();
        $guestToken = $request->query->get('guestToken');
        $currencyCode = strtoupper($request->query->get('currency', 'EUR'));

        $data       = json_decode($request->getContent(), true) ?? [];

        $billingAddressIri  = $data['billingAddress']  ?? null;
        $deliveryAddressIri = $data['deliveryAddress'] ?? null;
        $shippingMethodIri  = $data['shippingMethod']  ?? null;

        $currency = $this->currencyRepository->findOneBy(['code' => $currencyCode]);
        if (!$currency) {
            throw new BadRequestException("Devise invalide : {$currencyCode}");
        }

        // ============================================================
        // 1) Charger le panier
        // ============================================================
        if ($user) {
            $cart = $this->em->getRepository(Cart::class)->findOneBy([
                'owner'    => $user,
                'isActive' => true,
            ]);
        } else {
            if (!$guestToken) {
                throw new BadRequestException('guestToken manquant.');
            }
            $cart = $this->em->getRepository(Cart::class)->findOneBy([
                'guestToken' => $guestToken,
                'isActive'   => true,
            ]);
        }

        if (!$cart || $cart->getItems()->isEmpty()) {
            throw new BadRequestException('Panier vide ou introuvable.');
        }

        if (!$cart->getPaymentIntentId()) {
            throw new BadRequestException("PaymentIntent introuvable. Le paiement n’a pas été initialisé.");
        }

        // ============================================================
        // 2) Vérification des adresses
        // ============================================================
        if (!$billingAddressIri || !$deliveryAddressIri) {
            throw new BadRequestException("Adresses requises.");
        }

        $billingSource  = $this->resolveAddressInput($billingAddressIri);
        $deliverySource = $this->resolveAddressInput($deliveryAddressIri);


        if (!$billingSource || !$deliverySource) {
            throw new BadRequestException("Adresse de livraison ou facturation invalide.");
        }

        // ============================================================
        // 2b) Création des SNAPSHOTS (copies figées pour la commande)
        // ============================================================

        // --- SNAPSHOT BILLING ---
        $billingSnapshot = (new Address())
            ->setAddressKind($billingSource->getAddressKind())
            ->setStreetAddress($billingSource->getStreetAddress())
            ->setCity($billingSource->getCity())
            ->setPostalCode($billingSource->getPostalCode())
            ->setCountry($billingSource->getCountry())
            ->setLabel('Billing snapshot')
            ->setIsDefault(false)
            ->setOwner(null);

        // Champs persos
        $billingSnapshot
            ->setCivility($billingSource->getCivility())
            ->setFirstName($billingSource->getFirstName())
            ->setLastName($billingSource->getLastName())
            ->setPhone($billingSource->getPhone());

        // Champs business
        $billingSnapshot
            ->setIsBusiness($billingSource->isBusiness())
            ->setCompanyName($billingSource->getCompanyName());

        // Aucun champs relay ici (billing n'est jamais relay)

        $this->em->persist($billingSnapshot);

        // --- SNAPSHOT SHIPPING ---
        $shippingSnapshot = (new Address())
            ->setAddressKind($deliverySource->getAddressKind())
            ->setStreetAddress($deliverySource->getStreetAddress())
            ->setCity($deliverySource->getCity())
            ->setPostalCode($deliverySource->getPostalCode())
            ->setCountry($deliverySource->getCountry())
            ->setLabel('Shipping snapshot')
            ->setIsDefault(false)
            ->setOwner(null);

        // Champs persos
        $shippingSnapshot
            ->setCivility($deliverySource->getCivility())
            ->setFirstName($deliverySource->getFirstName())
            ->setLastName($deliverySource->getLastName())
            ->setPhone($deliverySource->getPhone());

        // Champs business
        $shippingSnapshot
            ->setIsBusiness($deliverySource->isBusiness())
            ->setCompanyName($deliverySource->getCompanyName());

        // Champs relay (uniquement si addressKind = relay)
        if ($deliverySource->getAddressKind() === 'relay') {
            $shippingSnapshot
                ->setIsRelayPoint(true)
                ->setRelayPointId($deliverySource->getRelayPointId())
                ->setRelayCarrier($deliverySource->getRelayCarrier());
        }

        $this->em->persist($shippingSnapshot);

        // ============================================================
        // 3) Méthode de livraison
        // ============================================================
        if (!$shippingMethodIri) {
            throw new BadRequestException("Méthode de livraison requise.");
        }

        $shippingMethod = $this->em->getRepository(ShippingMethod::class)->find(basename($shippingMethodIri));
        if (!$shippingMethod) {
            throw new BadRequestException("Méthode de livraison invalide.");
        }

        // ============================================================
        // 4) Recalcul total
        // ============================================================
        $itemsSubtotal = $cart->getSubtotal();                      // Items seuls
        $discountAmount = $cart->getDiscountAmount() ? (float) $cart->getDiscountAmount() : 0;
        $subtotalAfterDiscount = $itemsSubtotal - $discountAmount;  // Items - promo
        $shippingCost = $cart->getShippingCost() ?? 0;
        $computedTotal = $subtotalAfterDiscount + $shippingCost;

        // ============================================================
        // 5) Vérification PaymentIntent Stripe
        // ============================================================
        $pi = $this->stripeProvider->retrievePaymentIntent($cart->getPaymentIntentId());
        $piAmount = $pi->amount / 100;

        if (strtoupper($pi->currency) !== $currencyCode) {
            throw new BadRequestException(
                "Devise PaymentIntent ({$pi->currency}) ne correspond pas à la devise demandée ({$currencyCode})."
            );
        }

        if (abs($piAmount - $computedTotal) > 0.01) {
            throw new BadRequestException("Montant Stripe invalide. Veuillez rafraîchir le panier.");
        }

        // ============================================================
        // 6) Création Order
        // ============================================================
        $currentRequest = $this->requestStack->getCurrentRequest();
        $locale = $currentRequest?->getLocale() ?? 'fr';
        $order = new Order();
        $order
            ->setStatus(OrderStatus::PENDING)
            ->setShippingMethod($shippingMethod)
            ->setBillingAddress($billingSnapshot)
            ->setShippingAddress($shippingSnapshot)
            ->setShippingCost($shippingCost)
            ->setTotalAmount($piAmount)
            ->setCurrency($currencyCode)
            ->setLocale($locale);

        
        // 🆕 Transférer le code promo du panier à la commande
        if ($cart->getPromoCode()) {
            $order->setPromoCode($cart->getPromoCode())
            ->setDiscountAmount($cart->getDiscountAmount());
        }

        if ($user) {
            // Utilisateur connecté
            $order->setOwner($user);
        } else {

            // -----------------------------
            // INVITÉ : détermination email
            // -----------------------------
            $guestEmailFromPayload = null;
            if (is_array($billingAddressIri) && !empty($billingAddressIri['email'])) {
                $guestEmailFromPayload = trim($billingAddressIri['email']);
            }

           // Fallback sur le user invité attaché au panier
            $guestUser   = $cart->getOwner();
            $guestEmail  = $guestEmailFromPayload ?? $guestUser?->getEmail();

            if (!$guestEmail) {
                throw new BadRequestException("Impossible de déterminer l'email invité.");
            }

            $order
                ->setGuestEmail($guestEmail)
                ->setGuestFirstName($billingSource->getFirstName())
                ->setGuestLastName($billingSource->getLastName())
                ->setGuestPhone($billingSource->getPhone());
        }

        // Point relais uniquement si shipping address est relay
        if ($deliverySource->getAddressKind() === 'relay') {
            $order
                ->setIsRelayPoint(true)
                ->setRelayPointId($deliverySource->getRelayPointId())
                ->setRelayCarrier($deliverySource->getRelayCarrier());
        }

        // ============================================================
        // 7) OrderItems
        // ============================================================
        foreach ($cart->getItems() as $cartItem) {
            $item = (new OrderItem())
                ->setCustomerOrder($order)
                ->setProduct($cartItem->getProduct())
                ->setQuantity($cartItem->getQuantity())
                ->setUnitPrice($cartItem->getUnitPrice());

            $this->em->persist($item);
        }

        // ============================================================
        // 8) Paiement local
        // ============================================================
        $payment = (new Payment())
            ->setOrder($order)
            ->setProvider('stripe')
            ->setStatus(PaymentStatus::PENDING)
            ->setProviderPaymentId($pi->id)
            ->setClientSecret($cart->getPaymentClientSecret())
            ->setAmount($piAmount);
        $this->em->persist($payment);

        // ============================================================
        // 🆕 9) Marquer le code promo comme utilisé
        // ============================================================
        if ($cart->getPromoCode()) {
            try {
                $promoCode = $this->promoCodeRepository->findOneBy([
                    'code' => $cart->getPromoCode()
                ]);
                
                if ($promoCode && $promoCode->isValid()) {
                    $this->promoCodeApplicationService->markAsUsed($promoCode);
                }
            } catch (\Exception $e) {
                $this->logger->error('Failed to mark promo code as used', [
                    'code' => $cart->getPromoCode(),
                    'error' => $e->getMessage()
                ]);
                // Ne pas bloquer la commande si l'update du promo échoue
            }
        }

        // ============================================================
        // 10) Sauvegarde
        // ============================================================
        $this->em->persist($order);
        $this->em->persist($payment);

        // ============================================================
        // 11) Nettoyage du panier après commande
        // ============================================================
        foreach ($cart->getItems() as $cartItem) {
            $this->em->remove($cartItem);
        }

        $cart
            ->setIsActive(false)
            ->setPaymentIntentId(null)
            ->setPaymentClientSecret(null)
            ->setShippingCost(null)
            ->setPromoCode(null)        // 🆕 Nettoyer le promo
            ->setDiscountAmount(null);  // 🆕 Nettoyer la réduction

        // Si invité, on ne supprime pas le user invité
        // mais on délie le panier de ce user pour éviter collisions
        if (!$user) {
            $cart->setOwner(null);
        }

        $this->em->flush();

        return $this->json([
            'success'     => true,
            'orderId'     => $order->getId(),
            'orderNumber' => $order->getOrderNumber(),
            'currency'    => $currencyCode,
        ]);
    }

    private function resolveAddressInput(array|string $input): Address
    {
        // Si c’est une string → IRI
        if (is_string($input)) {
            $id = $this->extractIdFromIri($input);
            $address = $this->em->getRepository(Address::class)->find($id);

            if (!$address) {
                throw new BadRequestException("Adresse invalide : $input");
            }
            return $address;
        }

        // Sinon → objet JSON → créer un snapshot
        if (is_array($input)) {
            $snapshot = (new Address())
                ->setLabel('Adresse checkout')
                ->setFirstName($input['firstName'] ?? null)
                ->setLastName($input['lastName'] ?? null)
                ->setStreetAddress($input['streetAddress'] ?? '')
                ->setPostalCode($input['postalCode'] ?? '')
                ->setCity($input['city'] ?? '')
                ->setCountry($input['country'] ?? 'FR')
                ->setPhone($input['phone'] ?? null)
                ->setOwner(null) // snapshot détaché
                ->setIsDefault(false);

            if (!empty($input['isRelayPoint'])) {
                $snapshot->setIsRelayPoint(true);
                $snapshot->setRelayPointId($input['relayPointId'] ?? null);
                $snapshot->setRelayCarrier($input['relayCarrier'] ?? null);
            }

            $this->em->persist($snapshot);
            return $snapshot;
        }

        throw new BadRequestException("Format d’adresse invalide.");
    }

    private function extractIdFromIri(string $iri): int
    {
        // Capture le dernier nombre de l’IRI, peu importe le préfixe
        if (preg_match('/\/(\d+)$/', $iri, $matches)) {
            return (int) $matches[1];
        }

        throw new BadRequestException("Impossible d’extraire l’ID de l’IRI : $iri");
    }
}
