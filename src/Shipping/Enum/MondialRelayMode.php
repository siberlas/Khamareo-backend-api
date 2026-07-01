<?php

namespace App\Shipping\Enum;

/**
 * Codes produit API Mondial Relay Shipment REST V2
 * Source: connect-api.mondialrelay.com/api/shipment (XSD ShipmentCreationRequest)
 */
class MondialRelayMode
{
    // DeliveryMode
    public const RELAY_POINT    = '24R';    // Point Relais® (standard, max 30 kg)
    public const RELAY_POINT_XL = '24L';    // Point Relais® XL (lourd/encombrant)
    public const HOME           = 'HOM';    // Livraison à domicile standard
    public const HOME_PLUS      = 'HOC';    // Livraison à domicile avec option supplémentaire

    // CollectionMode
    public const COLLECTION_RELAY     = 'REL'; // Vendeur dépose le colis en Point Relais
    public const COLLECTION_WAREHOUSE = 'CCC'; // Mondial Relay collecte à l'entrepôt du vendeur
}
