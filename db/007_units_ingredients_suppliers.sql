-- 007: UNITS, INGREDIENT CATEGORIES, INGREDIENTS and their units, per-site settings and costs, price history, YIELD TESTS,
-- SUPPLIERS with their schedules and items, ORDER GUIDES, STORAGE AREAS (design §6.1).
--
-- THE ESTATE'S DATA MODEL (design §6.9): `units` and `ingredient_units` REUSE the cidery's / ProcessCore's `app.units` and
-- `app.item_units` shape verbatim in `public` — the first units tables of the kit family, now canonical; `ingredients` is the
-- `items` family under the kitchen's word (the cidery's column names kept where the concept matches; the retail Inventory's
-- `products` recorded as a name collision of a different concept); `suppliers` and `supplier_items` the cidery's shape with
-- Inventory's `moq`, `order_method`, `lead_time_days` and this application's appended columns; `price_history` Inventory's
-- canonical (kind cost only); `storage_areas` is Inventory's `locations` with `scope_id` appended and `kind` widened.
--
-- THE REFEREE'S RULES: every stored quantity is NUMERIC IN THE INGREDIENT'S BASE UNIT (g, ml or each); a unit converts only
-- within its measure class unless the ingredient carries a density or an each-weight; a case is never "1 each" — an
-- ingredient unit's factor must be > 0 and a purchase unit's factor ≥ 1 base unit; a yield is a percentage in (0, 100].
BEGIN;

CREATE TABLE units (
    code            text PRIMARY KEY CHECK (code ~ '^[a-z][a-z0-9_]{0,15}$'),
    name            text NOT NULL,
    measure_class   text NOT NULL CHECK (measure_class IN ('weight', 'volume', 'each')),
    to_base_factor  numeric(18,8) NOT NULL CHECK (to_base_factor > 0),        -- g, ml or each per one of this unit
    is_base         boolean NOT NULL DEFAULT false,
    display_order   integer NOT NULL DEFAULT 100,
    active          boolean NOT NULL DEFAULT true
);
CREATE UNIQUE INDEX units_one_base_per_class ON units (measure_class) WHERE is_base;
INSERT INTO units (code, name, measure_class, to_base_factor, is_base, display_order) VALUES
    ('g', 'gram', 'weight', 1, true, 10), ('kg', 'kilogram', 'weight', 1000, false, 11),
    ('oz', 'ounce', 'weight', 28.349523125, false, 12), ('lb', 'pound', 'weight', 453.59237, false, 13),
    ('ml', 'millilitre', 'volume', 1, true, 20), ('l', 'litre', 'volume', 1000, false, 21),
    ('floz', 'fluid ounce', 'volume', 29.5735295625, false, 22), ('cup', 'cup', 'volume', 236.5882365, false, 23),
    ('pt', 'pint', 'volume', 473.176473, false, 24), ('qt', 'quart', 'volume', 946.352946, false, 25),
    ('gal', 'gallon', 'volume', 3785.411784, false, 26), ('tsp', 'teaspoon', 'volume', 4.92892159375, false, 27),
    ('tbsp', 'tablespoon', 'volume', 14.78676478125, false, 28),
    ('each', 'each', 'each', 1, true, 30), ('dozen', 'dozen', 'each', 12, false, 31);

CREATE OR REPLACE FUNCTION ri_unit_class(p_code text) RETURNS text
    LANGUAGE sql STABLE AS $$ SELECT measure_class FROM units WHERE code = p_code $$;

