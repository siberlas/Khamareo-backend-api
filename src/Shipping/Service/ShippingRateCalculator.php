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
    ) {}

    /**
     * Calcule depuis un Order (utilise le CarrierMode si disponible, sinon ShippingMethod.price).
     */
    public function calculate(Order $order): float
    {
        $carrierMode = $this->resolveCarrierModeFromOrder($order);

        if ($carrierMode) {
            $address = $order->getShippingAddress();
            $zone = $this->mapCountryToZone((string) ($address?->getCountry() ?? 'FR'));
            $weightGrams = $this->getTotalWeightGramsFromOrder($order);
            $rate = $this->shippingRateRepository->findBestRate($carrierMode, $zone, $weightGrams);
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
    public function calculateFromCarrierMode(CarrierMode $carrierMode, string $zone, int $weightGrams): float
    {
        $rate = $this->shippingRateRepository->findBestRate($carrierMode, $zone, $weightGrams);
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
        $carrier = $order->getCarrier();
        if (!$carrier) {
            return null;
        }
        $modes = $this->carrierModeRepository->findBy(['carrier' => $carrier, 'isActive' => true]);
        return $modes[0] ?? null;
    }

    private function getTotalWeightGramsFromOrder(Order $order): int
    {
        $total = 0;
        foreach ($order->getItems() as $item) {
            $p = $item->getProduct();
            if (!$p) {
                continue;
            }
            if ($p->getWeightGrams() !== null) {
                $total += $p->getWeightGrams() * (int) $item->getQuantity();
            } elseif ($p->getWeight() !== null) {
                $total += (int) round($p->getWeight() * 1000) * (int) $item->getQuantity();
            }
        }
        return $total;
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
