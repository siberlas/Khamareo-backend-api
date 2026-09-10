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
        public ?string $error = null,
    ) {}

    /**
     * @param ParcelEstimate[] $parcels
     */
    public static function success(array $parcels): self
    {
        return new self(
            success: true,
            parcels: $parcels,
            totalPrice: round(array_sum(array_map(fn (ParcelEstimate $p) => $p->price, $parcels)), 2),
        );
    }

    public static function failure(string $error): self
    {
        return new self(success: false, error: $error);
    }
}