CREATE TABLE ingredient_categories (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key               text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name              text NOT NULL,
    kind              text NOT NULL DEFAULT 'food' CHECK (kind IN ('food', 'beverage', 'supplies')),
    parent_id         bigint REFERENCES ingredient_categories(id) ON DELETE SET NULL,
    safety_stock_pct  numeric(5,2) NOT NULL DEFAULT 15 CHECK (safety_stock_pct >= 0 AND safety_stock_pct <= 100),   -- MarginEdge's category rule when history is thin
    price_alert_pct   numeric(5,2),                                            -- NULL = the setting
    temperature_check boolean NOT NULL DEFAULT false,                          -- the FDA door check applies (TCS foods)
    sort_order        integer NOT NULL DEFAULT 100,
    active            boolean NOT NULL DEFAULT true
);
INSERT INTO ingredient_categories (key, name, kind, safety_stock_pct, temperature_check, sort_order) VALUES
    ('produce', 'Produce', 'food', 20, true, 10), ('dairy', 'Dairy', 'food', 18, true, 20), ('protein', 'Protein', 'food', 25, true, 30),
    ('seafood', 'Seafood', 'food', 25, true, 35), ('dry', 'Dry goods', 'food', 10, false, 40), ('frozen', 'Frozen', 'food', 12, true, 50),
    ('bakery', 'Bakery', 'food', 15, false, 60), ('liquor', 'Liquor', 'beverage', 8, false, 70), ('beer', 'Beer', 'beverage', 10, true, 71),
    ('wine', 'Wine', 'beverage', 8, false, 72), ('na_beverage', 'N/A beverage', 'beverage', 10, false, 73),
    ('paper', 'Paper and packaging', 'supplies', 10, false, 80), ('chemicals', 'Chemicals', 'supplies', 10, false, 81);

-- ---------------------------------------------------------------------------------------------
-- Ingredients: one catalog for every site (design §3).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE ingredients (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                    text NOT NULL,
    name                    text NOT NULL,
    category_id             bigint NOT NULL REFERENCES ingredient_categories(id) ON DELETE RESTRICT,
    measure_class           text NOT NULL CHECK (measure_class IN ('weight', 'volume', 'each')),
    base_unit_code          text NOT NULL REFERENCES units(code),
    recipe_unit_code        text NOT NULL REFERENCES units(code),                -- the unit the chef writes
    density_g_per_ml        numeric(10,6) CHECK (density_g_per_ml IS NULL OR density_g_per_ml > 0),
    each_weight_g           numeric(12,4) CHECK (each_weight_g IS NULL OR each_weight_g > 0),
    each_volume_ml          numeric(12,4) CHECK (each_volume_ml IS NULL OR each_volume_ml > 0),
    yield_pct               numeric(6,2) NOT NULL DEFAULT 100 CHECK (yield_pct > 0 AND yield_pct <= 100),   -- EP after trim at receipt — cost-bearing
    catch_weight            boolean NOT NULL DEFAULT false,
    shelf_life_days         integer CHECK (shelf_life_days IS NULL OR shelf_life_days > 0),
    costing_method          text NOT NULL DEFAULT 'avg' CHECK (costing_method IN ('avg', 'last')),
    variance_tolerance_pct  numeric(6,2) CHECK (variance_tolerance_pct IS NULL OR variance_tolerance_pct >= 0),
    variance_tolerance_value numeric(12,2) CHECK (variance_tolerance_value IS NULL OR variance_tolerance_value >= 0),
    allergens               text[] NOT NULL DEFAULT '{}',
    is_prep                 boolean NOT NULL DEFAULT false,                      -- made by a prep recipe (stock of an intermediate)
    notes                   text,
    active                  boolean NOT NULL DEFAULT true,
    archived_at             timestamptz,
    created_by              bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX ingredients_code_idx ON ingredients (lower(code));
CREATE INDEX ingredients_name_idx ON ingredients (lower(name));
CREATE INDEX ingredients_category_idx ON ingredients (category_id) WHERE active;
CREATE TRIGGER ingredients_touch BEFORE UPDATE ON ingredients FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION ri_ingredients_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF ri_unit_class(NEW.base_unit_code) IS DISTINCT FROM NEW.measure_class OR NOT (SELECT is_base FROM units WHERE code = NEW.base_unit_code) THEN
        RAISE EXCEPTION 'The base unit of a % ingredient is its class''s base unit', NEW.measure_class USING ERRCODE = 'check_violation';
    END IF;
    IF ri_unit_class(NEW.recipe_unit_code) IS DISTINCT FROM NEW.measure_class THEN
        IF ri_unit_class(NEW.recipe_unit_code) = 'volume' AND NEW.measure_class = 'weight' AND NEW.density_g_per_ml IS NULL THEN
            RAISE EXCEPTION 'A recipe unit of volume on an ingredient bought by weight needs a density' USING ERRCODE = 'check_violation';
        ELSIF ri_unit_class(NEW.recipe_unit_code) = 'weight' AND NEW.measure_class = 'volume' AND NEW.density_g_per_ml IS NULL THEN
            RAISE EXCEPTION 'A recipe unit of weight on an ingredient bought by volume needs a density' USING ERRCODE = 'check_violation';
        ELSIF NEW.measure_class = 'each' AND NEW.each_weight_g IS NULL AND NEW.each_volume_ml IS NULL THEN
            RAISE EXCEPTION 'A recipe unit of weight or volume on an ingredient counted each needs an each-weight or an each-volume' USING ERRCODE = 'check_violation';
        ELSIF ri_unit_class(NEW.recipe_unit_code) = 'each' AND NEW.measure_class <> 'each' AND NEW.each_weight_g IS NULL AND NEW.each_volume_ml IS NULL THEN
            RAISE EXCEPTION 'A recipe unit of each on an ingredient bought by weight or volume needs an each-weight or an each-volume' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER ingredients_before BEFORE INSERT OR UPDATE ON ingredients FOR EACH ROW EXECUTE FUNCTION ri_ingredients_before();

-- ---------------------------------------------------------------------------------------------
-- Converting a quantity in ANY unit (a global unit or an ingredient's own) to the ingredient's base unit, across classes
-- through the ingredient's density / each-weight. Refuses what it cannot convert.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE ingredient_units (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ingredient_id     bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    unit_code         text NOT NULL CHECK (unit_code ~ '^[a-z][a-z0-9_]{0,23}$'),   -- 'case', 'can', 'bottle', 'bag50'
    name              text NOT NULL,                                                 -- "case of 6 × #10 cans"
    to_base_factor    numeric(18,6) NOT NULL CHECK (to_base_factor > 0),             -- base units per one of this unit
    kind              text NOT NULL DEFAULT 'count' CHECK (kind IN ('purchase', 'count', 'recipe')),
    is_default_count  boolean NOT NULL DEFAULT false,
    active            boolean NOT NULL DEFAULT true,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (ingredient_id, unit_code)
);
CREATE UNIQUE INDEX ingredient_units_default_count ON ingredient_units (ingredient_id) WHERE is_default_count;
CREATE TRIGGER ingredient_units_touch BEFORE UPDATE ON ingredient_units FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_ingredient_units_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM units WHERE code = NEW.unit_code) THEN
        RAISE EXCEPTION 'The unit % is a global unit — an ingredient''s own unit takes another code', NEW.unit_code USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.kind = 'purchase' AND NEW.to_base_factor < 1 THEN
        RAISE EXCEPTION 'A purchase unit holds at least one base unit — a case is never "1 each"' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER ingredient_units_before BEFORE INSERT OR UPDATE ON ingredient_units FOR EACH ROW EXECUTE FUNCTION ri_ingredient_units_before();

