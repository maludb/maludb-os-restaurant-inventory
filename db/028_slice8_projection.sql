-- 028: SLICE 8 (projection and ordering) — what building the third exemplar found the schema owed (2026-10-11). Additive: a function body
-- replaced (SECURITY DEFINER, search_path and the grants RESTATED at the end, as db/024 taught), one function added, two renamed/split. No table
-- changes.
--
--  1. A CLOSED DAY FORECASTS ZERO — through the run as well as the baseline (the spec: "a calendar_days row with closed forces 0 through the
--     baseline and the run alike"). db/014's ri_item_forecast() zeroed a closed day only inside ri_forecast_baseline(): a closure set AFTER the
--     night's run kept the run's quantity for that day, so the demand, the projected stock and the suggested order ordered for a day the kitchen
--     had shut. The function now answers 0 with source 'closed' for a closed day, ahead of the run, the baseline and an override (a day the
--     kitchen is shut sells nothing; to override it, open the day). Fixed at the source, not by rewriting the run's rows from PHP.
--  2. ri_projected_stock_all(site, days, ingredients[]) — the projected stock of EVERY ingredient in one statement. ri_projected_stock() calls
--     ri_ingredient_demand() — the whole menu exploded over the window — for ONE ingredient, so the projected-stock table (and the stock-outs list)
--     would explode the menu once per ingredient: 14 days × every active item × every ingredient. The new function explodes once and does the
--     same arithmetic per ingredient (the schema proof asserts it equals ri_projected_stock row for row).
--  3. THE SUGGESTED ORDER IS WALLED (db/027's lesson): ri_suggested_order() is a SECURITY DEFINER read that returns unit_cost, and it was called
--     by ri_morning_note() and by Phase 4's `suggested_orders` tool with the wall left to the caller. The body is now ri_suggested_order_core()
--     (unwalled; callable by the owner's functions and the application's role only); ri_suggested_order() is the walled front: a member must hold
--     the site (ri_require_reader), unit_cost is withheld unless the member sees cost there, and with NO member set the application's own role (the
--     worker) sees everything while the two read roles are refused. ri_suggested_order_draft() reads the core, so a drafted line always carries its
--     cost whoever asked.
--  3a. DEFECT FOUND BY THE FIRST DYNAMIC-PAR PROOF: db/014's ri_suggested_order() could not run the periodic-review rule once a dynamic ingredient had seven days of depletion —
--      `round(z * sig * sqrt(p_days), 4)` rounds a double precision (sqrt of an integer) and PostgreSQL has no round(double precision, integer); the perishable cap's
--      `round(dq * shelf_life, 4)` (dq a percentile_cont, double precision) failed the same way. The Phase 0 proof's eggs had fewer than seven days, so the safety-percentage branch
--      answered and the rule's own branch never ran. Both are cast to numeric in the core. (And a minimum order reads "minimum 3", not "minimum 3.0000".)
--  3b. ri_stockouts_projected() read ri_stockout_on() twice per ingredient (two whole-menu explosions each); it reads ri_projected_stock_all() once.
--  3c. sales_daily had no index on (scope_id, business_date), which ri_forecast_baseline() asks four times per call ("did the site record any sales that day") for every item and day.
--  4. The WRITING definers of this slice (ri_suggested_order_draft, ri_forecast_accuracy_compute, ri_watches_fire) were executable by PUBLIC — a
--     read-only role could have called them. Revoked from PUBLIC; the writer keeps them.
BEGIN;

-- an index the baseline and the explosion lean on: "did the site record any sales that day" (ri_forecast_baseline's EXISTS, four times a call, for every item and day) had no index on (site, date)
CREATE INDEX IF NOT EXISTS sales_daily_site_date_idx ON sales_daily (scope_id, business_date);

-- 1 ------------------------------------------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_item_forecast(p_scope_id bigint, p_menu_item_id bigint, p_on date)
    RETURNS TABLE (qty numeric, source text, sigma numeric)
    LANGUAGE sql STABLE AS $$
    WITH run AS (SELECT f.qty, f.sigma FROM item_forecasts f JOIN forecast_runs r ON r.id = f.run_id WHERE r.scope_id = p_scope_id AND r.is_current AND f.menu_item_id = p_menu_item_id AND f.on_date = p_on),
    base AS (SELECT COALESCE((SELECT qty FROM run), ri_forecast_baseline(p_scope_id, p_menu_item_id, p_on), 0) AS q, CASE WHEN EXISTS (SELECT 1 FROM run) THEN 'run' ELSE 'baseline' END AS src, (SELECT sigma FROM run) AS s),
    ov AS (SELECT o.factor, o.qty FROM forecast_overrides o WHERE o.scope_id = p_scope_id AND o.status = 'set' AND (o.menu_item_id = p_menu_item_id OR o.menu_item_id IS NULL) AND p_on BETWEEN o.from_date AND o.to_date
            ORDER BY o.menu_item_id NULLS LAST, o.created_at DESC LIMIT 1),
    closed AS (SELECT COALESCE((SELECT cd.closed FROM calendar_days cd WHERE cd.scope_id = p_scope_id AND cd.on_date = p_on), false) AS c)
    SELECT CASE WHEN (SELECT c FROM closed) THEN 0::numeric
                ELSE round(CASE WHEN (SELECT qty FROM ov) IS NOT NULL THEN (SELECT qty FROM ov) ELSE b.q * COALESCE((SELECT factor FROM ov), 1) * ri_covers_factor(p_scope_id, p_on) END, 4) END,
           CASE WHEN (SELECT c FROM closed) THEN 'closed' WHEN EXISTS (SELECT 1 FROM ov) THEN 'override' ELSE b.src END, b.s
      FROM base b
$$;

-- 2 ------------------------------------------------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_projected_stock_all(p_scope_id bigint, p_days integer DEFAULT 14, p_ingredient_ids bigint[] DEFAULT NULL)
    RETURNS TABLE (ingredient_id bigint, on_date date, demand_base numeric, receipts_base numeric, projected_base numeric)
    LANGUAGE sql STABLE AS $$
    WITH days AS (SELECT (current_date + k)::date AS d FROM generate_series(0, p_days - 1) k),
    dem AS (SELECT x.ingredient_id, x.on_date, x.qty_base FROM ri_ingredient_demand(p_scope_id, current_date, current_date + p_days - 1) x WHERE p_ingredient_ids IS NULL OR x.ingredient_id = ANY (p_ingredient_ids)),
    rec AS (SELECT l.ingredient_id, COALESCE(l.expected_on, p.expected_on, p.ordered_on + 1) AS d, sum(greatest(l.qty_ordered_base - l.qty_received_base, 0)) AS q
              FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.purchase_order_id
             WHERE p.scope_id = p_scope_id AND p.status IN ('sent', 'acknowledged', 'partial') AND l.status IN ('open', 'acknowledged', 'partial')
               AND (p_ingredient_ids IS NULL OR l.ingredient_id = ANY (p_ingredient_ids)) GROUP BY 1, 2),
    ings AS (SELECT i.id AS iid FROM ingredients i
              WHERE i.active AND (p_ingredient_ids IS NULL OR i.id = ANY (p_ingredient_ids))
                AND (EXISTS (SELECT 1 FROM dem WHERE dem.ingredient_id = i.id) OR EXISTS (SELECT 1 FROM rec WHERE rec.ingredient_id = i.id)
                     OR ri_on_hand(i.id, p_scope_id) <> 0 OR EXISTS (SELECT 1 FROM ingredient_site_settings s WHERE s.ingredient_id = i.id AND s.scope_id = p_scope_id AND s.par_base IS NOT NULL))),
    wpct AS (SELECT t.ingredient_id AS iid, CASE WHEN COALESCE(sum(-t.qty_base) FILTER (WHERE t.txn_type = 'depletion'), 0) > 0
                         THEN COALESCE(sum(-t.qty_base) FILTER (WHERE t.txn_type = 'waste'), 0) / sum(-t.qty_base) FILTER (WHERE t.txn_type = 'depletion') ELSE 0 END AS w
               FROM inventory_transactions t WHERE t.scope_id = p_scope_id AND t.business_date >= current_date - 28 GROUP BY t.ingredient_id),
    grid AS (SELECT g.iid, dy.d, COALESCE(dem.qty_base, 0) * (1 + COALESCE(w.w, 0)) AS dm, COALESCE(rec.q, 0) AS rc, ri_on_hand(g.iid, p_scope_id) AS oh
               FROM ings g CROSS JOIN days dy LEFT JOIN dem ON dem.ingredient_id = g.iid AND dem.on_date = dy.d LEFT JOIN rec ON rec.ingredient_id = g.iid AND rec.d = dy.d LEFT JOIN wpct w ON w.iid = g.iid)
    SELECT gr.iid, gr.d, gr.dm, gr.rc, round(gr.oh + sum(gr.rc - gr.dm) OVER (PARTITION BY gr.iid ORDER BY gr.d), 4) FROM grid gr ORDER BY gr.iid, gr.d
$$;

-- ri_stockouts_projected() (the home's list, the morning note's heading 2, ri_morning_note) called ri_stockout_on() TWICE per ingredient — two
-- whole-menu explosions each. It now reads ri_projected_stock_all() once; the same ingredients (those with a balance row), the same first day.
CREATE OR REPLACE FUNCTION ri_stockouts_projected(p_scope_id bigint, p_days integer DEFAULT 7)
    RETURNS TABLE (ingredient_id bigint, stockout_on date, on_hand_base numeric)
    LANGUAGE sql STABLE AS $$
    SELECT c.ingredient_id, min(p.on_date), c.on_hand_base
      FROM ingredient_site_costs c JOIN ingredients i ON i.id = c.ingredient_id
      JOIN ri_projected_stock_all(p_scope_id, p_days) p ON p.ingredient_id = c.ingredient_id AND p.projected_base < 0
     WHERE c.scope_id = p_scope_id AND i.active AND NOT i.is_prep GROUP BY c.ingredient_id, c.on_hand_base
$$;

-- 3 ------------------------------------------------------------------------------------------------------------------------------------
-- the rule itself, db/014's body with the two typing defects fixed (see 3a); not callable by PUBLIC or the read roles
CREATE OR REPLACE FUNCTION ri_suggested_order_core(p_scope_id bigint, p_supplier_id bigint, p_as_of timestamptz DEFAULT now())
    RETURNS TABLE (supplier_item_id bigint, ingredient_id bigint, purchase_unit_code text, per_unit_base numeric, cutoff_at timestamptz, delivery_on date, next_delivery_on date,
                   par_mode text, demand_base numeric, safety_base numeric, need_base numeric, have_base numeric, on_hand_base numeric, on_order_base numeric, cap_base numeric,
                   raw_base numeric, qty_units numeric, unit_cost numeric, thin_history boolean, rounding_note text)
    LANGUAGE plpgsql STABLE AS $$
#variable_conflict use_column
DECLARE w record; si record; st ingredient_site_settings%ROWTYPE; i ingredients%ROWTYPE; cat ingredient_categories%ROWTYPE; dmd numeric; sig numeric; p_days integer; z numeric;
        hist_weeks integer; dyn_after integer; units numeric; floor_units numeric; split boolean; cap numeric; dq numeric; sumsq numeric; n integer;
BEGIN
    SELECT * INTO w FROM ri_next_order_windows(p_supplier_id, p_scope_id, p_as_of);
    SELECT dynamic_par_after_weeks INTO dyn_after FROM ri_settings WHERE id = 1; dyn_after := COALESCE(dyn_after, 4);
    SELECT allow_split_case INTO split FROM suppliers WHERE id = p_supplier_id;
    p_days := greatest(w.next_delivery_on - w.delivery_on, 1);
    FOR si IN
        SELECT s.*, COALESCE(g.seq, 9999) AS seq FROM supplier_items s
          LEFT JOIN (SELECT gl.supplier_item_id, gl.seq FROM order_guide_lines gl JOIN order_guides g ON g.id = gl.guide_id WHERE g.supplier_id = p_supplier_id AND g.scope_id = p_scope_id AND g.active) g ON g.supplier_item_id = s.id
         WHERE s.supplier_id = p_supplier_id AND s.active
           AND (g.supplier_item_id IS NOT NULL OR EXISTS (SELECT 1 FROM ingredient_site_settings x WHERE x.ingredient_id = s.ingredient_id AND x.scope_id = p_scope_id AND x.active AND (x.preferred_supplier_item_id = s.id OR x.preferred_supplier_item_id IS NULL)))
         ORDER BY seq, s.id
    LOOP
        SELECT * INTO i FROM ingredients WHERE id = si.ingredient_id;
        SELECT * INTO cat FROM ingredient_categories WHERE id = i.category_id;
        SELECT * INTO st FROM ingredient_site_settings WHERE ingredient_id = si.ingredient_id AND scope_id = p_scope_id;
        -- another supplier item is preferred for this ingredient → skip
        IF st.preferred_supplier_item_id IS NOT NULL AND st.preferred_supplier_item_id <> si.id THEN CONTINUE; END IF;
        supplier_item_id := si.id; ingredient_id := si.ingredient_id; purchase_unit_code := si.purchase_unit_code; per_unit_base := ri_to_base(si.ingredient_id, 1, si.purchase_unit_code);
        cutoff_at := w.cutoff_at; delivery_on := w.delivery_on; next_delivery_on := w.next_delivery_on;
        SELECT count(DISTINCT business_date) / 7 INTO hist_weeks FROM sales_daily WHERE scope_id = p_scope_id AND day_part_id IS NULL AND business_date >= current_date - 364;
        thin_history := hist_weeks < dyn_after;
        par_mode := CASE WHEN COALESCE(st.par_mode, 'fixed') = 'dynamic' AND NOT thin_history THEN 'dynamic' ELSE 'fixed' END;
        SELECT COALESCE(sum(d.qty_base), 0) INTO dmd FROM ri_ingredient_demand(p_scope_id, w.delivery_on, w.next_delivery_on - 1) d WHERE d.ingredient_id = si.ingredient_id;
        demand_base := round(dmd, 4);
        IF par_mode = 'dynamic' THEN
            z := ri_z(COALESCE(st.service_level, 0.95));
            -- σ: the daily demand's standard deviation over the last 28 days of depletion, in base units
            SELECT count(*), COALESCE(stddev_samp(-q), 0) INTO n, sig FROM (SELECT business_date, sum(qty_base) AS q FROM inventory_transactions WHERE scope_id = p_scope_id AND ingredient_id = si.ingredient_id AND txn_type = 'depletion' AND business_date >= current_date - 28 GROUP BY business_date) x;
            IF n < 7 THEN safety_base := round(dmd * cat.safety_stock_pct / 100.0, 4); ELSE safety_base := round(z * sig * sqrt(p_days::numeric), 4); END IF;
            need_base := round(dmd + safety_base, 4);
        ELSE
            safety_base := CASE WHEN st.par_base IS NULL THEN round(dmd * cat.safety_stock_pct / 100.0, 4) ELSE NULL END;
            need_base := COALESCE(st.par_base, round(dmd + COALESCE(safety_base, 0), 4));
        END IF;
        on_hand_base := ri_on_hand(si.ingredient_id, p_scope_id);
        on_order_base := ri_on_order(si.ingredient_id, p_scope_id, w.delivery_on);
        have_base := on_hand_base + on_order_base;
        cap_base := NULL;
        IF i.shelf_life_days IS NOT NULL AND i.shelf_life_days < p_days + 7 THEN
            SELECT percentile_cont(0.7) WITHIN GROUP (ORDER BY -q) INTO dq FROM (SELECT business_date, sum(qty_base) AS q FROM inventory_transactions WHERE scope_id = p_scope_id AND ingredient_id = si.ingredient_id AND txn_type = 'depletion' AND business_date >= current_date - 56 GROUP BY business_date) x;
            IF dq IS NOT NULL THEN cap_base := round((dq * i.shelf_life_days)::numeric, 4); END IF;
        END IF;
        raw_base := greatest(least(need_base, COALESCE(cap_base, need_base)) - have_base, 0);
        IF raw_base = 0 THEN units := 0; rounding_note := 'covered';
        ELSE
            units := raw_base / per_unit_base;
            IF split THEN units := ceil(units * 100) / 100; rounding_note := 'split case allowed';
            ELSE
                floor_units := floor(units);
                IF floor_units >= 1 AND (ceil(units) * per_unit_base - raw_base) > raw_base * 0.2 AND (raw_base - floor_units * per_unit_base) < per_unit_base * 0.2 THEN
                    units := floor_units; rounding_note := 'rounded down (within 20 %)';
                ELSE units := ceil(units); rounding_note := 'rounded up to the ' || si.purchase_unit_code; END IF;
            END IF;
            IF units < si.moq THEN units := si.moq; rounding_note := rounding_note || '; minimum ' || trim_scale(si.moq); END IF;
        END IF;
        qty_units := units;
        unit_cost := COALESCE(CASE WHEN si.contract_until IS NULL OR si.contract_until >= current_date THEN si.contract_price END, si.last_price, 0);
        RETURN NEXT;
    END LOOP;
END$$;

REVOKE ALL ON FUNCTION ri_suggested_order_core(bigint, bigint, timestamptz) FROM PUBLIC, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;
GRANT EXECUTE ON FUNCTION ri_suggested_order_core(bigint, bigint, timestamptz) TO restaurant_inventory_rw;

CREATE OR REPLACE FUNCTION ri_suggested_order(p_scope_id bigint, p_supplier_id bigint, p_as_of timestamptz DEFAULT now())
    RETURNS TABLE (supplier_item_id bigint, ingredient_id bigint, purchase_unit_code text, per_unit_base numeric, cutoff_at timestamptz, delivery_on date, next_delivery_on date,
                   par_mode text, demand_base numeric, safety_base numeric, need_base numeric, have_base numeric, on_hand_base numeric, on_order_base numeric, cap_base numeric,
                   raw_base numeric, qty_units numeric, unit_cost numeric, thin_history boolean, rounding_note text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE me bigint := app_current_member_id(); see_cost boolean := true;
BEGIN
    IF me IS NULL THEN
        IF session_user IN ('restaurant_inventory_records_ro', 'restaurant_inventory_activity_ro')
           OR current_setting('role', true) IN ('restaurant_inventory_records_ro', 'restaurant_inventory_activity_ro') THEN
            RAISE EXCEPTION 'Not a site you hold' USING ERRCODE = 'insufficient_privilege';
        END IF;                                                       -- the application's own role (the worker): no wall
    ELSE
        PERFORM ri_require_reader(p_scope_id);
        see_cost := ri_sees_cost(p_scope_id);
    END IF;
    RETURN QUERY
    SELECT c.supplier_item_id, c.ingredient_id, c.purchase_unit_code, c.per_unit_base, c.cutoff_at, c.delivery_on, c.next_delivery_on, c.par_mode, c.demand_base, c.safety_base, c.need_base, c.have_base,
           c.on_hand_base, c.on_order_base, c.cap_base, c.raw_base, c.qty_units, CASE WHEN see_cost THEN c.unit_cost END, c.thin_history, c.rounding_note
      FROM ri_suggested_order_core(p_scope_id, p_supplier_id, p_as_of) c;
END$$;

CREATE OR REPLACE FUNCTION ri_suggested_order_draft(p_scope_id bigint, p_supplier_id bigint, p_by bigint, p_origin text DEFAULT 'suggested') RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; pid bigint; n integer := 0; w record;
BEGIN
    SELECT * INTO w FROM ri_next_order_windows(p_supplier_id, p_scope_id, now());
    FOR r IN SELECT * FROM ri_suggested_order_core(p_scope_id, p_supplier_id, now()) WHERE qty_units > 0 LOOP
        IF pid IS NULL THEN
            INSERT INTO purchase_orders (scope_id, supplier_id, origin, cutoff_at, expected_on, created_by, storage_area_id)
            VALUES (p_scope_id, p_supplier_id, p_origin, w.cutoff_at, w.delivery_on, p_by, (SELECT id FROM storage_areas WHERE scope_id = p_scope_id AND active ORDER BY walk_order LIMIT 1)) RETURNING id INTO pid;
        END IF;
        n := n + 1;
        INSERT INTO purchase_order_lines (purchase_order_id, line_no, ingredient_id, supplier_item_id, supplier_sku, purchase_unit_code, qty_ordered, unit_cost, expected_on,
                                          need_base, have_base, on_order_base, cap_base, safety_base, raw_base, rounding_note)
        VALUES (pid, n, r.ingredient_id, r.supplier_item_id, (SELECT supplier_sku FROM supplier_items WHERE id = r.supplier_item_id), r.purchase_unit_code, r.qty_units, r.unit_cost, r.delivery_on,
                r.need_base, r.have_base, r.on_order_base, r.cap_base, r.safety_base, r.raw_base, r.rounding_note || ' · ' || r.par_mode || CASE WHEN r.thin_history THEN ' · thin history' ELSE '' END);
    END LOOP;
    RETURN pid;
END$$;

-- 4 and the rights restated ------------------------------------------------------------------------------------------------------------
DO $$
DECLARE f record; reads text[] := ARRAY['ri_item_forecast', 'ri_projected_stock_all', 'ri_stockouts_projected', 'ri_suggested_order'];
BEGIN
    FOR f IN SELECT p.oid::regprocedure AS sig FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND p.proname = ANY (reads) LOOP
        EXECUTE format('ALTER FUNCTION %s SECURITY DEFINER SET search_path = public', f.sig);
        EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f.sig);
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro', f.sig);
    END LOOP;
    FOR f IN SELECT p.oid::regprocedure AS sig FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
              WHERE n.nspname = 'public' AND p.proname IN ('ri_suggested_order_draft', 'ri_forecast_accuracy_compute', 'ri_watches_fire') LOOP
        EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC, restaurant_inventory_records_ro, restaurant_inventory_activity_ro', f.sig);
        EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO restaurant_inventory_rw', f.sig);
    END LOOP;
END$$;

COMMIT;
