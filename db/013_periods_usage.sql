-- 013: COSTING CALENDARS and PERIODS per site, the defining function ri_usage() — actual vs theoretical with every bucket —,
-- the CLOSE that freezes the ending value and COGS, the late-receipt rule, stock value and food-cost % (design §6.7).
--
-- THE ESTATE'S DATA MODEL (design §6.9): NEW — nothing per period per site exists (the GL's fiscal periods are the books',
-- read never copied; the cidery's v_batch_stage_yields a per-batch cousin).
--
-- THE REFEREE'S RULES: actual usage = opening + purchases + transfers in + production in − transfers out − production out
-- − closing (waste is INSIDE actual); theoretical = Σ depletion; variance = actual − theoretical; explained = waste logged
-- + comps; unexplained = the rest; opening and closing are the approved counts' figures when they exist, else the book
-- balance at the boundary; closing a period freezes ending_value and cogs_value; a receipt dated inside a closed period
-- lands in the next open period flagged late (the kernel's statement rule).
BEGIN;

CREATE TABLE costing_calendars (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name           text NOT NULL,
    kind           text NOT NULL CHECK (kind IN ('week', 'month', '4-4-5', '4-5-4', '5-4-4', '13x4')),
    year_start_on  date NOT NULL,                                                -- the first day of the fiscal year (week kind: a week boundary)
    week_starts    smallint NOT NULL DEFAULT 1 CHECK (week_starts BETWEEN 0 AND 6),   -- 1 = Monday
    is_default     boolean NOT NULL DEFAULT false,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX costing_calendars_default ON costing_calendars (is_default) WHERE is_default;

CREATE TABLE periods (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id          bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    calendar_id       bigint NOT NULL REFERENCES costing_calendars(id) ON DELETE RESTRICT,
    no                integer NOT NULL,                                          -- 1.. within the fiscal year
    fiscal_year       integer NOT NULL,
    starts_on         date NOT NULL,
    ends_on           date NOT NULL,                                             -- inclusive
    status            text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'closed')),
    opening_count_id  bigint REFERENCES inventory_counts(id) ON DELETE SET NULL,
    closing_count_id  bigint REFERENCES inventory_counts(id) ON DELETE SET NULL,
    opening_value     numeric(14,2),
    purchases_value   numeric(14,2),
    ending_value      numeric(14,2),
    cogs_value        numeric(14,2),
    theoretical_value numeric(14,2),
    waste_value       numeric(14,2),
    food_sales        numeric(14,2),
    closed_at         timestamptz,
    closed_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    reopened_at       timestamptz,
    reopened_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, calendar_id, starts_on),
    CHECK (ends_on >= starts_on)
);
CREATE INDEX periods_site_idx ON periods (scope_id, starts_on DESC);

-- The period boundaries a calendar makes for a year (weeks from the year start; months; 4-4-5 families; 13 × 4).
CREATE OR REPLACE FUNCTION ri_calendar_periods(p_calendar_id bigint, p_fiscal_year integer) RETURNS TABLE (no integer, starts_on date, ends_on date)
    LANGUAGE plpgsql STABLE AS $$
DECLARE c costing_calendars%ROWTYPE; ys date; d date; i integer; w integer; lens integer[]; k integer;
BEGIN
    SELECT * INTO c FROM costing_calendars WHERE id = p_calendar_id;
    ys := c.year_start_on + ((p_fiscal_year - extract(year FROM c.year_start_on)::integer) * interval '1 year');
    IF c.kind = 'month' THEN
        FOR i IN 0..11 LOOP no := i + 1; starts_on := (ys + (i * interval '1 month'))::date; ends_on := (ys + ((i + 1) * interval '1 month'))::date - 1; RETURN NEXT; END LOOP;
        RETURN;
    END IF;
    -- week-based kinds: align the year start to the week boundary
    d := ys - ((extract(isodow FROM ys)::integer % 7 - c.week_starts + 7) % 7);
    IF c.kind = 'week' THEN
        FOR i IN 0..52 LOOP
            EXIT WHEN d + i * 7 >= (ys + interval '1 year')::date;
            no := i + 1; starts_on := d + i * 7; ends_on := d + i * 7 + 6; RETURN NEXT;
        END LOOP;
        RETURN;
    END IF;
    lens := CASE c.kind WHEN '4-4-5' THEN ARRAY[4,4,5,4,4,5,4,4,5,4,4,5] WHEN '4-5-4' THEN ARRAY[4,5,4,4,5,4,4,5,4,4,5,4] WHEN '5-4-4' THEN ARRAY[5,4,4,5,4,4,5,4,4,5,4,4] ELSE ARRAY[4,4,4,4,4,4,4,4,4,4,4,4,4] END;
    w := 0;
    FOR k IN 1..array_length(lens, 1) LOOP
        no := k; starts_on := d + w * 7; ends_on := d + (w + lens[k]) * 7 - 1;
        IF k = array_length(lens, 1) AND (d + (w + lens[k] + 1) * 7 - 1) < (ys + interval '1 year')::date - 3 THEN ends_on := ends_on + 7; END IF;   -- the 53rd week joins the last period
        RETURN NEXT; w := w + lens[k];
    END LOOP;
