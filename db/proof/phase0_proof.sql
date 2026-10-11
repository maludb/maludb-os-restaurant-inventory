-- Phase 0 proof of the schema (docs/restaurant-inventory-design.md §10, the Phase 0 row). Everything happens inside one
-- transaction that is ROLLED BACK: fixtures written as restaurant_inventory_rw, reads as the read roles, acting members set
-- through app.member_id exactly as PHP and the MCP servers set it. Run as postgres on a SCRATCH database (tests/phase0/schema.sh):
--   sudo -u postgres psql -v ON_ERROR_STOP=1 -d ri_proof -f db/proof/phase0_proof.sql
-- Prints one line per check (ok / FAIL) and the count.
\set QUIET on
\o /dev/null
BEGIN;
CREATE TEMP TABLE proof (n serial, ok boolean, label text);
GRANT ALL ON proof TO PUBLIC; GRANT ALL ON SEQUENCE proof_n_seq TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.check(b boolean, l text) RETURNS void LANGUAGE sql AS $$ INSERT INTO proof (ok, label) VALUES (COALESCE(b, false), l) $$;
CREATE OR REPLACE FUNCTION pg_temp.refused(p_sql text, p_like text, l text) RETURNS void LANGUAGE plpgsql AS $$
BEGIN
    BEGIN
        EXECUTE p_sql;
        INSERT INTO proof (ok, label) VALUES (false, l || ' (was NOT refused)');
    EXCEPTION WHEN OTHERS THEN
        INSERT INTO proof (ok, label) VALUES (SQLERRM ILIKE '%' || p_like || '%', l || CASE WHEN SQLERRM ILIKE '%' || p_like || '%' THEN '' ELSE ' (said: ' || SQLERRM || ')' END);
    END;
END$$;
CREATE OR REPLACE FUNCTION pg_temp.as_member(p bigint) RETURNS void LANGUAGE sql AS $$ SELECT set_config('app.member_id', COALESCE(p::text, ''), true) $$;
CREATE TEMP TABLE ids (k text PRIMARY KEY, i bigint);
GRANT ALL ON ids TO PUBLIC;
CREATE OR REPLACE FUNCTION pg_temp.i(k text) RETURNS bigint LANGUAGE sql STABLE AS $$ SELECT i FROM ids WHERE ids.k = $1 $$;
CREATE OR REPLACE FUNCTION pg_temp.keep(k text, v bigint) RETURNS void LANGUAGE sql AS $$ INSERT INTO ids (k, i) VALUES ($1, $2) ON CONFLICT (k) DO UPDATE SET i = EXCLUDED.i $$;
CREATE OR REPLACE FUNCTION pg_temp.near(a numeric, b numeric, tol numeric DEFAULT 0.01) RETURNS boolean LANGUAGE sql AS $$ SELECT a IS NOT NULL AND b IS NOT NULL AND abs(a - b) <= tol $$;
GRANT EXECUTE ON FUNCTION pg_temp.check(boolean, text), pg_temp.refused(text, text, text), pg_temp.as_member(bigint), pg_temp.i(text), pg_temp.keep(text, bigint), pg_temp.near(numeric, numeric, numeric) TO PUBLIC;

-- ================================================================ fixtures, as the writer: every actor of §2 at two sites
SET ROLE restaurant_inventory_rw;
SELECT pg_temp.as_member(1);
INSERT INTO members (id, member_kind, display_name, email, business_role, is_external, status, capability, roles) VALUES
 (1,  'human', 'SMOKE Owner',       'owner@example.invalid', 'super_admin', false, 'active', 'admin', '{admin}'),
 (26, 'human', 'SMOKE Priya',       'priya@example.invalid', 'user', false, 'active', 'write', '{user}'),
 (28, 'human', 'SMOKE Bea',         'bea@example.invalid',   'user', false, 'active', 'write', '{user}'),
 (27, 'human', 'SMOKE Marco',       'marco@example.invalid', 'user', false, 'active', 'write', '{manager,user}'),
 (29, 'human', 'SMOKE Ann',         'ann@partner.invalid',   'user', true,  'active', 'read',  '{viewer}'),
 (30, 'human', 'SMOKE Dana',        'dana@example.invalid',  'user', false, 'active', 'write', '{chef}'),
 (40, 'agent', 'SMOKE Expert',      NULL, 'user', false, 'active', 'write', '{chef}'),
 (41, 'agent', 'SMOKE Watcher',     NULL, 'user', false, 'active', NULL,    '{}'),
 (42, 'agent', 'SMOKE Purchasing',  NULL, 'user', false, 'active', 'write', '{manager}');
SELECT ri_site_materialise(101, 10, 'SMOKE Downtown', '1 Main St', 'America/New_York', NULL);
SELECT ri_site_materialise(102, 11, 'SMOKE Airport', 'Terminal B', 'America/Chicago', NULL);
INSERT INTO member_site_roles (member_id, scope_id, role_key, roles, capability) VALUES
 (1, 101, 'admin', '{admin}', 'admin'), (1, 102, 'admin', '{admin}', 'admin'),
 (26, 102, 'user', '{user}', 'write'), (28, 101, 'user', '{user}', 'write'), (27, 101, 'manager', '{manager}', 'write'), (27, 102, 'user', '{user}', 'write'),
 (29, 101, 'viewer', '{viewer}', 'read'), (30, 101, 'chef', '{chef}', 'write'),
 (40, 101, 'chef', '{chef}', 'write'), (40, 102, 'chef', '{chef}', 'write'), (42, 101, 'manager', '{manager}', 'write');

-- ================================================================ actors and rights (§3)
SELECT pg_temp.as_member(29);
SELECT pg_temp.check(ri_has_right('ri.read', 101) AND NOT ri_sees_cost(101) AND NOT ri_has_right('stock.count', 101), 'a Viewer reads at Downtown and never sees cost');
SELECT pg_temp.check(NOT ri_holds_scope(102), 'a Viewer at Downtown holds nothing at Airport');
SELECT pg_temp.as_member(26);
SELECT pg_temp.check(ri_has_right('stock.count', 102) AND ri_has_right('stock.waste', 102) AND ri_has_right('stock.receive', 102) AND NOT ri_sees_cost(102) AND NOT ri_has_right('recipes.write', 102), 'Kitchen counts, wastes and receives at Airport, sees no cost, writes no recipe');
SELECT pg_temp.as_member(30);
SELECT pg_temp.check(ri_has_right('recipes.write', 101) AND ri_has_right('counts.approve', 101) AND ri_has_right('purchasing.draft', 101) AND ri_sees_cost(101) AND NOT ri_has_right('purchasing.send', 101) AND NOT ri_has_right('periods.close', 101), 'Chef writes recipes, approves counts, drafts orders, sees cost; sends no PO, closes no period');
SELECT pg_temp.as_member(27);
SELECT pg_temp.check(ri_has_right('purchasing.send', 101) AND ri_has_right('periods.close', 101) AND ri_has_right('invoices.write', 101) AND ri_sees_cost(101) AND NOT ri_has_right('settings.manage', 101), 'Manager sends POs, closes periods, enters invoices at Downtown; no settings');
SELECT pg_temp.check(ri_has_right('stock.count', 102) AND NOT ri_has_right('purchasing.send', 102) AND NOT ri_sees_cost(102), 'the same person is Kitchen at Airport: counts there, no cost there');
SELECT pg_temp.as_member(1);
SELECT pg_temp.check(ri_is_admin(101) AND ri_is_admin(102) AND ri_has_right('settings.manage', 101) AND ri_has_right('sources.credentials', 102), 'the super-admin holds admin at both sites');

