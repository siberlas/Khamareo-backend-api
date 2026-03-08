<?php

namespace App\Payment\Controller;

use App\Cart\Entity\Cart;
use App\User\Entity\Address;
use App\Shipping\Entity\CarrierMode;
use App\Shipping\Repository\ShippingRateRepository;
use App\Shipping\Repository\CarrierModeRepository;
use App\Cart\Service\CartWeightCalculator;
use App\Payment\Provider\StripePaymentProvider;
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
        private readonly ShippingRateRepository  $rateRepository,
        private readonly CarrierModeRepository   $carrierModeRepository,
        private readonly StripePaymentProvider   $stripeProvider,
        private readonly CurrencyRepository      $currencyRepository,
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

        if (!$carrierModeId || !$billingInput || !$deliveryInput) {
            throw new BadRequestException("carrierModeId, billingAddress et deliveryAddress sont requis.");
        }

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

        foreach ($cart->getItems() as $item) {
            $productPrice = $item->getProduct()->getPriceForCurrency($currencyCode);
            if (!$productPrice) {
                throw new BadRequestException(
                    "Le produit '{$item->getProduct()->getName()}' n'a pas de prix en {$currencyCode}."
                );
            }
        }

        // 2) Résolution des adresses
        $billingAddress  = $this->resolveAddressInput($billingInput);
        $deliveryAddress = $this->resolveAddressInput($deliveryInput);

        // 3) Récupération du CarrierMode
        $carrierMode = $this->carrierModeRepository->find((int) $carrierModeId);
        if (!$carrierMode) {
            throw new BadRequestException("Mode de livraison invalide.");
        }

        // 4) Calcul du coût de livraison
        $weightKg    = $this->weightCalculator->getTotalWeightFromCart($cart);
        $weightGrams = (int) round($weightKg * 1000);
        $zone        = $this->mapCountryToZone($deliveryAddress->getCountry());

        $rate = $shippingRateId ? $this->rateRepository->find((int) $shippingRateId) : null;

        if ($rate && (
            $rate->getZone() !== $zone ||
            $rate->getCarrierMode() !== $carrierMode ||
            $weightGrams < $rate->getMinWeightGrams() ||
            $weightGrams > $rate->getMaxWeightGrams()
        )) {
            $rate = null;
        }

        if (!$rate) {
            $rate = $this->rateRepository->findBestRate($carrierMode, $zone, $weightGrams);
        }

        $shippingCost = $rate
            ? (float) $rate->getPrice()
            : (float) ($carrierMode->getBasePrice() ?? 0);

        // 5) Total
        $itemsSubtotal         = $cart->getSubtotal();
        $discountAmount        = $cart->getDiscountAmount() ? (float) $cart->getDiscountAmount() : 0;
        $subtotalAfterDiscount = $itemsSubtotal - $discountAmount;
        $total                 = $subtotalAfterDiscount + $shippingCost;

        $stripeCurrency = strtolower($currencyCode);

        $carrierName = ($carrierMode->getCarrier()?->getName() ?? '')
            . ' - '
            . ($carrierMode->getShippingMode()?->getName() ?? '');

        // 6) Créer / mettre à jour PaymentIntent Stripe
        $resp = $this->stripeProvider->createOrUpdateCartPaymentIntent(
            $cart,
            (int) round($total * 100),
            $stripeCurrency,
            $shippingCost,
            $carrierName,
            [
                'billing_address_id'  => (string) $billingAddress->getId(),
                'delivery_address_id' => (string) $deliveryAddress->getId(),
                'is_relay_point'      => $deliveryAddress->isRelayPoint() ? '1' : '0',
                'relay_point_id'      => $deliveryAddress->getRelayPointId() ?? '',
                'relay_carrier'       => $deliveryAddress->getRelayCarrier() ?? '',
                'carrier_mode_id'     => (string) $carrierMode->getId(),
                'shipping_cost'       => (string) $shippingCost,
                'promo_code'          => $cart->getPromoCode() ?? '',
                'discount_amount'     => (string) ($cart->getDiscountAmount() ?? '0'),
                'currency'            => $currencyCode,
            ]
        );

        // 7) Mettre à jour le panier
        $cart->setPaymentIntentId($resp->paymentId);
        $cart->setPaymentClientSecret($resp->clientSecret);
        $cart->setShippingCost($shippingCost);

        $this->em->flush();

        // 9) Réponse
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
        ]);
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
                ->setPostalCode($input['postalCode'] ?? '')
                ->setCity($input['city'] ?? '')
                ->setCountry($input['country'] ?? 'FR')
                ->setPhone($input['phone'] ?? null)
                ->setOwner(null)
                ->setIsDefault(false);

            if (!empty($input['isRelayPoint'])) {
                $snapshot->setIsRelayPoint(true);
                $snapshot->setRelayPointId($input['relayPointId'] ?? null);
                $snapshot->setRelayCarrier($input['relayCarrier'] ?? null);
            }

            $this->em->persist($snapshot);
            return $snapshot;
        }

        throw new BadRequestException("Format d'adresse invalide.");
    }

    private function extractIdFromIri(string $iri): int
    {
        if (preg_match('/\/(\d+)$/', $iri, $matches)) {
            return (int) $matches[1];
        }
        throw new BadRequestException("Impossible d'extraire l'ID de l'IRI : $iri");
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
