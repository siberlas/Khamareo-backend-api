<?php

namespace App\Payment\Controller;

use App\Cart\Entity\Cart;
use App\User\Entity\Address;
use App\Shipping\Repository\ShippingRateRepository;
use App\Shipping\Repository\CarrierModeRepository;
use App\Cart\Service\CartWeightCalculator;
use App\Shipping\Service\ShippingZoneMapper;
use App\Payment\Provider\StripePaymentProvider;
use App\Shipping\Service\CheckoutEstimationService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use App\Shared\Repository\CurrencyRepository;
use App\Shared\Entity\StoreSettings;

#[AsController]
class CheckoutPaymentIntentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly Security                $security,
        private readonly CartWeightCalculator    $weightCalculator,
        private readonly ShippingRateRepository  $rateRepository,
        private readonly CarrierModeRepository   $carrierModeRepository,
        private readonly StripePaymentProvider   $stripeProvider,
        private readonly CurrencyRepository      $currencyRepository,
        private readonly ShippingZoneMapper      $zoneMapper,
        private readonly CheckoutEstimationService $checkoutEstimationService,
        private readonly LoggerInterface         $shippingLogger,
    ) {}

    #[Route('/api/checkout/payment-intent', name: 'checkout_payment_intent', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user         = $this->security->getUser();
        $guestToken   = $request->query->get('guestToken');
        $currencyCode = strtoupper($request->query->get('currency', 'EUR'));

        $data = json_decode($request->getContent(), true) ?? [];

        $carrierModeId  = $data['carrierModeId']   ?? null;
        $billingInput   = $data['billingAddress']  ?? null;
        $deliveryInput  = $data['deliveryAddress'] ?? null;
        $shippingRateId = $data['shippingRateId']  ?? null;
        $deliveryPhoneOverride = trim((string) ($data['deliveryPhone'] ?? ''));

        // Valider la devise
        $currency = $this->currencyRepository->findOneBy(['code' => $currencyCode]);
        if (!$currency) {
            throw new BadRequestException("Devise invalide : {$currencyCode}");
        }

        // 1) Récupération du panier
        if ($user) {
            $cart = $this->em->getRepository(Cart::class)->findOneBy(['owner' => $user, 'isActive' => true]);
        } else {
            if (!$guestToken) {
                throw new BadRequestException('guestToken manquant pour un invité.');
            }
            $cart = $this->em->getRepository(Cart::class)->findOneBy(['guestToken' => $guestToken, 'isActive' => true]);
        }

        if (!$cart || $cart->getItems()->isEmpty()) {
            throw new BadRequestException("Panier vide ou introuvable.");
        }

        // Retirer automatiquement les produits passés en rupture depuis leur ajout au
        // panier — sinon rien n'empêche de payer pour un produit indisponible (aucun
        // contrôle de stock n'existe ailleurs dans le tunnel de paiement).
        $removedOutOfStock = [];
        foreach ($cart->getItems()->toArray() as $item) {
            // Les livres numériques ont un stock illimité : jamais retirés.
            if ($item->getProduct()->requiresShipping() && $item->getProduct()->getStock() <= 0) {
                $removedOutOfStock[] = $item->getProduct()->getName();
                $cart->removeItem($item);
                $this->em->remove($item);
            }
        }

        if ($cart->getItems()->isEmpty()) {
            throw new BadRequestException(
                'Tous les produits de votre panier sont désormais en rupture de stock : '
                . implode(', ', $removedOutOfStock) . '.'
            );
        }

        // Vérification que chaque produit restant a un prix
        foreach ($cart->getItems() as $item) {
            if (!$item->getProduct()->getPrice()) {
                throw new BadRequestException(
                    "Le produit '{$item->getProduct()->getName()}' n'a pas de prix."
                );
            }
        }

        // Panier 100 % numérique : aucune livraison, aucun transporteur, aucune
        // adresse — seul l'email du client (porté par le owner du panier) suffit.
        $digitalOnly = $cart->hasOnlyDigitalItems();

        if ($digitalOnly) {
            if (!$cart->getOwner()?->getEmail()) {
                throw new BadRequestException("Email client requis pour finaliser la commande numérique.");
            }
            $billingAddress       = null;
            $carrierMode          = null;
            $deliveryAddress      = null;
            $zone                 = null;
            $weightGrams          = 0;
            $shippingCost         = 0.0;
            $carrierShippingCost  = 0.0;
            $zoneThreshold        = null;
            $carrierName          = '';
        } else {
            if (!$billingInput) {
                throw new BadRequestException("billingAddress est requise.");
            }
            $billingAddress = $this->resolveAddressInput($billingInput);

            if (!$carrierModeId || !$deliveryInput) {
                throw new BadRequestException("carrierModeId et deliveryAddress sont requis pour une commande avec livraison.");
            }

            $deliveryAddress = $this->resolveAddressInput($deliveryInput);

            // Mondial Relay Domicile exige un mobile dédié (SMS de suivi + code de livraison),
            // distinct du téléphone de l'adresse résolue. On ne mute jamais l'adresse résolue
            // en place (elle peut être l'adresse par défaut sauvegardée du client) : on clone
            // dans une nouvelle Address avec le mobile fourni, utilisée pour cette commande only.
            if ($deliveryPhoneOverride !== '' && $deliveryPhoneOverride !== $deliveryAddress->getPhone()) {
                $deliveryAddress = $this->cloneAddressWithPhone($deliveryAddress, $deliveryPhoneOverride);
                $this->em->flush();
            }

            // 3) Récupération du CarrierMode
            $carrierMode = $this->carrierModeRepository->find((int) $carrierModeId);
            if (!$carrierMode) {
                throw new BadRequestException("Mode de livraison invalide.");
            }

            [$shippingCost, $carrierShippingCost, $zone, $zoneThreshold, $carrierName] =
                $this->computeShipping($cart, $carrierMode, $deliveryAddress, $shippingRateId);
        }

        $itemsSubtotal         = $cart->getSubtotal();
        $discountAmount        = $cart->getDiscountAmount() ? (float) $cart->getDiscountAmount() : 0;
        $subtotalAfterDiscount = $itemsSubtotal - $discountAmount;
        $total                 = $subtotalAfterDiscount + $shippingCost;
        $stripeCurrency        = 'eur';

        $resp = $this->stripeProvider->createOrUpdateCartPaymentIntent(
            $cart,
            (int) round($total * 100),
            $stripeCurrency,
            $shippingCost,
            $carrierName,
            [
                'billing_address_id'  => $billingAddress ? (string) $billingAddress->getId() : '',
                'delivery_address_id' => $deliveryAddress ? (string) $deliveryAddress->getId() : '',
                'is_relay_point'      => $deliveryAddress && $deliveryAddress->isRelayPoint() ? '1' : '0',
                'relay_point_id'      => $deliveryAddress?->getRelayPointId() ?? '',
                'relay_carrier'       => $deliveryAddress?->getRelayCarrier() ?? '',
                'carrier_mode_id'     => $carrierMode ? (string) $carrierMode->getId() : '',
                'shipping_cost'       => (string) $shippingCost,
                'digital_only'        => $digitalOnly ? '1' : '0',
                'promo_code'          => $cart->getPromoCode() ?? '',
                'promo_codes'         => $cart->getPromoCodesData() ? json_encode($cart->getPromoCodesData()) : '',
                'discount_amount'     => (string) ($cart->getDiscountAmount() ?? '0'),
                'currency'            => $currencyCode,
            ]
        );

        $cart->setPaymentIntentId($resp->paymentId);
        $cart->setPaymentClientSecret($resp->clientSecret);
        $cart->setShippingCost($shippingCost);
        $cart->setCarrierShippingCost($carrierShippingCost);
        $cart->setDeliveryAddress($deliveryAddress);
        $cart->setBillingAddress($billingAddress);
        $this->em->flush();

        return $this->json([
            'paymentIntentId'  => $resp->paymentId,
            'clientSecret'     => $resp->clientSecret,
            'total'            => $total,
            'subtotal'         => $itemsSubtotal,
            'shippingCost'     => $shippingCost,
            'currency'         => $currencyCode,
            'currencySymbol'   => $currency->getSymbol(),
            'itemsSubtotal'    => $itemsSubtotal,
            'discountAmount'   => $discountAmount,
            'promoCode'        => $cart->getPromoCode(),
            'requiresShipping' => !$digitalOnly,
            'freeShipping'          => $shippingCost === 0.0,
            'freeShippingThreshold' => $zoneThreshold,
            'shippingZone'          => $zone,
            'removedOutOfStock'     => $removedOutOfStock,
        ]);
    }

    /**
     * @return array{0: float, 1: float, 2: string, 3: float|null, 4: string} shippingCost, carrierShippingCost, zone, zoneThreshold, carrierName
     */
    private function computeShipping(Cart $cart, \App\Shipping\Entity\CarrierMode $carrierMode, Address $deliveryAddress, ?string $shippingRateId): array
    {
        $weightKg    = $this->weightCalculator->getTotalWeightFromCart($cart);
        $weightGrams = (int) round($weightKg * 1000);
        $zone        = $this->zoneMapper->mapCountryToZone($deliveryAddress->getCountry());

        $rate = $shippingRateId ? $this->rateRepository->find((int) $shippingRateId) : null;

        if ($rate && (
            $rate->getZone() !== $zone ||
            $rate->getCarrierMode() !== $carrierMode ||
            $weightGrams < $rate->getMinWeightGrams() ||
            $weightGrams > $rate->getMaxWeightGrams()
        )) {
            $rate = null;
        }

        $countryCode = $deliveryAddress->getCountry();

        if (!$rate) {
            $rate = $this->rateRepository->findBestRate($carrierMode, $zone, $weightGrams, $countryCode);
        }

        $shippingCost = $rate
            ? (float) $rate->getPrice()
            : (float) ($carrierMode->getBasePrice() ?? 0);

        // Recalcul précis (colisage + poids volumétrique + CAE + suppléments —
        // mêmes règles que la génération réelle de l'étiquette), avec repli
        // silencieux sur le calcul ci-dessus si l'estimation échoue. Seuls les
        // articles physiques entrent dans le colisage (les livres numériques
        // n'ont ni poids ni dimensions).
        $cartItems = array_map(fn ($item) => [
            'product' => $item->getProduct(),
            'quantity' => $item->getQuantity(),
        ], $cart->getShippableItems());

        $estimation = $this->checkoutEstimationService->estimate($cartItems, $carrierMode, $countryCode, $deliveryAddress->getPostalCode());
        if ($estimation->success) {
            $shippingCost = $estimation->totalPrice;
        } else {
            $this->shippingLogger->warning('Estimation checkout précise indisponible au paiement, repli sur le tarif de base', [
                'carrierModeId' => $carrierMode->getId(),
                'reason' => $estimation->error,
            ]);
        }

        // Tarif réel que Khamareo paie au transporteur (avant toute remise livraison offerte)
        $carrierShippingCost = $shippingCost;

        // 4b) Livraison offerte si le seuil de la zone est atteint
        $storeSettings = $this->em->getRepository(StoreSettings::class)->findOneBy([]);
        $zoneThreshold = null;
        if ($storeSettings && $storeSettings->isFreeShippingEnabled()) {
            $zoneThreshold = $storeSettings->getThresholdForZone($zone);
            if ($zoneThreshold !== null && $zoneThreshold > 0 && $cart->getSubtotal() >= $zoneThreshold) {
                $shippingCost = 0;
            }
        }

        $carrierName = ($carrierMode->getCarrier()?->getName() ?? '')
            . ' - '
            . ($carrierMode->getShippingMode()?->getName() ?? '');

        return [$shippingCost, $carrierShippingCost, $zone, $zoneThreshold, $carrierName];
    }

    // =========================================================================
    // UTILITAIRES
    // =========================================================================

    private function resolveAddressInput(array|string $input): Address
    {
        if (is_string($input)) {
            $id = $this->extractIdFromIri($input);
            $address = $this->em->getRepository(Address::class)->find($id);
            if (!$address) {
                throw new BadRequestException("Adresse invalide : $input");
            }
            return $address;
        }

        if (is_array($input)) {
            $snapshot = (new Address())
                ->setLabel('Adresse checkout')
                ->setFirstName($input['firstName'] ?? null)
                ->setLastName($input['lastName'] ?? null)
                ->setStreetAddress($input['streetAddress'] ?? '')
                ->setAddressComplement($input['addressComplement'] ?? null)
                ->setPostalCode($input['postalCode'] ?? '')
                ->setCity($input['city'] ?? '')
                ->setCountry($input['country'] ?? 'FR')
                ->setState($input['state'] ?? null)
                ->setPhone($input['phone'] ?? null)
                ->setOwner(null)
                ->setIsDefault(false);

            if (!empty($input['isRelayPoint'])) {
                $snapshot->setAddressKind('relay');
                $snapshot->setIsRelayPoint(true);
                $snapshot->setRelayPointId($input['relayPointId'] ?? null);
                $snapshot->setRelayCarrier($input['relayCarrier'] ?? null);
            }

            $this->em->persist($snapshot);
            return $snapshot;
        }

        throw new BadRequestException("Format d'adresse invalide.");
    }

    private function cloneAddressWithPhone(Address $source, string $phone): Address
    {
        $clone = (new Address())
            ->setLabel($source->getLabel())
            ->setCivility($source->getCivility())
            ->setFirstName($source->getFirstName())
            ->setLastName($source->getLastName())
            ->setStreetAddress($source->getStreetAddress())
            ->setAddressComplement($source->getAddressComplement())
            ->setPostalCode($source->getPostalCode())
            ->setCity($source->getCity())
            ->setCountry($source->getCountry())
            ->setState($source->getState())
            ->setPhone($phone)
            ->setOwner(null)
            ->setIsDefault(false);

        if ($source->isRelayPoint()) {
            $clone->setAddressKind('relay');
            $clone->setIsRelayPoint(true);
            $clone->setRelayPointId($source->getRelayPointId());
            $clone->setRelayCarrier($source->getRelayCarrier());
        }

        $this->em->persist($clone);
        return $clone;
    }

    private function extractIdFromIri(string $iri): int
    {
        if (preg_match('/\/(\d+)$/', $iri, $matches)) {
            return (int) $matches[1];
        }
        throw new BadRequestException("Impossible d'extraire l'ID de l'IRI : $iri");
    }

}
