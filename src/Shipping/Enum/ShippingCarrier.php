<?php

namespace App\Shipping\Enum;

/**
 * Enum pour les transporteurs/méthodes d'expédition
 * 
 * Utilisé pour :
 * - Normaliser les noms de transporteurs
 * - Fournir une liste de référence au frontend
 * - Valider les données d'entrée
 */
enum ShippingCarrier: string
{
    case COLISSIMO = 'colissimo';
    case CHRONOPOST = 'chronopost';
    case UPS = 'ups';
    case DHL = 'dhl';
    case FEDEX = 'fedex';
    case MONDIAL_RELAY = 'mondial_relay';
    case RELAIS_COLIS = 'relais_colis';
    case LA_POSTE = 'la_poste';
    case DPD = 'dpd';
    case GLS = 'gls';
    case TNT = 'tnt';
    case OTHER = 'other';

    /**
     * Retourne le label français du transporteur
     */
    public function getLabel(): string
    {
        return match($this) {
            self::COLISSIMO => 'Colissimo',
            self::CHRONOPOST => 'Chronopost',
            self::UPS => 'UPS',
            self::DHL => 'DHL',
            self::FEDEX => 'FedEx',
            self::MONDIAL_RELAY => 'Mondial Relay',
            self::RELAIS_COLIS => 'Relais Colis',
            self::LA_POSTE => 'La Poste',
            self::DPD => 'DPD',
            self::GLS => 'GLS',
            self::TNT => 'TNT',
            self::OTHER => 'Autre',
        };
    }

    /**
     * Retourne tous les transporteurs sous forme de tableau
     * Format: [['value' => 'colissimo', 'label' => 'Colissimo'], ...]
     */
    public static function toArray(): array
    {
        return array_map(
            fn(self $carrier) => [
                'value' => $carrier->value,
                'label' => $carrier->getLabel(),
            ],
            self::cases()
        );
    }

    /**
     * Retourne seulement les values (pour validation)
     */
    public static function values(): array
    {
        return array_map(fn(self $carrier) => $carrier->value, self::cases());
    }
}