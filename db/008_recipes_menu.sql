-- 008: RECIPES (menu item, prep, modifier), their VERSIONS with effective dates, their LINES naming an ingredient or a
-- sub-recipe, the MENU ITEMS the POS sells and their MODIFIERS, and COSTING as of a date (design §6.2).
--
-- THE ESTATE'S DATA MODEL (design §6.9): NEW — the canonical kitchen recipe. The cidery's `recipe_versions` columns are
-- kept where the concept matches (version_no, status, expected_loss_pct, change_note, activated_at/by, the immutability
-- guard); sub-recipes, the per-line yield, the menu link and effective dates are new; "backflush" is the estate's word for
-- what a sale does to a recipe's lines. `menu_items` / `pos_*` — nothing in the estate holds a POS sale or a menu.
--
-- THE REFEREE'S RULES: a recipe may not contain itself through any path (refused at save); a version's lines change only
-- while it is a draft; ONE active version per recipe per date — activating a new one ends the previous at the new one's
-- effective_from; the cost of a recipe is COMPUTED as of a date from price history, never stored on the version; a prep
-- recipe's output ingredient is flagged is_prep.
BEGIN;

CREATE TABLE menu_categories (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    key         text NOT NULL UNIQUE CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    kind        text NOT NULL DEFAULT 'food' CHECK (kind IN ('food', 'beverage', 'other')),
    sort_order  integer NOT NULL DEFAULT 100,
    active      boolean NOT NULL DEFAULT true
);
INSERT INTO menu_categories (key, name, kind, sort_order) VALUES ('starters', 'Starters', 'food', 10), ('mains', 'Mains', 'food', 20),
    ('sides', 'Sides', 'food', 30), ('desserts', 'Desserts', 'food', 40), ('cocktails', 'Cocktails', 'beverage', 50), ('beer', 'Beer', 'beverage', 51),
    ('wine', 'Wine', 'beverage', 52), ('na', 'Non-alcoholic', 'beverage', 53), ('prep', 'Prep', 'other', 90), ('modifiers', 'Modifiers', 'other', 91);

CREATE TABLE recipes (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code                  text NOT NULL,
    name                  text NOT NULL,
    kind                  text NOT NULL CHECK (kind IN ('menu_item', 'prep', 'modifier')),
    category_id           bigint REFERENCES menu_categories(id) ON DELETE SET NULL,
    output_ingredient_id  bigint REFERENCES ingredients(id) ON DELETE RESTRICT,     -- prep: the ingredient this recipe makes stock of
    portion_qty           numeric(14,4) CHECK (portion_qty IS NULL OR portion_qty > 0),   -- a menu item's portion, in portion_unit_code (informational)
    portion_unit_code     text,
    method                text,
    photo_attachment_id   bigint,
    allergens_rollup      text[] NOT NULL DEFAULT '{}',                               -- maintained by ri_recipe_allergens on activation
    active                boolean NOT NULL DEFAULT true,
    archived_at           timestamptz,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CHECK (kind <> 'prep' OR output_ingredient_id IS NOT NULL),
    CHECK (kind = 'prep' OR output_ingredient_id IS NULL)
);
CREATE UNIQUE INDEX recipes_code_idx ON recipes (lower(code));
CREATE INDEX recipes_name_idx ON recipes (lower(name));
CREATE INDEX recipes_kind_idx ON recipes (kind, category_id) WHERE active;
CREATE UNIQUE INDEX recipes_output_idx ON recipes (output_ingredient_id) WHERE output_ingredient_id IS NOT NULL AND active;
CREATE TRIGGER recipes_touch BEFORE UPDATE ON recipes FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_recipes_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.output_ingredient_id IS NOT NULL THEN UPDATE ingredients SET is_prep = true WHERE id = NEW.output_ingredient_id AND NOT is_prep; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER recipes_after AFTER INSERT OR UPDATE OF output_ingredient_id ON recipes FOR EACH ROW EXECUTE FUNCTION ri_recipes_after();

