-- 016: THE READS THAT MATTER — one SQL function behind a screen, a tool, an export and a share, so none disagree
-- (design §6.9): find, menu engineering, price movers, days on hand, POS health, the unmapped queue, count variance,
-- open purchase orders and deliveries due, the morning note's gatherers, the ledger's three documents.
BEGIN;

-- One search: an ingredient, a recipe, a menu item, a supplier item by name, code, PLU or SKU.
CREATE OR REPLACE FUNCTION ri_find(p_q text, p_limit integer DEFAULT 25)
    RETURNS TABLE (kind text, id bigint, code text, name text, detail text, rank real)
    LANGUAGE sql STABLE AS $$
    WITH q AS (SELECT lower(trim(p_q)) AS s)
    SELECT * FROM (
        SELECT 'ingredient', i.id, i.code, i.name, c.name, CASE WHEN lower(i.code) = (SELECT s FROM q) THEN 1.0 WHEN lower(i.name) LIKE (SELECT s FROM q) || '%' THEN 0.9 ELSE 0.5 END::real
          FROM ingredients i JOIN ingredient_categories c ON c.id = i.category_id WHERE i.active AND (lower(i.name) LIKE '%' || (SELECT s FROM q) || '%' OR lower(i.code) LIKE (SELECT s FROM q) || '%')
        UNION ALL
        SELECT 'recipe', r.id, r.code, r.name, r.kind, CASE WHEN lower(r.code) = (SELECT s FROM q) THEN 1.0 WHEN lower(r.name) LIKE (SELECT s FROM q) || '%' THEN 0.9 ELSE 0.5 END::real
          FROM recipes r WHERE r.active AND (lower(r.name) LIKE '%' || (SELECT s FROM q) || '%' OR lower(r.code) LIKE (SELECT s FROM q) || '%')
        UNION ALL
        SELECT 'menu_item', m.id, m.plu, m.name, CASE WHEN m.recipe_id IS NULL THEN 'unmapped' ELSE 'mapped' END, CASE WHEN m.plu = (SELECT s FROM q) THEN 1.0 ELSE 0.6 END::real
          FROM menu_items m WHERE m.active AND (lower(m.name) LIKE '%' || (SELECT s FROM q) || '%' OR lower(m.plu) = (SELECT s FROM q))
        UNION ALL
        SELECT 'supplier_item', si.id, si.supplier_sku, COALESCE(si.description, i.name), s.name, 0.4::real
          FROM supplier_items si JOIN suppliers s ON s.id = si.supplier_id JOIN ingredients i ON i.id = si.ingredient_id WHERE si.active AND (lower(si.supplier_sku) = (SELECT s FROM q) OR lower(COALESCE(si.description, '')) LIKE '%' || (SELECT s FROM q) || '%')
        UNION ALL
        SELECT 'supplier', s.id, NULL, s.name, s.kind, 0.7::real FROM suppliers s WHERE s.active AND lower(s.name) LIKE '%' || (SELECT s FROM q) || '%'
    ) x (kind, id, code, name, detail, rank) ORDER BY x.rank DESC, x.name LIMIT p_limit
$$;