END$$;

-- Make (or find) the periods of a year at a site.
CREATE OR REPLACE FUNCTION ri_periods_ensure(p_scope_id bigint, p_fiscal_year integer, p_calendar_id bigint DEFAULT NULL) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE cal bigint := COALESCE(p_calendar_id, (SELECT id FROM costing_calendars WHERE is_default)); n integer;
BEGIN
    IF cal IS NULL THEN RAISE EXCEPTION 'No costing calendar is set' USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO periods (scope_id, calendar_id, no, fiscal_year, starts_on, ends_on)
    SELECT p_scope_id, cal, no, p_fiscal_year, starts_on, ends_on FROM ri_calendar_periods(cal, p_fiscal_year) ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

CREATE OR REPLACE FUNCTION ri_period_on(p_scope_id bigint, p_date date) RETURNS bigint
    LANGUAGE sql STABLE AS $$ SELECT id FROM periods WHERE scope_id = p_scope_id AND p_date BETWEEN starts_on AND ends_on ORDER BY calendar_id LIMIT 1 $$;

-- ---------------------------------------------------------------------------------------------
-- ri_usage: the defining function. Per ingredient over [from, to] (inclusive dates, the site's day): every bucket in base
-- units and at cost. Opening/closing use the approved count on the boundary when one exists for the site (its lines'
-- counted figures), else the book balance at the moment. Costs at the period's weighted average (Σ value ÷ Σ qty of the
-- opening and the period's receipts), else the current cost.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_site_moment(p_scope_id bigint, p_date date, p_end boolean) RETURNS timestamptz
    LANGUAGE sql STABLE AS $$
    SELECT ((CASE WHEN p_end THEN p_date + 1 ELSE p_date END)::timestamp + COALESCE((SELECT sales_cut_time FROM site_settings WHERE scope_id = p_scope_id), '04:00'::time)) AT TIME ZONE COALESCE((SELECT timezone FROM sites WHERE scope_id = p_scope_id), 'UTC')
$$;

CREATE OR REPLACE FUNCTION ri_usage(p_scope_id bigint, p_from date, p_to date, p_basis text DEFAULT NULL)
    RETURNS TABLE (ingredient_id bigint, category_id bigint, opening_base numeric, purchases_base numeric, transfers_in_base numeric, transfers_out_base numeric,
                   production_in_base numeric, production_out_base numeric, adjustments_base numeric, closing_base numeric, actual_base numeric, theoretical_base numeric,
                   variance_base numeric, waste_base numeric, comps_base numeric, unexplained_base numeric, unit_cost numeric,
                   opening_value numeric, purchases_value numeric, closing_value numeric, actual_value numeric, theoretical_value numeric, variance_value numeric, waste_value numeric, unexplained_value numeric)
    LANGUAGE sql STABLE AS $$
    WITH bounds AS (SELECT ri_site_moment(p_scope_id, p_from, false) AS t0, ri_site_moment(p_scope_id, p_to, true) AS t1),
    ids AS (SELECT DISTINCT t.ingredient_id FROM inventory_transactions t WHERE t.scope_id = p_scope_id
             UNION SELECT c.ingredient_id FROM ingredient_site_costs c WHERE c.scope_id = p_scope_id AND c.on_hand_base <> 0),
    mv AS (
        SELECT t.ingredient_id,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'receipt' OR (t.txn_type = 'reversal' AND r.txn_type = 'receipt')) AS purchases,
               sum(t.value)    FILTER (WHERE t.txn_type = 'receipt' OR (t.txn_type = 'reversal' AND r.txn_type = 'receipt')) AS purchases_v,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'transfer_in'  OR (t.txn_type = 'reversal' AND r.txn_type = 'transfer_in'))  AS xin,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'transfer_out' OR (t.txn_type = 'reversal' AND r.txn_type = 'transfer_out')) AS xout,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'production_in'  OR (t.txn_type = 'reversal' AND r.txn_type = 'production_in'))  AS pin,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'production_out' OR (t.txn_type = 'reversal' AND r.txn_type = 'production_out')) AS pout,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'adjustment' OR (t.txn_type = 'reversal' AND r.txn_type = 'adjustment')) AS adj,
               sum(t.qty_base) FILTER (WHERE t.txn_type IN ('depletion', 'return') OR (t.txn_type = 'reversal' AND r.txn_type IN ('depletion', 'return'))) AS depl,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'waste' OR (t.txn_type = 'reversal' AND r.txn_type = 'waste')) AS waste,
               sum(t.qty_base) FILTER (WHERE t.txn_type = 'count_correction' AND t.occurred_at > (SELECT t0 FROM bounds) AND t.occurred_at < (SELECT t1 FROM bounds)) AS mid_counts
          FROM inventory_transactions t LEFT JOIN inventory_transactions r ON r.id = t.reverses_id, bounds b
         WHERE t.scope_id = p_scope_id AND t.occurred_at > b.t0 AND t.occurred_at <= b.t1
         GROUP BY t.ingredient_id),
    comps AS (
        SELECT e.ingredient_id, sum(e.qty_base * sd.comps_qty / v.batch_yield_qty) AS q
          FROM sales_daily sd JOIN menu_items m ON m.id = sd.menu_item_id JOIN recipe_versions v ON v.id = ri_recipe_version_on(m.recipe_id, sd.business_date),
               LATERAL ri_explode(v.id, sd.business_date, false) e
         WHERE sd.scope_id = p_scope_id AND sd.day_part_id IS NULL AND sd.business_date BETWEEN p_from AND p_to AND sd.comps_qty > 0 GROUP BY e.ingredient_id),
    rows_ AS (
        SELECT i.ingredient_id, ing.category_id,
               ri_on_hand_as_of(i.ingredient_id, p_scope_id, (SELECT t0 FROM bounds)) AS opening,
               COALESCE(mv.purchases, 0) AS purchases, COALESCE(mv.purchases_v, 0) AS purchases_v, COALESCE(mv.xin, 0) AS xin, -COALESCE(mv.xout, 0) AS xout,
               COALESCE(mv.pin, 0) AS pin, -COALESCE(mv.pout, 0) AS pout, COALESCE(mv.adj, 0) AS adj,
               ri_on_hand_as_of(i.ingredient_id, p_scope_id, (SELECT t1 FROM bounds)) AS closing,
               -COALESCE(mv.depl, 0) AS theoretical, -COALESCE(mv.waste, 0) AS waste, COALESCE(c.q, 0) AS comps,
               CASE WHEN p_basis = 'last' THEN ri_cost_as_of(i.ingredient_id, p_scope_id, (SELECT t1 FROM bounds), 'last')
                    WHEN (ri_on_hand_as_of(i.ingredient_id, p_scope_id, (SELECT t0 FROM bounds)) + COALESCE(mv.purchases, 0)) > 0
                     THEN round((ri_on_hand_as_of(i.ingredient_id, p_scope_id, (SELECT t0 FROM bounds)) * ri_cost_as_of(i.ingredient_id, p_scope_id, (SELECT t0 FROM bounds)) + COALESCE(mv.purchases_v, 0))
                                / (ri_on_hand_as_of(i.ingredient_id, p_scope_id, (SELECT t0 FROM bounds)) + COALESCE(mv.purchases, 0)), 6)
                    ELSE ri_cost_as_of(i.ingredient_id, p_scope_id, (SELECT t1 FROM bounds)) END AS cost
          FROM ids i JOIN ingredients ing ON ing.id = i.ingredient_id LEFT JOIN mv ON mv.ingredient_id = i.ingredient_id LEFT JOIN comps c ON c.ingredient_id = i.ingredient_id)
    SELECT r.ingredient_id, r.category_id, r.opening, r.purchases, r.xin, r.xout, r.pin, r.pout, r.adj, r.closing,
           (r.opening + r.purchases + r.xin - r.xout + r.pin - r.pout + r.adj - r.closing) AS actual,
           r.theoretical,
           (r.opening + r.purchases + r.xin - r.xout + r.pin - r.pout + r.adj - r.closing) - r.theoretical AS variance,
           r.waste, r.comps,
           (r.opening + r.purchases + r.xin - r.xout + r.pin - r.pout + r.adj - r.closing) - r.theoretical - r.waste AS unexplained,
           r.cost,
           round(r.opening * r.cost, 2), round(r.purchases_v, 2), round(r.closing * r.cost, 2),
           round((r.opening + r.purchases + r.xin - r.xout + r.pin - r.pout + r.adj - r.closing) * r.cost, 2),
           round(r.theoretical * r.cost, 2),
           round(((r.opening + r.purchases + r.xin - r.xout + r.pin - r.pout + r.adj - r.closing) - r.theoretical) * r.cost, 2),
           round(r.waste * r.cost, 2),
           round(((r.opening + r.purchases + r.xin - r.xout + r.pin - r.pout + r.adj - r.closing) - r.theoretical - r.waste) * r.cost, 2)
      FROM rows_ r
     WHERE r.opening <> 0 OR r.purchases <> 0 OR r.closing <> 0 OR r.theoretical <> 0 OR r.waste <> 0 OR r.xin <> 0 OR r.xout <> 0 OR r.pin <> 0 OR r.pout <> 0