CREATE TABLE recipe_versions (
    id                     bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    recipe_id              bigint NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
    version_no             integer NOT NULL,
    status                 text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'active', 'retired')),
    effective_from         date,
    effective_to           date,                                                      -- exclusive; NULL = in force
    batch_yield_qty        numeric(14,4) NOT NULL DEFAULT 1 CHECK (batch_yield_qty > 0),   -- what one batch makes: a prep's output (in its unit) or a menu item's portions
    batch_yield_unit_code  text,                                                      -- prep: a unit of the output ingredient; menu item/modifier: NULL = portions
    expected_loss_pct      numeric(6,2) NOT NULL DEFAULT 0 CHECK (expected_loss_pct >= 0 AND expected_loss_pct < 100),  -- cook loss on the batch as a whole
    change_note            text,
    activated_at           timestamptz,
    activated_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    created_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at             timestamptz NOT NULL DEFAULT now(),
    updated_at             timestamptz NOT NULL DEFAULT now(),
    UNIQUE (recipe_id, version_no),
    CHECK (status = 'draft' OR effective_from IS NOT NULL),
    CHECK (effective_to IS NULL OR effective_to > effective_from)
);
CREATE INDEX recipe_versions_recipe_idx ON recipe_versions (recipe_id, effective_from DESC);
CREATE UNIQUE INDEX recipe_versions_one_draft ON recipe_versions (recipe_id) WHERE status = 'draft';
CREATE UNIQUE INDEX recipe_versions_one_open ON recipe_versions (recipe_id) WHERE status = 'active' AND effective_to IS NULL;
CREATE TRIGGER recipe_versions_touch BEFORE UPDATE ON recipe_versions FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE OR REPLACE FUNCTION ri_recipe_versions_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        IF NEW.version_no IS NULL THEN SELECT COALESCE(max(version_no), 0) + 1 INTO NEW.version_no FROM recipe_versions WHERE recipe_id = NEW.recipe_id; END IF;
        RETURN NEW;
    END IF;
    -- immutable once active, except the closing of its window and retirement
    IF OLD.status <> 'draft' AND (NEW.batch_yield_qty IS DISTINCT FROM OLD.batch_yield_qty OR NEW.batch_yield_unit_code IS DISTINCT FROM OLD.batch_yield_unit_code
        OR NEW.expected_loss_pct IS DISTINCT FROM OLD.expected_loss_pct OR NEW.effective_from IS DISTINCT FROM OLD.effective_from OR NEW.version_no <> OLD.version_no) THEN
        RAISE EXCEPTION 'Version % of the recipe is % — a new version carries the change', OLD.version_no, OLD.status USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER recipe_versions_before BEFORE INSERT OR UPDATE ON recipe_versions FOR EACH ROW EXECUTE FUNCTION ri_recipe_versions_before();

CREATE TABLE recipe_lines (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    version_id      bigint NOT NULL REFERENCES recipe_versions(id) ON DELETE CASCADE,
    seq             integer NOT NULL DEFAULT 0,
    ingredient_id   bigint REFERENCES ingredients(id) ON DELETE RESTRICT,
    sub_recipe_id   bigint REFERENCES recipes(id) ON DELETE RESTRICT,
    qty             numeric(14,4) NOT NULL CHECK (qty > 0),
    unit_code       text NOT NULL,                                                    -- ingredient: any convertible unit; sub-recipe: the sub-recipe's batch unit, or 'portion'
    qty_base        numeric(18,4) NOT NULL,                                           -- ingredient: in its base unit; sub-recipe: in the sub-recipe's batch unit (its output's base when prep)
    line_yield_pct  numeric(6,2) NOT NULL DEFAULT 100 CHECK (line_yield_pct > 0 AND line_yield_pct <= 100),   -- depletion-bearing: a dice yields less than a julienne
    group_name      text,
    optional        boolean NOT NULL DEFAULT false,
    notes           text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    CHECK ((ingredient_id IS NULL) <> (sub_recipe_id IS NULL))
);
CREATE INDEX recipe_lines_version_idx ON recipe_lines (version_id, seq);
CREATE INDEX recipe_lines_ingredient_idx ON recipe_lines (ingredient_id);
CREATE INDEX recipe_lines_sub_idx ON recipe_lines (sub_recipe_id);
CREATE TRIGGER recipe_lines_touch BEFORE UPDATE ON recipe_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Does recipe A reach recipe B through its lines (any version in force or draft)? Used to refuse cycles.
CREATE OR REPLACE FUNCTION ri_recipe_reaches(p_from bigint, p_to bigint) RETURNS boolean
    LANGUAGE sql STABLE AS $$
    WITH RECURSIVE walk AS (
        SELECT l.sub_recipe_id AS rid, 1 AS depth FROM recipe_lines l JOIN recipe_versions v ON v.id = l.version_id
         WHERE v.recipe_id = p_from AND l.sub_recipe_id IS NOT NULL AND v.status <> 'retired'
        UNION
        SELECT l.sub_recipe_id, w.depth + 1 FROM walk w JOIN recipe_versions v ON v.recipe_id = w.rid AND v.status <> 'retired'
          JOIN recipe_lines l ON l.version_id = v.id AND l.sub_recipe_id IS NOT NULL WHERE w.depth < 12)
    SELECT EXISTS (SELECT 1 FROM walk WHERE rid = p_to)
