<?php
// src/Shipping/Enum/DestinationZone.php

namespace App\Shipping\Enum;

/**
 * Zones de destination pour l'expédition Colissimo
 * 
 * Détermine :
 * - Le code produit Colissimo à utiliser
 * - Si une déclaration en douane (CN23) est requise
 */
enum DestinationZone: string
{
    /**
     * France métropolitaine + Monaco (98xxx) + Andorre (AD)
     * Code produit: DOM
     * CN23: NON
     */
    case FRANCE_METRO = 'france_metro';

    /**
     * Outre-mer français (DROM-COM)
     * Martinique, Guadeloupe, Guyane, Réunion, Mayotte, Saint-Pierre-et-Miquelon,
     * Saint-Martin, Saint-Barthélemy
     * Code produit: COM
     * CN23: OUI
     */
    case OUTRE_MER = 'outre_mer';

    /**
     * Union Européenne (27 pays + Norvège + Islande via accords)
     * Code produit: COLI
     * CN23: NON (marché unique)
     */
    case UNION_EUROPEENNE = 'union_europeenne';

    /**
     * Suisse, Royaume-Uni (post-Brexit), autres pays européens hors UE
     * Code produit: COLI
     * CN23: OUI
     */
    case EUROPE_HORS_UE = 'europe_hors_ue';

    /**
     * Reste du monde (USA, Canada, Asie, Afrique, Océanie, etc.)
     * Code produit: COLI
     * CN23: OUI
     */
    case INTERNATIONAL = 'international';

    /**
     * Obtient le code produit Colissimo pour cette zone
     */
    public function getProductCode(): string
    {
        return match ($this) {
            self::FRANCE_METRO => 'DOM',
            self::OUTRE_MER => 'COM',
            self::UNION_EUROPEENNE, 
            self::EUROPE_HORS_UE, 
            self::INTERNATIONAL => 'DOM',
        };
    }

    /**
     * Indique si une déclaration en douane (CN23) est requise
     */
    public function requiresCN23(): bool
    {
        return match ($this) {
            self::FRANCE_METRO => false,
            self::OUTRE_MER => true,
            self::UNION_EUROPEENNE => false,
            self::EUROPE_HORS_UE => true,
            self::INTERNATIONAL => true,
        };
    }

    /**
     * Obtient une description lisible de la zone
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::FRANCE_METRO => 'France métropolitaine',
            self::OUTRE_MER => 'Outre-mer français (DROM-COM)',
            self::UNION_EUROPEENNE => 'Union Européenne',
            self::EUROPE_HORS_UE => 'Europe hors UE',
            self::INTERNATIONAL => 'International',
        };
    }

    /**
     * Indique si c'est une destination française
     */
    public function isFrenchTerritory(): bool
    {
        return in_array($this, [self::FRANCE_METRO, self::OUTRE_MER], true);
    }
}