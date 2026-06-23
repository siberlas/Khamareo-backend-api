-- ============================================================
-- Corrections tarifaires 2026 — À jouer en PRODUCTION
-- Colissimo : suppression tarifs ECO OM2 incorrects
-- Mondial Relay France : mise à jour tarifs juin 2026
-- ============================================================
-- Commande : docker exec -i khamareo-db psql -U khamareo -d khamareo < fix_shipping_rates_2026.sql

BEGIN;

-- ============================================================
-- COLISSIMO : tarifs ECO (outre_mer_eco) incorrects sur OM2
-- Le service ECO n'existe que vers OM1 (GP,MQ,RE,YT,GF,PM,MF,BL)
-- ============================================================

DELETE FROM shipping_rate
WHERE carrier_mode_id = (
    SELECT id FROM carrier_mode WHERE colissimo_product_code_key = 'outre_mer_eco'
) AND zone = 'OM2';

UPDATE carrier_mode
SET supported_zones = '["OM1"]'
WHERE colissimo_product_code_key = 'outre_mer_eco';

-- ============================================================
-- MONDIAL RELAY FRANCE — Point Relais
-- ============================================================

DO $$
DECLARE
  cm_pr  INTEGER;
  cm_lk  INTEGER;
  cm_dom INTEGER;
  next_id INTEGER;
BEGIN
  -- Résolution des IDs par code carrier + mode + zone FR uniquement
  SELECT cm.id INTO cm_pr
  FROM carrier_mode cm
  JOIN carrier c ON cm.carrier_id = c.id
  JOIN shipping_mode sm ON cm.shipping_mode_id = sm.id
  WHERE c.code = 'mondialrelay' AND sm.code = 'relay_point'
    AND cm.supported_zones::text = '["FR"]';

  SELECT cm.id INTO cm_lk
  FROM carrier_mode cm
  JOIN carrier c ON cm.carrier_id = c.id
  JOIN shipping_mode sm ON cm.shipping_mode_id = sm.id
  WHERE c.code = 'mondialrelay' AND sm.code = 'locker'
    AND cm.supported_zones::text = '["FR"]';

  SELECT cm.id INTO cm_dom
  FROM carrier_mode cm
  JOIN carrier c ON cm.carrier_id = c.id
  JOIN shipping_mode sm ON cm.shipping_mode_id = sm.id
  WHERE c.code = 'mondialrelay' AND sm.code = 'home'
    AND cm.supported_zones::text = '["FR"]';

  -- ---- Point Relais FR ----
  UPDATE shipping_rate SET price = 4.15 WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND max_weight_grams IN (250, 500);
  DELETE FROM shipping_rate WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND min_weight_grams = 501 AND max_weight_grams = 750;
  UPDATE shipping_rate SET min_weight_grams = 501, price = 5.99 WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND max_weight_grams = 1000;
  UPDATE shipping_rate SET price = 7.99 WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND max_weight_grams = 2000;
  UPDATE shipping_rate SET max_weight_grams = 3000, price = 7.99 WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND min_weight_grams = 2001 AND max_weight_grams = 4000;
  SELECT COALESCE(MAX(id), 0) + 1 INTO next_id FROM shipping_rate;
  INSERT INTO shipping_rate (id, carrier_mode_id, zone, min_weight_grams, max_weight_grams, price, created_at)
  SELECT next_id, cm_pr, 'FR', 3001, 4000, 9.99, NOW()
  WHERE NOT EXISTS (SELECT 1 FROM shipping_rate WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND min_weight_grams = 3001 AND max_weight_grams = 4000);
  UPDATE shipping_rate SET price = 25.99 WHERE carrier_mode_id = cm_pr AND zone = 'FR' AND min_weight_grams >= 10001;

  -- ---- Locker FR (mêmes prix que Point Relais) ----
  UPDATE shipping_rate SET price = 4.15 WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND max_weight_grams IN (250, 500);
  DELETE FROM shipping_rate WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND min_weight_grams = 501 AND max_weight_grams = 750;
  UPDATE shipping_rate SET min_weight_grams = 501, price = 5.99 WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND max_weight_grams = 1000;
  UPDATE shipping_rate SET price = 7.99 WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND max_weight_grams = 2000;
  UPDATE shipping_rate SET max_weight_grams = 3000, price = 7.99 WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND min_weight_grams = 2001 AND max_weight_grams = 4000;
  SELECT COALESCE(MAX(id), 0) + 1 INTO next_id FROM shipping_rate;
  INSERT INTO shipping_rate (id, carrier_mode_id, zone, min_weight_grams, max_weight_grams, price, created_at)
  SELECT next_id, cm_lk, 'FR', 3001, 4000, 9.99, NOW()
  WHERE NOT EXISTS (SELECT 1 FROM shipping_rate WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND min_weight_grams = 3001 AND max_weight_grams = 4000);
  UPDATE shipping_rate SET price = 25.99 WHERE carrier_mode_id = cm_lk AND zone = 'FR' AND min_weight_grams >= 10001;

  -- ---- Domicile FR ----
  UPDATE shipping_rate SET price = 7.49  WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND max_weight_grams = 500;
  DELETE FROM shipping_rate WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND min_weight_grams = 501 AND max_weight_grams = 750;
  UPDATE shipping_rate SET min_weight_grams = 501, price = 9.49  WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND max_weight_grams = 1000;
  UPDATE shipping_rate SET price = 10.99 WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND max_weight_grams = 2000;
  UPDATE shipping_rate SET price = 16.39 WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND min_weight_grams >= 2001 AND max_weight_grams <= 5000;
  UPDATE shipping_rate SET price = 24.99 WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND min_weight_grams >= 5001 AND max_weight_grams <= 10000;
  UPDATE shipping_rate SET price = 31.49 WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND max_weight_grams = 15000;
  UPDATE shipping_rate SET price = 42.99 WHERE carrier_mode_id = cm_dom AND zone = 'FR' AND max_weight_grams = 25000;
END $$;

COMMIT;
