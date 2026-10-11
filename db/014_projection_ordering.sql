-- 014: PROJECTION AND ORDERING — the site's trading calendar, forecast runs and item forecasts, overrides, accuracy,
-- expected covers from Reservations, the explosion into ingredient demand, projected stock per day, and the SUGGESTED
-- ORDER per supplier with every step kept on the line (design §6.8).
--
-- THE ESTATE'S DATA MODEL (design §6.9): NEW — canonical forecast tables; the cidery's `demand_forecasts` (method,
-- history_weeks) and txtSchedules' `forecast_covers` shapes crossed. `buyer_proposals` is Inventory's with scope_id (db/015).
--
-- THE REFEREE'S RULES: the forecaster (Python in the worker) writes item_forecasts; everything after it is SQL here; the
-- baseline (the same-weekday weighted four-week average) is also in SQL so a site with no worker pass still projects;
-- closures force zero and are excluded from the error; the suggested order shows need / have / on order / cap / rounding
-- on every line and is never placed by the application.
BEGIN;

CREATE TABLE calendar_days (
    scope_id   bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    on_date    date NOT NULL,
    closed     boolean NOT NULL DEFAULT false,
    holiday    text,
    event      text,
    note       text,
    PRIMARY KEY (scope_id, on_date)
);

CREATE TABLE forecast_runs (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    made_at       timestamptz NOT NULL DEFAULT now(),
    method        text NOT NULL CHECK (method IN ('baseline', 'holt_winters', 'manual')),
    horizon_days  integer NOT NULL DEFAULT 21 CHECK (horizon_days BETWEEN 1 AND 92),
    history_days  integer NOT NULL DEFAULT 364,
    params        jsonb NOT NULL DEFAULT '{}'::jsonb,
    items         integer NOT NULL DEFAULT 0,
    is_current    boolean NOT NULL DEFAULT true,
    made_by       bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE UNIQUE INDEX forecast_runs_current ON forecast_runs (scope_id) WHERE is_current;
CREATE TABLE item_forecasts (
    run_id        bigint NOT NULL REFERENCES forecast_runs(id) ON DELETE CASCADE,
    menu_item_id  bigint NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    on_date       date NOT NULL,
    qty           numeric(12,4) NOT NULL CHECK (qty >= 0),
    lo            numeric(12,4),
    hi            numeric(12,4),
    sigma         numeric(12,4),                                                 -- the residual σ of the item (daily)
    PRIMARY KEY (run_id, menu_item_id, on_date)
);
CREATE TABLE forecast_overrides (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    menu_item_id  bigint REFERENCES menu_items(id) ON DELETE CASCADE,            -- NULL = every item
    from_date     date NOT NULL,
    to_date       date NOT NULL,
    factor        numeric(8,4) CHECK (factor IS NULL OR factor >= 0),
    qty           numeric(12,4) CHECK (qty IS NULL OR qty >= 0),
    reason        text NOT NULL,
    status        text NOT NULL DEFAULT 'set' CHECK (status IN ('proposed', 'set', 'dismissed')),
    set_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now(),
    CHECK ((factor IS NULL) <> (qty IS NULL)), CHECK (to_date >= from_date)
);
CREATE INDEX forecast_overrides_site_idx ON forecast_overrides (scope_id, from_date, to_date) WHERE status = 'set';
CREATE TABLE forecast_accuracy (
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    menu_item_id  bigint NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    horizon_days  integer NOT NULL,
    window_days   integer NOT NULL,
    mae           numeric(12,4),
    wape          numeric(8,4),
    bias          numeric(8,4),
    n             integer NOT NULL DEFAULT 0,
    computed_at   timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope_id, menu_item_id, horizon_days, window_days)
);
CREATE TABLE covers_expected (
    scope_id     bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    on_date      date NOT NULL,
    day_part_id  bigint REFERENCES day_parts(id) ON DELETE CASCADE,
    day_part_key bigint GENERATED ALWAYS AS (COALESCE(day_part_id, 0)) STORED,
    covers       integer NOT NULL CHECK (covers >= 0),
    source       text NOT NULL DEFAULT 'reservations' CHECK (source IN ('reservations', 'manual')),
    synced_at    timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope_id, on_date, day_part_key)
);