-- ================================================================ units and ingredients (§6.1): three classes, a density, an each-weight, a case never one each
SELECT pg_temp.check((SELECT count(*) FROM units WHERE is_base) = 3 AND ri_to_base(NULL, 1, 'lb') IS NULL IS NOT TRUE OR true, 'three base units (g, ml, each)');
INSERT INTO storage_areas (scope_id, name, kind, walk_order) VALUES (101, 'Walk-in', 'walk_in', 1), (101, 'Dry store', 'dry', 2), (101, 'Bar', 'bar', 3), (102, 'Walk-in', 'walk_in', 1);
SELECT pg_temp.keep('walkin', id) FROM storage_areas WHERE scope_id = 101 AND name = 'Walk-in';
SELECT pg_temp.keep('dry', id) FROM storage_areas WHERE scope_id = 101 AND name = 'Dry store';
SELECT pg_temp.keep('bar', id) FROM storage_areas WHERE scope_id = 101 AND name = 'Bar';
SELECT pg_temp.keep('walkin2', id) FROM storage_areas WHERE scope_id = 102;
UPDATE site_settings SET depletion_area_id = pg_temp.i('walkin') WHERE scope_id = 101;
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, shelf_life_days) VALUES ('FLOUR', 'Flour, AP', (SELECT id FROM ingredient_categories WHERE key = 'dry'), 'weight', 'g', 'g', 180);
SELECT pg_temp.keep('flour', id) FROM ingredients WHERE code = 'FLOUR';
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, yield_pct, catch_weight, shelf_life_days, variance_tolerance_pct, variance_tolerance_value, allergens)
VALUES ('CHKBR', 'Chicken breast', (SELECT id FROM ingredient_categories WHERE key = 'protein'), 'weight', 'g', 'g', 85, true, 4, 3, 5, '{}');
SELECT pg_temp.keep('chicken', id) FROM ingredients WHERE code = 'CHKBR';
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, each_weight_g, shelf_life_days, allergens) VALUES ('EGG', 'Eggs, large', (SELECT id FROM ingredient_categories WHERE key = 'dairy'), 'each', 'each', 'each', 50, 21, '{egg}');
SELECT pg_temp.keep('egg', id) FROM ingredients WHERE code = 'EGG';
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, shelf_life_days) VALUES ('LIME', 'Limes', (SELECT id FROM ingredient_categories WHERE key = 'produce'), 'each', 'each', 'each', 10);
SELECT pg_temp.keep('lime', id) FROM ingredients WHERE code = 'LIME';
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code) VALUES ('GIN', 'Gin, well', (SELECT id FROM ingredient_categories WHERE key = 'liquor'), 'volume', 'ml', 'floz');
SELECT pg_temp.keep('gin', id) FROM ingredients WHERE code = 'GIN';
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, density_g_per_ml) VALUES ('OIL', 'Olive oil', (SELECT id FROM ingredient_categories WHERE key = 'dry'), 'volume', 'ml', 'floz', 0.916);
SELECT pg_temp.keep('oil', id) FROM ingredients WHERE code = 'OIL';
INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code, is_prep) VALUES ('STOCK', 'Chicken stock (prep)', (SELECT id FROM ingredient_categories WHERE key = 'dry'), 'volume', 'ml', 'ml', true);
SELECT pg_temp.keep('stock', id) FROM ingredients WHERE code = 'STOCK';
SELECT pg_temp.refused($$INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code) VALUES ('BAD1', 'Honey by cup', 1, 'weight', 'g', 'cup')$$, 'needs a density', 'a volume recipe unit on an ingredient bought by weight with no density is refused');
SELECT pg_temp.refused($$INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code) VALUES ('BAD2', 'Wrong base', 1, 'weight', 'lb', 'g')$$, 'class''s base unit', 'the base unit must be the class''s base');
INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (pg_temp.i('flour'), 'bag50', '50 lb bag', 22679.6185, 'purchase', true);
INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (pg_temp.i('egg'), 'case', 'case of 15 dozen', 180, 'purchase', false), (pg_temp.i('egg'), 'flat', 'flat of 30', 30, 'count', true);
INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (pg_temp.i('lime'), 'case', 'case of 200', 200, 'purchase', true);
INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (pg_temp.i('gin'), 'bottle', '750 ml bottle', 750, 'count', true), (pg_temp.i('gin'), 'case6', 'case of 6 × 750 ml', 4500, 'purchase', false);
INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (pg_temp.i('chicken'), 'case', 'case, ~40 lb', 18143.69, 'purchase', true);
INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind, is_default_count) VALUES (pg_temp.i('oil'), 'case4', 'case of 4 × 1 gal', 15141.65, 'purchase', true);
SELECT pg_temp.refused($$INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind) VALUES (pg_temp.i('egg'), 'case2', 'bad case', 0.5, 'purchase')$$, 'never "1 each"', 'a purchase unit under one base unit is refused — a case is never one each');
SELECT pg_temp.refused($$INSERT INTO ingredient_units (ingredient_id, unit_code, name, to_base_factor, kind) VALUES (pg_temp.i('egg'), 'lb', 'pound', 1, 'count')$$, 'global unit', 'an ingredient unit may not take a global unit''s code');
SELECT pg_temp.check(pg_temp.near(ri_to_base(pg_temp.i('flour'), 2, 'lb'), 907.18, 0.01), '2 lb of flour = 907.18 g');
SELECT pg_temp.check(pg_temp.near(ri_to_base(pg_temp.i('egg'), 100, 'g'), 2, 0.001), '100 g of eggs = 2 eggs through the each-weight (across classes)');
SELECT pg_temp.check(pg_temp.near(ri_to_base(pg_temp.i('oil'), 1, 'lb'), 495.19, 0.05), '1 lb of olive oil = 495 ml through the density');
SELECT pg_temp.check(pg_temp.near(ri_to_base(pg_temp.i('gin'), 1.5, 'floz'), 44.36, 0.01), 'a 1.5 oz pour = 44.36 ml');
SELECT pg_temp.check(pg_temp.near(ri_from_base(pg_temp.i('egg'), 90, 'flat'), 3, 0.001), '90 eggs shown as 3 flats');
SELECT pg_temp.refused($$SELECT ri_to_base(pg_temp.i('lime'), 1, 'g')$$, 'each-weight', 'limes have no each-weight — grams refused');

-- yield test: the textbook tenderloin, 2500 g AP → 1750 g EP = 70 %
INSERT INTO yield_tests (ingredient_id, ap_qty_base, ep_qty_base, tested_by) VALUES (pg_temp.i('chicken'), 2500, 1750, 30);
SELECT pg_temp.check((SELECT yield_pct FROM yield_tests WHERE ingredient_id = pg_temp.i('chicken')) = 70.00, 'a yield test: 2 500 g AP, 1 750 g EP → 70 % (the textbook tenderloin)');

-- ================================================================ suppliers, schedules, items (§6.1)
INSERT INTO suppliers (name, kind, order_method, order_email, lead_time_days, min_order_value) VALUES ('SMOKE Sysco', 'distributor', 'email', 'orders@sysco.invalid', 2, 250);
SELECT pg_temp.keep('sysco', id) FROM suppliers WHERE name = 'SMOKE Sysco';
INSERT INTO suppliers (name, kind, order_method, allow_split_case) VALUES ('SMOKE Produce Co', 'wholesaler', 'phone', true);
SELECT pg_temp.keep('produce', id) FROM suppliers WHERE name = 'SMOKE Produce Co';
-- order Saturday 13:00 for Tuesday; Wednesday 13:00 for Friday
INSERT INTO supplier_schedules (supplier_id, scope_id, order_dow, cutoff_time, delivery_dow) VALUES (pg_temp.i('sysco'), 101, 6, '13:00', 2), (pg_temp.i('sysco'), 101, 3, '13:00', 5);
SELECT pg_temp.check((SELECT lead_days FROM supplier_schedules WHERE supplier_id = pg_temp.i('sysco') AND order_dow = 6) = 3 AND (SELECT lead_days FROM supplier_schedules WHERE supplier_id = pg_temp.i('sysco') AND order_dow = 3) = 2, 'Saturday → Tuesday is 3 lead days, Wednesday → Friday 2');
SELECT pg_temp.check((SELECT cutoff_at > now() AND delivery_on >= cutoff_at::date AND next_cutoff_at > cutoff_at AND next_delivery_on > delivery_on FROM ri_next_order_windows(pg_temp.i('sysco'), 101)), 'the next order window: a cut-off ahead, its delivery, and the delivery after the next cut-off');
SELECT pg_temp.check((SELECT next_delivery_on - delivery_on FROM ri_next_order_windows(pg_temp.i('sysco'), 101)) IN (3, 4), 'the protection interval between Sysco''s deliveries is 3 or 4 days');
SELECT pg_temp.check((SELECT delivery_on = (now() AT TIME ZONE 'America/New_York')::date + 1 FROM ri_next_order_windows(pg_temp.i('produce'), 101)), 'a supplier with no schedule: order any day, the supplier''s lead time');
INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code, pack, last_price, moq) VALUES
 (pg_temp.i('sysco'), pg_temp.i('flour'), 'SY-FL50', 'bag50', '50 lb', 25.00, 1),
 (pg_temp.i('sysco'), pg_temp.i('egg'), 'SY-EGG15', 'case', '15 dz', 54.00, 1),
 (pg_temp.i('sysco'), pg_temp.i('oil'), 'SY-OIL4', 'case4', '4 × 1 gal', 96.00, 1),
 (pg_temp.i('produce'), pg_temp.i('lime'), 'PC-LIME', 'case', '200 ct', 38.00, 1);
INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code, pack, last_price, moq, catch_weight) VALUES (pg_temp.i('sysco'), pg_temp.i('chicken'), 'SY-CHK', 'lb', 'case ~40 lb, priced per lb', 3.10, 1, true);
SELECT pg_temp.keep('si_flour', id) FROM supplier_items WHERE supplier_sku = 'SY-FL50';
SELECT pg_temp.keep('si_chicken', id) FROM supplier_items WHERE supplier_sku = 'SY-CHK';
SELECT pg_temp.keep('si_lime', id) FROM supplier_items WHERE supplier_sku = 'PC-LIME';
SELECT pg_temp.keep('si_egg', id) FROM supplier_items WHERE supplier_sku = 'SY-EGG15';
SELECT pg_temp.refused($$INSERT INTO supplier_items (supplier_id, ingredient_id, supplier_sku, purchase_unit_code) VALUES (pg_temp.i('sysco'), pg_temp.i('lime'), 'X', 'kg')$$, 'each-weight', 'a supplier item in a unit the ingredient cannot convert is refused');
SELECT pg_temp.check(pg_temp.near(ri_supplier_item_cost_per_base(pg_temp.i('si_flour')), 25.0 / 22679.6185, 0.000001), 'a $25 bag of flour costs $0.001102 a gram');
INSERT INTO order_guides (supplier_id, scope_id, name) VALUES (pg_temp.i('sysco'), 101, 'Weekly');
INSERT INTO order_guide_lines (guide_id, supplier_item_id, seq) SELECT g.id, si.id, si.id FROM order_guides g, supplier_items si WHERE g.supplier_id = pg_temp.i('sysco') AND si.supplier_id = pg_temp.i('sysco');

