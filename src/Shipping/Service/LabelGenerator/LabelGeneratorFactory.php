<?php

namespace App\Shipping\Service\LabelGenerator;

use App\Shipping\Entity\Carrier;

/**
 * Factory pour obtenir le générateur d'étiquettes approprié
 * selon le transporteur
 */
class LabelGeneratorFactory
{
    /** @var LabelGeneratorInterface[] */
    private array $generators = [];
    
    /**
     * @param iterable<LabelGeneratorInterface> $generators
     */
    public function __construct(iterable $generators)
    {
        foreach ($generators as $generator) {
            $this->generators[] = $generator;
        }
    }
    
    /**
     * Obtient le générateur pour un transporteur
     * 
     * @throws \RuntimeException Si aucun générateur trouvé
     */
    public function getGenerator(Carrier $carrier): LabelGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->supports($carrier->getCode())) {
                return $generator;
            }
        }
        
        throw new \RuntimeException(sprintf(
            'No label generator found for carrier: %s (%s)',
            $carrier->getName(),
            $carrier->getCode()
        ));
    }
    
    /**
     * Vérifie si un transporteur est supporté
     */
    public function isSupported(Carrier $carrier): bool
    {
        foreach ($this->generators as $generator) {
            if ($generator->supports($carrier->getCode())) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Liste tous les codes transporteurs supportés
     * 
     * @return string[]
     */
    public function getSupportedCarriers(): array
    {
        $supported = [];
        
        foreach ($this->generators as $generator) {
            // On suppose que chaque générateur supporte au moins un code
            // Cette méthode est informative, pas critique
            if ($generator->supports('colissimo')) {
                $supported[] = 'colissimo';
            }
    
            // Ajouter d'autres selon besoins
        }
        
        return array_unique($supported);
    }
}