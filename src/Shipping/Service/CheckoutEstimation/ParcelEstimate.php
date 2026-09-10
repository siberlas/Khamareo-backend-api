<?php

namespace App\Shipping\Service\CheckoutEstimation;

/**
 * Estimation d'un colis simulé (aucune entité Parcel réelle) : format de
 * carton retenu, poids, décomposition du prix.
 */
readonly class ParcelEstimate
{
    public function __construct(
        public string $cartonId,
        public string $cartonName,
        public array $productNames,
        public int $weightGrams,
        public ?int $volumetricWeightGrams,
        public int $billableWeightGrams,
        public float $portNet,
        public float $cae,
        public float $smicCompensation,
        public float $supplements,
        public float $vat,
        public float $price,
    ) {}
}
