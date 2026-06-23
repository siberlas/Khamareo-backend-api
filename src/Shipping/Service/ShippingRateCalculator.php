<?php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use App\Shipping\Entity\CarrierMode;
use App\Shipping\Entity\ShippingMethod;
use App\Shipping\Entity\ShippingRate;
use App\Shipping\Repository\CarrierModeRepository;
use App\Shipping\Repository\ShippingRateRepository;

class ShippingRateCalculator
{
    public function __construct(
        private ShippingRateRepository $shippingRateRepository,
        private CarrierModeRepository $carrierModeRepository,
        private ShippingZoneMapper $zoneMapper,
    ) {}

    /**
     * Calcule depuis un Order (utilise le CarrierMode si disponible, sinon ShippingMethod.price).
     */
    public function calculate(Order $order): float
    {
        $carrierMode = $this->resolveCarrierModeFromOrder($order);

        if ($carrierMode) {
            $address = $order->getShippingAddress();
            $countryCode = (string) ($address?->getCountry() ?? 'FR');
            $zone = $this->zoneMapper->mapCountryToZone($countryCode);
            $weightGrams = $this->getTotalWeightGramsFromOrder($order);
            $rate = $this->shippingRateRepository->findBestRate($carrierMode, $zone, $weightGrams, $countryCode);
            if ($rate) {
                return (float) $rate->getPrice();
            }
            return (float) ($carrierMode->getBasePrice() ?? 0);
        }

        // Fallback: prix de base de la méthode de livraison legacy
        $method = $order->getShippingMethod();
        return $method ? (float) $method->getPrice() : 0.0;
    }

    /**
     * Calcule depuis un CarrierMode, une zone et un poids en grammes.
     */
    public function calculateFromCarrierMode(CarrierMode $carrierMode, string $zone, int $weightGrams, ?string $countryCode = null): float
    {
        $rate = $this->shippingRateRepository->findBestRate($carrierMode, $zone, $weightGrams, $countryCode);
        if ($rate) {
            return (float) $rate->getPrice();
        }
        return (float) ($carrierMode->getBasePrice() ?? 0);
    }

    /**
     * @deprecated Use calculateFromCarrierMode() instead.
     */
    public function calculateFromMethod(ShippingMethod $method, string $zone, float $weight): float
    {
        return (float) $method->getPrice();
    }

    /**
     * @deprecated Use shippingRateRepository->findBestRate() with CarrierMode directly.
     */
    public function resolveRate(ShippingMethod $method, string $zone, float $weight): ?ShippingRate
    {
        return null;
    }

    private function resolveCarrierModeFromOrder(Order $order): ?CarrierMode
    {
        // Priorité : le mode exact sélectionné par le client au checkout
        if ($order->getCarrierMode()) {
            return $order->getCarrierMode();
        }

        // Fallback legacy : on déduit depuis le Carrier (ordres créés avant migration)
        $carrier = $order->getCarrier();
        if (!$carrier) {
            return null;
        }
        $modes = $this->carrierModeRepository->findBy(['carrier' => $carrier, 'isActive' => true]);
        return $modes[0] ?? null;
    }

    private const DEFAULT_PRODUCT_WEIGHT_GRAMS = 500;

    private function getTotalWeightGramsFromOrder(Order $order): int
    {
        $total = 0;
        foreach ($order->getItems() as $item) {
            $p = $item->getProduct();
            if (!$p) {
                continue;
            }
            $qty = (int) $item->getQuantity();
            if ($p->getWeightGrams() !== null) {
                $total += $p->getWeightGrams() * $qty;
            } elseif ($p->getWeight() !== null) {
                $total += (int) round($p->getWeight() * 1000) * $qty;
            } else {
                // Produit sans poids configuré : fallback 500g par unité
                $total += self::DEFAULT_PRODUCT_WEIGHT_GRAMS * $qty;
            }
        }
        // Sécurité : si le panier est vide ou tous les produits supprimés
        return max($total, self::DEFAULT_PRODUCT_WEIGHT_GRAMS);
    }

}