$$;

CREATE OR REPLACE FUNCTION ri_recipe_lines_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE v recipe_versions%ROWTYPE; sub recipes%ROWTYPE; subv recipe_versions%ROWTYPE;
BEGIN
    SELECT * INTO v FROM recipe_versions WHERE id = COALESCE(NEW.version_id, OLD.version_id);
    IF v.status <> 'draft' THEN RAISE EXCEPTION 'The lines of version % change only while it is a draft', v.version_no USING ERRCODE = 'check_violation'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.ingredient_id IS NOT NULL THEN
        NEW.qty_base := ri_to_base(NEW.ingredient_id, NEW.qty, NEW.unit_code);
    ELSE
        SELECT * INTO sub FROM recipes WHERE id = NEW.sub_recipe_id;
        IF sub.id = v.recipe_id OR ri_recipe_reaches(NEW.sub_recipe_id, v.recipe_id) THEN
            RAISE EXCEPTION 'A recipe may not contain itself — % reaches this recipe', sub.name USING ERRCODE = 'check_violation';
        END IF;
        IF sub.kind = 'prep' THEN
            NEW.qty_base := ri_to_base(sub.output_ingredient_id, NEW.qty, NEW.unit_code);   -- a prep is consumed in its output ingredient's units
        ELSE
            IF NEW.unit_code <> 'portion' THEN RAISE EXCEPTION 'A menu-item or modifier sub-recipe is taken in portions' USING ERRCODE = 'check_violation'; END IF;
            NEW.qty_base := NEW.qty;
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER recipe_lines_before BEFORE INSERT OR UPDATE OR DELETE ON recipe_lines FOR EACH ROW EXECUTE FUNCTION ri_recipe_lines_before();

-- The version in force on a date.
CREATE OR REPLACE FUNCTION ri_recipe_version_on(p_recipe_id bigint, p_on date DEFAULT current_date) RETURNS bigint
    LANGUAGE sql STABLE AS $$
    SELECT id FROM recipe_versions WHERE recipe_id = p_recipe_id AND status <> 'draft' AND effective_from <= p_on AND (effective_to IS NULL OR effective_to > p_on)
     ORDER BY effective_from DESC, version_no DESC LIMIT 1
$$;

-- Allergens through the tree (ingredients' allergens, the sub-recipes' roll-ups).
CREATE OR REPLACE FUNCTION ri_recipe_allergens(p_version_id bigint) RETURNS text[]
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE(array_agg(DISTINCT a ORDER BY a), '{}')
      FROM (SELECT unnest(i.allergens) AS a FROM recipe_lines l JOIN ingredients i ON i.id = l.ingredient_id WHERE l.version_id = p_version_id
            UNION SELECT unnest(r.allergens_rollup) FROM recipe_lines l JOIN recipes r ON r.id = l.sub_recipe_id WHERE l.version_id = p_version_id) x
$$;

