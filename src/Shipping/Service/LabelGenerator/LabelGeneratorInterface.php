<?php

namespace App\Shipping\Service\LabelGenerator;

use App\Shipping\Entity\Parcel;

/**
 * Interface pour les générateurs d'étiquettes
 * 
 * Permet d'abstraire la génération d'étiquettes pour différents transporteurs
 */
interface LabelGeneratorInterface
{
    /**
     * Génère une étiquette pour un colis
     * 
     * @param Parcel $parcel Le colis pour lequel générer l'étiquette
     * @return LabelGenerationResult Le résultat de la génération
     */
    public function generateLabelForParcel(Parcel $parcel): LabelGenerationResult;
    
    /**
     * Indique si ce générateur supporte le transporteur
     * 
     * @param string $carrierCode Le code du transporteur (ex: 'colissimo', 'ups', 'dhl')
     * @return bool True si supporté
     */
    public function supports(string $carrierCode): bool;
}

/**
 * Résultat de génération d'étiquette
 */
class LabelGenerationResult
{
    public function __construct(
        public bool $success,
        public ?string $trackingNumber = null,
        public ?string $labelUrl = null,
        public ?string $cn23Url = null,
        public ?string $error = null,
        public ?string $rawData = null,
    ) {}
}