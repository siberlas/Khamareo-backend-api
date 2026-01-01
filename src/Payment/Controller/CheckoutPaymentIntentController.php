<?php

namespace App\Payment\Controller;

use App\Cart\Entity\Cart;
use App\User\Entity\Address;
use App\Shipping\Entity\ShippingMethod;
use App\Shipping\Repository\ShippingRateRepository;
use App\Cart\Service\CartWeightCalculator;
use App\Shipping\Service\ShippingRateCalculator;
use App\Payment\StripePaymentProvider;
use App\Shared\Service\CurrencyResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpKernel\Attribute\AsController;
use App\Shared\Repository\CurrencyRepository;

#[AsController]
class CheckoutPaymentIntentController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly Security                $security,
        private readonly CartWeightCalculator    $weightCalculator,
        private readonly ShippingRateCalculator  $rateCalculator,
        private readonly ShippingRateRepository  $rateRepository,
        private readonly StripePaymentProvider   $stripeProvider,
        private readonly CurrencyRepository      $currencyRepository, // 🆕

    ) {}

    #[Route('/api/checkout/payment-intent', name: 'checkout_payment_intent', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user       = $this->security->getUser();
        $guestToken = $request->query->get('guestToken');
        $currencyCode = strtoupper($request->query->get('currency', 'EUR'));

        $data = json_decode($request->getContent(), true) ?? [];

        $shippingMethodIri  = $data['shippingMethod']   ?? null;
        $billingInput       = $data['billingAddress']   ?? null;
        $deliveryInput      = $data['deliveryAddress']  ?? null;
        $shippingRateId     = $data['shippingRateId']   ?? null;

        if (!$shippingMethodIri || !$billingInput || !$deliveryInput) {
            throw new BadRequestException("shippingMethod, billingAddress et deliveryAddress sont requis.");
        }

        // 🆕 Valider que la devise existe
        $currency = $this->currencyRepository->findOneBy(['code' => $currencyCode]);
        if (!$currency) {
            throw new BadRequestException("Devise invalide : {$currencyCode}");
        }

        // ----------------------------------------------------------------------
        // 1) Récupération du panier
        // ----------------------------------------------------------------------
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

        foreach ($cart->getItems() as $item) {
            $productPrice = $item->getProduct()->getPriceForCurrency($currencyCode);
            if (!$productPrice) {
                throw new BadRequestException(
                    "Le produit '{$item->getProduct()->getName()}' n'a pas de prix en {$currencyCode}."
                );
            }
        }

        // ----------------------------------------------------------------------
        // 2) Résolution des adresses : IRI → BDD, objet JSON → snapshot
        // ----------------------------------------------------------------------
        $billingAddress  = $this->resolveAddressInput($billingInput);
        $deliveryAddress = $this->resolveAddressInput($deliveryInput);

        // ----------------------------------------------------------------------
        // 3) Récupération de la méthode de livraison
        // ----------------------------------------------------------------------
        $shippingMethodId = $this->extractIdFromIri($shippingMethodIri);
        $shippingMethod = $this->em->getRepository(ShippingMethod::class)->find($shippingMethodId);

        if (!$shippingMethod) {
            throw new BadRequestException("Méthode de livraison invalide.");
        }

        // ----------------------------------------------------------------------
        // 4) Calcul du coût de livraison
        // ----------------------------------------------------------------------
        $totalWeight = $this->weightCalculator->getTotalWeightFromCart($cart);
        $zone        = $this->mapCountryToZone($deliveryAddress->getCountry());

        $rate = $shippingRateId ? $this->rateRepository->find($shippingRateId) : null;

        if ($rate && (
            $rate->getZone() !== $zone ||
            $rate->getProvider() !== $shippingMethod->getCarrierCode() ||
            $totalWeight < $rate->getMinWeight() ||
            $totalWeight > $rate->getMaxWeight()
        )) {
            $rate = null;
        }

        $shippingCost = $rate
            ? (float)$rate->getPrice()
            : $this->rateCalculator->calculateFromMethod($shippingMethod, $zone, $totalWeight);

        // ----------------------------------------------------------------------
        // 5) Total
        // ----------------------------------------------------------------------
        $itemsSubtotal = $cart->getSubtotal(); // Items seuls
        $discountAmount = $cart->getDiscountAmount() ? (float) $cart->getDiscountAmount() : 0;
        $subtotalAfterDiscount = $itemsSubtotal - $discountAmount; // Items - promo
        $total = $subtotalAfterDiscount + $shippingCost; // + shipping
      
        $stripeCurrency = strtolower($currencyCode);


    

        // ----------------------------------------------------------------------
        // 6) Créer / mettre à jour PaymentIntent Stripe
        // ----------------------------------------------------------------------
        $resp = $this->stripeProvider->createOrUpdateCartPaymentIntent(
            $cart,
            (int) round($total * 100),
            $stripeCurrency,
            $shippingCost,
            $shippingMethod->getName(),
            [
                'billing_address_id'  => (string)$billingAddress->getId(),
                'delivery_address_id' => (string)$deliveryAddress->getId(),
                'is_relay_point'      => $deliveryAddress->isRelayPoint() ? '1' : '0',
                'relay_point_id'      => $deliveryAddress->getRelayPointId(),
                'relay_carrier'       => $deliveryAddress->getRelayCarrier(),
                'shipping_method_id'  => (string)$shippingMethod->getId(),
                'shipping_cost'       => (string)$shippingCost,
                'promo_code'          => $cart->getPromoCode() ?? '',
                'discount_amount'     => $cart->getDiscountAmount() ?? '0',
                'currency'            => $currencyCode, // 🆕 Stocker la devise
            ]
        );

        // ----------------------------------------------------------------------
        // 7) Mettre à jour le panier
        // ----------------------------------------------------------------------
        $cart->setPaymentIntentId($resp->paymentId);
        $cart->setPaymentClientSecret($resp->clientSecret);
        $cart->setShippingCost($shippingCost);

        $this->em->flush();

        // ----------------------------------------------------------------------
        // 8) Réponse
        // ----------------------------------------------------------------------
        return $this->json([
            'paymentIntentId'       => $resp->paymentId,
            'clientSecret'          => $resp->clientSecret,
            'total'                 => $total,
            'subtotal'              => $subtotalAfterDiscount, // Sous-total après promo
            'shippingCost'          => $shippingCost,
            'currency'              => $currencyCode,
            'currencySymbol'        => $currency->getSymbol(),
            // 🆕 Détails promo pour affichage
            'itemsSubtotal'         => $itemsSubtotal,
            'discountAmount'        => $discountAmount,
            'promoCode'             => $cart->getPromoCode(),
        ]);
    }

    // =========================================================================
    // 🧩 METHODES UTILITAIRES
    // =========================================================================

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


    private function mapCountryToZone(string $country): string
    {
        $up = strtoupper(trim($country));
        if ($up === 'FR' || $up === 'FRANCE') {
            return 'FR';
        }
        $eu = ['BE','LU','NL','DE','ES','PT','IT','IE','AT','CZ','DK','EE','FI','GR','HR','HU','LT','LV','MT','PL','RO','SE','SI','SK','BG'];
        return in_array($up, $eu, true) ? 'EU' : 'INTL';
    }
}
