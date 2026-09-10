<?php

namespace App\Shipping\Service\CheckoutEstimation;

readonly class CheckoutEstimationResult
{
    /**
     * @param ParcelEstimate[] $parcels
     */
    public function __construct(
        public bool $success,
        public array $parcels = [],
        public float $totalPrice = 0.0,
        public float $totalHt = 0.0,
        public float $totalVat = 0.0,
        public ?string $error = null,
    ) {}

    /**
     * La TVA (frais de port répercutés) est calculée UNE fois sur le total HT
     * de la commande — comme sur la facture La Poste, jamais colis par colis
     * (l'arrondi colis par colis puis sommé dérive de quelques centimes).
     *
     * @param ParcelEstimate[] $parcels  chaque $price est un montant HT
     */
    public static function success(array $parcels, float $totalHt, float $totalVat): self
    {
        return new self(
            success: true,
            parcels: $parcels,
            totalPrice: round($totalHt + $totalVat, 2),
            totalHt: round($totalHt, 2),
            totalVat: round($totalVat, 2),
        );
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