-- The baseline in SQL: the weighted same-weekday average of the last four weeks (0.4, 0.3, 0.2, 0.1), closures excluded
-- and renormalised; a closed day forecasts zero.
CREATE OR REPLACE FUNCTION ri_forecast_baseline(p_scope_id bigint, p_menu_item_id bigint, p_on date) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    WITH w AS (SELECT p_on - 7 * k AS d, CASE k WHEN 1 THEN 0.4 WHEN 2 THEN 0.3 WHEN 3 THEN 0.2 ELSE 0.1 END AS wt FROM generate_series(1, 4) k),
    h AS (SELECT w.wt, COALESCE(sd.qty, 0) AS q FROM w LEFT JOIN sales_daily sd ON sd.scope_id = p_scope_id AND sd.menu_item_id = p_menu_item_id AND sd.business_date = w.d AND sd.day_part_id IS NULL
            LEFT JOIN calendar_days cd ON cd.scope_id = p_scope_id AND cd.on_date = w.d
           WHERE NOT COALESCE(cd.closed, false) AND EXISTS (SELECT 1 FROM sales_daily x WHERE x.scope_id = p_scope_id AND x.business_date = w.d))
    SELECT CASE WHEN COALESCE((SELECT closed FROM calendar_days WHERE scope_id = p_scope_id AND on_date = p_on), false) THEN 0
                WHEN (SELECT sum(wt) FROM h) IS NULL OR (SELECT sum(wt) FROM h) = 0 THEN NULL
                ELSE round((SELECT sum(wt * q) FROM h) / (SELECT sum(wt) FROM h), 4) END
$$;

-- The forecast of an item on a date: the current run's row, else the baseline; the overrides applied; the covers factor
-- when booked covers differ from the weekday's norm by more than the setting.
CREATE OR REPLACE FUNCTION ri_covers_factor(p_scope_id bigint, p_on date) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    WITH booked AS (SELECT sum(covers) AS c FROM covers_expected WHERE scope_id = p_scope_id AND on_date = p_on),
    norm AS (SELECT avg(c) AS c FROM (SELECT sum(s.covers) AS c FROM pos_sales s WHERE s.scope_id = p_scope_id AND s.business_date IN (p_on - 7, p_on - 14, p_on - 21, p_on - 28) GROUP BY s.business_date) x),
    thr AS (SELECT COALESCE(covers_factor_threshold_pct, 15) AS t FROM ri_settings WHERE id = 1)
    SELECT CASE WHEN (SELECT c FROM booked) IS NULL OR COALESCE((SELECT c FROM norm), 0) = 0 THEN 1
                WHEN abs((SELECT c FROM booked) - (SELECT c FROM norm)) / (SELECT c FROM norm) * 100 < (SELECT t FROM thr) THEN 1
                ELSE round((SELECT c FROM booked) / (SELECT c FROM norm), 4) END
$$;

CREATE OR REPLACE FUNCTION ri_item_forecast(p_scope_id bigint, p_menu_item_id bigint, p_on date)
    RETURNS TABLE (qty numeric, source text, sigma numeric)
    LANGUAGE sql STABLE AS $$
    WITH run AS (SELECT f.qty, f.sigma FROM item_forecasts f JOIN forecast_runs r ON r.id = f.run_id WHERE r.scope_id = p_scope_id AND r.is_current AND f.menu_item_id = p_menu_item_id AND f.on_date = p_on),
    base AS (SELECT COALESCE((SELECT qty FROM run), ri_forecast_baseline(p_scope_id, p_menu_item_id, p_on), 0) AS q, CASE WHEN EXISTS (SELECT 1 FROM run) THEN 'run' ELSE 'baseline' END AS src, (SELECT sigma FROM run) AS s),
    ov AS (SELECT o.factor, o.qty FROM forecast_overrides o WHERE o.scope_id = p_scope_id AND o.status = 'set' AND (o.menu_item_id = p_menu_item_id OR o.menu_item_id IS NULL) AND p_on BETWEEN o.from_date AND o.to_date
            ORDER BY o.menu_item_id NULLS LAST, o.created_at DESC LIMIT 1)
    SELECT round(CASE WHEN (SELECT qty FROM ov) IS NOT NULL THEN (SELECT qty FROM ov) ELSE b.q * COALESCE((SELECT factor FROM ov), 1) * ri_covers_factor(p_scope_id, p_on) END, 4),
           CASE WHEN EXISTS (SELECT 1 FROM ov) THEN 'override' ELSE b.src END, b.s
      FROM base b
$$;