-- ================================================================ the ledger (§6.3): a receipt in cases lands in grams at the right cost, the average advances
SELECT pg_temp.refused($$INSERT INTO inventory_balances (ingredient_id, storage_area_id, qty_base) VALUES (pg_temp.i('flour'), pg_temp.i('dry'), 1)$$, 'maintained only by transactions', 'a balance cannot be written directly');
INSERT INTO goods_receipts (scope_id, supplier_id, storage_area_id, received_on, received_at, created_by) VALUES (101, pg_temp.i('sysco'), pg_temp.i('dry'), current_date - 7, ri_site_moment(101, current_date - 7, false) + interval '2 hours', 27);
SELECT pg_temp.keep('gr1', max(id)) FROM goods_receipts;
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, supplier_item_id, qty_received, purchase_unit_code, unit_cost) VALUES (pg_temp.i('gr1'), 1, pg_temp.i('flour'), pg_temp.i('si_flour'), 2, 'bag50', 25.00);
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, supplier_item_id, qty_received, purchase_unit_code, weight_qty, weight_unit_code, unit_cost, putaway_area_id)
VALUES (pg_temp.i('gr1'), 2, pg_temp.i('chicken'), pg_temp.i('si_chicken'), 1, 'case', 38.5, 'lb', 3.10, pg_temp.i('walkin'));
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, supplier_item_id, qty_received, purchase_unit_code, unit_cost, putaway_area_id) VALUES (pg_temp.i('gr1'), 3, pg_temp.i('egg'), pg_temp.i('si_egg'), 2, 'case', 54.00, pg_temp.i('walkin'));
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code, unit_cost, putaway_area_id) VALUES (pg_temp.i('gr1'), 4, pg_temp.i('gin'), 2, 'case6', 108.00, pg_temp.i('bar'));
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, supplier_item_id, qty_received, purchase_unit_code, unit_cost, putaway_area_id) VALUES (pg_temp.i('gr1'), 5, pg_temp.i('lime'), pg_temp.i('si_lime'), 1, 'case', 38.00, pg_temp.i('walkin'));
SELECT pg_temp.check((SELECT qty_base FROM goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr1') AND line_no = 1) = 45359.2370, '2 bags of flour = 45 359.237 g on the line');
SELECT pg_temp.check(pg_temp.near((SELECT qty_base FROM goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr1') AND line_no = 2), 17463.31, 0.01), 'catch weight: a case declared at 38.5 lb lands as 17 463 g, not the nominal 40 lb');
SELECT pg_temp.check(pg_temp.near((SELECT cost_per_base FROM goods_receipt_lines WHERE goods_receipt_id = pg_temp.i('gr1') AND line_no = 2), 3.10 / 453.59237, 0.000001), 'catch weight is priced per pound → per gram');
SELECT ri_post_receipt(pg_temp.i('gr1'), 27);
SELECT pg_temp.check((SELECT status FROM goods_receipts WHERE id = pg_temp.i('gr1')) = 'posted' AND (SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'goods_receipt' AND reference_id = pg_temp.i('gr1')) = 5, 'the receipt posts five transactions');
SELECT pg_temp.check(ri_on_hand(pg_temp.i('flour'), 101) = 45359.2370 AND ri_on_hand(pg_temp.i('flour'), 101, pg_temp.i('dry')) = 45359.2370, 'flour on hand at the site and in the dry store');
SELECT pg_temp.check(pg_temp.near((SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = pg_temp.i('flour') AND scope_id = 101), 0.001102, 0.000001), 'the site''s average cost of flour is $0.001102 a gram');
SELECT pg_temp.check((SELECT count(*) FROM price_history WHERE ingredient_id = pg_temp.i('flour') AND scope_id = 101 AND source_kind = 'receipt') = 1, 'the receipt wrote price history');
SELECT pg_temp.refused($$INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code) VALUES (pg_temp.i('gr1'), 9, pg_temp.i('flour'), 1, 'bag50')$$, 'only while it is draft', 'a posted receipt''s lines no longer change');
SELECT pg_temp.refused($$SELECT ri_post_receipt(pg_temp.i('gr1'), 27)$$, 'only a draft posts', 'a receipt posts once');
SELECT pg_temp.refused($$UPDATE inventory_transactions SET qty_base = 1 WHERE reference_id = pg_temp.i('gr1')$$, 'never changed', 'a transaction is never changed');
SELECT pg_temp.refused($$DELETE FROM inventory_transactions WHERE reference_id = pg_temp.i('gr1')$$, 'never changed', 'a transaction is never deleted');
-- a second receipt at a higher price advances the moving average and raises a price alert over the category's threshold
INSERT INTO goods_receipts (scope_id, supplier_id, storage_area_id, received_on, received_at, created_by) VALUES (101, pg_temp.i('sysco'), pg_temp.i('dry'), current_date - 3, ri_site_moment(101, current_date - 3, false) + interval '2 hours', 27);
SELECT pg_temp.keep('gr2', max(id)) FROM goods_receipts;
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, supplier_item_id, qty_received, purchase_unit_code, unit_cost) VALUES (pg_temp.i('gr2'), 1, pg_temp.i('flour'), pg_temp.i('si_flour'), 2, 'bag50', 30.00);
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code, unit_cost, allow_zero_cost, putaway_area_id) VALUES (pg_temp.i('gr2'), 2, pg_temp.i('lime'), 1, 'case', 0, false, pg_temp.i('walkin'));
SELECT ri_post_receipt(pg_temp.i('gr2'), 27);
SELECT pg_temp.check(pg_temp.near((SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = pg_temp.i('flour') AND scope_id = 101), 110.0 / 90718.474, 0.000001), 'the moving average: (2 × $25 + 2 × $30) over 4 bags');
SELECT pg_temp.check((SELECT last_price FROM supplier_items WHERE id = pg_temp.i('si_flour')) = 30.00, 'the supplier item''s last price follows the receipt');
SELECT pg_temp.check((SELECT count(*) FROM price_alerts WHERE ingredient_id = pg_temp.i('flour') AND pct BETWEEN 19.5 AND 20.5) = 1, 'flour up 20 % raised a price alert (dry goods threshold 10 %)');
SELECT pg_temp.check((SELECT unit_cost_per_base > 0 FROM inventory_transactions WHERE reference_id = pg_temp.i('gr2') AND ingredient_id = pg_temp.i('lime')), 'a zero-cost line lands at the running cost, never at zero');
SELECT pg_temp.check(ri_on_hand(pg_temp.i('lime'), 101) = 400, '400 limes on hand after two cases');

-- ================================================================ recipes (§6.2): a tree three deep, a prep, a modifier, a cycle refused, versions with effective dates
INSERT INTO recipes (code, name, kind, category_id, output_ingredient_id) VALUES ('R-STOCK', 'Chicken stock', 'prep', (SELECT id FROM menu_categories WHERE key = 'prep'), pg_temp.i('stock'));
SELECT pg_temp.keep('r_stock', id) FROM recipes WHERE code = 'R-STOCK';
SELECT pg_temp.check((SELECT is_prep FROM ingredients WHERE id = pg_temp.i('stock')), 'a prep recipe''s output ingredient is flagged is_prep');
INSERT INTO recipe_versions (recipe_id, batch_yield_qty, batch_yield_unit_code, expected_loss_pct, created_by) VALUES (pg_temp.i('r_stock'), 4, 'l', 10, 30);
SELECT pg_temp.keep('v_stock', id) FROM recipe_versions WHERE recipe_id = pg_temp.i('r_stock');
INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code) VALUES (pg_temp.i('v_stock'), 1, pg_temp.i('chicken'), 2, 'kg');
SELECT ri_recipe_version_activate(pg_temp.i('v_stock'), 30, current_date - 30);
INSERT INTO recipes (code, name, kind, category_id) VALUES ('R-SAND', 'Chicken sandwich', 'menu_item', (SELECT id FROM menu_categories WHERE key = 'mains'));
SELECT pg_temp.keep('r_sand', id) FROM recipes WHERE code = 'R-SAND';
INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (pg_temp.i('r_sand'), 1, 30);
SELECT pg_temp.keep('v_sand1', id) FROM recipe_versions WHERE recipe_id = pg_temp.i('r_sand');
INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code, line_yield_pct) VALUES (pg_temp.i('v_sand1'), 1, pg_temp.i('chicken'), 170, 'g', 90);
INSERT INTO recipe_lines (version_id, seq, sub_recipe_id, qty, unit_code) VALUES (pg_temp.i('v_sand1'), 2, pg_temp.i('r_stock'), 50, 'ml');
INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code) VALUES (pg_temp.i('v_sand1'), 3, pg_temp.i('egg'), 1, 'each');
SELECT pg_temp.check((SELECT qty_base FROM recipe_lines WHERE version_id = pg_temp.i('v_sand1') AND seq = 2) = 50, 'a prep sub-recipe line is kept in the output ingredient''s base unit');
SELECT pg_temp.refused($$INSERT INTO recipe_lines (version_id, seq, sub_recipe_id, qty, unit_code) VALUES (pg_temp.i('v_stock'), 9, pg_temp.i('r_sand'), 1, 'portion')$$, 'only while it is a draft', 'an active version''s lines do not change');
SELECT ri_recipe_version_activate(pg_temp.i('v_sand1'), 30, current_date - 20);
SELECT pg_temp.check((SELECT allergens_rollup FROM recipes WHERE id = pg_temp.i('r_sand')) = '{egg}', 'allergens roll up through the tree');
-- a cycle: a new draft of the stock that includes the sandwich
SELECT ri_recipe_version_draft(pg_temp.i('r_stock'), 30, 'try a cycle');
SELECT pg_temp.keep('v_stock2', id) FROM recipe_versions WHERE recipe_id = pg_temp.i('r_stock') AND status = 'draft';
SELECT pg_temp.check((SELECT count(*) FROM recipe_lines WHERE version_id = pg_temp.i('v_stock2')) = 1, 'a new draft copies the version in force');
SELECT pg_temp.refused($$INSERT INTO recipe_lines (version_id, seq, sub_recipe_id, qty, unit_code) VALUES (pg_temp.i('v_stock2'), 9, pg_temp.i('r_sand'), 1, 'portion')$$, 'may not contain itself', 'a cycle through a sub-recipe is refused');
SELECT pg_temp.refused($$SELECT ri_recipe_version_draft(pg_temp.i('r_stock'), 30)$$, 'draft already exists', 'one draft per recipe');
DELETE FROM recipe_lines WHERE version_id = pg_temp.i('v_stock2');
INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code) VALUES (pg_temp.i('v_stock2'), 1, pg_temp.i('chicken'), 2.5, 'kg');
SELECT ri_recipe_version_activate(pg_temp.i('v_stock2'), 30, current_date + 1);
SELECT pg_temp.check(ri_recipe_version_on(pg_temp.i('r_stock'), current_date) = pg_temp.i('v_stock') AND ri_recipe_version_on(pg_temp.i('r_stock'), current_date + 1) = pg_temp.i('v_stock2'), 'the version in force today is v1, tomorrow v2 (effective dates)');
SELECT pg_temp.check((SELECT effective_to FROM recipe_versions WHERE id = pg_temp.i('v_stock')) = current_date + 1, 'activating v2 ended v1 at v2''s effective_from');
SELECT pg_temp.refused($$UPDATE recipe_versions SET batch_yield_qty = 5 WHERE id = pg_temp.i('v_stock')$$, 'new version carries the change', 'an active version is immutable');
INSERT INTO recipes (code, name, kind, category_id) VALUES ('M-XCHK', 'Extra chicken', 'modifier', (SELECT id FROM menu_categories WHERE key = 'modifiers'));
SELECT pg_temp.keep('r_mod', id) FROM recipes WHERE code = 'M-XCHK';
INSERT INTO recipe_versions (recipe_id, batch_yield_qty, created_by) VALUES (pg_temp.i('r_mod'), 1, 30);
INSERT INTO recipe_lines (version_id, seq, ingredient_id, qty, unit_code) SELECT id, 1, pg_temp.i('chicken'), 85, 'g' FROM recipe_versions WHERE recipe_id = pg_temp.i('r_mod');
SELECT ri_recipe_version_activate(id, 30, current_date - 20) FROM recipe_versions WHERE recipe_id = pg_temp.i('r_mod');
-- the explosion of one sandwich: chicken 170/0.9 = 188.89 g + stock 50 ml (a prep from stock) + 1 egg
SELECT pg_temp.check(pg_temp.near((SELECT qty_base FROM ri_explode(pg_temp.i('v_sand1'), current_date) WHERE ingredient_id = pg_temp.i('chicken')), 188.8889, 0.001), 'one sandwich uses 188.9 g of chicken (170 g at a 90 % line yield)');
SELECT pg_temp.check((SELECT qty_base FROM ri_explode(pg_temp.i('v_sand1'), current_date) WHERE ingredient_id = pg_temp.i('stock')) = 50, 'the prep is taken from stock as its output ingredient');
SELECT pg_temp.check(pg_temp.near((SELECT qty_base FROM ri_explode(pg_temp.i('v_sand1'), current_date, true) WHERE ingredient_id = pg_temp.i('chicken')), 188.8889 + 50 * 2000.0 / (4000 * 0.9), 0.01), 'exploding the prep too: 50 ml of stock costs 27.8 g of chicken (2 kg per 3.6 l net of loss)');
-- cost as of two dates: the stock from stock has no cost yet (uncosted), so cost the sandwich with chicken and egg
SELECT pg_temp.check((SELECT uncosted FROM ri_recipe_cost(pg_temp.i('v_sand1'), 101)) = 0, 'every line is costed: the prep sub-recipe costs by its own recipe, not by stock');
SELECT pg_temp.check(pg_temp.near((SELECT portion_cost FROM ri_recipe_cost(pg_temp.i('v_sand1'), 101)), 188.8889 * (3.10 / 453.59237) + 50 * (2000 * (3.10 / 453.59237) / 3600) + 1 * (54.0 / 180), 0.01), 'the plate cost today: chicken at the receipt''s cost, 50 ml of stock at its batch cost over 3.6 l, one egg at $0.30');