-- Menu engineering (Kasavana & Smith) for a site over a window, by menu category.
CREATE OR REPLACE FUNCTION ri_menu_engineering(p_scope_id bigint, p_from date, p_to date, p_basis text DEFAULT NULL)
    RETURNS TABLE (menu_item_id bigint, category_id bigint, qty_sold numeric, net numeric, avg_price numeric, plate_cost numeric, food_cost_pct numeric, cm numeric, cm_total numeric,
                   mix_pct numeric, cm_avg numeric, mix_threshold numeric, quadrant text)
    LANGUAGE sql STABLE AS $$
    WITH s AS (
        SELECT sd.menu_item_id, m.category_id, sum(sd.qty) AS q, sum(sd.net) AS net,
               CASE WHEN sum(sd.qty) > 0 THEN sum(sd.net) / sum(sd.qty) ELSE 0 END AS price,
               COALESCE((SELECT avg(ri_portion_cost_cached(ri_recipe_version_on(m.recipe_id, d::date), p_scope_id, d::date, p_basis)) FROM generate_series(p_from, p_to, interval '7 days') d WHERE ri_recipe_version_on(m.recipe_id, d::date) IS NOT NULL), 0) * m.portions AS cost
          FROM sales_daily sd JOIN menu_items m ON m.id = sd.menu_item_id
         WHERE sd.scope_id = p_scope_id AND sd.day_part_id IS NULL AND sd.business_date BETWEEN p_from AND p_to GROUP BY sd.menu_item_id, m.category_id, m.recipe_id, m.portions),
    cat AS (SELECT category_id, sum((price - cost) * q) / nullif(sum(q), 0) AS cm_avg, (1.0 / count(*)) * 0.7 AS thr, sum(q) AS qt FROM s GROUP BY category_id)
    SELECT s.menu_item_id, s.category_id, s.q, round(s.net, 2), round(s.price, 2), round(s.cost, 4), CASE WHEN s.price > 0 THEN round(s.cost / s.price * 100, 1) END,
           round(s.price - s.cost, 4), round((s.price - s.cost) * s.q, 2), round(s.q / nullif(c.qt, 0) * 100, 2), round(c.cm_avg, 4), round(c.thr * 100, 2),
           CASE WHEN (s.price - s.cost) >= c.cm_avg AND s.q / nullif(c.qt, 0) >= c.thr THEN 'star'
                WHEN (s.price - s.cost) < c.cm_avg AND s.q / nullif(c.qt, 0) >= c.thr THEN 'plowhorse'
                WHEN (s.price - s.cost) >= c.cm_avg THEN 'puzzle' ELSE 'dog' END
      FROM s JOIN cat c ON c.category_id IS NOT DISTINCT FROM s.category_id
$$;

CREATE OR REPLACE FUNCTION ri_price_movers(p_scope_id bigint, p_days integer DEFAULT 7)
    RETURNS TABLE (ingredient_id bigint, old_per_base numeric, new_per_base numeric, pct numeric, changed_at timestamptz, source_kind text, reference_kind text, reference_id bigint)
    LANGUAGE sql STABLE AS $$
    SELECT DISTINCT ON (h.ingredient_id) h.ingredient_id, f.new_per_base, h.new_per_base,
           CASE WHEN f.new_per_base > 0 THEN round((h.new_per_base - f.new_per_base) / f.new_per_base * 100, 2) END, h.changed_at, h.source_kind, h.reference_kind, h.reference_id
      FROM price_history h
      JOIN LATERAL (SELECT new_per_base FROM price_history x WHERE x.ingredient_id = h.ingredient_id AND (x.scope_id = p_scope_id OR x.scope_id IS NULL) AND x.changed_at < now() - (p_days || ' days')::interval ORDER BY x.changed_at DESC LIMIT 1) f ON true
     WHERE (h.scope_id = p_scope_id OR h.scope_id IS NULL) AND h.changed_at >= now() - (p_days || ' days')::interval
     ORDER BY h.ingredient_id, h.changed_at DESC
$$;

