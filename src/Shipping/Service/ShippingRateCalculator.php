<?php

namespace App\Shipping\Service;

use App\Order\Entity\Order;
use App\Shipping\Entity\ShippingMethod;
use App\Shipping\Repository\ShippingRateRepository;

class ShippingRateCalculator
{
    public function __construct(
        private ShippingRateRepository $shippingRateRepository
    ) {}

    /**
     * Ancienne méthode (compat) – calcule depuis l’Order.
     * Idéalement, vous utiliserez plutôt calculateFromMethod().
     */
    public function calculate(Order $order): float
    {
        $method = $order->getShippingMethod();
        $address = $order->getShippingAddress();
        $weight = 0.0;

        // → calculez le poids depuis les OrderItems si besoin
        foreach ($order->getItems() as $item) {
            $p = $item->getProduct();
            if ($p && $p->getWeight() !== null) {
                $weight += ((float) $p->getWeight()) * (int) $item->getQuantity();
            }
        }

        $zone = $this->mapCountryToZone((string) $address->getCountry());
        return $this->calculateFromMethod($method, $zone, $weight);
    }

    /**
     * Nouvelle méthode stateless pour l’estimation “au choix du mode”.
     */
    public function calculateFromMethod(ShippingMethod $method, string $zone, float $weight): float
    {
        $provider = (string) $method->getCarrierCode(); // ex: "LA_POSTE" / "MONDIAL_RELAY"
        $rate = $this->shippingRateRepository->findBestRate($provider, $zone, $weight);

        if (!$rate) {
            // fallback: on peut utiliser un prix “par défaut” de la méthode
            return (float) $method->getPrice();
        }

        return (float) $rate->getPrice();
    }

    public function resolveRate(ShippingMethod $method, string $zone, float $weight): ?\App\Shipping\Entity\ShippingRate
    {
        $provider = (string) $method->getCarrierCode();
        return $this->shippingRateRepository->findBestRate($provider, $zone, $weight);
    }

    private function mapCountryToZone(string $country): string
    {
        $up = strtoupper(trim($country));
        if ($up === 'FR' || $up === 'FRANCE') {
            return 'FR';
        }

        $eu = ['BE','LU','NL','DE','ES','PT','IT','IE','AT','CZ','DK','EE','FI','GR','HR','HU','LT','LV','MT','PL','RO','SE','SI','SK','BG'];
        if (in_array($up, $eu, true)) {
            return 'EU';
        }

        return 'INTL';
    }
}
