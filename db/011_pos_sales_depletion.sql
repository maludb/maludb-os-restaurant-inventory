-- 011: POS SOURCES (Inventory's canonical connector frame), the imported SALES and their lines, DEPLETION RUNS — one per
-- site per business date, one ledger row per ingredient per area per date —, the daily SALES table the forecaster reads
-- (design §6.5).
--
-- THE ESTATE'S DATA MODEL (design §6.9): `sources`, `source_pulls`, `source_credentials` REUSE Inventory's canonical
-- connector frame verbatim (the crawl columns kept nullable: a POS is read with a key or a file, never crawled);
-- `pos_sales`, `pos_sale_lines`, `depletion_runs`, `sales_daily` are NEW — nothing in the estate holds a POS sale; the
-- import's unmatched PLUs are a queue like Inventory's unmatched listings (menu_items with no recipe, db/008).
--
-- THE REFEREE'S RULES: a void never depletes, a comp always does; a sale explodes through the recipe version in force on
-- its business date; a run posts ONE depletion transaction per ingredient per area per date and may be re-run (the old
-- group reversed); an unmapped PLU is counted and queued, never dropped; a credential's ciphertext appears nowhere.
BEGIN;

CREATE OR REPLACE FUNCTION ri_connectors() RETURNS text[] LANGUAGE sql IMMUTABLE AS $$ SELECT ARRAY['file', 'square', 'toast', 'clover', 'lightspeed', 'manual'] $$;

CREATE TABLE sources (
    id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id              bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,       -- a POS belongs to a site
    name                  text NOT NULL,
    connector             text NOT NULL CHECK (connector = ANY (ri_connectors())),
    role                  text NOT NULL DEFAULT 'pos' CHECK (role IN ('pos')),
    base_url              text,
    settings              jsonb NOT NULL DEFAULT '{}'::jsonb CHECK (jsonb_typeof(settings) = 'object'),   -- file: {mapping_key, delimiter, intake: upload|email|sftp, sftp: {host, path}}; square: {location_id}
    credential_id         bigint,
    schedule_minutes      integer NOT NULL DEFAULT 0 CHECK (schedule_minutes >= 0),            -- 0 = manual / on arrival
    last_pull_id          bigint,
    last_ok_at            timestamptz,
    consecutive_failures  integer NOT NULL DEFAULT 0,
    backoff_until         timestamptz,
    paused_at             timestamptz,
    paused_reason         text,
    active                boolean NOT NULL DEFAULT true,
    created_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, name)
);
CREATE TRIGGER sources_touch BEFORE UPDATE ON sources FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE menu_items ADD CONSTRAINT menu_items_source_fk FOREIGN KEY (pos_source_id) REFERENCES sources(id) ON DELETE SET NULL;
ALTER TABLE modifiers ADD CONSTRAINT modifiers_source_fk FOREIGN KEY (pos_source_id) REFERENCES sources(id) ON DELETE SET NULL;

CREATE TABLE source_credentials (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id   bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind        text NOT NULL CHECK (kind IN ('api_key', 'oauth_client', 'basic', 'sftp_password', 'sftp_key', 'bearer')),
    label       text NOT NULL,
    last4       text NOT NULL CHECK (length(last4) BETWEEN 1 AND 4),
    ciphertext  bytea NOT NULL,                                                 -- libsodium secretbox under RI_SECRETS_KEY
    rotated_at  timestamptz,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX source_credentials_source_idx ON source_credentials (source_id);
CREATE TRIGGER source_credentials_touch BEFORE UPDATE ON source_credentials FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE sources ADD CONSTRAINT sources_credential_fk FOREIGN KEY (credential_id) REFERENCES source_credentials(id) ON DELETE SET NULL;

CREATE TABLE source_pulls (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    source_id      bigint NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
    kind           text NOT NULL DEFAULT 'scheduled' CHECK (kind IN ('scheduled', 'manual', 'upload', 'email', 'probe')),
    started_at     timestamptz NOT NULL DEFAULT now(),
    finished_at    timestamptz,
    status         text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'ok', 'partial', 'failed')),
    file_name      text,
    attachment_id  bigint,
    dates_seen     date[] NOT NULL DEFAULT '{}',
    sales_seen     integer NOT NULL DEFAULT 0,
    sales_new      integer NOT NULL DEFAULT 0,
    lines_seen     integer NOT NULL DEFAULT 0,
    plus_new       integer NOT NULL DEFAULT 0,
    error          text CHECK (error IS NULL OR length(error) <= 200),
    started_by     bigint REFERENCES members(id) ON DELETE SET NULL
);
CREATE INDEX source_pulls_source_idx ON source_pulls (source_id, started_at DESC);
ALTER TABLE sources ADD CONSTRAINT sources_last_pull_fk FOREIGN KEY (last_pull_id) REFERENCES source_pulls(id) ON DELETE SET NULL;