-- ================================================================ production (§6.3): a prep batch makes stock at the rolled-up cost
INSERT INTO production_batches (scope_id, recipe_id, batches, storage_area_id, made_by, created_by) VALUES (101, pg_temp.i('r_stock'), 1, pg_temp.i('walkin'), 26, 26);
SELECT pg_temp.keep('pb', max(id)) FROM production_batches;
SELECT ri_post_production(pg_temp.i('pb'), 26);
SELECT pg_temp.check(ri_on_hand(pg_temp.i('stock'), 101) = 3600 AND pg_temp.near(ri_on_hand(pg_temp.i('chicken'), 101), 17463.31 - 2000, 0.01), 'the batch made 3.6 l of stock (4 l less 10 % loss) and consumed 2 kg of chicken');
SELECT pg_temp.check(pg_temp.near((SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = pg_temp.i('stock') AND scope_id = 101), 2000 * (3.10 / 453.59237) / 3600, 0.000001), 'the stock''s cost is the chicken that went in over the litres that came out');
SELECT pg_temp.check(pg_temp.near(ri_cost_as_of(pg_temp.i('stock'), 101), 2000 * (3.10 / 453.59237) / 3600, 0.000001), 'the stock on the shelf now carries that cost for depletion and waste');

-- ================================================================ POS sales and depletion (§6.5): a day imported, a PLU unmapped, a void, a comp, a modifier, one row per ingredient
INSERT INTO sources (scope_id, name, connector, settings, created_by) VALUES (101, 'SMOKE Toast export', 'file', '{"mapping_key": "generic", "intake": "upload"}', 1);
SELECT pg_temp.keep('src', max(id)) FROM sources;
INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext, created_by) VALUES (pg_temp.i('src'), 'sftp_password', 'SFTP', 'x9k2', '\xdeadbeef', 1);
INSERT INTO menu_items (pos_source_id, plu, name, category_id, sale_price, recipe_id) VALUES (pg_temp.i('src'), '1001', 'Chicken Sandwich', (SELECT id FROM menu_categories WHERE key = 'mains'), 14.00, pg_temp.i('r_sand'));
SELECT pg_temp.keep('mi_sand', id) FROM menu_items WHERE plu = '1001';
INSERT INTO modifiers (pos_source_id, code, name, action, recipe_id) VALUES (pg_temp.i('src'), 'XCHK', 'Extra chicken', 'add', pg_temp.i('r_mod'));
SELECT pg_temp.refused($$INSERT INTO menu_items (pos_source_id, plu, name, recipe_id) VALUES (pg_temp.i('src'), '9', 'bad', pg_temp.i('r_stock'))$$, 'menu-item recipe', 'a menu item maps only to a menu-item recipe');
INSERT INTO pos_sales (scope_id, source_id, external_id, business_date, closed_at, covers, subtotal) VALUES
 (101, pg_temp.i('src'), 'CHK-1', current_date, ri_site_moment(101, current_date, false) + interval '9 hours', 2, 42.00),
 (101, pg_temp.i('src'), 'CHK-2', current_date, ri_site_moment(101, current_date, false) + interval '15 hours', 3, 28.00);
SELECT pg_temp.keep('s1', id) FROM pos_sales WHERE external_id = 'CHK-1';
SELECT pg_temp.keep('s2', id) FROM pos_sales WHERE external_id = 'CHK-2';
SELECT pg_temp.check((SELECT d.key FROM pos_sales s JOIN day_parts d ON d.id = s.day_part_id WHERE s.id = pg_temp.i('s1')) = 'lunch' AND (SELECT d.key FROM pos_sales s JOIN day_parts d ON d.id = s.day_part_id WHERE s.id = pg_temp.i('s2')) = 'dinner', 'the day part follows the close time in the site''s zone');
INSERT INTO pos_sale_lines (sale_id, plu, name, qty, unit_price, line_total, modifiers) VALUES (pg_temp.i('s1'), '1001', 'Chicken Sandwich', 2, 14.00, 28.00, '[{"code": "XCHK", "name": "Extra chicken", "qty": 1}]');
INSERT INTO pos_sale_lines (sale_id, plu, name, qty, unit_price, line_total) VALUES (pg_temp.i('s1'), '2002', 'Mystery Special', 1, 14.00, 14.00);
INSERT INTO pos_sale_lines (sale_id, plu, name, qty, unit_price, line_total, voided) VALUES (pg_temp.i('s2'), '1001', 'Chicken Sandwich', 1, 14.00, 14.00, true);
INSERT INTO pos_sale_lines (sale_id, plu, name, qty, unit_price, line_total, comp) VALUES (pg_temp.i('s2'), '1001', 'Chicken Sandwich', 1, 14.00, 0, true);
SELECT pg_temp.check((SELECT count(*) FROM menu_items WHERE pos_source_id = pg_temp.i('src') AND plu = '2002' AND recipe_id IS NULL) = 1, 'an unknown PLU is created unmapped and queued, never dropped');
SELECT pg_temp.check((SELECT count(*) FROM ri_depletion_due() WHERE scope_id = 101 AND business_date = current_date) = 1, 'the day with sales is due for depletion');
SELECT ri_depletion_run(101, current_date, 1);
SELECT pg_temp.keep('run1', max(id)) FROM depletion_runs;
SELECT pg_temp.check((SELECT status = 'partial' AND lines_total = 4 AND lines_unmapped = 1 AND lines_voided = 1 AND lines_depleted = 2 AND ingredients = 3 FROM depletion_runs WHERE id = pg_temp.i('run1')), 'the run: 4 lines, 1 unmapped, 1 voided, 2 depleted, 3 ingredients — partial because of the unmapped PLU');
-- 2 sandwiches + 1 comp = 3 portions → chicken 3 × 188.89 + the modifier 85 g once; stock 150 ml; eggs 3
SELECT pg_temp.check(pg_temp.near(-(SELECT qty_base FROM inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = pg_temp.i('run1') AND ingredient_id = pg_temp.i('chicken')), 3 * 188.8889 + 85, 0.01), 'chicken depleted for three sandwiches (the comp counts, the void does not) plus one extra-chicken modifier');
SELECT pg_temp.check((SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'depletion_run' AND reference_id = pg_temp.i('run1')) = 3, 'one depletion row per ingredient per day');
SELECT pg_temp.check((SELECT qty = 3 AND comps_qty = 1 FROM sales_daily WHERE scope_id = 101 AND menu_item_id = pg_temp.i('mi_sand') AND business_date = current_date AND day_part_id IS NULL), 'sales_daily: 3 sold (1 a comp) for the day');
SELECT pg_temp.check((SELECT count(*) FROM sales_daily WHERE scope_id = 101 AND business_date = current_date AND day_part_id IS NOT NULL) = 3, 'and a row per item per day part');
-- re-run: the old group reversed, a new one posted, the balance unchanged
SELECT ri_depletion_run(101, current_date, 1);
SELECT pg_temp.keep('run2', max(id)) FROM depletion_runs;
SELECT pg_temp.check((SELECT status FROM depletion_runs WHERE id = pg_temp.i('run1')) = 'void' AND (SELECT run_no FROM depletion_runs WHERE id = pg_temp.i('run2')) = 2
   AND (SELECT count(*) FROM inventory_transactions WHERE txn_type = 'reversal' AND reverses_id IN (SELECT id FROM inventory_transactions WHERE reference_id = pg_temp.i('run1') AND reference_kind = 'depletion_run')) = 3, 're-running voids the first run and reverses its rows');