CREATE OR REPLACE FUNCTION ri_to_base(p_ingredient_id bigint, p_qty numeric, p_unit_code text) RETURNS numeric
    LANGUAGE plpgsql STABLE AS $$
DECLARE i ingredients%ROWTYPE; u units%ROWTYPE; f numeric;
BEGIN
    SELECT * INTO i FROM ingredients WHERE id = p_ingredient_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such ingredient' USING ERRCODE = 'no_data_found'; END IF;
    SELECT to_base_factor INTO f FROM ingredient_units WHERE ingredient_id = p_ingredient_id AND unit_code = p_unit_code AND active;
    IF FOUND THEN RETURN round(p_qty * f, 4); END IF;
    SELECT * INTO u FROM units WHERE code = p_unit_code AND active;
    IF NOT FOUND THEN RAISE EXCEPTION 'No unit % for %', p_unit_code, i.name USING ERRCODE = 'check_violation'; END IF;
    IF u.measure_class = i.measure_class THEN RETURN round(p_qty * u.to_base_factor, 4); END IF;
    -- across classes
    IF i.measure_class = 'weight' AND u.measure_class = 'volume' THEN
        IF i.density_g_per_ml IS NULL THEN RAISE EXCEPTION '% has no density — a volume cannot become a weight', i.name USING ERRCODE = 'check_violation'; END IF;
        RETURN round(p_qty * u.to_base_factor * i.density_g_per_ml, 4);
    ELSIF i.measure_class = 'volume' AND u.measure_class = 'weight' THEN
        IF i.density_g_per_ml IS NULL THEN RAISE EXCEPTION '% has no density — a weight cannot become a volume', i.name USING ERRCODE = 'check_violation'; END IF;
        RETURN round(p_qty * u.to_base_factor / i.density_g_per_ml, 4);
    ELSIF i.measure_class = 'each' AND u.measure_class = 'weight' THEN
        IF i.each_weight_g IS NULL THEN RAISE EXCEPTION '% has no each-weight', i.name USING ERRCODE = 'check_violation'; END IF;
        RETURN round(p_qty * u.to_base_factor / i.each_weight_g, 4);
    ELSIF i.measure_class = 'each' AND u.measure_class = 'volume' THEN
        IF i.each_volume_ml IS NULL THEN RAISE EXCEPTION '% has no each-volume', i.name USING ERRCODE = 'check_violation'; END IF;
        RETURN round(p_qty * u.to_base_factor / i.each_volume_ml, 4);
    ELSIF i.measure_class = 'weight' AND u.measure_class = 'each' THEN
        IF i.each_weight_g IS NULL THEN RAISE EXCEPTION '% has no each-weight', i.name USING ERRCODE = 'check_violation'; END IF;
        RETURN round(p_qty * u.to_base_factor * i.each_weight_g, 4);
    ELSIF i.measure_class = 'volume' AND u.measure_class = 'each' THEN
        IF i.each_volume_ml IS NULL THEN RAISE EXCEPTION '% has no each-volume', i.name USING ERRCODE = 'check_violation'; END IF;
        RETURN round(p_qty * u.to_base_factor * i.each_volume_ml, 4);
    END IF;
    RAISE EXCEPTION 'Cannot convert % to the base unit of %', p_unit_code, i.name USING ERRCODE = 'check_violation';