$$;

CREATE OR REPLACE FUNCTION ri_usage_by_category(p_scope_id bigint, p_from date, p_to date, p_basis text DEFAULT NULL)
    RETURNS TABLE (category_id bigint, opening_value numeric, purchases_value numeric, closing_value numeric, actual_value numeric, theoretical_value numeric, variance_value numeric, waste_value numeric, unexplained_value numeric, ingredients integer)
    LANGUAGE sql STABLE AS $$
    SELECT u.category_id, sum(u.opening_value), sum(u.purchases_value), sum(u.closing_value), sum(u.actual_value), sum(u.theoretical_value), sum(u.variance_value), sum(u.waste_value), sum(u.unexplained_value), count(*)::integer
      FROM ri_usage(p_scope_id, p_from, p_to, p_basis) u GROUP BY u.category_id
$$;

CREATE OR REPLACE FUNCTION ri_food_sales(p_scope_id bigint, p_from date, p_to date, p_kind text DEFAULT 'food') RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE(sum(sd.net), 0) FROM sales_daily sd JOIN menu_items m ON m.id = sd.menu_item_id LEFT JOIN menu_categories c ON c.id = m.category_id
     WHERE sd.scope_id = p_scope_id AND sd.day_part_id IS NULL AND sd.business_date BETWEEN p_from AND p_to AND (p_kind IS NULL OR COALESCE(c.kind, 'food') = p_kind)
