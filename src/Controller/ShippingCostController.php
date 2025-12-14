<?php

namespace App\Controller;

use App\Entity\ShippingMethod;
use App\Entity\Address;
use App\Repository\ProductRepository;
use App\Service\CartWeightCalculator;
use App\Service\ShippingRateCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use App\Repository\CartRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Annotation\Route;

#[AsController]
final class ShippingCostController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ShippingRateCalculator $rateCalculator,
        private CartWeightCalculator $weightCalculator,
        private ProductRepository $productRepository, // pour charger les produits du payload
        private CartRepository $cartRepository,
        private Security $security,
    ) {}

    #[Route('/api/shipping_methods/{id}/calculate', name: 'shipping_calculate', methods: ['POST'])]
    public function __invoke(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            throw new BadRequestException("Requête JSON invalide.");
        }

        $shippingMethod = $this->em->getRepository(ShippingMethod::class)->find($id);
        if (!$shippingMethod) {
            throw new BadRequestException("Méthode de livraison introuvable.");
        }

        $items = $payload['items'] ?? [];

        if (empty($items)) {
            throw new BadRequestException("Items requis pour le calcul.");
        }

        $shippingAddressIri = $payload['shippingAddress'] ?? null;
        $country = null;

        if ($shippingAddressIri) {
            $addressId = basename($shippingAddressIri);
            $shippingAddress = $this->em->getRepository(Address::class)->find($addressId);
            if (!$shippingAddress) {
                throw new BadRequestException("Adresse de livraison invalide.");
            }
            $country = (string) $shippingAddress->getCountry();
        }
        elseif (!empty($payload['country'])) {
            $country = (string) $payload['country'];
        } else {
            throw new BadRequestException("Le pays de livraison est requis.");
        }
      

        // Poids total depuis le payload (pas besoin du Cart complet)
        $totalWeight = $this->weightCalculator->getTotalWeightFromItems(
            $items,
            fn(string $slug) => $this->productRepository->findOneBy(['slug' => $slug])
        );

        $zone = $this->mapCountryToZone($country);

        // Récupère le tarif + coût
        $rate = $this->rateCalculator->resolveRate($shippingMethod, $zone, $totalWeight);
        $shippingCost = $rate ? (float) $rate->getPrice() : (float) $shippingMethod->getPrice();

        $user = $this->security->getUser();
        $guestToken = $payload['guestToken'] ?? null;

        $cart = null;
        if ($user) {
            $cart = $this->cartRepository->findOneBy(['owner' => $user, 'isActive' => true]);
        } elseif ($guestToken) {
            $cart = $this->cartRepository->findOneBy(['guestToken' => $guestToken, 'isActive' => true]);
        }

        if ($cart) {
            $cart->setShippingCost($shippingCost);
            $this->em->flush();
        }


        // 🆕 Calculer le récapitulatif complet
        $itemsSubtotal = 0;
        foreach ($items as $item) {
            $product = $this->productRepository->findOneBy(['slug' => $item['slug']]);
            if ($product) {
                $itemsSubtotal += $item['quantity'] * $product->getPrice();
            }
        }

        

        $discountAmount = $cart ? (float) ($cart->getDiscountAmount() ?? 0) : 0;
        $subtotalAfterDiscount = $itemsSubtotal - $discountAmount;
        $total = $subtotalAfterDiscount + $shippingCost;

        return $this->json([
            'carrier'        => $shippingMethod->getName(),
            'carrierCode'    => $shippingMethod->getCarrierCode(),
            'zone'           => $zone,
            'totalWeight'    => $totalWeight,
            'shippingCost'   => $shippingCost,
            'shippingRateId' => $rate?->getId(), // important pour le checkout
            'itemsSubtotal'  => $itemsSubtotal,
            'discountAmount' => $discountAmount,
            'promoCode'      => $cart?->getPromoCode(),
            'subtotalAfterDiscount' => $subtotalAfterDiscount,
            'total'          => $total,
        ]);
    }

    private function mapCountryToZone(string $country): string
    {
        $up = strtoupper(trim($country));
        if ($up === 'FR' || $up === 'FRANCE') return 'FR';
        $eu = ['BE','LU','NL','DE','ES','PT','IT','IE','AT','CZ','DK','EE','FI','GR','HR','HU','LT','LV','MT','PL','RO','SE','SI','SK','BG'];
        return in_array($up, $eu, true) ? 'EU' : 'INTL';
    }
}