END$$;

-- The inverse: a base quantity shown in a unit (for screens and tools).
CREATE OR REPLACE FUNCTION ri_from_base(p_ingredient_id bigint, p_qty_base numeric, p_unit_code text) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT CASE WHEN ri_to_base(p_ingredient_id, 1, p_unit_code) = 0 THEN NULL ELSE round(p_qty_base / ri_to_base(p_ingredient_id, 1, p_unit_code), 4) END
$$;

-- ---------------------------------------------------------------------------------------------
-- Per-site settings and costs (design §3: pars and costs are per site).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE ingredient_site_settings (
    ingredient_id               bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    scope_id                    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    par_base                    numeric(18,4) CHECK (par_base IS NULL OR par_base >= 0),
    par_mode                    text NOT NULL DEFAULT 'fixed' CHECK (par_mode IN ('fixed', 'dynamic')),
    reorder_min_base            numeric(18,4) CHECK (reorder_min_base IS NULL OR reorder_min_base >= 0),
    service_level               numeric(4,3) NOT NULL DEFAULT 0.95 CHECK (service_level > 0.5 AND service_level < 1),
    preferred_supplier_item_id  bigint,                                            -- FK below
    cost_override_per_base      numeric(18,6) CHECK (cost_override_per_base IS NULL OR cost_override_per_base >= 0),
    default_area_id             bigint,                                            -- FK below (where this ingredient is depleted from)
    active                      boolean NOT NULL DEFAULT true,
    updated_at                  timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (ingredient_id, scope_id)
);
CREATE TRIGGER ingredient_site_settings_touch BEFORE UPDATE ON ingredient_site_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE ingredient_site_costs (
    ingredient_id      bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    scope_id           bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    avg_cost_per_base  numeric(18,6) NOT NULL DEFAULT 0 CHECK (avg_cost_per_base >= 0),
    last_cost_per_base numeric(18,6) CHECK (last_cost_per_base IS NULL OR last_cost_per_base >= 0),
    last_cost_at       timestamptz,
    on_hand_base       numeric(18,4) NOT NULL DEFAULT 0,                             -- Σ over the site's storage areas, kept by the ledger
    on_hand_value      numeric(14,2) NOT NULL DEFAULT 0,
    updated_at         timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (ingredient_id, scope_id)
);
-- Written by the ledger's trigger alone (db/009), like inventory_balances.
CREATE OR REPLACE FUNCTION ri_site_costs_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF COALESCE(current_setting('ri.balance_writer', true), '') <> 'on' THEN
        RAISE EXCEPTION 'A site''s cost and on-hand are maintained only by the ledger' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    NEW.updated_at := now();
    RETURN NEW;
END$$;
CREATE TRIGGER ingredient_site_costs_guard BEFORE INSERT OR UPDATE OR DELETE ON ingredient_site_costs FOR EACH ROW EXECUTE FUNCTION ri_site_costs_guard();