$$;

CREATE OR REPLACE FUNCTION ri_stock_value(p_scope_id bigint, p_as_of timestamptz DEFAULT now())
    RETURNS TABLE (ingredient_id bigint, category_id bigint, on_hand_base numeric, unit_cost numeric, value numeric)
    LANGUAGE sql STABLE AS $$
    SELECT c.ingredient_id, i.category_id, ri_on_hand_as_of(c.ingredient_id, p_scope_id, p_as_of), ri_cost_as_of(c.ingredient_id, p_scope_id, p_as_of),
           round(ri_on_hand_as_of(c.ingredient_id, p_scope_id, p_as_of) * ri_cost_as_of(c.ingredient_id, p_scope_id, p_as_of), 2)
      FROM ingredient_site_costs c JOIN ingredients i ON i.id = c.ingredient_id WHERE c.scope_id = p_scope_id AND ri_on_hand_as_of(c.ingredient_id, p_scope_id, p_as_of) <> 0
$$;

-- Close: the figures frozen. The closing count (if named) must be approved and as-of the period's end; else the book.
CREATE OR REPLACE FUNCTION ri_period_close(p_period_id bigint, p_by bigint, p_closing_count_id bigint DEFAULT NULL) RETURNS periods
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p periods%ROWTYPE; tot record;
BEGIN
    SELECT * INTO p FROM periods WHERE id = p_period_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such period' USING ERRCODE = 'no_data_found'; END IF;
    IF p.status = 'closed' THEN RAISE EXCEPTION 'Period % is closed', p.no USING ERRCODE = 'check_violation'; END IF;
    IF p.ends_on >= current_date THEN RAISE EXCEPTION 'Period % ends % — it closes after its last day', p.no, p.ends_on USING ERRCODE = 'check_violation'; END IF;
    IF EXISTS (SELECT 1 FROM periods WHERE scope_id = p.scope_id AND calendar_id = p.calendar_id AND status = 'open' AND ends_on < p.starts_on) THEN
        RAISE EXCEPTION 'An earlier period is still open — close it first' USING ERRCODE = 'check_violation';
    END IF;
    IF p_closing_count_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM inventory_counts WHERE id = p_closing_count_id AND scope_id = p.scope_id AND status = 'approved') THEN
        RAISE EXCEPTION 'The closing count is not approved' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (SELECT 1 FROM ri_depletion_due() d WHERE d.scope_id = p.scope_id AND d.business_date BETWEEN p.starts_on AND p.ends_on) THEN
        RAISE EXCEPTION 'Sales in the period are not yet depleted — run the depletion first' USING ERRCODE = 'check_violation';
    END IF;
    SELECT sum(opening_value) AS o, sum(purchases_value) AS pu, sum(closing_value) AS c, sum(actual_value) AS a, sum(theoretical_value) AS t, sum(waste_value) AS w INTO tot FROM ri_usage(p.scope_id, p.starts_on, p.ends_on);
    UPDATE periods SET status = 'closed', closed_at = now(), closed_by = p_by, closing_count_id = COALESCE(p_closing_count_id, closing_count_id),
           opening_value = COALESCE(tot.o, 0), purchases_value = COALESCE(tot.pu, 0), ending_value = COALESCE(tot.c, 0), cogs_value = COALESCE(tot.a, 0),
           theoretical_value = COALESCE(tot.t, 0), waste_value = COALESCE(tot.w, 0), food_sales = ri_food_sales(p.scope_id, p.starts_on, p.ends_on, NULL)
     WHERE id = p_period_id RETURNING * INTO p;
    UPDATE periods SET opening_count_id = COALESCE(opening_count_id, p.closing_count_id), opening_value = COALESCE(opening_value, p.ending_value)
     WHERE scope_id = p.scope_id AND calendar_id = p.calendar_id AND starts_on = p.ends_on + 1;
    RETURN p;