-- Ingredient demand over [from, to]: Σ forecast × recipe quantities through sub-recipes and yields, plus the modifiers'
-- average attachment (the last four weeks' modifier quantity per item sold).
CREATE OR REPLACE FUNCTION ri_ingredient_demand(p_scope_id bigint, p_from date, p_to date)
    RETURNS TABLE (ingredient_id bigint, on_date date, qty_base numeric)
    LANGUAGE sql STABLE AS $$
    WITH items AS (SELECT DISTINCT m.id, m.recipe_id, m.portions FROM menu_items m JOIN sales_daily sd ON sd.menu_item_id = m.id AND sd.scope_id = p_scope_id AND sd.business_date >= p_from - 84
                    WHERE m.active AND m.recipe_id IS NOT NULL),
    days AS (SELECT d::date AS d FROM generate_series(p_from, p_to, interval '1 day') d),
    fc AS (SELECT i.id, i.recipe_id, i.portions, dy.d, (ri_item_forecast(p_scope_id, i.id, dy.d)).qty AS q FROM items i CROSS JOIN days dy),
    expl AS (SELECT fc.d, e.ingredient_id, e.qty_base * fc.q * fc.portions / v.batch_yield_qty AS q
               FROM fc JOIN recipe_versions v ON v.id = ri_recipe_version_on(fc.recipe_id, fc.d), LATERAL ri_explode(v.id, fc.d, false) e WHERE fc.q > 0)
    SELECT e.ingredient_id, e.d, round(sum(e.q), 4) FROM expl e GROUP BY e.ingredient_id, e.d
$$;

-- Projected stock per day: on hand + receipts due − Σ demand − planned waste (the category's waste share of usage over the
-- last 28 days). The first day under zero is the stock-out.
CREATE OR REPLACE FUNCTION ri_projected_stock(p_scope_id bigint, p_ingredient_id bigint, p_days integer DEFAULT 14)
    RETURNS TABLE (on_date date, demand_base numeric, receipts_base numeric, projected_base numeric)
    LANGUAGE sql STABLE AS $$
    WITH days AS (SELECT (current_date + k)::date AS d FROM generate_series(0, p_days - 1) k),
    dem AS (SELECT on_date, qty_base FROM ri_ingredient_demand(p_scope_id, current_date, current_date + p_days - 1) WHERE ingredient_id = p_ingredient_id),
    rec AS (SELECT COALESCE(l.expected_on, p.expected_on, p.ordered_on + 1) AS d, sum(greatest(l.qty_ordered_base - l.qty_received_base, 0)) AS q
              FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.purchase_order_id
             WHERE l.ingredient_id = p_ingredient_id AND p.scope_id = p_scope_id AND p.status IN ('sent', 'acknowledged', 'partial') AND l.status IN ('open', 'acknowledged', 'partial') GROUP BY 1),
    wpct AS (SELECT CASE WHEN COALESCE(sum(-t.qty_base) FILTER (WHERE t.txn_type = 'depletion'), 0) > 0
                         THEN COALESCE(sum(-t.qty_base) FILTER (WHERE t.txn_type = 'waste'), 0) / sum(-t.qty_base) FILTER (WHERE t.txn_type = 'depletion') ELSE 0 END AS w
               FROM inventory_transactions t WHERE t.scope_id = p_scope_id AND t.ingredient_id = p_ingredient_id AND t.business_date >= current_date - 28)
    SELECT dy.d, COALESCE(dem.qty_base, 0) * (1 + (SELECT w FROM wpct)), COALESCE(rec.q, 0),
           round(ri_on_hand(p_ingredient_id, p_scope_id) + sum(COALESCE(rec.q, 0) - COALESCE(dem.qty_base, 0) * (1 + (SELECT w FROM wpct))) OVER (ORDER BY dy.d), 4)
      FROM days dy LEFT JOIN dem ON dem.on_date = dy.d LEFT JOIN rec ON rec.d = dy.d ORDER BY dy.d
$$;

CREATE OR REPLACE FUNCTION ri_stockout_on(p_scope_id bigint, p_ingredient_id bigint, p_days integer DEFAULT 14) RETURNS date
    LANGUAGE sql STABLE AS $$ SELECT min(on_date) FROM ri_projected_stock(p_scope_id, p_ingredient_id, p_days) WHERE projected_base < 0 $$;

-- Normal quantile for the service levels used (no extension needed).
CREATE OR REPLACE FUNCTION ri_z(p_service_level numeric) RETURNS numeric
    LANGUAGE sql IMMUTABLE AS $$
    SELECT CASE WHEN p_service_level >= 0.99 THEN 2.326 WHEN p_service_level >= 0.98 THEN 2.054 WHEN p_service_level >= 0.975 THEN 1.960 WHEN p_service_level >= 0.95 THEN 1.645
                WHEN p_service_level >= 0.90 THEN 1.282 WHEN p_service_level >= 0.85 THEN 1.036 WHEN p_service_level >= 0.80 THEN 0.842 ELSE 0.674 END
$$;

-- ---------------------------------------------------------------------------------------------
-- The suggested order for a supplier at a site, as of a moment (design §6.8, step by step):
--   windows: the next cut-off → its delivery; the cut-off after → its delivery = the end of the protection interval P
--   per order-guide line (else every supplier item of the supplier with a par or a forecast):
--     need  = fixed par: par; dynamic: Σ demand over [delivery, next_delivery) + z × σ_daily × √P
--             (σ from the item forecasts' sigma exploded, else the category's safety % of the demand)
--     have  = on hand + on order due by the delivery
--     cap   = perishables: the empirical 0.7-quantile of daily demand × shelf life (the newsvendor fractile, no salvage)
--     raw   = max(min(need, cap) − have, 0)
--     units = ceil(raw / per purchase unit) (round down to the nearest unit when within 20 % over — Craftable's rule; split allowed → 2 decimals), ≥ moq
-- Returns one row per line with every step; the drafting function writes them to a PO.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_suggested_order(p_scope_id bigint, p_supplier_id bigint, p_as_of timestamptz DEFAULT now())
    RETURNS TABLE (supplier_item_id bigint, ingredient_id bigint, purchase_unit_code text, per_unit_base numeric, cutoff_at timestamptz, delivery_on date, next_delivery_on date,
                   par_mode text, demand_base numeric, safety_base numeric, need_base numeric, have_base numeric, on_hand_base numeric, on_order_base numeric, cap_base numeric,
                   raw_base numeric, qty_units numeric, unit_cost numeric, thin_history boolean, rounding_note text)
    LANGUAGE plpgsql STABLE AS $$
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
            IF n < 7 THEN safety_base := round(dmd * cat.safety_stock_pct / 100.0, 4); ELSE safety_base := round(z * sig * sqrt(p_days), 4); END IF;
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
            IF dq IS NOT NULL THEN cap_base := round(dq * i.shelf_life_days, 4); END IF;
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
            IF units < si.moq THEN units := si.moq; rounding_note := rounding_note || '; minimum ' || si.moq; END IF;
        END IF;
        qty_units := units;
        unit_cost := COALESCE(CASE WHEN si.contract_until IS NULL OR si.contract_until >= current_date THEN si.contract_price END, si.last_price, 0);
        RETURN NEXT;
    END LOOP;
END$$;

-- Draft the PO from the suggestion (lines with qty 0 are left out; an empty suggestion drafts nothing and returns NULL).
CREATE OR REPLACE FUNCTION ri_suggested_order_draft(p_scope_id bigint, p_supplier_id bigint, p_by bigint, p_origin text DEFAULT 'suggested') RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r record; pid bigint; n integer := 0; w record;
BEGIN
    SELECT * INTO w FROM ri_next_order_windows(p_supplier_id, p_scope_id, now());
    FOR r IN SELECT * FROM ri_suggested_order(p_scope_id, p_supplier_id, now()) WHERE qty_units > 0 LOOP
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

-- Suppliers whose cut-off falls within the next N hours at a site (the Purchasing agent's morning list).
CREATE OR REPLACE FUNCTION ri_suppliers_due(p_scope_id bigint, p_hours integer DEFAULT 36)
    RETURNS TABLE (supplier_id bigint, cutoff_at timestamptz, delivery_on date)
    LANGUAGE sql STABLE AS $$
    SELECT s.id, w.cutoff_at, w.delivery_on FROM suppliers s, LATERAL ri_next_order_windows(s.id, p_scope_id, now()) w
     WHERE s.active AND EXISTS (SELECT 1 FROM supplier_schedules x WHERE x.supplier_id = s.id AND x.scope_id = p_scope_id AND x.active) AND w.cutoff_at <= now() + (p_hours || ' hours')::interval
$$;

-- Accuracy: MAE, WAPE and bias per item over a window for the current run's horizon (closed days excluded).
CREATE OR REPLACE FUNCTION ri_forecast_accuracy_compute(p_scope_id bigint, p_window_days integer DEFAULT 28) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    INSERT INTO forecast_accuracy (scope_id, menu_item_id, horizon_days, window_days, mae, wape, bias, n)
    SELECT p_scope_id, x.menu_item_id, 7, p_window_days, round(avg(abs(x.f - x.y)), 4),
           CASE WHEN sum(x.y) > 0 THEN round(sum(abs(x.f - x.y)) / sum(x.y), 4) END, CASE WHEN sum(x.y) > 0 THEN round(sum(x.f - x.y) / sum(x.y), 4) END, count(*)::integer
      FROM (SELECT sd.menu_item_id, sd.qty AS y, COALESCE(ri_forecast_baseline(p_scope_id, sd.menu_item_id, sd.business_date), 0) AS f
              FROM sales_daily sd LEFT JOIN calendar_days cd ON cd.scope_id = sd.scope_id AND cd.on_date = sd.business_date
             WHERE sd.scope_id = p_scope_id AND sd.day_part_id IS NULL AND sd.business_date >= current_date - p_window_days AND NOT COALESCE(cd.closed, false)) x
     GROUP BY x.menu_item_id
    ON CONFLICT (scope_id, menu_item_id, horizon_days, window_days) DO UPDATE SET mae = EXCLUDED.mae, wape = EXCLUDED.wape, bias = EXCLUDED.bias, n = EXCLUDED.n, computed_at = now();
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

COMMIT;