-- Activation: the draft becomes the version in force from a date; the previous open version ends there; a prep's
-- output ingredient inherits the roll-up's allergens (design §6.2).
CREATE OR REPLACE FUNCTION ri_recipe_version_activate(p_version_id bigint, p_by bigint, p_from date DEFAULT current_date) RETURNS recipe_versions
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE v recipe_versions%ROWTYPE; r recipes%ROWTYPE;
BEGIN
    SELECT * INTO v FROM recipe_versions WHERE id = p_version_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such version' USING ERRCODE = 'no_data_found'; END IF;
    IF v.status <> 'draft' THEN RAISE EXCEPTION 'Version % is already %', v.version_no, v.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM recipe_lines WHERE version_id = p_version_id) THEN RAISE EXCEPTION 'Version % has no lines', v.version_no USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO r FROM recipes WHERE id = v.recipe_id;
    IF r.kind = 'prep' AND v.batch_yield_unit_code IS NULL THEN RAISE EXCEPTION 'A prep recipe''s batch yield names a unit of its output' USING ERRCODE = 'check_violation'; END IF;
    IF EXISTS (SELECT 1 FROM recipe_versions WHERE recipe_id = v.recipe_id AND status = 'active' AND effective_from >= p_from) THEN
        RAISE EXCEPTION 'A version already in force from % or later exists — activate from a later date', p_from USING ERRCODE = 'check_violation';
    END IF;
    UPDATE recipe_versions SET effective_to = p_from WHERE recipe_id = v.recipe_id AND status = 'active' AND effective_to IS NULL;
    UPDATE recipe_versions SET status = 'active', effective_from = p_from, activated_at = now(), activated_by = p_by WHERE id = p_version_id RETURNING * INTO v;
    UPDATE recipes SET allergens_rollup = ri_recipe_allergens(p_version_id) WHERE id = v.recipe_id;
    IF r.output_ingredient_id IS NOT NULL THEN
        UPDATE ingredients SET allergens = ri_recipe_allergens(p_version_id) WHERE id = r.output_ingredient_id;
    END IF;
    RETURN v;
END$$;