-- The column mappings of the known exports (seeded in Phase 1 from the fixtures; the generic shape here).
CREATE TABLE sales_file_mappings (
    key         text PRIMARY KEY CHECK (key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    mapping     jsonb NOT NULL,                                                  -- {columns: {external_id, business_date, closed_at, plu, item_name, qty, unit_price, line_total, voided, comp, modifiers, covers}, date_format, delimiter, ...}
    is_builtin  boolean NOT NULL DEFAULT true,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO sales_file_mappings (key, name, mapping) VALUES
    ('generic', 'Generic (one row per line)', '{"delimiter": ",", "columns": {"external_id": "check_id", "business_date": "date", "closed_at": "closed_at", "plu": "plu", "item_name": "item", "qty": "qty", "unit_price": "price", "line_total": "total", "voided": "voided", "comp": "comp", "modifiers": "modifiers", "covers": "covers"}}');

-- ---------------------------------------------------------------------------------------------
-- The checks and their lines.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE pos_sales (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id       bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    source_id      bigint NOT NULL REFERENCES sources(id) ON DELETE RESTRICT,
    external_id    text NOT NULL,
    business_date  date NOT NULL,
    day_part_id    bigint REFERENCES day_parts(id) ON DELETE SET NULL,
    closed_at      timestamptz,
    covers         integer CHECK (covers IS NULL OR covers >= 0),
    subtotal       numeric(12,2),
    tax            numeric(12,2),
    total          numeric(12,2),
    comps          numeric(12,2) NOT NULL DEFAULT 0,
    voids          numeric(12,2) NOT NULL DEFAULT 0,
    raw            jsonb,
    pull_id        bigint REFERENCES source_pulls(id) ON DELETE SET NULL,
    imported_at    timestamptz NOT NULL DEFAULT now(),
    UNIQUE (source_id, external_id)
);
CREATE INDEX pos_sales_site_date_idx ON pos_sales (scope_id, business_date);

CREATE TABLE pos_sale_lines (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sale_id           bigint NOT NULL REFERENCES pos_sales(id) ON DELETE CASCADE,
    external_line_id  text,
    plu               text NOT NULL,
    menu_item_id      bigint REFERENCES menu_items(id) ON DELETE SET NULL,
    name              text,
    qty               numeric(10,4) NOT NULL CHECK (qty > 0),
    unit_price        numeric(12,4),
    line_total        numeric(12,2),
    voided            boolean NOT NULL DEFAULT false,
    comp              boolean NOT NULL DEFAULT false,
    modifiers         jsonb NOT NULL DEFAULT '[]'::jsonb,                        -- [{code, name, qty}]
    created_at        timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX pos_sale_lines_sale_idx ON pos_sale_lines (sale_id);
CREATE INDEX pos_sale_lines_item_idx ON pos_sale_lines (menu_item_id);

-- Resolve a line's PLU to a menu item (creating the unmapped row the first time it is seen); the day part from the close time.
CREATE OR REPLACE FUNCTION ri_pos_line_resolve() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE s pos_sales%ROWTYPE; mid bigint; m jsonb;
BEGIN
    SELECT * INTO s FROM pos_sales WHERE id = NEW.sale_id;
    IF NEW.menu_item_id IS NULL THEN
        SELECT id INTO mid FROM menu_items WHERE pos_source_id = s.source_id AND plu = NEW.plu;
        IF mid IS NULL THEN
            INSERT INTO menu_items (pos_source_id, plu, name, sale_price) VALUES (s.source_id, NEW.plu, COALESCE(NEW.name, NEW.plu), NEW.unit_price) RETURNING id INTO mid;
        END IF;
        NEW.menu_item_id := mid;
    END IF;
    UPDATE menu_items SET last_sold_at = greatest(COALESCE(last_sold_at, '-infinity'), COALESCE(s.closed_at, s.business_date::timestamptz)) WHERE id = NEW.menu_item_id;
    FOR m IN SELECT * FROM jsonb_array_elements(NEW.modifiers) LOOP
        INSERT INTO modifiers (pos_source_id, code, name) VALUES (s.source_id, m->>'code', COALESCE(m->>'name', m->>'code')) ON CONFLICT DO NOTHING;
    END LOOP;
    RETURN NEW;
END$$;
CREATE TRIGGER pos_sale_lines_resolve BEFORE INSERT ON pos_sale_lines FOR EACH ROW EXECUTE FUNCTION ri_pos_line_resolve();
CREATE OR REPLACE FUNCTION ri_pos_sales_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE tz text; t time;
BEGIN
    IF NEW.day_part_id IS NULL AND NEW.closed_at IS NOT NULL THEN
        SELECT timezone INTO tz FROM sites WHERE scope_id = NEW.scope_id;
        t := (NEW.closed_at AT TIME ZONE COALESCE(tz, 'UTC'))::time;
        SELECT id INTO NEW.day_part_id FROM day_parts WHERE scope_id = NEW.scope_id AND ((starts_at <= ends_at AND t >= starts_at AND t < ends_at) OR (starts_at > ends_at AND (t >= starts_at OR t < ends_at))) ORDER BY starts_at LIMIT 1;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER pos_sales_before BEFORE INSERT OR UPDATE ON pos_sales FOR EACH ROW EXECUTE FUNCTION ri_pos_sales_before();

-- Daily sales by item and day part — the forecaster's input, maintained by the depletion run.
CREATE TABLE sales_daily (
    scope_id       bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    menu_item_id   bigint NOT NULL REFERENCES menu_items(id) ON DELETE CASCADE,
    business_date  date NOT NULL,
    day_part_id    bigint,                                                      -- NULL = the whole day (one row per item per date per day part, plus the day)
    day_part_key   bigint GENERATED ALWAYS AS (COALESCE(day_part_id, 0)) STORED,
    qty            numeric(12,4) NOT NULL DEFAULT 0,
    net            numeric(12,2) NOT NULL DEFAULT 0,
    comps_qty      numeric(12,4) NOT NULL DEFAULT 0,
    updated_at     timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (scope_id, menu_item_id, business_date, day_part_key)
);
CREATE INDEX sales_daily_item_idx ON sales_daily (scope_id, menu_item_id, business_date DESC) WHERE day_part_id IS NULL;

-- ---------------------------------------------------------------------------------------------
-- Depletion runs.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE depletion_runs (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id         bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    business_date    date NOT NULL,
    status           text NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'done', 'partial', 'void')),
    lines_total      integer NOT NULL DEFAULT 0,
    lines_depleted   integer NOT NULL DEFAULT 0,
    lines_unmapped   integer NOT NULL DEFAULT 0,
    lines_voided     integer NOT NULL DEFAULT 0,
    modifiers_unmapped integer NOT NULL DEFAULT 0,
    ingredients      integer NOT NULL DEFAULT 0,
    value            numeric(14,2) NOT NULL DEFAULT 0,
    group_id         uuid,
    run_no           integer NOT NULL DEFAULT 1,
    started_at       timestamptz NOT NULL DEFAULT now(),
    finished_at      timestamptz,
    started_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    note             text
);
CREATE UNIQUE INDEX depletion_runs_live ON depletion_runs (scope_id, business_date) WHERE status <> 'void';
CREATE INDEX depletion_runs_date_idx ON depletion_runs (scope_id, business_date DESC);

-- The explosion of a day's sales at a site into base quantities per ingredient, through the versions in force on the date.
-- A line: qty × menu item's portions × the recipe's per-portion explosion; a modifier: its action's recipe × qty_factor
-- (remove = negative). Comps count, voids do not.
CREATE OR REPLACE FUNCTION ri_day_depletion(p_scope_id bigint, p_date date)
    RETURNS TABLE (ingredient_id bigint, qty_base numeric, lines integer)
    LANGUAGE sql STABLE AS $$
    WITH lines AS (
        SELECT l.id, l.qty * m.portions AS portions, m.recipe_id, ri_recipe_version_on(m.recipe_id, p_date) AS vid, l.modifiers, l.menu_item_id
          FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id JOIN menu_items m ON m.id = l.menu_item_id
         WHERE s.scope_id = p_scope_id AND s.business_date = p_date AND NOT l.voided AND m.recipe_id IS NOT NULL),
    base AS (
        SELECT e.ingredient_id, e.qty_base * ln.portions / v.batch_yield_qty AS q, ln.id AS line_id
          FROM lines ln JOIN recipe_versions v ON v.id = ln.vid, LATERAL ri_explode(ln.vid, p_date, false) e),
    mods AS (
        SELECT e.ingredient_id,
               e.qty_base * (mm->>'qty')::numeric * COALESCE(mim.qty_factor, md.qty_factor) / mv.batch_yield_qty * CASE md.action WHEN 'remove' THEN -1 ELSE 1 END AS q, ln.id AS line_id
          FROM lines ln JOIN menu_items mi ON mi.id = ln.menu_item_id
          CROSS JOIN LATERAL jsonb_array_elements(ln.modifiers) mm
          JOIN modifiers md ON md.pos_source_id = mi.pos_source_id AND md.code = mm->>'code' AND md.action IN ('add', 'remove', 'swap')
          LEFT JOIN menu_item_modifiers mim ON mim.menu_item_id = ln.menu_item_id AND mim.modifier_id = md.id
          JOIN recipe_versions mv ON mv.id = ri_recipe_version_on(COALESCE(mim.recipe_id, md.recipe_id), p_date), LATERAL ri_explode(mv.id, p_date, false) e)
    SELECT x.ingredient_id, sum(x.q), count(DISTINCT x.line_id)::integer FROM (SELECT * FROM base UNION ALL SELECT * FROM mods) x GROUP BY x.ingredient_id HAVING sum(x.q) <> 0
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
    SELECT count(*), count(*) FILTER (WHERE m.recipe_id IS NULL AND NOT l.voided), count(*) FILTER (WHERE l.voided) INTO n_total, n_unmapped, n_void
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

CREATE OR REPLACE FUNCTION ri_depletion_void(p_run_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS depletion_runs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r depletion_runs%ROWTYPE; t record;
BEGIN
    SELECT * INTO r FROM depletion_runs WHERE id = p_run_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such run' USING ERRCODE = 'no_data_found'; END IF;
    IF r.status = 'void' THEN RAISE EXCEPTION 'Already void' USING ERRCODE = 'check_violation'; END IF;
    FOR t IN SELECT id FROM inventory_transactions WHERE group_id = r.group_id AND txn_type IN ('depletion', 'return') LOOP PERFORM ri_reverse_transaction(t.id, p_by, p_note); END LOOP;
    UPDATE depletion_runs SET status = 'void', note = p_note WHERE id = p_run_id RETURNING * INTO r;
    RETURN r;
END$$;

-- Days with sales but no live run (the worker's queue).
CREATE OR REPLACE FUNCTION ri_depletion_due() RETURNS TABLE (scope_id bigint, business_date date)
    LANGUAGE sql STABLE AS $$
    SELECT DISTINCT s.scope_id, s.business_date FROM pos_sales s
     WHERE NOT EXISTS (SELECT 1 FROM depletion_runs r WHERE r.scope_id = s.scope_id AND r.business_date = s.business_date AND r.status <> 'void' AND r.started_at >= s.imported_at)
     ORDER BY 1, 2
$$;

COMMIT;