END$$;

CREATE OR REPLACE FUNCTION ri_period_reopen(p_period_id bigint, p_by bigint) RETURNS periods
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p periods%ROWTYPE;
BEGIN
    SELECT * INTO p FROM periods WHERE id = p_period_id FOR UPDATE;
    IF p.status <> 'closed' THEN RAISE EXCEPTION 'Period % is open', p.no USING ERRCODE = 'check_violation'; END IF;
    IF EXISTS (SELECT 1 FROM periods WHERE scope_id = p.scope_id AND calendar_id = p.calendar_id AND status = 'closed' AND starts_on > p.ends_on) THEN
        RAISE EXCEPTION 'A later period is closed — reopen it first' USING ERRCODE = 'check_violation';
    END IF;
    UPDATE periods SET status = 'open', reopened_at = now(), reopened_by = p_by WHERE id = p_period_id RETURNING * INTO p;
    RETURN p;
END$$;

-- The late-receipt rule: a transaction whose business date falls in a CLOSED period is moved to the first open period's
-- first day and flagged in its note (the kernel's statement rule). Receipts and invoices are the usual case.
CREATE OR REPLACE FUNCTION ri_transactions_late() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE p periods%ROWTYPE; nxt date;
BEGIN
    IF NOT COALESCE((SELECT late_receipt_flag FROM ri_settings WHERE id = 1), true) THEN RETURN NEW; END IF;
    SELECT * INTO p FROM periods WHERE scope_id = NEW.scope_id AND status = 'closed' AND NEW.business_date BETWEEN starts_on AND ends_on LIMIT 1;
    IF FOUND THEN
        SELECT min(starts_on) INTO nxt FROM periods WHERE scope_id = NEW.scope_id AND calendar_id = p.calendar_id AND status = 'open' AND starts_on > p.ends_on;
        nxt := COALESCE(nxt, p.ends_on + 1);
        NEW.note := concat_ws(' · ', NEW.note, 'late: dated ' || NEW.business_date || ' in closed period ' || p.no);
        NEW.business_date := nxt;
        NEW.occurred_at := greatest(NEW.occurred_at, ri_site_moment(NEW.scope_id, nxt, false) + interval '1 second');
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_transactions_late BEFORE INSERT ON inventory_transactions FOR EACH ROW EXECUTE FUNCTION ri_transactions_late();

COMMIT;