-- Every cost change, with who, when and why (Inventory's canonical price_history, kind cost only).
CREATE TABLE price_history (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ingredient_id   bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    scope_id        bigint REFERENCES sites(scope_id) ON DELETE CASCADE,          -- NULL = every site (a manual catalog cost)
    kind            text NOT NULL DEFAULT 'cost' CHECK (kind IN ('cost')),
    old_per_base    numeric(18,6),
    new_per_base    numeric(18,6) NOT NULL CHECK (new_per_base >= 0),
    per_unit_code   text,                                                          -- the unit the price was given in, for the screen
    per_unit_price  numeric(14,4),
    source_kind     text NOT NULL CHECK (source_kind IN ('receipt', 'invoice', 'manual', 'contract', 'production', 'import')),
    reference_kind  text,
    reference_id    bigint,
    reason          text,
    changed_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    changed_at      timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX price_history_ingredient_idx ON price_history (ingredient_id, scope_id, changed_at DESC);

-- The cost of an ingredient at a site as of a moment, on a basis (design §6.2): the site's cost override first, then the
-- last price-history row at or before the moment, then the site's running average, then zero (flagged by the caller).
CREATE OR REPLACE FUNCTION ri_cost_as_of(p_ingredient_id bigint, p_scope_id bigint, p_as_of timestamptz DEFAULT now(), p_basis text DEFAULT NULL) RETURNS numeric
    LANGUAGE plpgsql STABLE AS $$
DECLARE basis text := COALESCE(p_basis, (SELECT cost_basis FROM ri_settings WHERE id = 1), 'avg'); c numeric;
BEGIN
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

CREATE TABLE yield_tests (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ingredient_id   bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    ap_qty_base     numeric(18,4) NOT NULL CHECK (ap_qty_base > 0),
    ep_qty_base     numeric(18,4) NOT NULL CHECK (ep_qty_base > 0),
    trim_loss_pct   numeric(6,2),
    cook_loss_pct   numeric(6,2),
    byproducts      jsonb NOT NULL DEFAULT '[]'::jsonb,                            -- [{name, qty_base, unit_value}]
    yield_pct       numeric(6,2) GENERATED ALWAYS AS (round(ep_qty_base / ap_qty_base * 100, 2)) STORED,
    applied         boolean NOT NULL DEFAULT false,                                -- set the ingredient's yield_pct
    notes           text,
    tested_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    tested_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (ep_qty_base <= ap_qty_base)
);
CREATE INDEX yield_tests_ingredient_idx ON yield_tests (ingredient_id, tested_at DESC);

-- ---------------------------------------------------------------------------------------------
-- Suppliers, their schedules and items; order guides (design §6.1, §6.8).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE suppliers (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name                  text NOT NULL,
    kind                  text NOT NULL DEFAULT 'distributor' CHECK (kind IN ('distributor', 'producer', 'wholesaler', 'retailer', 'commissary', 'other')),
    contact_name          text,
    email                 citext,
    phone                 text,
    address               text,
    notes                 text,
    terms_days            integer CHECK (terms_days IS NULL OR terms_days >= 0),
    lead_time_days        integer NOT NULL DEFAULT 1 CHECK (lead_time_days >= 0),
    order_method          text NOT NULL DEFAULT 'email' CHECK (order_method IN ('email', 'portal', 'phone', 'api', 'edi')),
    order_email           citext,
    portal_url            text,
    min_order_value       numeric(12,2) CHECK (min_order_value IS NULL OR min_order_value >= 0),
    allow_split_case      boolean NOT NULL DEFAULT false,
    match_tolerance_pct   numeric(5,2) NOT NULL DEFAULT 2 CHECK (match_tolerance_pct >= 0),
    match_tolerance_value numeric(12,2) NOT NULL DEFAULT 5 CHECK (match_tolerance_value >= 0),
    account_number        text,
    rep_name              text,
    rep_phone             text,
    commissary_scope_id   bigint REFERENCES sites(scope_id) ON DELETE SET NULL,   -- kind commissary: the site it is
    active                boolean NOT NULL DEFAULT true,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX suppliers_name_idx ON suppliers (lower(name));
CREATE TRIGGER suppliers_touch BEFORE UPDATE ON suppliers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- "Order by Saturday 13:00 for Tuesday delivery": several rows a week, per site (a supplier may serve sites differently).
CREATE TABLE supplier_schedules (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_id   bigint NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    order_dow     smallint NOT NULL CHECK (order_dow BETWEEN 0 AND 6),             -- 0 = Sunday (ISODOW % 7)
    cutoff_time   time NOT NULL DEFAULT '12:00',
    delivery_dow  smallint NOT NULL CHECK (delivery_dow BETWEEN 0 AND 6),
    lead_days     smallint GENERATED ALWAYS AS (((delivery_dow - order_dow + 7) % 7)) STORED,
    active        boolean NOT NULL DEFAULT true,
    UNIQUE (supplier_id, scope_id, order_dow, cutoff_time)
);
CREATE INDEX supplier_schedules_site_idx ON supplier_schedules (scope_id, supplier_id) WHERE active;

-- The next order opportunity at a site for a supplier, from a moment: the cut-off and the delivery it leads to, and the
-- delivery after the NEXT opportunity (the protection interval's end). Lead days of 0 mean same-day; the delivery is on or
-- after the cut-off.
CREATE OR REPLACE FUNCTION ri_next_order_windows(p_supplier_id bigint, p_scope_id bigint, p_from timestamptz DEFAULT now())
    RETURNS TABLE (cutoff_at timestamptz, delivery_on date, next_cutoff_at timestamptz, next_delivery_on date)
    LANGUAGE plpgsql STABLE AS $$
DECLARE tz text; d date; r record; found_first boolean := false; k integer;
BEGIN
    SELECT timezone INTO tz FROM sites WHERE scope_id = p_scope_id;
    tz := COALESCE(tz, 'UTC');
    d := (p_from AT TIME ZONE tz)::date;
    FOR k IN 0..14 LOOP
        FOR r IN SELECT s.* FROM supplier_schedules s WHERE s.supplier_id = p_supplier_id AND s.scope_id = p_scope_id AND s.active
                   AND s.order_dow = extract(isodow FROM d + k)::int % 7 ORDER BY s.cutoff_time LOOP
            IF ((d + k) + r.cutoff_time) AT TIME ZONE tz > p_from THEN
                IF NOT found_first THEN
                    cutoff_at := ((d + k) + r.cutoff_time) AT TIME ZONE tz; delivery_on := (d + k) + r.lead_days; found_first := true;
                ELSE
                    next_cutoff_at := ((d + k) + r.cutoff_time) AT TIME ZONE tz; next_delivery_on := (d + k) + r.lead_days;
                    RETURN NEXT; RETURN;
                END IF;
            END IF;
        END LOOP;
    END LOOP;
    IF found_first THEN
        -- one schedule a week: the next opportunity is a week on
        next_cutoff_at := cutoff_at + interval '7 days'; next_delivery_on := delivery_on + 7;
        RETURN NEXT;
    ELSE
        -- no schedule: order any day, lead time from the supplier
        SELECT lead_time_days INTO k FROM suppliers WHERE id = p_supplier_id;
        cutoff_at := p_from; delivery_on := d + COALESCE(k, 1); next_cutoff_at := p_from + interval '1 day'; next_delivery_on := delivery_on + 1;
        RETURN NEXT;
    END IF;
END$$;

CREATE TABLE supplier_items (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_id         bigint NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    ingredient_id       bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    supplier_sku        text,
    description         text,
    purchase_unit_code  text NOT NULL,                                             -- an ingredient_units.unit_code of kind purchase, or a global unit (lb for catch weight)
    pack                text,                                                      -- "6 × #10", "50 lb bag" — for the screen
    last_price          numeric(14,4) CHECK (last_price IS NULL OR last_price >= 0),  -- per purchase unit
    last_price_at       timestamptz,
    contract_price      numeric(14,4) CHECK (contract_price IS NULL OR contract_price >= 0),
    contract_until      date,
    moq                 numeric(12,4) NOT NULL DEFAULT 1 CHECK (moq > 0),           -- in purchase units
    catch_weight        boolean NOT NULL DEFAULT false,                             -- ordered by the piece, priced and received by weight
    lead_time_days      integer CHECK (lead_time_days IS NULL OR lead_time_days >= 0),
    active              boolean NOT NULL DEFAULT true,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (supplier_id, ingredient_id, purchase_unit_code)
);
CREATE INDEX supplier_items_ingredient_idx ON supplier_items (ingredient_id) WHERE active;
CREATE INDEX supplier_items_sku_idx ON supplier_items (supplier_id, lower(supplier_sku));
CREATE TRIGGER supplier_items_touch BEFORE UPDATE ON supplier_items FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_supplier_items_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    PERFORM ri_to_base(NEW.ingredient_id, 1, NEW.purchase_unit_code);   -- refuses a unit the ingredient cannot convert
    RETURN NEW;
END$$;
CREATE TRIGGER supplier_items_before BEFORE INSERT OR UPDATE ON supplier_items FOR EACH ROW EXECUTE FUNCTION ri_supplier_items_before();
ALTER TABLE ingredient_site_settings ADD CONSTRAINT ingredient_site_settings_pref_fk FOREIGN KEY (preferred_supplier_item_id) REFERENCES supplier_items(id) ON DELETE SET NULL;

-- A supplier item's cost per base unit, from its last (or contract) price.
CREATE OR REPLACE FUNCTION ri_supplier_item_cost_per_base(p_supplier_item_id bigint) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT CASE WHEN COALESCE(si.contract_price, si.last_price) IS NULL THEN NULL
                ELSE round(COALESCE(CASE WHEN si.contract_until IS NULL OR si.contract_until >= current_date THEN si.contract_price END, si.last_price)
                           / ri_to_base(si.ingredient_id, 1, si.purchase_unit_code), 6) END
      FROM supplier_items si WHERE si.id = p_supplier_item_id
$$;

CREATE TABLE order_guides (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    supplier_id  bigint NOT NULL REFERENCES suppliers(id) ON DELETE CASCADE,
    scope_id     bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    name         text NOT NULL DEFAULT 'Order guide',
    active       boolean NOT NULL DEFAULT true,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now(),
    UNIQUE (supplier_id, scope_id, name)
);
CREATE TRIGGER order_guides_touch BEFORE UPDATE ON order_guides FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TABLE order_guide_lines (
    guide_id          bigint NOT NULL REFERENCES order_guides(id) ON DELETE CASCADE,
    supplier_item_id  bigint NOT NULL REFERENCES supplier_items(id) ON DELETE CASCADE,
    seq               integer NOT NULL DEFAULT 0,
    preferred         boolean NOT NULL DEFAULT true,
    PRIMARY KEY (guide_id, supplier_item_id)
);

-- ---------------------------------------------------------------------------------------------
-- Storage areas: where stock sits inside a site. Inventory's `locations` with scope_id and the kitchen's kinds.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE storage_areas (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id        bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    name            text NOT NULL,
    kind            text NOT NULL DEFAULT 'dry' CHECK (kind IN ('walk_in', 'freezer', 'dry', 'bar', 'line', 'office', 'in_transit', 'other')),
    walk_order      integer NOT NULL DEFAULT 100,
    allow_negative  boolean NOT NULL DEFAULT true,                                 -- a kitchen depletes before it counts — the balance may dip below zero until the count (design §1)
    active          boolean NOT NULL DEFAULT true,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, name)
);
CREATE INDEX storage_areas_site_idx ON storage_areas (scope_id, walk_order) WHERE active;
CREATE TRIGGER storage_areas_touch BEFORE UPDATE ON storage_areas FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE ingredient_site_settings ADD CONSTRAINT ingredient_site_settings_area_fk FOREIGN KEY (default_area_id) REFERENCES storage_areas(id) ON DELETE SET NULL;
ALTER TABLE site_settings ADD CONSTRAINT site_settings_depletion_area_fk FOREIGN KEY (depletion_area_id) REFERENCES storage_areas(id) ON DELETE SET NULL;

-- The area an ingredient is depleted from at a site: its own default, else the site's, else the first area in walk order.
CREATE OR REPLACE FUNCTION ri_depletion_area(p_ingredient_id bigint, p_scope_id bigint) RETURNS bigint
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE((SELECT default_area_id FROM ingredient_site_settings WHERE ingredient_id = p_ingredient_id AND scope_id = p_scope_id),
                    (SELECT depletion_area_id FROM site_settings WHERE scope_id = p_scope_id),
                    (SELECT id FROM storage_areas WHERE scope_id = p_scope_id AND active ORDER BY walk_order, id LIMIT 1))
$$;

COMMIT;
