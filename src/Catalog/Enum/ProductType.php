<?php

namespace App\Catalog\Enum;

/**
 * Nature d'un produit :
 *  - PHYSICAL : produit expédié (poids, dimensions, colisage, transporteur)
 *  - DIGITAL  : livre numérique (PDF) livré par email après paiement, aucun
 *    frais de port, pas de gestion de stock.
 */
enum ProductType: string
{
    case PHYSICAL = 'physical';
    case DIGITAL = 'digital';

    public function label(): string
    {
        return match ($this) {
            self::PHYSICAL => 'Physique',
            self::DIGITAL => 'Numérique',
        };
    }

    /** Le produit nécessite-t-il une livraison physique (adresse + transporteur) ? */
    public function requiresShipping(): bool
    {
        return $this === self::PHYSICAL;
    }
}
