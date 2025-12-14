<?php

namespace App\Service;

class CurrencyResolver
{
    /**
     * Pour l’instant :
     * - US / USA → usd
     * - tout le reste → eur
     *
     * Tu pourras affiner plus tard (multi-prix, choix user, etc.)
     */
    public function resolveForCountry(?string $country): string
    {
        if ($country === null) {
            return 'eur';
        }

        $code = strtoupper(trim($country));

        if ($code === 'US' || $code === 'USA') {
            return 'usd';
        }

        // Par défaut : EUR
        return 'eur';
    }
}