-- A new draft copied from the version in force (the way a chef edits a live recipe).
CREATE OR REPLACE FUNCTION ri_recipe_version_draft(p_recipe_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS recipe_versions
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE src recipe_versions%ROWTYPE; v recipe_versions%ROWTYPE;
BEGIN
    IF EXISTS (SELECT 1 FROM recipe_versions WHERE recipe_id = p_recipe_id AND status = 'draft') THEN
        RAISE EXCEPTION 'A draft already exists for this recipe' USING ERRCODE = 'check_violation';
    END IF;
    SELECT * INTO src FROM recipe_versions WHERE id = ri_recipe_version_on(p_recipe_id, current_date);
    INSERT INTO recipe_versions (recipe_id, batch_yield_qty, batch_yield_unit_code, expected_loss_pct, change_note, created_by)
    VALUES (p_recipe_id, COALESCE(src.batch_yield_qty, 1), src.batch_yield_unit_code, COALESCE(src.expected_loss_pct, 0), p_note, p_by) RETURNING * INTO v;
    IF src.id IS NOT NULL THEN
        INSERT INTO recipe_lines (version_id, seq, ingredient_id, sub_recipe_id, qty, unit_code, qty_base, line_yield_pct, group_name, optional, notes)
        SELECT v.id, seq, ingredient_id, sub_recipe_id, qty, unit_code, qty_base, line_yield_pct, group_name, optional, notes FROM recipe_lines WHERE version_id = src.id ORDER BY seq;
    END IF;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- The explosion: one batch (or one portion) of a version into base quantities of leaf ingredients, through sub-recipes with
-- their yields, as of a date (the sub-recipes' versions in force then). A prep sub-recipe taken from STOCK is a leaf (its
-- output ingredient) unless p_explode_prep — the depletion of a sale consumes the prep from stock when the kitchen posts
-- production, else the raw ingredients (site setting; design D12 posts production, so the default is the prep as stock).
-- Returns (ingredient_id, qty_base per one batch of the top version).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_explode(p_version_id bigint, p_on date DEFAULT current_date, p_explode_prep boolean DEFAULT false)
    RETURNS TABLE (ingredient_id bigint, qty_base numeric)
    LANGUAGE sql STABLE AS $$
    WITH RECURSIVE tree AS (
        SELECT l.ingredient_id, l.sub_recipe_id, (l.qty_base / (l.line_yield_pct / 100.0))::numeric AS q, 1 AS depth
          FROM recipe_lines l WHERE l.version_id = p_version_id
        UNION ALL
        SELECT l.ingredient_id, l.sub_recipe_id,
               t.q * (l.qty_base / (l.line_yield_pct / 100.0)) / (sv.batch_yield_qty * (1 - sv.expected_loss_pct / 100.0))
               / CASE WHEN sr.kind = 'prep' THEN ri_to_base(sr.output_ingredient_id, 1, sv.batch_yield_unit_code) ELSE 1 END,
               t.depth + 1
          FROM tree t JOIN recipes sr ON sr.id = t.sub_recipe_id
          JOIN recipe_versions sv ON sv.id = ri_recipe_version_on(sr.id, p_on)
          JOIN recipe_lines l ON l.version_id = sv.id
         WHERE t.sub_recipe_id IS NOT NULL AND t.depth < 12 AND (p_explode_prep OR sr.kind <> 'prep'))
    SELECT x.iid, sum(x.q) FROM (
        SELECT t.ingredient_id AS iid, t.q FROM tree t WHERE t.ingredient_id IS NOT NULL
        UNION ALL
        SELECT sr.output_ingredient_id, t.q FROM tree t JOIN recipes sr ON sr.id = t.sub_recipe_id WHERE sr.kind = 'prep' AND NOT p_explode_prep) x
    GROUP BY x.iid
$$;

-- The cost of one batch and of one portion of a version at a site as of a moment: every leaf at ri_cost_as_of, every
-- sub-recipe at its own batch cost ÷ its batch yield, every line ÷ its yield. Lines are returned too, so the card shows the
-- share of each (design §6.2). Memoised by day in recipe_cost_cache for the reports.
CREATE OR REPLACE FUNCTION ri_recipe_cost(p_version_id bigint, p_scope_id bigint, p_as_of timestamptz DEFAULT now(), p_basis text DEFAULT NULL)
    RETURNS TABLE (batch_cost numeric, portion_cost numeric, batch_yield_qty numeric, lines jsonb, uncosted integer)
    LANGUAGE plpgsql STABLE AS $$
DECLARE v recipe_versions%ROWTYPE; r recipes%ROWTYPE; l record; c numeric; lc numeric; tot numeric := 0; unc integer := 0; js jsonb := '[]'::jsonb;
        sub_cost numeric; sub_v bigint; sub_r recipes%ROWTYPE; sub_rows record;
BEGIN
    SELECT * INTO v FROM recipe_versions WHERE id = p_version_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such version' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO r FROM recipes WHERE id = v.recipe_id;
    FOR l IN SELECT * FROM recipe_lines WHERE version_id = p_version_id ORDER BY seq, id LOOP
        IF l.ingredient_id IS NOT NULL THEN
            c := ri_cost_as_of(l.ingredient_id, p_scope_id, p_as_of, p_basis);
            IF c = 0 THEN unc := unc + 1; END IF;
            lc := l.qty_base / (l.line_yield_pct / 100.0) * c;
            js := js || jsonb_build_object('line_id', l.id, 'ingredient_id', l.ingredient_id, 'qty', l.qty, 'unit_code', l.unit_code, 'qty_base', l.qty_base,
                                           'cost_per_base', c, 'line_cost', round(lc, 4));
        ELSE
            SELECT * INTO sub_r FROM recipes WHERE id = l.sub_recipe_id;
            sub_v := ri_recipe_version_on(l.sub_recipe_id, (p_as_of AT TIME ZONE 'UTC')::date);
            IF sub_v IS NULL THEN unc := unc + 1; lc := 0; sub_cost := NULL;
            ELSE
                SELECT * INTO sub_rows FROM ri_recipe_cost(sub_v, p_scope_id, p_as_of, p_basis);
                unc := unc + sub_rows.uncosted;
                IF sub_r.kind = 'prep' THEN
                    -- the sub's batch yield is in its output's unit, net of its expected loss; the line's qty_base is in that output's base unit
                    sub_cost := sub_rows.batch_cost / (ri_to_base(sub_r.output_ingredient_id, sub_rows.batch_yield_qty, (SELECT batch_yield_unit_code FROM recipe_versions WHERE id = sub_v))
                                                       * (1 - (SELECT expected_loss_pct FROM recipe_versions WHERE id = sub_v) / 100.0));
                ELSE
                    sub_cost := sub_rows.portion_cost;   -- per portion
                END IF;
                lc := l.qty_base / (l.line_yield_pct / 100.0) * sub_cost;
            END IF;
            js := js || jsonb_build_object('line_id', l.id, 'sub_recipe_id', l.sub_recipe_id, 'qty', l.qty, 'unit_code', l.unit_code, 'qty_base', l.qty_base,
                                           'cost_per_base', sub_cost, 'line_cost', round(lc, 4));
        END IF;
        tot := tot + lc;
    END LOOP;
    batch_cost := round(tot, 4);
    batch_yield_qty := v.batch_yield_qty;
    -- a menu item's or modifier's batch is batch_yield_qty portions; a prep's portion cost is per batch unit after loss
    portion_cost := CASE WHEN r.kind = 'prep' THEN round(tot / (v.batch_yield_qty * (1 - v.expected_loss_pct / 100.0)), 4)
                         ELSE round(tot / v.batch_yield_qty, 4) END;
    lines := js; uncosted := unc;
    RETURN NEXT;
END$$;

CREATE TABLE recipe_cost_cache (
    version_id        bigint NOT NULL REFERENCES recipe_versions(id) ON DELETE CASCADE,
    scope_id          bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    as_of_date        date NOT NULL,
    basis             text NOT NULL,
    batch_cost        numeric(14,4) NOT NULL,
    portion_cost      numeric(14,4) NOT NULL,
    uncosted          integer NOT NULL DEFAULT 0,
    computed_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (version_id, scope_id, as_of_date, basis)
);
CREATE OR REPLACE FUNCTION ri_portion_cost_cached(p_version_id bigint, p_scope_id bigint, p_on date, p_basis text DEFAULT NULL) RETURNS numeric
    LANGUAGE plpgsql AS $$
DECLARE b text := COALESCE(p_basis, (SELECT cost_basis FROM ri_settings WHERE id = 1), 'avg'); c numeric; rr record;
BEGIN
    SELECT portion_cost INTO c FROM recipe_cost_cache WHERE version_id = p_version_id AND scope_id = p_scope_id AND as_of_date = p_on AND basis = b;
    IF FOUND THEN RETURN c; END IF;
    SELECT * INTO rr FROM ri_recipe_cost(p_version_id, p_scope_id, (p_on + 1)::timestamp AT TIME ZONE 'UTC' - interval '1 second', b);
    INSERT INTO recipe_cost_cache (version_id, scope_id, as_of_date, basis, batch_cost, portion_cost, uncosted)
    VALUES (p_version_id, p_scope_id, p_on, b, rr.batch_cost, rr.portion_cost, rr.uncosted) ON CONFLICT DO NOTHING;
    RETURN rr.portion_cost;
END$$;
-- a cost change invalidates the day's cache for the site (today and later)
CREATE OR REPLACE FUNCTION ri_price_history_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    DELETE FROM recipe_cost_cache WHERE as_of_date >= (NEW.changed_at AT TIME ZONE 'UTC')::date AND (NEW.scope_id IS NULL OR scope_id = NEW.scope_id);
    RETURN NEW;
END$$;
CREATE TRIGGER price_history_after AFTER INSERT ON price_history FOR EACH ROW EXECUTE FUNCTION ri_price_history_after();

-- ---------------------------------------------------------------------------------------------
-- Menu items: what the POS sells, by PLU per source. A PLU with no recipe is the unmapped queue.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE menu_items (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pos_source_id  bigint,                                                           -- FK in db/011; NULL = entered by hand
    plu            text NOT NULL,
    name           text NOT NULL,
    category_id    bigint REFERENCES menu_categories(id) ON DELETE SET NULL,
    sale_price     numeric(12,2) CHECK (sale_price IS NULL OR sale_price >= 0),
    recipe_id      bigint REFERENCES recipes(id) ON DELETE SET NULL,
    portions       numeric(10,4) NOT NULL DEFAULT 1 CHECK (portions > 0),             -- a "large" = 1.5 portions of the recipe
    active         boolean NOT NULL DEFAULT true,
    first_seen_at  timestamptz NOT NULL DEFAULT now(),
    last_sold_at   timestamptz,
    mapped_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    mapped_at      timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX menu_items_plu_idx ON menu_items (COALESCE(pos_source_id, 0), plu);
CREATE INDEX menu_items_recipe_idx ON menu_items (recipe_id);
CREATE INDEX menu_items_unmapped_idx ON menu_items (pos_source_id) WHERE recipe_id IS NULL AND active;
CREATE TRIGGER menu_items_touch BEFORE UPDATE ON menu_items FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_menu_items_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.recipe_id IS NOT NULL AND (SELECT kind FROM recipes WHERE id = NEW.recipe_id) <> 'menu_item' THEN
        RAISE EXCEPTION 'A menu item maps to a menu-item recipe' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.recipe_id IS NOT NULL AND (TG_OP = 'INSERT' OR NEW.recipe_id IS DISTINCT FROM OLD.recipe_id) THEN
        NEW.mapped_at := now(); NEW.mapped_by := COALESCE(NEW.mapped_by, app_current_member_id());
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER menu_items_before BEFORE INSERT OR UPDATE ON menu_items FOR EACH ROW EXECUTE FUNCTION ri_menu_items_before();

CREATE TABLE menu_item_prices (
    menu_item_id    bigint NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    scope_id        bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    price           numeric(12,2) NOT NULL CHECK (price >= 0),
    effective_from  date NOT NULL DEFAULT current_date,
    PRIMARY KEY (menu_item_id, scope_id, effective_from)
);
CREATE OR REPLACE FUNCTION ri_menu_price(p_menu_item_id bigint, p_scope_id bigint, p_on date DEFAULT current_date) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE((SELECT price FROM menu_item_prices WHERE menu_item_id = p_menu_item_id AND scope_id = p_scope_id AND effective_from <= p_on ORDER BY effective_from DESC LIMIT 1),
                    (SELECT sale_price FROM menu_items WHERE id = p_menu_item_id))
$$;

CREATE TABLE modifiers (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    pos_source_id  bigint,
    code           text NOT NULL,
    name           text NOT NULL,
    action         text NOT NULL DEFAULT 'none' CHECK (action IN ('add', 'remove', 'swap', 'none')),
    recipe_id      bigint REFERENCES recipes(id) ON DELETE SET NULL,                -- a modifier recipe (add / swap: what is added; remove: what is taken off)
    qty_factor     numeric(8,4) NOT NULL DEFAULT 1 CHECK (qty_factor > 0),
    active         boolean NOT NULL DEFAULT true,
    first_seen_at  timestamptz NOT NULL DEFAULT now(),
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX modifiers_code_idx ON modifiers (COALESCE(pos_source_id, 0), code);
CREATE INDEX modifiers_unmapped_idx ON modifiers (pos_source_id) WHERE recipe_id IS NULL AND action <> 'none' AND active;
CREATE TRIGGER modifiers_touch BEFORE UPDATE ON modifiers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- A modifier that means a different quantity on each parent ("no tomato" on the wedge vs the burger).
CREATE TABLE menu_item_modifiers (
    menu_item_id       bigint NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    modifier_id        bigint NOT NULL REFERENCES modifiers(id) ON DELETE CASCADE,
    qty_factor         numeric(8,4) CHECK (qty_factor IS NULL OR qty_factor > 0),  -- NULL = the modifier's
    recipe_id          bigint REFERENCES recipes(id) ON DELETE SET NULL,            -- NULL = the modifier's
    PRIMARY KEY (menu_item_id, modifier_id)
);

-- Menu engineering (Kasavana & Smith) for a site over a window: needs the sales (db/011) — defined in db/016.

COMMIT;
