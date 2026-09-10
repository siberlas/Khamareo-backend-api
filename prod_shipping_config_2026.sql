-- ============================================================
-- Configuration tarifaire livraison 2026 — À jouer en PRODUCTION
-- APRÈS le déploiement (migrations 20260811* / 20260812* / 20260907*
-- appliquées : colonnes créées mais vides).
--
-- Commande :
--   docker exec -i khamareo-db psql -U khamareo -d khamareo < prod_shipping_config_2026.sql
--
-- Ne touche PAS aux grilles (shipping_rate) : celles-ci sont chargées
-- par les migrations 20260812150000 -> 20260812180000.
-- Ici on ne renseigne que :
--   1. carrier_mode.energy_coefficient_type  (sinon CAE = 0)
--   2. store_settings.*                       (coefficients & suppléments)
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- 1. Type de coefficient énergie par offre Colissimo
--    (routier = terrestre métropole/UE ; aérien = OM + international)
--    outre_mer_eco : volontairement laissé NULL (pas de CAE sur l'éco).
-- ------------------------------------------------------------
UPDATE carrier_mode SET energy_coefficient_type = 'routier'
  WHERE colissimo_product_code_key IN ('france_metro', 'union_europeenne');

UPDATE carrier_mode SET energy_coefficient_type = 'aerien'
  WHERE colissimo_product_code_key IN ('outre_mer', 'international');

UPDATE carrier_mode SET energy_coefficient_type = NULL
  WHERE colissimo_product_code_key = 'outre_mer_eco';

-- ------------------------------------------------------------
-- 2. Coefficients & suppléments (table à ligne unique)
--
--    CAE      : contrat privilège Colissimo — routier 13,83 % / aérien 15,50 %
--               (valeurs juillet 2026, variables mensuellement : à réajuster)
--    SMIC     : compensation évolution du SMIC — 1,00 %  (⚠ VÉRIFIER le contrat)
--    TVA port : 20 % répercutés (franchise 293 B, TVA non récupérable)
--    Sûreté   : 0,20 € (int'l)      Décarbonation : 0,05 € (toutes offres)
--    Suppléments pays : US 5,00 €   Chine 6,00 €   Royaume-Uni 4,40 €
--
--    cae_excluded_carrier_mode_ids : offres SANS CAE
--      = Colissimo Eco OM (48) + tous Mondial Relay (51,52,53,54,55) + Chronopost (56)
--      ⚠ vérifier que ces ID correspondent bien en prod (cf. SELECT carrier_mode)
-- ------------------------------------------------------------
UPDATE store_settings SET
    cae_percent_routier              = 13.83,
    cae_percent_aerien               = 15.50,
    smic_compensation_percent        = 1.00,
    shipping_vat_rate_percent        = 20.00,
    supplement_international_security = 0.20,
    supplement_decarbonation         = 0.05,
    supplement_us                    = 5.00,
    supplement_china                 = 6.00,
    supplement_uk                    = 4.40,
    cae_excluded_carrier_mode_ids    = '[48,51,52,53,54,55,56]'
;

-- ------------------------------------------------------------
-- Contrôle
-- ------------------------------------------------------------
SELECT id, colissimo_product_code_key AS key, energy_coefficient_type AS energy
FROM carrier_mode WHERE colissimo_product_code_key IS NOT NULL ORDER BY id;

SELECT cae_percent_routier, cae_percent_aerien, smic_compensation_percent,
       shipping_vat_rate_percent, supplement_international_security,
       supplement_decarbonation, supplement_us, supplement_china, supplement_uk,
       cae_excluded_carrier_mode_ids
FROM store_settings;

COMMIT;
