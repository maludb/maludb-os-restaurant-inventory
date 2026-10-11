-- 018: PHASE 1 FIXES (2026-10-11) — what the tool surface, the build specs and the ten slice specs found the schema owed, in ONE
-- additive migration (the specs name db/018 by several working titles; this is the file). Nothing is dropped; every change is
-- an appended column, a new table, a new function, or a CREATE OR REPLACE of a function's body.
--
--  1. THE READ ROLE CAN CALL THE READ FUNCTIONS. db/017 grants restaurant_inventory_records_ro the mcp_* views only, and every
--     read function of db/007–016 was SECURITY INVOKER over base tables — so Phase 4's records server could not call one. Every
--     read function is now SECURITY DEFINER with search_path = public, revoked from PUBLIC and granted to the three roles. The
--     gate is ri_require_reader(scope): a tool calls it first (refused with insufficient_privilege unless the caller holds the
--     site), then nulls cost through ri_sees_cost(scope) — the "fn" wall of the tool surface. The six shares answer the kernel's
--     token the same way (the kernel's reads cross cost unnulled by design §8).
--  2. The missing reads: ri_recipe_what_if, ri_recipes_using, ri_sales_by_item, ri_variance_top, ri_invoice_mismatches,
--     ri_watch_events (+ watch_events), ri_morning_note, ri_share_ingredients_index, ri_count_line_add, ri_find with kinds[].
--  3. menu_items.no_depletion (a PLU that sells nothing from stock: a service charge, a gift card) — the queue and the run skip it.
--  4. notification_prefs.display_units / default_area_id (the manifest's preferences) + mcp_notification_prefs; mcp_access_tokens_mine.
--  5. agent_site_settings (the Purchasing agent's duty hour and enablement per site) + ri_settings.forecast_method.
--  6. The four vendor mapping templates (column names to be confirmed against real exports in Phase 5 — the mapping screen exists for it).
--  7. ri_po_supplier_event(..., p_source, p_member_id): the door and a person's phone record share one writer.
--  8. ri_cost_as_of honours a session's cost overrides (the what-if).
BEGIN;

-- ---------------------------------------------------------------------------------------------
-- 3, 4, 5, 6: columns and tables
-- ---------------------------------------------------------------------------------------------
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS no_depletion boolean NOT NULL DEFAULT false;
DROP INDEX IF EXISTS menu_items_unmapped_idx;
CREATE INDEX menu_items_unmapped_idx ON menu_items (pos_source_id) WHERE recipe_id IS NULL AND NOT no_depletion AND active;
ALTER TABLE notification_prefs ADD COLUMN IF NOT EXISTS display_units text NOT NULL DEFAULT 'metric' CHECK (display_units IN ('metric', 'imperial'));
ALTER TABLE notification_prefs ADD COLUMN IF NOT EXISTS default_area_id bigint REFERENCES storage_areas(id) ON DELETE SET NULL;
ALTER TABLE ri_settings ADD COLUMN IF NOT EXISTS forecast_method text NOT NULL DEFAULT 'baseline' CHECK (forecast_method IN ('baseline', 'holt_winters'));
CREATE TABLE IF NOT EXISTS agent_site_settings (
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id         bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    duty_hour        time NOT NULL DEFAULT '06:00',
    enabled          boolean NOT NULL DEFAULT true,
    updated_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (agent_member_id, scope_id)
);
CREATE TABLE IF NOT EXISTS watch_events (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    watch_id       bigint NOT NULL REFERENCES watches(id) ON DELETE CASCADE,
    scope_id       bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    ingredient_id  bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    kind           text NOT NULL,
    fired_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS watch_events_site_idx ON watch_events (scope_id, fired_at DESC);
INSERT INTO sales_file_mappings (key, name, mapping) VALUES
    ('square_items', 'Square — Items detail export', '{"delimiter": ",", "date_format": "YYYY-MM-DD", "columns": {"external_id": "Transaction ID", "business_date": "Date", "closed_at": "Time", "plu": "SKU", "item_name": "Item", "qty": "Qty", "unit_price": "Price Point Name", "line_total": "Gross Sales", "voided": "Event Type", "comp": "Discounts", "modifiers": "Modifiers Applied", "covers": null}, "to_confirm": true}'),
    ('toast_items', 'Toast — Item Selection Details', '{"delimiter": ",", "date_format": "MM/DD/YYYY", "columns": {"external_id": "Order #", "business_date": "Order Date", "closed_at": "Sent Date", "plu": "PLU", "item_name": "Menu Item", "qty": "Qty", "unit_price": "Price", "line_total": "Net Price", "voided": "Void?", "comp": "Comp", "modifiers": "Modifiers", "covers": "# of Guests"}, "to_confirm": true}'),
    ('clover_items', 'Clover — Line items export', '{"delimiter": ",", "date_format": "MM/DD/YYYY", "columns": {"external_id": "Order ID", "business_date": "Order Date", "closed_at": "Order Time", "plu": "Item Code", "item_name": "Item Name", "qty": "Quantity", "unit_price": "Item Price", "line_total": "Line Total", "voided": "Refunded", "comp": "Discount", "modifiers": "Modifiers", "covers": null}, "to_confirm": true}'),
    ('lightspeed_items', 'Lightspeed Restaurant — Sales by product', '{"delimiter": ",", "date_format": "YYYY-MM-DD", "columns": {"external_id": "Receipt", "business_date": "Date", "closed_at": "Time", "plu": "SKU", "item_name": "Product", "qty": "Quantity", "unit_price": "Unit price", "line_total": "Total", "voided": "Voided", "comp": "Discount", "modifiers": "Modifiers", "covers": "Covers"}, "to_confirm": true}')
ON CONFLICT (key) DO NOTHING;

-- ---------------------------------------------------------------------------------------------
-- 8: the cost as of a moment honours a session's overrides (the what-if)
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_cost_as_of(p_ingredient_id bigint, p_scope_id bigint, p_as_of timestamptz DEFAULT now(), p_basis text DEFAULT NULL) RETURNS numeric
    LANGUAGE plpgsql STABLE AS $$
DECLARE basis text := COALESCE(p_basis, (SELECT cost_basis FROM ri_settings WHERE id = 1), 'avg'); c numeric; ov text := current_setting('ri.cost_overrides', true);
BEGIN
    -- a what-if (db/018): the session's overrides win — {"<ingredient_id>": <cost per base>}
    IF ov IS NOT NULL AND ov <> '' AND (ov::jsonb ? p_ingredient_id::text) THEN RETURN (ov::jsonb ->> p_ingredient_id::text)::numeric; END IF;
    SELECT cost_override_per_base INTO c FROM ingredient_site_settings WHERE ingredient_id = p_ingredient_id AND scope_id = p_scope_id;
    IF c IS NOT NULL THEN RETURN c; END IF;
    IF basis = 'last' OR p_as_of < now() - interval '1 minute' THEN
        -- last: the last price seen; avg as of a past date: the running average is not kept by date, so the last price
        -- at that date is the honest stand-in (the design: recomputed from price history as of a date)
        SELECT new_per_base INTO c FROM price_history
         WHERE ingredient_id = p_ingredient_id AND (scope_id = p_scope_id OR scope_id IS NULL) AND changed_at <= p_as_of
         ORDER BY changed_at DESC LIMIT 1;
        IF c IS NOT NULL THEN RETURN c; END IF;
    END IF;
    SELECT avg_cost_per_base INTO c FROM ingredient_site_costs WHERE ingredient_id = p_ingredient_id AND scope_id = p_scope_id;
    IF c IS NOT NULL AND c > 0 THEN RETURN c; END IF;
    SELECT new_per_base INTO c FROM price_history WHERE ingredient_id = p_ingredient_id AND (scope_id = p_scope_id OR scope_id IS NULL) ORDER BY changed_at DESC LIMIT 1;
    RETURN COALESCE(c, 0);
END$$;


-- ---------------------------------------------------------------------------------------------
-- 3: the queue and the run skip a no-depletion PLU
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_unmapped_plus(p_scope_id bigint, p_days integer DEFAULT 28)
    RETURNS TABLE (menu_item_id bigint, plu text, name text, qty_sold numeric, net numeric, last_sold_at timestamptz)
    LANGUAGE sql STABLE AS $$
    SELECT m.id, m.plu, m.name, COALESCE(sum(l.qty), 0), COALESCE(sum(l.line_total), 0), m.last_sold_at
      FROM menu_items m JOIN sources s ON s.id = m.pos_source_id
      LEFT JOIN pos_sale_lines l ON l.menu_item_id = m.id AND NOT l.voided
      LEFT JOIN pos_sales ps ON ps.id = l.sale_id AND ps.business_date >= current_date - p_days
     WHERE s.scope_id = p_scope_id AND m.recipe_id IS NULL AND NOT m.no_depletion AND m.active GROUP BY m.id ORDER BY 4 DESC
$$;

CREATE OR REPLACE FUNCTION ri_depletion_run(p_scope_id bigint, p_date date, p_by bigint DEFAULT NULL) RETURNS depletion_runs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r depletion_runs%ROWTYPE; old depletion_runs%ROWTYPE; e record; g uuid := gen_random_uuid(); c numeric; area bigint; tot numeric := 0; n integer := 0; t record;
        n_total integer; n_unmapped integer; n_void integer; n_mod integer; tz text; occ timestamptz;
BEGIN
    SELECT * INTO old FROM depletion_runs WHERE scope_id = p_scope_id AND business_date = p_date AND status <> 'void' FOR UPDATE;
    IF FOUND THEN
        FOR t IN SELECT id FROM inventory_transactions WHERE group_id = old.group_id AND txn_type = 'depletion' LOOP PERFORM ri_reverse_transaction(t.id, p_by, 're-run'); END LOOP;
        UPDATE depletion_runs SET status = 'void' WHERE id = old.id;
    END IF;
    SELECT timezone INTO tz FROM sites WHERE scope_id = p_scope_id;
    occ := ((p_date + 1)::timestamp + COALESCE((SELECT sales_cut_time FROM site_settings WHERE scope_id = p_scope_id), '04:00'::time) - interval '1 second') AT TIME ZONE COALESCE(tz, 'UTC');
    SELECT count(*), count(*) FILTER (WHERE m.recipe_id IS NULL AND NOT m.no_depletion AND NOT l.voided), count(*) FILTER (WHERE l.voided) INTO n_total, n_unmapped, n_void
      FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id JOIN menu_items m ON m.id = l.menu_item_id WHERE s.scope_id = p_scope_id AND s.business_date = p_date;
    SELECT count(*) INTO n_mod FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id JOIN menu_items mi ON mi.id = l.menu_item_id CROSS JOIN LATERAL jsonb_array_elements(l.modifiers) mm
      JOIN modifiers md ON md.pos_source_id = mi.pos_source_id AND md.code = mm->>'code' WHERE s.scope_id = p_scope_id AND s.business_date = p_date AND NOT l.voided AND md.action <> 'none' AND md.recipe_id IS NULL
        AND NOT EXISTS (SELECT 1 FROM menu_item_modifiers x WHERE x.menu_item_id = l.menu_item_id AND x.modifier_id = md.id AND x.recipe_id IS NOT NULL);
    INSERT INTO depletion_runs (scope_id, business_date, group_id, run_no, started_by, lines_total, lines_unmapped, lines_voided, modifiers_unmapped)
    VALUES (p_scope_id, p_date, g, COALESCE(old.run_no, 0) + 1, p_by, n_total, n_unmapped, n_void, n_mod) RETURNING * INTO r;
    FOR e IN SELECT * FROM ri_day_depletion(p_scope_id, p_date) LOOP
        c := ri_cost_as_of(e.ingredient_id, p_scope_id, occ);
        area := ri_depletion_area(e.ingredient_id, p_scope_id);
        IF area IS NULL THEN RAISE EXCEPTION 'The site has no storage area to deplete from' USING ERRCODE = 'check_violation'; END IF;
        PERFORM ri_post_txn(CASE WHEN e.qty_base > 0 THEN 'depletion' ELSE 'return' END, p_scope_id, e.ingredient_id, area, -e.qty_base, c, 'depletion_run', r.id,
                            'depl:' || r.id || ':' || e.ingredient_id, 'customer', NULL, NULL, g, p_by, NULL, occ, p_date);
        tot := tot + e.qty_base * c; n := n + 1;
    END LOOP;
    -- the daily sales table
    DELETE FROM sales_daily WHERE scope_id = p_scope_id AND business_date = p_date;
    INSERT INTO sales_daily (scope_id, menu_item_id, business_date, day_part_id, qty, net, comps_qty)
    SELECT s.scope_id, l.menu_item_id, s.business_date, s.day_part_id, sum(l.qty), COALESCE(sum(COALESCE(l.line_total, 0)) FILTER (WHERE NOT l.comp), 0), COALESCE(sum(l.qty) FILTER (WHERE l.comp), 0)
      FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id WHERE s.scope_id = p_scope_id AND s.business_date = p_date AND NOT l.voided GROUP BY 1, 2, 3, 4
    UNION ALL
    SELECT s.scope_id, l.menu_item_id, s.business_date, NULL, sum(l.qty), COALESCE(sum(COALESCE(l.line_total, 0)) FILTER (WHERE NOT l.comp), 0), COALESCE(sum(l.qty) FILTER (WHERE l.comp), 0)
      FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id WHERE s.scope_id = p_scope_id AND s.business_date = p_date AND NOT l.voided GROUP BY 1, 2, 3;
    UPDATE depletion_runs SET status = CASE WHEN n_unmapped > 0 OR n_mod > 0 THEN 'partial' ELSE 'done' END, lines_depleted = n_total - n_unmapped - n_void, ingredients = n, value = round(tot, 2), finished_at = now()
     WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;


-- ---------------------------------------------------------------------------------------------
-- 7: the door and a person share one writer of a supplier's event
-- ---------------------------------------------------------------------------------------------
DROP FUNCTION IF EXISTS ri_po_supplier_event(bigint, text, bigint, date, text, text);
CREATE OR REPLACE FUNCTION ri_po_supplier_event(p_po_id bigint, p_kind text, p_line_id bigint DEFAULT NULL, p_expected_on date DEFAULT NULL, p_reason text DEFAULT NULL, p_ref text DEFAULT NULL, p_source text DEFAULT 'door', p_member_id bigint DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO p FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF p.status NOT IN ('sent', 'acknowledged', 'partial') THEN RAISE EXCEPTION 'Purchase order % is %', p.number, p.status USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, expected_on, reason, supplier_order_ref, member_id) VALUES (p_po_id, p_line_id, p_kind, p_source, p_expected_on, p_reason, p_ref, p_member_id);
    IF p_kind = 'acknowledge' THEN
        UPDATE purchase_orders SET status = CASE WHEN status = 'sent' THEN 'acknowledged' ELSE status END, acknowledged_at = COALESCE(acknowledged_at, now()),
               expected_on = COALESCE(p_expected_on, expected_on), supplier_order_ref = COALESCE(p_ref, supplier_order_ref) WHERE id = p_po_id;
        UPDATE purchase_order_lines SET status = 'acknowledged' WHERE purchase_order_id = p_po_id AND status = 'open' AND (p_line_id IS NULL OR id = p_line_id);
    ELSIF p_kind = 'decline' THEN
        UPDATE purchase_order_lines SET status = 'declined', supplier_note = p_reason WHERE purchase_order_id = p_po_id AND id = p_line_id;
    ELSIF p_kind = 'date' THEN
        UPDATE purchase_orders SET expected_on = p_expected_on WHERE id = p_po_id;
        UPDATE purchase_order_lines SET expected_on = p_expected_on WHERE purchase_order_id = p_po_id AND (p_line_id IS NULL OR id = p_line_id);
    END IF;
END$$;


-- ---------------------------------------------------------------------------------------------
-- 2: the missing reads
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_require_reader(p_scope_id bigint) RETURNS void
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NOT ri_is_member_here() OR NOT ri_holds_scope(p_scope_id) THEN
        RAISE EXCEPTION 'Not a site you hold' USING ERRCODE = 'insufficient_privilege';
    END IF;
END$$;

-- what-if: the plate cost with some ingredients at another price (per base unit, or per a unit given)
CREATE OR REPLACE FUNCTION ri_recipe_what_if(p_version_id bigint, p_scope_id bigint, p_overrides jsonb, p_basis text DEFAULT NULL)
    RETURNS TABLE (batch_cost numeric, portion_cost numeric, batch_yield_qty numeric, lines jsonb, uncosted integer, base_portion_cost numeric)
    LANGUAGE plpgsql AS $$
DECLARE o jsonb := '{}'::jsonb; e jsonb; iid bigint; c numeric; r record; base numeric;
BEGIN
    -- [{ingredient (id), cost, unit}] → {"id": cost per base}
    FOR e IN SELECT * FROM jsonb_array_elements(COALESCE(p_overrides, '[]'::jsonb)) LOOP
        iid := (e->>'ingredient')::bigint;
        c := (e->>'cost')::numeric / COALESCE(ri_to_base(iid, 1, COALESCE(e->>'unit', (SELECT base_unit_code FROM ingredients WHERE id = iid))), 1);
        o := o || jsonb_build_object(iid::text, c);
    END LOOP;
    SELECT x.portion_cost INTO base FROM ri_recipe_cost(p_version_id, p_scope_id, now(), p_basis) x;
    PERFORM set_config('ri.cost_overrides', o::text, true);
    SELECT * INTO r FROM ri_recipe_cost(p_version_id, p_scope_id, now(), p_basis);
    PERFORM set_config('ri.cost_overrides', '', true);
    batch_cost := r.batch_cost; portion_cost := r.portion_cost; batch_yield_qty := r.batch_yield_qty; lines := r.lines; uncosted := r.uncosted; base_portion_cost := base;
    RETURN NEXT;
END$$;

-- the recipes whose version in force names the ingredient (directly, or through a prep that does)
CREATE OR REPLACE FUNCTION ri_recipes_using(p_ingredient_id bigint, p_on date DEFAULT current_date)
    RETURNS TABLE (recipe_id bigint, recipe_name text, kind text, version_id bigint, qty_base_per_batch numeric, direct boolean)
    LANGUAGE sql STABLE AS $$
    SELECT r.id, r.name, r.kind, v.id, round(e.qty_base, 4), EXISTS (SELECT 1 FROM recipe_lines l WHERE l.version_id = v.id AND l.ingredient_id = p_ingredient_id)
      FROM recipes r JOIN recipe_versions v ON v.id = ri_recipe_version_on(r.id, p_on), LATERAL ri_explode(v.id, p_on, true) e
     WHERE r.active AND e.ingredient_id = p_ingredient_id ORDER BY r.kind, r.name
$$;

CREATE OR REPLACE FUNCTION ri_sales_by_item(p_scope_id bigint, p_from date, p_to date, p_day_part_id bigint DEFAULT NULL)
    RETURNS TABLE (menu_item_id bigint, plu text, name text, qty numeric, net numeric, comps_qty numeric, days integer)
    LANGUAGE sql STABLE AS $$
    SELECT m.id, m.plu, m.name, sum(sd.qty), sum(sd.net), sum(sd.comps_qty), count(DISTINCT sd.business_date)::integer
      FROM sales_daily sd JOIN menu_items m ON m.id = sd.menu_item_id
     WHERE sd.scope_id = p_scope_id AND sd.business_date BETWEEN p_from AND p_to AND sd.day_part_id IS NOT DISTINCT FROM p_day_part_id
     GROUP BY m.id ORDER BY 4 DESC
$$;

CREATE OR REPLACE FUNCTION ri_variance_top(p_scope_id bigint, p_from date, p_to date, p_limit integer DEFAULT 20, p_basis text DEFAULT NULL)
    RETURNS TABLE (ingredient_id bigint, category_id bigint, actual_base numeric, theoretical_base numeric, variance_base numeric, waste_base numeric, unexplained_base numeric,
                   unit_cost numeric, variance_value numeric, waste_value numeric, unexplained_value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT u.ingredient_id, u.category_id, u.actual_base, u.theoretical_base, u.variance_base, u.waste_base, u.unexplained_base, u.unit_cost, u.variance_value, u.waste_value, u.unexplained_value
      FROM ri_usage(p_scope_id, p_from, p_to, p_basis) u ORDER BY abs(u.unexplained_value) DESC NULLS LAST LIMIT p_limit
$$;

CREATE OR REPLACE FUNCTION ri_invoice_mismatches(p_scope_id bigint)
    RETURNS TABLE (invoice_id bigint, number text, invoice_no text, supplier_id bigint, invoice_line_id bigint, ingredient_id bigint, match_state text, diff_qty numeric, diff_value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT i.id, i.number, i.invoice_no, i.supplier_id, l.id, l.ingredient_id, l.match_state, l.diff_qty, l.diff_value
      FROM supplier_invoices i JOIN supplier_invoice_lines l ON l.invoice_id = i.id
     WHERE i.scope_id = p_scope_id AND i.status IN ('draft', 'disputed') AND l.match_state IN ('qty_diff', 'price_diff', 'both_diff', 'unordered') ORDER BY i.invoice_date, i.number, l.line_no
$$;

CREATE OR REPLACE FUNCTION ri_watch_events(p_scope_id bigint, p_days integer DEFAULT 30)
    RETURNS TABLE (event_id bigint, watch_id bigint, member_id bigint, ingredient_id bigint, kind text, fired_at timestamptz)
    LANGUAGE sql STABLE AS $$
    SELECT e.id, e.watch_id, w.member_id, e.ingredient_id, e.kind, e.fired_at FROM watch_events e JOIN watches w ON w.id = e.watch_id
     WHERE e.scope_id = p_scope_id AND e.fired_at >= now() - (p_days || ' days')::interval ORDER BY e.fired_at DESC
$$;
CREATE OR REPLACE FUNCTION ri_watches_fire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE w record; st boolean; n integer := 0; nm text;
BEGIN
    FOR w IN SELECT * FROM watches WHERE active LOOP
        st := ri_watch_state(w.id);
        IF st AND NOT COALESCE(w.last_state, false) THEN
            SELECT name INTO nm FROM ingredients WHERE id = w.ingredient_id;
            PERFORM ri_notify(w.member_id, 'watch', 'ingredient', w.ingredient_id, replace(w.kind, '_', ' ') || ': ' || nm, w.note, 'watch:' || w.id || ':' || to_char(now(), 'YYYYMMDDHH24'), w.text_me, w.scope_id);
            INSERT INTO watch_events (watch_id, scope_id, ingredient_id, kind, fired_at) VALUES (w.id, w.scope_id, w.ingredient_id, w.kind, now());
            IF w.agent_member_id IS NOT NULL THEN
                INSERT INTO agent_dispatches (record_type, record_id, scope_id, agent_member_id, kind, via, acting_member_id, watch_id) VALUES ('ingredient', w.ingredient_id, w.scope_id, w.agent_member_id, 'watch', 'chat', w.member_id, w.id);
            END IF;
            n := n + 1;
        END IF;
        UPDATE watches SET last_state = st, fired_at = CASE WHEN st AND NOT COALESCE(last_state, false) THEN now() ELSE fired_at END, fire_count = fire_count + CASE WHEN st AND NOT COALESCE(last_state, false) THEN 1 ELSE 0 END WHERE id = w.id;
    END LOOP;
    RETURN n;
END$$;


-- the morning note as one gatherer: the seven headings of design §5 for a site and a day
CREATE OR REPLACE FUNCTION ri_morning_note(p_scope_id bigint, p_date date DEFAULT current_date) RETURNS jsonb
    LANGUAGE sql STABLE AS $$
    SELECT jsonb_build_object(
        'site', (SELECT name FROM sites WHERE scope_id = p_scope_id), 'date', p_date,
        'suggested_orders', (SELECT COALESCE(jsonb_agg(jsonb_build_object('supplier_id', d.supplier_id, 'cutoff_at', d.cutoff_at, 'delivery_on', d.delivery_on,
                              'lines', (SELECT COALESCE(jsonb_agg(to_jsonb(s)), '[]'::jsonb) FROM ri_suggested_order(p_scope_id, d.supplier_id, now()) s WHERE s.qty_units > 0))), '[]'::jsonb)
                             FROM ri_suppliers_due(p_scope_id, 36) d),
        'stockouts', (SELECT COALESCE(jsonb_agg(to_jsonb(x)), '[]'::jsonb) FROM ri_stockouts_projected(p_scope_id, 7) x),
        'prices', jsonb_build_object('movers', (SELECT COALESCE(jsonb_agg(to_jsonb(x)), '[]'::jsonb) FROM ri_price_movers(p_scope_id, 1) x),
                                     'alerts_open', (SELECT count(*) FROM price_alerts WHERE (scope_id = p_scope_id OR scope_id IS NULL) AND seen_at IS NULL)),
        'pos', (SELECT COALESCE(jsonb_agg(to_jsonb(x)), '[]'::jsonb) FROM ri_pos_health(p_scope_id) x),
        'last_count', (SELECT to_jsonb(c) || jsonb_build_object('over_tolerance', (SELECT COALESCE(jsonb_agg(to_jsonb(v)), '[]'::jsonb) FROM ri_count_variance(c.count_id) v WHERE v.over_tolerance))
                         FROM (SELECT id AS count_id, number, as_of, status, total_variance_value FROM inventory_counts WHERE scope_id = p_scope_id AND status = 'approved' ORDER BY as_of DESC LIMIT 1) c),
        'forecast', jsonb_build_object('worst', (SELECT COALESCE(jsonb_agg(to_jsonb(a)), '[]'::jsonb) FROM (SELECT menu_item_id, wape, bias FROM forecast_accuracy WHERE scope_id = p_scope_id AND window_days = 28 ORDER BY wape DESC NULLS LAST LIMIT 5) a),
                                        'closures_ahead', (SELECT COALESCE(jsonb_agg(to_jsonb(d)), '[]'::jsonb) FROM (SELECT on_date, holiday, event FROM calendar_days WHERE scope_id = p_scope_id AND on_date BETWEEN p_date AND p_date + 14 AND (closed OR holiday IS NOT NULL OR event IS NOT NULL)) d)),
        'purchase_orders', jsonb_build_object('open', (SELECT COALESCE(jsonb_agg(to_jsonb(x)), '[]'::jsonb) FROM ri_purchase_orders_open(p_scope_id) x WHERE x.status <> 'draft'),
                                              'deliveries_due', (SELECT COALESCE(jsonb_agg(to_jsonb(x)), '[]'::jsonb) FROM ri_deliveries_due(p_scope_id, p_date) x)))
$$;

CREATE OR REPLACE FUNCTION ri_share_ingredients_index(p_scope_id bigint, p_q text DEFAULT NULL, p_offset integer DEFAULT 0, p_limit integer DEFAULT 500)
    RETURNS TABLE (ingredient_id bigint, code text, name text, category_key text, measure_class text, base_unit text, recipe_unit text, is_prep boolean, on_hand_base numeric)
    LANGUAGE sql STABLE AS $$
    SELECT i.id, i.code, i.name, c.key, i.measure_class, i.base_unit_code, i.recipe_unit_code, i.is_prep, COALESCE(sc.on_hand_base, 0)
      FROM ingredients i JOIN ingredient_categories c ON c.id = i.category_id LEFT JOIN ingredient_site_costs sc ON sc.ingredient_id = i.id AND sc.scope_id = p_scope_id
     WHERE i.active AND (p_q IS NULL OR lower(i.name) LIKE '%' || lower(p_q) || '%' OR lower(i.code) LIKE lower(p_q) || '%') ORDER BY i.name OFFSET p_offset LIMIT least(p_limit, 500)
$$;

-- an ingredient found on the shelf that is not on the sheet: a line with its book figure as of the count's moment
CREATE OR REPLACE FUNCTION ri_count_line_add(p_count_id bigint, p_area_id bigint, p_ingredient_id bigint, p_by bigint) RETURNS inventory_count_lines
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE; l inventory_count_lines%ROWTYPE;
BEGIN
    SELECT * INTO c FROM inventory_counts WHERE id = p_count_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such count' USING ERRCODE = 'no_data_found'; END IF;
    IF c.status NOT IN ('open', 'review', 'recount') THEN RAISE EXCEPTION 'Count % is %', c.number, c.status USING ERRCODE = 'check_violation'; END IF;
    IF (SELECT scope_id FROM storage_areas WHERE id = p_area_id) <> c.scope_id THEN RAISE EXCEPTION 'The area is not at that site' USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO inventory_count_lines (count_id, storage_area_id, ingredient_id, shelf_order, count_unit_code, system_qty_base)
    VALUES (p_count_id, p_area_id, p_ingredient_id, COALESCE((SELECT max(shelf_order) + 1 FROM inventory_count_lines WHERE count_id = p_count_id AND storage_area_id = p_area_id), 1),
            (SELECT unit_code FROM ingredient_units u WHERE u.ingredient_id = p_ingredient_id AND u.is_default_count),
            COALESCE((SELECT b.qty_base FROM inventory_balances b WHERE b.ingredient_id = p_ingredient_id AND b.storage_area_id = p_area_id), 0)
            - COALESCE((SELECT sum(t.qty_base) FROM inventory_transactions t WHERE t.ingredient_id = p_ingredient_id AND t.storage_area_id = p_area_id AND t.occurred_at > c.as_of), 0))
    RETURNING * INTO l;
    RETURN l;
END$$;

-- find, narrowed to kinds
CREATE OR REPLACE FUNCTION ri_find(p_q text, p_kinds text[], p_limit integer DEFAULT 25)
    RETURNS TABLE (kind text, id bigint, code text, name text, detail text, rank real)
    LANGUAGE sql STABLE AS $$ SELECT * FROM ri_find(p_q, 500) f WHERE p_kinds IS NULL OR f.kind = ANY (p_kinds) ORDER BY f.rank DESC, f.name LIMIT p_limit $$;

-- ---------------------------------------------------------------------------------------------
-- 4, 5: the views
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE VIEW mcp_access_tokens_mine WITH (security_barrier = true) AS
SELECT t.id AS token_id, t.label, t.scope, right(t.token_hash, 4) AS last4, t.last_used_at, t.expires_at, t.revoked_at, t.created_at FROM mcp_access_tokens t WHERE t.member_id = app_current_member_id();
CREATE OR REPLACE VIEW mcp_notification_prefs WITH (security_barrier = true) AS
SELECT p.member_id, p.email_enabled, p.text_enabled, p.kinds, p.text_kinds, p.display_units, p.default_area_id, p.updated_at FROM notification_prefs p WHERE p.member_id = app_current_member_id();
CREATE OR REPLACE VIEW mcp_agent_site_settings WITH (security_barrier = true) AS
SELECT a.agent_member_id, a.scope_id, a.duty_hour, a.enabled, a.updated_at FROM agent_site_settings a WHERE a.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_watch_events WITH (security_barrier = true) AS
SELECT e.id AS event_id, e.watch_id, e.scope_id, e.ingredient_id, e.kind, e.fired_at FROM watch_events e JOIN watches w ON w.id = e.watch_id
 WHERE e.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids())) AND (w.member_id = app_current_member_id() OR w.agent_member_id = app_current_member_id() OR (SELECT ri_is_admin()));
GRANT SELECT ON mcp_access_tokens_mine, mcp_notification_prefs, mcp_agent_site_settings, mcp_watch_events TO restaurant_inventory_records_ro, restaurant_inventory_rw;
GRANT SELECT, INSERT, UPDATE, DELETE ON agent_site_settings, watch_events TO restaurant_inventory_rw;

-- ---------------------------------------------------------------------------------------------
-- 1: every read function SECURITY DEFINER, revoked from PUBLIC, granted to the three roles (the writer keeps every function)
-- ---------------------------------------------------------------------------------------------
DO $$
DECLARE f record; names text[] := ARRAY['ri_find', 'ri_recipe_cost', 'ri_recipe_what_if', 'ri_explode', 'ri_recipe_version_on', 'ri_recipe_allergens', 'ri_recipes_using', 'ri_menu_engineering',
    'ri_portion_cost_cached', 'ri_menu_price', 'ri_usage', 'ri_usage_by_category', 'ri_variance_top', 'ri_food_sales', 'ri_stock_value', 'ri_days_on_hand', 'ri_price_movers',
    'ri_pos_health', 'ri_unmapped_plus', 'ri_sales_by_item', 'ri_count_variance', 'ri_count_areas_skipped', 'ri_purchase_orders_open', 'ri_deliveries_due', 'ri_invoice_mismatches',
    'ri_stockouts_projected', 'ri_item_forecast', 'ri_forecast_baseline', 'ri_covers_factor', 'ri_ingredient_demand', 'ri_projected_stock', 'ri_stockout_on', 'ri_suggested_order',
    'ri_suppliers_due', 'ri_next_order_windows', 'ri_on_hand', 'ri_on_hand_as_of', 'ri_on_order', 'ri_cost_as_of', 'ri_to_base', 'ri_from_base', 'ri_unit_class', 'ri_depletion_area',
    'ri_container_remaining_base', 'ri_supplier_item_cost_per_base', 'ri_site_moment', 'ri_depletion_due', 'ri_day_depletion', 'ri_period_on', 'ri_calendar_periods', 'ri_z',
    'ri_watch_state', 'ri_watch_events', 'ri_morning_note', 'ri_require_reader',
    'ri_share_purchases_received', 'ri_share_stock_valuation', 'ri_share_cogs_summary', 'ri_share_waste_summary', 'ri_share_sales_by_day', 'ri_share_ingredients_index'];
BEGIN
    FOR f IN SELECT p.oid::regprocedure AS sig FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND p.proname = ANY (names) LOOP
        EXECUTE format('ALTER FUNCTION %s SECURITY DEFINER SET search_path = public', f.sig);
        EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f.sig);
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro', f.sig);
    END LOOP;
END$$;
-- the activity role reads the trail's names through the views it holds (db/017); nothing else widened.

COMMIT;
