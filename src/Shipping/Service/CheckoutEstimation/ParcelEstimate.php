<?php

namespace App\Shipping\Service\CheckoutEstimation;

/**
 * Estimation d'un colis simulé (aucune entité Parcel réelle) : format de
 * carton retenu, poids, décomposition du prix.
 *
 * `price` est le montant **HT** du colis : port net + CAE + compensation SMIC
 * + suppléments, hors TVA. La TVA n'est PAS appliquée colis par colis : La
 * Poste la facture une seule fois sur le total HT de la commande — elle est
 * portée par CheckoutEstimationResult::$totalVat. `vat` ci-dessous n'est que
 * la part indicative de ce colis (affichage debug), jamais sommée.
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
