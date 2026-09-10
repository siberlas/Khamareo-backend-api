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
     * Territoire TVA français : France métropolitaine + Monaco. L'Andorre,
     * bien que classée en zone tarifaire 'FR', en est exclue (traitée en
     * export). Les DROM-COM aussi (hors territoire TVA de l'UE).
     */
    private const FRENCH_VAT_COUNTRIES = ['FR', 'MC', 'FRANCE'];

    /**
     * États membres de l'UE (hors France) : le transport y est soumis à la
     * TVA française pour un client français (La Poste facture 20 %). Le
     * Royaume-Uni et la Suisse, bien que dans la zone tarifaire 'EU'/'CH',
     * sont des exports → 0 % de TVA, donc absents de cette liste. Limite
     * assumée : les territoires hors TVA de l'UE (Canaries, Madère, DROM…)
     * ne sont pas distinguables au seul code pays — même compromis que le
     * colisage international (cf. Version20260812160000).
     */
    private const EU_VAT_COUNTRIES = ['AT','BE','BG','HR','CY','CZ','DK','EE','FI','DE','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE'];

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

    /**
     * Le transport de ce colis est-il soumis à la TVA française (20 %) ?
     * Vrai pour France métro + Monaco + UE-27 ; faux pour Outre-mer, UK,
     * Suisse, Andorre et tout export international (exonération art. 262 II CGI).
     */
    public function isFrenchVatApplicable(string $countryCode): bool
    {
        $up = strtoupper(trim($countryCode));

        return in_array($up, self::FRENCH_VAT_COUNTRIES, true)
            || in_array($up, self::EU_VAT_COUNTRIES, true);
    }
}
