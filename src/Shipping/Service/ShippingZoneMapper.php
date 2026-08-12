<?php

namespace App\Shipping\Service;

class ShippingZoneMapper
{
    private const OM1 = ['GP', 'MQ', 'GF', 'RE', 'YT', 'PM', 'MF', 'BL'];
    private const OM2 = ['NC', 'PF', 'WF', 'TF'];
    // Membres UE + pays européens hors UE partageant la même grille tarifaire
    // Colissimo "Reste des destinations Europe & Monde" (zones 1-4 du
    // document 07/08/2026) — Groenland (GL) et Îles Féroé (FO) via le
    // Danemark, Liechtenstein (LI) via la Suisse, Guernesey (GG) / Jersey
    // (JE) via le Royaume-Uni, Turquie (TR) / Islande (IS) explicitement
    // listés en zone 4. Avant ce correctif, ces pays retombaient à tort
    // dans le même panier tarifaire que les USA/Chine/Australie (zone C).
    private const EU  = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','GL','FO','LI','GG','JE','TR','IS'];
    private const ZONE_B = ['NO','MA','DZ','TN','LY','EG','AL','BA','MK','ME','RS','XK','MD','UA','BY','GE','AM','AZ'];

    /**
     * Mappe un code pays ISO 3166-1 alpha-2 vers une zone tarifaire.
     * Zones possibles : FR, OM1, OM2, EU, CH, UK, B, C
     * Ces valeurs correspondent exactement aux zones stockées en base (shipping_rate.zone).
     */
    public function mapCountryToZone(string $countryCode): string
    {
        $up = strtoupper(trim($countryCode));

        if (in_array($up, ['FR', 'MC', 'AD', 'FRANCE'], true)) {
            return 'FR';
        }

        if (in_array($up, self::OM1, true)) {
            return 'OM1';
        }

        if (in_array($up, self::OM2, true)) {
            return 'OM2';
        }

        if (in_array($up, self::EU, true)) {
            return 'EU';
        }

        if ($up === 'CH') {
            return 'CH';
        }

        if ($up === 'GB') {
            return 'UK';
        }

        if (in_array($up, self::ZONE_B, true)) {
            return 'B';
        }

        return 'C';
    }
}