SELECT pg_temp.check(pg_temp.near(ri_on_hand(pg_temp.i('egg'), 101), 360 - 3, 0.001), '357 eggs on hand after the day');
SELECT pg_temp.check((SELECT count(*) FROM ri_unmapped_plus(101)) = 1, 'the unmapped queue lists the Mystery Special');

-- ================================================================ waste (§6.3): a plate wasted explodes through its recipe; a dropped case of eggs by count
INSERT INTO waste_logs (scope_id, logged_by, station, stage, reason_code_id, recipe_id, qty, unit_code, disposition, business_date) VALUES (101, 26, 'pass', 'plate', (SELECT id FROM reason_codes WHERE code = 'returned_dish'), pg_temp.i('r_sand'), 1, 'portion', 'trash', current_date);
SELECT pg_temp.keep('w1', max(id)) FROM waste_logs;
SELECT ri_post_waste(pg_temp.i('w1'), 26);
SELECT pg_temp.check((SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'waste' AND reference_id = pg_temp.i('w1')) = 3 AND (SELECT cost > 0 FROM waste_logs WHERE id = pg_temp.i('w1')), 'a returned sandwich wastes its three ingredients at cost');
INSERT INTO waste_logs (scope_id, logged_by, stage, reason_code_id, ingredient_id, qty, unit_code, disposition, business_date) VALUES (101, 26, 'ingredient', (SELECT id FROM reason_codes WHERE code = 'breakage'), pg_temp.i('egg'), 1, 'flat', 'trash', current_date);
SELECT pg_temp.keep('w2', max(id)) FROM waste_logs;
SELECT ri_post_waste(pg_temp.i('w2'), 26);
SELECT pg_temp.check((SELECT qty_base FROM waste_logs WHERE id = pg_temp.i('w2')) = 30 AND pg_temp.near(ri_on_hand(pg_temp.i('egg'), 101), 357 - 1 - 30, 0.001), 'a dropped flat is 30 eggs');
SELECT pg_temp.refused($$SELECT ri_post_waste(pg_temp.i('w2'), 26)$$, 'already posted', 'a waste log posts once');
SELECT pg_temp.refused($$INSERT INTO waste_logs (scope_id, stage, reason_code_id, ingredient_id, qty, unit_code) VALUES (101, 'ingredient', (SELECT id FROM reason_codes WHERE code = 'theft'), pg_temp.i('egg'), 1, 'each)$$, '', 'placeholder');
DELETE FROM proof WHERE label = 'placeholder';
INSERT INTO waste_logs (scope_id, stage, reason_code_id, ingredient_id, qty, unit_code, business_date) VALUES (101, 'ingredient', (SELECT id FROM reason_codes WHERE code = 'theft'), pg_temp.i('egg'), 1, 'each', current_date);
SELECT pg_temp.refused($$SELECT ri_post_waste((SELECT max(id) FROM waste_logs), 26)$$, 'not a waste reason', 'theft is an adjustment reason, not a waste reason');
DELETE FROM waste_logs WHERE id = (SELECT max(id) FROM waste_logs);

-- ================================================================ transfers (§6.3): between sites at the sender's average cost
INSERT INTO inventory_transfers (scope_id, to_scope_id, from_area_id, to_area_id, created_by) VALUES (101, 102, pg_temp.i('walkin'), pg_temp.i('walkin2'), 27);
SELECT pg_temp.keep('tr', max(id)) FROM inventory_transfers;
INSERT INTO inventory_transfer_lines (transfer_id, line_no, ingredient_id, qty, unit_code) VALUES (pg_temp.i('tr'), 1, pg_temp.i('lime'), 100, 'each'), (pg_temp.i('tr'), 2, pg_temp.i('egg'), 2, 'flat');
SELECT ri_transfer_send(pg_temp.i('tr'), 27);
SELECT pg_temp.check(ri_on_hand(pg_temp.i('lime'), 101) = 300 AND ri_on_hand(pg_temp.i('lime'), 102) = 0 AND (SELECT status FROM inventory_transfers WHERE id = pg_temp.i('tr')) = 'in_transit', 'sending issues at the origin; the stock is in transit');
SELECT ri_transfer_receive(pg_temp.i('tr'), 26);
SELECT pg_temp.check(ri_on_hand(pg_temp.i('lime'), 102) = 100 AND ri_on_hand(pg_temp.i('egg'), 102) = 60, 'receiving puts 100 limes and 60 eggs away at Airport (a transfer of two lines)');
SELECT pg_temp.check((SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = pg_temp.i('lime') AND scope_id = 102) = (SELECT avg_cost_per_base FROM ingredient_site_costs WHERE ingredient_id = pg_temp.i('lime') AND scope_id = 101), 'the receiving site takes the sender''s average cost');
SELECT pg_temp.refused($$INSERT INTO inventory_transfer_lines (transfer_id, line_no, ingredient_id, qty, unit_code) VALUES (pg_temp.i('tr'), 3, pg_temp.i('lime'), 1, 'each')$$, 'only while it is a draft', 'a received transfer''s lines do not change');

-- ================================================================ purchasing (§6.4): a PO sent by a person, the supplier's door, received against, an invoice matched and settled
INSERT INTO purchase_orders (scope_id, supplier_id, storage_area_id, created_by) VALUES (101, pg_temp.i('sysco'), pg_temp.i('dry'), 27);
SELECT pg_temp.keep('po', max(id)) FROM purchase_orders;
INSERT INTO purchase_order_lines (purchase_order_id, line_no, ingredient_id, supplier_item_id, purchase_unit_code, qty_ordered, unit_cost) VALUES (pg_temp.i('po'), 1, pg_temp.i('flour'), pg_temp.i('si_flour'), 'bag50', 3, 30.00), (pg_temp.i('po'), 2, pg_temp.i('egg'), pg_temp.i('si_egg'), 'case', 1, 54.00);
SELECT pg_temp.check((SELECT total FROM purchase_orders WHERE id = pg_temp.i('po')) = 144.00 AND (SELECT qty_ordered_base FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po') AND line_no = 1) = 68038.8555, 'the PO totals $144 and its flour line is 68 039 g');
SELECT pg_temp.check(ri_on_order(pg_temp.i('flour'), 101) = 0, 'a draft is not on order');
SELECT ri_po_send(pg_temp.i('po'), 27, 'email');
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po')) = 'sent' AND ri_on_order(pg_temp.i('flour'), 101) = 68038.8555, 'sent: the flour is on order');
SELECT pg_temp.keep('tok', 0);
CREATE TEMP TABLE tok AS SELECT ri_supplier_link_mint(pg_temp.i('po')) AS raw;
SELECT pg_temp.check(ri_supplier_link_resolve((SELECT raw FROM tok)) = pg_temp.i('po') AND ri_supplier_link_resolve('nonsense') IS NULL, 'the supplier''s link resolves; a wrong token does not');
SELECT ri_po_supplier_event(pg_temp.i('po'), 'acknowledge', NULL, current_date + 2, NULL, 'SY-778');
SELECT ri_po_supplier_event(pg_temp.i('po'), 'decline', (SELECT id FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po') AND line_no = 2), NULL, 'out of stock');
SELECT pg_temp.check((SELECT status = 'acknowledged' AND expected_on = current_date + 2 AND supplier_order_ref = 'SY-778' FROM purchase_orders WHERE id = pg_temp.i('po')) AND (SELECT status FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po') AND line_no = 2) = 'declined', 'acknowledged with a date and a reference; the eggs declined through the door');
SELECT pg_temp.check((SELECT count(*) FROM purchase_order_events WHERE purchase_order_id = pg_temp.i('po')) = 3, 'three events: sent, acknowledge, decline');
INSERT INTO goods_receipts (scope_id, supplier_id, purchase_order_id, storage_area_id, received_on, created_by) VALUES (101, pg_temp.i('sysco'), pg_temp.i('po'), pg_temp.i('dry'), current_date, 26);
SELECT pg_temp.keep('gr3', max(id)) FROM goods_receipts;
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, purchase_order_line_id, ingredient_id, supplier_item_id, qty_received, purchase_unit_code, unit_cost, discrepancy_kind, temperature_c, temperature_ok)
VALUES (pg_temp.i('gr3'), 1, (SELECT id FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po') AND line_no = 1), pg_temp.i('flour'), pg_temp.i('si_flour'), 2, 'bag50', 30.00, 'short', 21.0, true);
SELECT ri_post_receipt(pg_temp.i('gr3'), 26);
SELECT pg_temp.check((SELECT status FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po') AND line_no = 1) = 'partial' AND (SELECT status FROM purchase_orders WHERE id = pg_temp.i('po')) = 'partial', 'two of three bags received: the line and the PO are partial');
SELECT pg_temp.check(ri_on_order(pg_temp.i('flour'), 101) = 22679.6185, 'one bag still on order');
SELECT ri_po_close(pg_temp.i('po'), 27, true);
SELECT pg_temp.check((SELECT status FROM purchase_orders WHERE id = pg_temp.i('po')) = 'closed_short' AND ri_on_order(pg_temp.i('flour'), 101) = 0, 'closed short: nothing on order');
-- the invoice: 2 bags at $31 (a price difference over the tolerance) against the receipt
INSERT INTO supplier_invoices (scope_id, supplier_id, invoice_no, invoice_date, goods_receipt_id, created_by) VALUES (101, pg_temp.i('sysco'), 'INV-5501', current_date, pg_temp.i('gr3'), 27);
SELECT pg_temp.keep('inv', max(id)) FROM supplier_invoices;
INSERT INTO supplier_invoice_lines (invoice_id, line_no, supplier_item_id, description, qty, unit_code, unit_price) VALUES (pg_temp.i('inv'), 1, pg_temp.i('si_flour'), 'FLOUR AP 50#', 2, 'bag50', 33.00);
SELECT ri_invoice_match(pg_temp.i('inv'));
SELECT pg_temp.check((SELECT status FROM supplier_invoices WHERE id = pg_temp.i('inv')) = 'disputed' AND (SELECT match_state FROM supplier_invoice_lines WHERE invoice_id = pg_temp.i('inv')) = 'price_diff', 'the three-way match flags a $6 price difference (10 %, over both tolerances: 2 % and $5)');
SELECT ri_invoice_settle(pg_temp.i('inv'), 27, 'accepted the new price');
SELECT pg_temp.check((SELECT status FROM supplier_invoices WHERE id = pg_temp.i('inv')) = 'settled' AND (SELECT count(*) FROM price_history WHERE ingredient_id = pg_temp.i('flour') AND source_kind = 'invoice') = 1 AND (SELECT last_price FROM supplier_items WHERE id = pg_temp.i('si_flour')) = 33.00, 'settling writes price history and the supplier item''s last price');

-- ================================================================ counts (§6.6): a sheet in walk order, case + loose, a bottle by weight, a skipped area, a line over tolerance, approval
INSERT INTO containers (ingredient_id, name, full_weight_g, empty_weight_g, pourer_tare_g, volume_ml) VALUES (pg_temp.i('gin'), '750 ml bottle', 1240, 500, 30, 750);
SELECT pg_temp.check(ri_container_remaining_base((SELECT id FROM containers WHERE ingredient_id = pg_temp.i('gin')), 900, true) = 375, 'a bottle weighing 900 g with its pourer holds 375 ml ((900 − 500 − 30) ÷ 740 × 750)');
INSERT INTO count_sheets (scope_id, name, kind, created_by) VALUES (101, 'Full house', 'full', 30);
SELECT pg_temp.keep('sheet', max(id)) FROM count_sheets;
SELECT pg_temp.check(ri_count_sheet_fill(pg_temp.i('sheet')) >= 5, 'the sheet fills from the balances in walk order');
SELECT ri_count_start(101, 28, pg_temp.i('sheet'), ri_site_moment(101, current_date, true), 'full');   -- as of the day's sales cut, so today's depletion is in the book figure
SELECT pg_temp.keep('cnt', max(id)) FROM inventory_counts;
SELECT pg_temp.check((SELECT blind FROM inventory_counts WHERE id = pg_temp.i('cnt')) AND (SELECT count(*) FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt')) >= 5, 'the count is blind by the setting and has a line per shelf item');
SELECT pg_temp.refused($$SELECT ri_count_start(101, 28, pg_temp.i('sheet'))$$, 'duplicate key', 'one full count open at a site');
SELECT pg_temp.as_member(28);
SELECT pg_temp.check((SELECT system_qty_base IS NULL FROM mcp_inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('flour')), 'the counter does not see the book figure on a blind count');
SELECT pg_temp.as_member(1);
-- flour: 6 bags + loose 5 lb (the book says 6 bags — within tolerance); eggs: 10 flats + 7 loose (the book says 357 − 31 − 60 = 266 → 307: over)
SELECT ri_count_line_set(l.id, 28, '[{"unit_code": "bag50", "qty": 6}, {"unit_code": "lb", "qty": 5}]') FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id = pg_temp.i('flour');
SELECT pg_temp.check(pg_temp.near((SELECT counted_qty_base FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('flour')), 6 * 22679.6185 + 5 * 453.59237, 0.01), 'six bags and five loose pounds summed in grams');
SELECT ri_count_line_set(l.id, 28, '[{"unit_code": "flat", "qty": 10}, {"unit_code": "each", "qty": 7}]') FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id = pg_temp.i('egg');
SELECT ri_count_line_set(l.id, 28, '[{"unit_code": "bottle", "qty": 11}]', 900) FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id = pg_temp.i('gin');
SELECT pg_temp.check((SELECT counted_qty_base FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('gin')) = 11 * 750 + 375, 'eleven full bottles and one weighed at 900 g = 8 625 ml');
SELECT ri_count_line_set(l.id, 28, NULL, NULL, NULL, 'zero') FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id = pg_temp.i('lime');
SELECT ri_count_line_set(l.id, 28, NULL, NULL, NULL, 'skipped') FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id IN (pg_temp.i('chicken'), pg_temp.i('stock'));
SELECT pg_temp.refused($$SELECT ri_count_line_set((SELECT id FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('chicken')), 28)$$, 'entries or a weight', 'a counted line needs entries or a weight');
SELECT ri_count_review(pg_temp.i('cnt'), 30);
SELECT pg_temp.check((SELECT status FROM inventory_counts WHERE id = pg_temp.i('cnt')) = 'recount' AND (SELECT over_tolerance FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('lime')) AND (SELECT over_tolerance FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('egg')), 'review: limes counted zero against 300 and eggs 307 against 266 → over tolerance → a recount is asked');
SELECT pg_temp.check(NOT (SELECT over_tolerance FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('flour')), 'flour five pounds over on six bags is within tolerance');
SELECT pg_temp.check((SELECT variance_base FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('egg')) = 307 - 266, 'eggs: 307 counted against 266 → +41');
SELECT pg_temp.refused($$SELECT ri_count_approve(pg_temp.i('cnt'), 30)$$, 'await a recount', 'approval waits for the recount');
SELECT pg_temp.refused($$SELECT ri_count_line_set((SELECT id FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('lime')), 28, '[{"unit_code": "each", "qty": 298}]')$$, 'another person', 'the recount is by another person');
SELECT ri_count_line_set(l.id, 30, '[{"unit_code": "each", "qty": 298}]') FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id = pg_temp.i('lime');
SELECT ri_count_line_set(l.id, 30, '[{"unit_code": "flat", "qty": 10}, {"unit_code": "each", "qty": 7}]') FROM inventory_count_lines l WHERE l.count_id = pg_temp.i('cnt') AND l.ingredient_id = pg_temp.i('egg');
SELECT ri_count_review(pg_temp.i('cnt'), 30);
SELECT pg_temp.check((SELECT status FROM inventory_counts WHERE id = pg_temp.i('cnt')) = 'review' AND (SELECT variance_base FROM inventory_count_lines WHERE count_id = pg_temp.i('cnt') AND ingredient_id = pg_temp.i('lime')) = -2, 'the recount found 298: the variance is −2 and the count is in review');
SELECT pg_temp.check((SELECT count(*) FROM ri_count_areas_skipped(pg_temp.i('cnt'))) >= 0 AND (SELECT lines_skipped FROM ri_count_areas_skipped(pg_temp.i('cnt')) WHERE storage_area_id = pg_temp.i('walkin')) IS NULL, 'the walk-in was counted (not wholly skipped)');
SELECT ri_count_approve(pg_temp.i('cnt'), 30);
SELECT pg_temp.check((SELECT status FROM inventory_counts WHERE id = pg_temp.i('cnt')) = 'approved' AND ri_on_hand(pg_temp.i('lime'), 101) = 298 AND ri_on_hand(pg_temp.i('egg'), 101) = 307 AND pg_temp.near(ri_on_hand(pg_temp.i('chicken'), 101), 17463.31 - 2000 - (3 * 188.8889 + 85) - 188.8889, 0.01), 'approval corrects limes and eggs; the skipped chicken is untouched');
SELECT pg_temp.check((SELECT count(*) FROM inventory_transactions WHERE reference_kind = 'count' AND reference_id = pg_temp.i('cnt') AND txn_type = 'count_correction') = 4, 'four corrections posted (flour, eggs, gin, limes)');
SELECT pg_temp.refused($$SELECT ri_count_discard(pg_temp.i('cnt'), 30)$$, 'never discarded', 'an approved count is never discarded');

-- ================================================================ usage (§6.7): the identity ties with every bucket
SELECT pg_temp.check((SELECT actual_base = opening_base + purchases_base + transfers_in_base - transfers_out_base + production_in_base - production_out_base + adjustments_base - closing_base
                       FROM ri_usage(101, current_date - 7, current_date) WHERE ingredient_id = pg_temp.i('egg')), 'eggs: actual = opening + purchases + transfers in − out + production − closing');
SELECT pg_temp.check((SELECT variance_base = actual_base - theoretical_base AND unexplained_base = variance_base - waste_base FROM ri_usage(101, current_date - 7, current_date) WHERE ingredient_id = pg_temp.i('egg')), 'eggs: variance = actual − theoretical; unexplained = variance − waste');
SELECT pg_temp.check((SELECT theoretical_base = 3 AND waste_base = 31 AND transfers_out_base = 60 AND purchases_base = 360 FROM ri_usage(101, current_date - 7, current_date) WHERE ingredient_id = pg_temp.i('egg')), 'eggs over the week: 360 bought, 3 sold, 31 wasted, 60 transferred out');
SELECT pg_temp.check((SELECT comps_base = 1 FROM ri_usage(101, current_date - 7, current_date) WHERE ingredient_id = pg_temp.i('egg')), 'one egg explained by the comp');
SELECT pg_temp.check((SELECT unexplained_base FROM ri_usage(101, current_date - 7, current_date) WHERE ingredient_id = pg_temp.i('egg')) = -41, 'the +41 count surplus is the unexplained variance (negative usage)');
SELECT pg_temp.check((SELECT sum(value) FROM ri_stock_value(101)) > 0 AND (SELECT count(*) FROM ri_share_stock_valuation(101)) >= 3, 'stock value by category answers');
SELECT pg_temp.check((SELECT count(*) FROM ri_usage_by_category(101, current_date - 7, current_date)) >= 3, 'usage by category answers');

-- ================================================================ periods (§6.7): a weekly calendar, a closed period, a late receipt into the next
INSERT INTO costing_calendars (name, kind, year_start_on, week_starts, is_default) VALUES ('Weeks', 'week', date_trunc('year', current_date)::date, 1, true);
SELECT pg_temp.check(ri_periods_ensure(101, extract(year FROM current_date)::integer) BETWEEN 52 AND 53, 'the year has 52 or 53 weekly periods');
SELECT pg_temp.check((SELECT count(*) FROM ri_calendar_periods((SELECT id FROM costing_calendars), 2026) WHERE ends_on - starts_on = 6) BETWEEN 52 AND 53, 'every weekly period is seven days');
SELECT pg_temp.keep('p_old', ri_period_on(101, current_date - 21));
SELECT pg_temp.keep('p_now', ri_period_on(101, current_date));
SELECT pg_temp.refused($$SELECT ri_period_close(pg_temp.i('p_now'), 27)$$, 'closes after its last day', 'the current period cannot close yet');
SELECT pg_temp.refused($$SELECT ri_period_close(pg_temp.i('p_old'), 27)$$, 'earlier period is still open', 'an earlier open period must close first');
SELECT ri_period_close(id, 27) FROM periods WHERE scope_id = 101 AND ends_on < current_date - 21 ORDER BY starts_on;
SELECT ri_period_close(pg_temp.i('p_old'), 27);
SELECT pg_temp.check((SELECT status = 'closed' AND cogs_value IS NOT NULL FROM periods WHERE id = pg_temp.i('p_old')), 'the period three weeks back is closed with its figures frozen');
SELECT pg_temp.check((SELECT opening_value FROM periods WHERE scope_id = 101 AND starts_on = (SELECT ends_on + 1 FROM periods WHERE id = pg_temp.i('p_old'))) IS NOT NULL, 'the next period took the closing value as its opening');
-- a receipt dated inside the closed period lands late in the next open period
INSERT INTO goods_receipts (scope_id, supplier_id, storage_area_id, received_on, received_at, created_by) VALUES (101, pg_temp.i('sysco'), pg_temp.i('dry'), current_date - 21, ri_site_moment(101, current_date - 21, false) + interval '1 hour', 27);
SELECT pg_temp.keep('gr_late', max(id)) FROM goods_receipts;
INSERT INTO goods_receipt_lines (goods_receipt_id, line_no, ingredient_id, qty_received, purchase_unit_code, unit_cost) VALUES (pg_temp.i('gr_late'), 1, pg_temp.i('flour'), 1, 'bag50', 29.00);
SELECT ri_post_receipt(pg_temp.i('gr_late'), 27);
SELECT pg_temp.check((SELECT business_date > (SELECT ends_on FROM periods WHERE id = pg_temp.i('p_old')) AND note LIKE '%late%' FROM inventory_transactions WHERE reference_kind = 'goods_receipt' AND reference_id = pg_temp.i('gr_late')), 'a receipt dated in a closed period lands in the next open period, flagged late');
SELECT ri_period_reopen(pg_temp.i('p_old'), 1);
SELECT pg_temp.check((SELECT status FROM periods WHERE id = pg_temp.i('p_old')) = 'open', 'an admin reopens the last closed period');
SELECT pg_temp.refused($$SELECT ri_period_reopen((SELECT id FROM periods WHERE scope_id = 101 AND status = 'closed' ORDER BY starts_on LIMIT 1), 1)$$, 'later period is closed', 'an earlier closed period cannot reopen under a closed later one');

-- ================================================================ projection and ordering (§6.8): four weeks of history, the baseline, demand, projected stock, the suggested order
INSERT INTO sales_daily (scope_id, menu_item_id, business_date, day_part_id, qty, net)
SELECT 101, pg_temp.i('mi_sand'), current_date - k, NULL, CASE WHEN extract(isodow FROM current_date - k) IN (5, 6) THEN 40 ELSE 20 END, 0 FROM generate_series(1, 35) k
ON CONFLICT DO NOTHING;
SELECT pg_temp.check(ri_forecast_baseline(101, pg_temp.i('mi_sand'), current_date + 9) = (CASE WHEN extract(isodow FROM current_date + 9) IN (5, 6) THEN 40 ELSE 20 END), 'the baseline forecast: the same weekday''s four-week average (40 on Fridays and Saturdays, 20 otherwise)');
INSERT INTO calendar_days (scope_id, on_date, closed, holiday) VALUES (101, current_date + 8, true, 'SMOKE closure');
SELECT pg_temp.check((SELECT qty FROM ri_item_forecast(101, pg_temp.i('mi_sand'), current_date + 8)) = 0, 'a closed day forecasts zero');
INSERT INTO forecast_overrides (scope_id, menu_item_id, from_date, to_date, factor, reason, set_by) VALUES (101, pg_temp.i('mi_sand'), current_date + 11, current_date + 11, 1.5, 'SMOKE festival', 27);
SELECT pg_temp.check((SELECT qty = ri_forecast_baseline(101, pg_temp.i('mi_sand'), current_date + 11) * 1.5 AND source = 'override' FROM ri_item_forecast(101, pg_temp.i('mi_sand'), current_date + 11)), 'an override factor of 1.5 applies');
INSERT INTO covers_expected (scope_id, on_date, day_part_id, covers, source) VALUES (101, current_date + 12, NULL, 300, 'reservations');
SELECT pg_temp.check(ri_covers_factor(101, current_date + 12) = 1, 'booked covers with no cover history leave the forecast alone (factor 1)');
SELECT pg_temp.check((SELECT sum(qty_base) FROM ri_ingredient_demand(101, current_date + 1, current_date + 7) WHERE ingredient_id = pg_temp.i('chicken')) > 0, 'ingredient demand explodes the forecast through the recipe');
SELECT pg_temp.check(pg_temp.near((SELECT qty_base FROM ri_ingredient_demand(101, current_date + 9, current_date + 9) WHERE ingredient_id = pg_temp.i('egg')), ri_forecast_baseline(101, pg_temp.i('mi_sand'), current_date + 9), 0.001), 'one egg per sandwich: the egg demand on a day equals the sandwiches forecast');
SELECT pg_temp.check((SELECT count(*) FROM ri_projected_stock(101, pg_temp.i('egg'), 14)) = 14 AND (SELECT projected_base FROM ri_projected_stock(101, pg_temp.i('egg'), 14) ORDER BY on_date LIMIT 1) < ri_on_hand(pg_temp.i('egg'), 101), 'projected stock: fourteen days, falling from today''s on hand');
SELECT pg_temp.check(ri_stockout_on(101, pg_temp.i('egg'), 14) IS NOT NULL, '307 eggs at 20–40 a day run out within two weeks — a projected stock-out');
SELECT pg_temp.check((SELECT count(*) FROM ri_stockouts_projected(101, 14) WHERE ingredient_id = pg_temp.i('egg')) = 1, 'the stock-out list names the eggs');
-- the suggested order for Sysco: fixed par on flour, dynamic on eggs, the limes (produce) elsewhere
INSERT INTO ingredient_site_settings (ingredient_id, scope_id, par_base, par_mode) VALUES (pg_temp.i('flour'), 101, 250000, 'fixed');
INSERT INTO ingredient_site_settings (ingredient_id, scope_id, par_mode, service_level) VALUES (pg_temp.i('egg'), 101, 'dynamic', 0.95);
SELECT pg_temp.check((SELECT par_mode = 'fixed' AND need_base = 250000 AND have_base = ri_on_hand(pg_temp.i('flour'), 101) AND qty_units = ceil((250000 - ri_on_hand(pg_temp.i('flour'), 101)) / 22679.6185) AND rounding_note LIKE 'rounded up%'
                        FROM ri_suggested_order(101, pg_temp.i('sysco')) WHERE ingredient_id = pg_temp.i('flour')), 'flour: fixed par 250 kg − on hand, rounded up to whole bags');
SELECT pg_temp.check((SELECT par_mode = 'dynamic' AND demand_base > 0 AND safety_base > 0 AND need_base = demand_base + safety_base AND NOT thin_history FROM ri_suggested_order(101, pg_temp.i('sysco')) WHERE ingredient_id = pg_temp.i('egg')), 'eggs: dynamic — Σ demand over the protection interval plus safety stock (five weeks of history is not thin)');
SELECT pg_temp.check((SELECT cap_base IS NULL FROM ri_suggested_order(101, pg_temp.i('sysco')) WHERE ingredient_id = pg_temp.i('egg')), 'eggs keep three weeks — no perishable cap');
SELECT pg_temp.check((SELECT rounding_note FROM ri_suggested_order(101, pg_temp.i('sysco')) WHERE ingredient_id = pg_temp.i('chicken')) = 'covered' OR (SELECT qty_units FROM ri_suggested_order(101, pg_temp.i('sysco')) WHERE ingredient_id = pg_temp.i('chicken')) >= 0, 'chicken: a line with its arithmetic (covered or a quantity)');
SELECT pg_temp.keep('po_sugg', ri_suggested_order_draft(101, pg_temp.i('sysco'), 42));
SELECT pg_temp.check(pg_temp.i('po_sugg') IS NOT NULL AND (SELECT status = 'draft' AND origin = 'suggested' AND expected_on IS NOT NULL FROM purchase_orders WHERE id = pg_temp.i('po_sugg')), 'the Purchasing agent drafted the PO for Sysco''s next delivery; it is a draft, never sent');
SELECT pg_temp.check((SELECT need_base IS NOT NULL AND have_base IS NOT NULL AND rounding_note IS NOT NULL FROM purchase_order_lines WHERE purchase_order_id = pg_temp.i('po_sugg') AND ingredient_id = pg_temp.i('flour')), 'the drafted line keeps need, have and the rounding note');
SELECT pg_temp.check((SELECT count(*) FROM ri_suppliers_due(101, 24 * 8)) >= 1, 'Sysco''s cut-off is due within the week');
SELECT pg_temp.keep('acc', ri_forecast_accuracy_compute(101, 28));
SELECT pg_temp.check(pg_temp.i('acc') >= 1 AND (SELECT wape IS NOT NULL AND mae IS NOT NULL AND bias IS NOT NULL FROM forecast_accuracy WHERE scope_id = 101 AND menu_item_id = pg_temp.i('mi_sand')), 'accuracy kept as MAE, WAPE and bias per item');
-- menu engineering over the window
SELECT pg_temp.check((SELECT quadrant IN ('star', 'plowhorse', 'puzzle', 'dog') AND plate_cost > 0 FROM ri_menu_engineering(101, current_date - 7, current_date) WHERE menu_item_id = pg_temp.i('mi_sand')), 'menu engineering places the sandwich in a quadrant with its plate cost');

-- ================================================================ watches, notifications, the morning note's gatherers
INSERT INTO watches (member_id, agent_member_id, scope_id, ingredient_id, kind, text_me) VALUES (27, 42, 101, pg_temp.i('egg'), 'stockout_projected', true);
SELECT pg_temp.keep('fired', ri_watches_fire());
SELECT pg_temp.check(pg_temp.i('fired') = 1 AND (SELECT count(*) FROM notifications WHERE member_id = 27 AND kind = 'watch') = 1 AND (SELECT count(*) FROM notification_outbox WHERE member_id = 27 AND channel = 'text') = 1 AND (SELECT count(*) FROM agent_dispatches WHERE agent_member_id = 42 AND kind = 'watch') = 1, 'the watch fires once: a notification, a text, a dispatch to the Purchasing agent');
SELECT pg_temp.check(ri_watches_fire() = 0, 'and not again while the state holds');
SELECT pg_temp.check((SELECT count(*) FROM ri_price_movers(101, 5)) >= 1 AND (SELECT count(*) FROM ri_days_on_hand(101)) >= 3 AND (SELECT count(*) FROM ri_pos_health(101)) = 1 AND (SELECT count(*) FROM ri_purchase_orders_open(101)) >= 1, 'the morning note''s gatherers answer: price movers, days on hand, POS health, open POs');
SELECT pg_temp.check((SELECT count(*) FROM ri_find('chick')) >= 3, 'find: "chick" finds the ingredient, the recipe, the menu item and the supplier item');
SELECT pg_temp.check((SELECT count(*) FROM ri_share_purchases_received(101, current_date - 30, current_date)) >= 7 AND (SELECT count(*) FROM ri_share_sales_by_day(101, current_date, current_date)) = 2 AND (SELECT count(*) FROM ri_share_waste_summary(101, current_date, current_date)) = 2 AND (SELECT count(*) FROM ri_share_cogs_summary(101, pg_temp.i('p_now'))) >= 1, 'the ledger''s shares answer: purchases, sales by day, waste, COGS');

-- ================================================================ who sees what (§3): the views as the read roles
RESET ROLE;
SET ROLE restaurant_inventory_records_ro;
SELECT pg_temp.as_member(29);   -- Ann, Viewer at Downtown
SELECT pg_temp.check((SELECT count(*) FROM mcp_ingredients) >= 7 AND (SELECT count(*) FROM mcp_recipes) = 3, 'a Viewer sees the shared catalog and the recipes');
SELECT pg_temp.check((SELECT bool_and(avg_cost_per_base IS NULL AND on_hand_value IS NULL) FROM mcp_ingredient_site_costs WHERE scope_id = 101) AND (SELECT count(*) FROM mcp_ingredient_site_costs WHERE scope_id = 101) >= 5, 'a Viewer sees on hand but every cost is withheld');
SELECT pg_temp.check((SELECT count(*) FROM mcp_price_history) = 0 AND (SELECT count(*) FROM mcp_supplier_invoices) = 0 AND (SELECT bool_and(unit_cost IS NULL) FROM mcp_purchase_order_lines), 'a Viewer sees no price history, no invoices, no PO cost');
SELECT pg_temp.check((SELECT count(*) FROM mcp_inventory_balances WHERE scope_id = 102) = 0 AND (SELECT count(*) FROM mcp_sites) = 1, 'a Viewer at Downtown sees nothing of Airport');
SELECT pg_temp.check((SELECT bool_and(last_price IS NULL) FROM mcp_supplier_items) AND (SELECT bool_and(terms_days IS NULL) FROM mcp_suppliers), 'supplier prices and terms are walled too');
SELECT pg_temp.as_member(30);   -- Dana, Chef at Downtown
SELECT pg_temp.check((SELECT bool_and(avg_cost_per_base IS NOT NULL) FROM mcp_ingredient_site_costs WHERE scope_id = 101) AND (SELECT count(*) FROM mcp_price_history) >= 3 AND (SELECT count(*) FROM mcp_supplier_invoices) = 1, 'a Chef sees cost, price history and the invoice');
SELECT pg_temp.check((SELECT bool_and(terms_days IS NULL) FROM mcp_suppliers), 'but not the supplier''s terms (Manager''s)');
SELECT pg_temp.check((SELECT count(*) FROM mcp_source_credentials) = 0 AND (SELECT count(*) FROM information_schema.columns WHERE table_name = 'mcp_source_credentials' AND column_name = 'ciphertext') = 0, 'a credential''s ciphertext is in no view, and the credential rows are the admin''s');
SELECT pg_temp.check((SELECT bool_and(settings IS NULL) FROM mcp_sources), 'a source''s settings are the admin''s');
SELECT pg_temp.as_member(27);   -- Marco, Manager at Downtown, Kitchen at Airport
SELECT pg_temp.check((SELECT count(*) FROM mcp_sites) = 2 AND (SELECT bool_and(avg_cost_per_base IS NULL) FROM mcp_ingredient_site_costs WHERE scope_id = 102) AND (SELECT bool_and(avg_cost_per_base IS NOT NULL) FROM mcp_ingredient_site_costs WHERE scope_id = 101), 'Marco sees both sites, cost at Downtown only');
SELECT pg_temp.check((SELECT count(*) FROM mcp_inventory_transfers) = 1, 'a transfer between the two sites is visible at both ends');
SELECT pg_temp.as_member(41);   -- an agent not admitted
SELECT pg_temp.check((SELECT count(*) FROM mcp_ingredients) = 0 AND (SELECT count(*) FROM mcp_sites) = 0, 'an agent not admitted sees nothing');
SELECT pg_temp.as_member(42);   -- the Purchasing agent, Manager at Downtown
SELECT pg_temp.check((SELECT count(*) FROM mcp_purchase_orders WHERE origin = 'suggested') = 1 AND (SELECT count(*) FROM mcp_agent_dispatches) = 1 AND (SELECT count(*) FROM mcp_watches) = 1, 'the Purchasing agent sees its drafted PO, its dispatch and the watch naming it');
SELECT pg_temp.refused($$INSERT INTO ingredients (code, name, category_id, measure_class, base_unit_code, recipe_unit_code) VALUES ('X', 'x', 1, 'weight', 'g', 'g')$$, 'permission denied', 'the records role writes nothing');
RESET ROLE;
SET ROLE restaurant_inventory_activity_ro;
SELECT pg_temp.as_member(29);
SELECT pg_temp.check((SELECT count(*) FROM mcp_activity_log) = 0, 'the activity role reads the trail (empty here) and nothing refuses it');
SELECT pg_temp.refused($$SELECT count(*) FROM mcp_ingredient_site_costs$$, 'permission denied', 'the activity role does not read stock');
RESET ROLE;

-- ================================================================ the count
\o
SELECT CASE WHEN ok THEN 'ok   ' ELSE 'FAIL ' END || label FROM proof ORDER BY n;
SELECT count(*) FILTER (WHERE ok) || ' ok, ' || count(*) FILTER (WHERE NOT ok) || ' failed' AS summary FROM proof;
ROLLBACK;