CREATE OR REPLACE FUNCTION ri_days_on_hand(p_scope_id bigint, p_days integer DEFAULT 28)
    RETURNS TABLE (ingredient_id bigint, on_hand_base numeric, daily_usage_base numeric, days_on_hand numeric, value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT c.ingredient_id, c.on_hand_base, u.d, CASE WHEN u.d > 0 THEN round(c.on_hand_base / u.d, 1) END, c.on_hand_value
      FROM ingredient_site_costs c
      LEFT JOIN LATERAL (SELECT COALESCE(sum(-t.qty_base), 0) / p_days AS d FROM inventory_transactions t WHERE t.scope_id = p_scope_id AND t.ingredient_id = c.ingredient_id AND t.txn_type IN ('depletion', 'waste', 'production_out') AND t.business_date >= current_date - p_days) u ON true
     WHERE c.scope_id = p_scope_id AND c.on_hand_base <> 0
$$;

CREATE OR REPLACE FUNCTION ri_pos_health(p_scope_id bigint)
    RETURNS TABLE (source_id bigint, name text, connector text, last_ok_at timestamptz, consecutive_failures integer, paused_at timestamptz, days_missing date[], unmapped_plus integer, unmapped_modifiers integer, pending_runs integer)
    LANGUAGE sql STABLE AS $$
    SELECT s.id, s.name, s.connector, s.last_ok_at, s.consecutive_failures, s.paused_at,
           (SELECT array_agg(d::date ORDER BY d) FROM generate_series(current_date - 7, current_date - 1, interval '1 day') d
             WHERE NOT COALESCE((SELECT closed FROM calendar_days cd WHERE cd.scope_id = p_scope_id AND cd.on_date = d::date), false)
               AND NOT EXISTS (SELECT 1 FROM pos_sales ps WHERE ps.source_id = s.id AND ps.business_date = d::date)),
           (SELECT count(*)::integer FROM menu_items m WHERE m.pos_source_id = s.id AND m.recipe_id IS NULL AND m.active AND m.last_sold_at >= now() - interval '28 days'),
           (SELECT count(*)::integer FROM modifiers md WHERE md.pos_source_id = s.id AND md.recipe_id IS NULL AND md.action <> 'none' AND md.active),
           (SELECT count(*)::integer FROM ri_depletion_due() d WHERE d.scope_id = p_scope_id)
      FROM sources s WHERE s.scope_id = p_scope_id AND s.active
$$;

CREATE OR REPLACE FUNCTION ri_unmapped_plus(p_scope_id bigint, p_days integer DEFAULT 28)
    RETURNS TABLE (menu_item_id bigint, plu text, name text, qty_sold numeric, net numeric, last_sold_at timestamptz)
    LANGUAGE sql STABLE AS $$
    SELECT m.id, m.plu, m.name, COALESCE(sum(l.qty), 0), COALESCE(sum(l.line_total), 0), m.last_sold_at
      FROM menu_items m JOIN sources s ON s.id = m.pos_source_id
      LEFT JOIN pos_sale_lines l ON l.menu_item_id = m.id AND NOT l.voided
      LEFT JOIN pos_sales ps ON ps.id = l.sale_id AND ps.business_date >= current_date - p_days
     WHERE s.scope_id = p_scope_id AND m.recipe_id IS NULL AND m.active GROUP BY m.id ORDER BY 4 DESC
$$;

CREATE OR REPLACE FUNCTION ri_count_variance(p_count_id bigint)
    RETURNS TABLE (line_id bigint, storage_area_id bigint, ingredient_id bigint, system_qty_base numeric, counted_qty_base numeric, variance_base numeric, variance_value numeric, over_tolerance boolean, state text)
    LANGUAGE sql STABLE AS $$
    SELECT l.id, l.storage_area_id, l.ingredient_id, l.system_qty_base, COALESCE(l.recount_qty_base, l.counted_qty_base), l.variance_base, l.variance_value, l.over_tolerance, l.state
      FROM inventory_count_lines l WHERE l.count_id = p_count_id ORDER BY abs(COALESCE(l.variance_value, 0)) DESC
$$;

CREATE OR REPLACE FUNCTION ri_purchase_orders_open(p_scope_id bigint)
    RETURNS TABLE (purchase_order_id bigint, number text, supplier_id bigint, status text, ordered_on date, expected_on date, total numeric, days_unacknowledged integer, overdue boolean)
    LANGUAGE sql STABLE AS $$
    SELECT p.id, p.number, p.supplier_id, p.status, p.ordered_on, p.expected_on, p.total,
           CASE WHEN p.status = 'sent' THEN (current_date - p.sent_at::date) END, p.expected_on < current_date AND p.status IN ('sent', 'acknowledged', 'partial')
      FROM purchase_orders p WHERE p.scope_id = p_scope_id AND p.status IN ('draft', 'sent', 'acknowledged', 'partial') ORDER BY p.expected_on NULLS LAST, p.ordered_on
$$;

CREATE OR REPLACE FUNCTION ri_deliveries_due(p_scope_id bigint, p_on date DEFAULT current_date)
    RETURNS TABLE (purchase_order_id bigint, number text, supplier_id bigint, expected_on date, received boolean)
    LANGUAGE sql STABLE AS $$
    SELECT p.id, p.number, p.supplier_id, p.expected_on, EXISTS (SELECT 1 FROM goods_receipts g WHERE g.purchase_order_id = p.id AND g.status = 'posted')
      FROM purchase_orders p WHERE p.scope_id = p_scope_id AND p.expected_on <= p_on AND p.status IN ('sent', 'acknowledged', 'partial') ORDER BY p.expected_on
$$;

CREATE OR REPLACE FUNCTION ri_stockouts_projected(p_scope_id bigint, p_days integer DEFAULT 7)
    RETURNS TABLE (ingredient_id bigint, stockout_on date, on_hand_base numeric)
    LANGUAGE sql STABLE AS $$
    SELECT c.ingredient_id, ri_stockout_on(p_scope_id, c.ingredient_id, p_days), c.on_hand_base
      FROM ingredient_site_costs c JOIN ingredients i ON i.id = c.ingredient_id
     WHERE c.scope_id = p_scope_id AND i.active AND NOT i.is_prep AND ri_stockout_on(p_scope_id, c.ingredient_id, p_days) IS NOT NULL
$$;

-- The ledger's three documents (design §8), paged ≤ 500.
CREATE OR REPLACE FUNCTION ri_share_purchases_received(p_scope_id bigint, p_from date, p_to date, p_offset integer DEFAULT 0, p_limit integer DEFAULT 500)
    RETURNS TABLE (receipt_number text, received_on date, supplier_id bigint, supplier_name text, invoice_ref text, category_key text, ingredient_code text, ingredient_name text, qty_base numeric, base_unit text, value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT g.number, g.received_on, s.id, s.name, g.invoice_ref, c.key, i.code, i.name, l.qty_base, i.base_unit_code, round(l.qty_base * COALESCE(l.cost_per_base, 0), 2)
      FROM goods_receipts g JOIN goods_receipt_lines l ON l.goods_receipt_id = g.id JOIN ingredients i ON i.id = l.ingredient_id JOIN ingredient_categories c ON c.id = i.category_id
      LEFT JOIN suppliers s ON s.id = g.supplier_id
     WHERE g.scope_id = p_scope_id AND g.status = 'posted' AND g.received_on BETWEEN p_from AND p_to ORDER BY g.received_on, g.number, l.line_no OFFSET p_offset LIMIT least(p_limit, 500)
$$;
CREATE OR REPLACE FUNCTION ri_share_stock_valuation(p_scope_id bigint, p_as_of date DEFAULT current_date)
    RETURNS TABLE (category_key text, category_name text, kind text, ingredients integer, value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT c.key, c.name, c.kind, count(*)::integer, round(sum(v.value), 2) FROM ri_stock_value(p_scope_id, ri_site_moment(p_scope_id, p_as_of, true)) v JOIN ingredient_categories c ON c.id = v.category_id GROUP BY c.key, c.name, c.kind, c.sort_order ORDER BY c.sort_order
$$;
CREATE OR REPLACE FUNCTION ri_share_cogs_summary(p_scope_id bigint, p_period_id bigint)
    RETURNS TABLE (period_no integer, starts_on date, ends_on date, status text, category_key text, category_name text, kind text, opening_value numeric, purchases_value numeric, closing_value numeric, actual_value numeric, theoretical_value numeric, waste_value numeric, unexplained_value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT p.no, p.starts_on, p.ends_on, p.status, c.key, c.name, c.kind, u.opening_value, u.purchases_value, u.closing_value, u.actual_value, u.theoretical_value, u.waste_value, u.unexplained_value
      FROM periods p, LATERAL ri_usage_by_category(p.scope_id, p.starts_on, p.ends_on) u JOIN ingredient_categories c ON c.id = u.category_id
     WHERE p.id = p_period_id AND p.scope_id = p_scope_id ORDER BY c.sort_order
$$;
CREATE OR REPLACE FUNCTION ri_share_waste_summary(p_scope_id bigint, p_from date, p_to date)
    RETURNS TABLE (stage text, reason text, disposition text, entries integer, cost numeric)
    LANGUAGE sql STABLE AS $$
    SELECT w.stage, r.name, w.disposition, count(*)::integer, round(sum(COALESCE(w.cost, 0)), 2) FROM waste_logs w JOIN reason_codes r ON r.id = w.reason_code_id
     WHERE w.scope_id = p_scope_id AND w.status <> 'voided' AND w.business_date BETWEEN p_from AND p_to GROUP BY 1, 2, 3 ORDER BY 5 DESC
$$;
CREATE OR REPLACE FUNCTION ri_share_sales_by_day(p_scope_id bigint, p_from date, p_to date)
    RETURNS TABLE (business_date date, day_part text, checks integer, covers integer, net numeric, comps numeric, voids numeric)
    LANGUAGE sql STABLE AS $$
    SELECT s.business_date, d.name, count(*)::integer, sum(s.covers)::integer, round(sum(COALESCE(s.subtotal, 0)), 2), round(sum(s.comps), 2), round(sum(s.voids), 2)
      FROM pos_sales s LEFT JOIN day_parts d ON d.id = s.day_part_id WHERE s.scope_id = p_scope_id AND s.business_date BETWEEN p_from AND p_to GROUP BY 1, 2 ORDER BY 1, 2
$$;

COMMIT;
