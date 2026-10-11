-- 012: COUNT SHEETS by storage area in shelf order, COUNTS with their lines in count units and partials, bottle and keg
-- CONTAINERS for the bar's count by weight, review with tolerances, recount, APPROVAL posting the corrections (design §6.6).
--
-- THE ESTATE'S DATA MODEL (design §6.9): `inventory_counts(+lines)` REUSE Inventory's canonical count with the measured
-- deviation: `counted_unit_code`, `entries` (a case + 3 loose), `gross_weight_g`, `shelf_order`, the state, the recount;
-- `count_sheets(+lines)` and `containers` are new.
--
-- THE REFEREE'S RULES: a count is AS OF a moment (the sales cut — the book quantity is the balance as of then, frozen on
-- the line); a skipped area is listed, never counted as zero; a blind count hides the book figure until review; a line over
-- tolerance asks for a recount by another person; APPROVAL is a Chef's (pauses for an agent) and posts count_correction
-- rows dated at the count's moment; a count may be discarded, never edited after approval.
BEGIN;

CREATE TABLE containers (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    ingredient_id   bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    name            text NOT NULL,                                               -- "750 ml bottle", "1/6 bbl keg"
    full_weight_g   numeric(12,2) NOT NULL CHECK (full_weight_g > 0),
    empty_weight_g  numeric(12,2) NOT NULL CHECK (empty_weight_g >= 0 AND empty_weight_g < full_weight_g),
    pourer_tare_g   numeric(12,2) NOT NULL DEFAULT 0,
    volume_ml       numeric(12,2) NOT NULL CHECK (volume_ml > 0),
    is_default      boolean NOT NULL DEFAULT true,
    active          boolean NOT NULL DEFAULT true,
    UNIQUE (ingredient_id, name)
);
CREATE UNIQUE INDEX containers_default ON containers (ingredient_id) WHERE is_default AND active;

-- fraction = (gross − empty − pourer) ÷ (full − empty); remaining = fraction × volume → the ingredient's base unit
CREATE OR REPLACE FUNCTION ri_container_remaining_base(p_container_id bigint, p_gross_g numeric, p_with_pourer boolean DEFAULT false) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT round(ri_to_base(c.ingredient_id, greatest(least((p_gross_g - c.empty_weight_g - CASE WHEN p_with_pourer THEN c.pourer_tare_g ELSE 0 END) / (c.full_weight_g - c.empty_weight_g), 1), 0) * c.volume_ml, 'ml'), 4)
      FROM containers c WHERE c.id = p_container_id
$$;

CREATE TABLE count_sheets (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    name        text NOT NULL,
    kind        text NOT NULL DEFAULT 'full' CHECK (kind IN ('full', 'cycle', 'spot')),
    active      boolean NOT NULL DEFAULT true,
    created_by  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at  timestamptz NOT NULL DEFAULT now(),
    updated_at  timestamptz NOT NULL DEFAULT now(),
    UNIQUE (scope_id, name)
);
CREATE TRIGGER count_sheets_touch BEFORE UPDATE ON count_sheets FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TABLE count_sheet_lines (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    sheet_id         bigint NOT NULL REFERENCES count_sheets(id) ON DELETE CASCADE,
    storage_area_id  bigint NOT NULL REFERENCES storage_areas(id) ON DELETE CASCADE,
    ingredient_id    bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    shelf_order      integer NOT NULL DEFAULT 0,
    count_unit_code  text,                                                       -- NULL = the ingredient's default count unit, else its base
    UNIQUE (sheet_id, storage_area_id, ingredient_id)
);
CREATE INDEX count_sheet_lines_sheet_idx ON count_sheet_lines (sheet_id, storage_area_id, shelf_order);

-- The whole site in walk order: every area, every ingredient with a balance or a sheet line there.
CREATE OR REPLACE FUNCTION ri_count_sheet_fill(p_sheet_id bigint) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE sh count_sheets%ROWTYPE; n integer;
BEGIN
    SELECT * INTO sh FROM count_sheets WHERE id = p_sheet_id;
    INSERT INTO count_sheet_lines (sheet_id, storage_area_id, ingredient_id, shelf_order, count_unit_code)
    SELECT p_sheet_id, b.storage_area_id, b.ingredient_id, row_number() OVER (PARTITION BY b.storage_area_id ORDER BY i.name), (SELECT unit_code FROM ingredient_units u WHERE u.ingredient_id = i.id AND u.is_default_count)
      FROM inventory_balances b JOIN storage_areas a ON a.id = b.storage_area_id JOIN ingredients i ON i.id = b.ingredient_id
     WHERE a.scope_id = sh.scope_id AND a.active AND i.active
    ON CONFLICT DO NOTHING;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

CREATE TABLE inventory_counts (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number        text NOT NULL UNIQUE,
    scope_id      bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    sheet_id      bigint REFERENCES count_sheets(id) ON DELETE SET NULL,
    kind          text NOT NULL DEFAULT 'full' CHECK (kind IN ('full', 'cycle', 'spot')),
    as_of         timestamptz NOT NULL DEFAULT now(),
    status        text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'review', 'recount', 'approved', 'discarded')),
    blind         boolean NOT NULL DEFAULT true,
    notes         text,
    started_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    started_at    timestamptz NOT NULL DEFAULT now(),
    approved_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    approved_at   timestamptz,
    group_id      uuid,
    total_variance_value numeric(14,2),
    created_at    timestamptz NOT NULL DEFAULT now(),
    updated_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX inventory_counts_site_idx ON inventory_counts (scope_id, as_of DESC);
CREATE UNIQUE INDEX inventory_counts_one_open ON inventory_counts (scope_id) WHERE status IN ('open', 'review', 'recount') AND kind = 'full';
CREATE TRIGGER inventory_counts_touch BEFORE UPDATE ON inventory_counts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_counts_number BEFORE INSERT ON inventory_counts FOR EACH ROW EXECUTE FUNCTION ri_number_document('count');

CREATE TABLE inventory_count_lines (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    count_id          bigint NOT NULL REFERENCES inventory_counts(id) ON DELETE CASCADE,
    storage_area_id   bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    ingredient_id     bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    shelf_order       integer NOT NULL DEFAULT 0,
    count_unit_code   text,
    system_qty_base   numeric(18,4) NOT NULL DEFAULT 0,                          -- the book figure as of the count's moment, frozen
    entries           jsonb NOT NULL DEFAULT '[]'::jsonb,                        -- [{unit_code, qty}] — 1 case + 3 cans
    gross_weight_g    numeric(12,2),
    container_id      bigint REFERENCES containers(id) ON DELETE SET NULL,
    counted_qty_base  numeric(18,4),
    state             text NOT NULL DEFAULT 'pending' CHECK (state IN ('pending', 'counted', 'zero', 'skipped')),
    recount_qty_base  numeric(18,4),
    recount_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    counted_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    counted_at        timestamptz,
    variance_base     numeric(18,4),
    variance_value    numeric(14,2),
    over_tolerance    boolean NOT NULL DEFAULT false,
    note              text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (count_id, storage_area_id, ingredient_id)
);
CREATE INDEX inventory_count_lines_count_idx ON inventory_count_lines (count_id, storage_area_id, shelf_order);
CREATE TRIGGER inventory_count_lines_touch BEFORE UPDATE ON inventory_count_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_count_lines_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text;
BEGIN
    IF COALESCE(current_setting('ri.balance_writer', true), '') = 'on' THEN RETURN COALESCE(NEW, OLD); END IF;
    SELECT status INTO st FROM inventory_counts WHERE id = COALESCE(NEW.count_id, OLD.count_id);
    IF st NOT IN ('open', 'review', 'recount') THEN RAISE EXCEPTION 'The count is % — its lines no longer change', st USING ERRCODE = 'check_violation'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_count_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_count_lines FOR EACH ROW EXECUTE FUNCTION ri_count_lines_guard();

-- Start: from a sheet (or the whole site in walk order), the book quantity frozen as of the moment.
CREATE OR REPLACE FUNCTION ri_count_start(p_scope_id bigint, p_by bigint, p_sheet_id bigint DEFAULT NULL, p_as_of timestamptz DEFAULT now(), p_kind text DEFAULT 'full', p_blind boolean DEFAULT NULL) RETURNS inventory_counts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE; bl boolean := COALESCE(p_blind, (SELECT blind_counts FROM ri_settings WHERE id = 1), true);
BEGIN
    INSERT INTO inventory_counts (scope_id, sheet_id, kind, as_of, blind, started_by) VALUES (p_scope_id, p_sheet_id, p_kind, p_as_of, bl, p_by) RETURNING * INTO c;
    IF p_sheet_id IS NOT NULL THEN
        INSERT INTO inventory_count_lines (count_id, storage_area_id, ingredient_id, shelf_order, count_unit_code, system_qty_base)
        SELECT c.id, l.storage_area_id, l.ingredient_id, l.shelf_order, COALESCE(l.count_unit_code, (SELECT unit_code FROM ingredient_units u WHERE u.ingredient_id = l.ingredient_id AND u.is_default_count)),
               COALESCE((SELECT b.qty_base FROM inventory_balances b WHERE b.ingredient_id = l.ingredient_id AND b.storage_area_id = l.storage_area_id), 0)
               - COALESCE((SELECT sum(t.qty_base) FROM inventory_transactions t WHERE t.ingredient_id = l.ingredient_id AND t.storage_area_id = l.storage_area_id AND t.occurred_at > p_as_of), 0)
          FROM count_sheet_lines l JOIN storage_areas a ON a.id = l.storage_area_id WHERE l.sheet_id = p_sheet_id AND a.scope_id = p_scope_id ORDER BY a.walk_order, l.shelf_order;
    ELSE
        INSERT INTO inventory_count_lines (count_id, storage_area_id, ingredient_id, shelf_order, count_unit_code, system_qty_base)
        SELECT c.id, b.storage_area_id, b.ingredient_id, row_number() OVER (PARTITION BY b.storage_area_id ORDER BY i.name), (SELECT unit_code FROM ingredient_units u WHERE u.ingredient_id = i.id AND u.is_default_count),
               b.qty_base - COALESCE((SELECT sum(t.qty_base) FROM inventory_transactions t WHERE t.ingredient_id = b.ingredient_id AND t.storage_area_id = b.storage_area_id AND t.occurred_at > p_as_of), 0)
          FROM inventory_balances b JOIN storage_areas a ON a.id = b.storage_area_id JOIN ingredients i ON i.id = b.ingredient_id WHERE a.scope_id = p_scope_id AND a.active AND i.active;
    END IF;
    RETURN c;
END$$;

-- One line's entry: a list of {unit_code, qty} summed in base, or a gross weight against a container, or zero, or skipped.
CREATE OR REPLACE FUNCTION ri_count_line_set(p_line_id bigint, p_by bigint, p_entries jsonb DEFAULT NULL, p_gross_g numeric DEFAULT NULL, p_container_id bigint DEFAULT NULL, p_state text DEFAULT 'counted', p_note text DEFAULT NULL) RETURNS inventory_count_lines
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE l inventory_count_lines%ROWTYPE; q numeric := 0; e jsonb; c inventory_counts%ROWTYPE;
BEGIN
    SELECT * INTO l FROM inventory_count_lines WHERE id = p_line_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such count line' USING ERRCODE = 'no_data_found'; END IF;
    SELECT * INTO c FROM inventory_counts WHERE id = l.count_id;
    IF p_state = 'skipped' THEN
        UPDATE inventory_count_lines SET state = 'skipped', counted_qty_base = NULL, counted_by = p_by, counted_at = now(), note = p_note WHERE id = p_line_id RETURNING * INTO l; RETURN l;
    ELSIF p_state = 'zero' THEN
        q := 0;
    ELSE
        IF p_gross_g IS NOT NULL THEN
            q := ri_container_remaining_base(COALESCE(p_container_id, l.container_id, (SELECT id FROM containers WHERE ingredient_id = l.ingredient_id AND is_default AND active)), p_gross_g, true);
            IF q IS NULL THEN RAISE EXCEPTION 'No container is known for this ingredient — a weight cannot be read' USING ERRCODE = 'check_violation'; END IF;
        END IF;
        FOR e IN SELECT * FROM jsonb_array_elements(COALESCE(p_entries, '[]'::jsonb)) LOOP
            q := q + ri_to_base(l.ingredient_id, (e->>'qty')::numeric, e->>'unit_code');
        END LOOP;
        IF p_entries IS NULL AND p_gross_g IS NULL THEN RAISE EXCEPTION 'A counted line carries entries or a weight' USING ERRCODE = 'check_violation'; END IF;
    END IF;
    IF c.status = 'recount' AND l.over_tolerance THEN
        IF l.counted_by = p_by THEN RAISE EXCEPTION 'A recount is by another person' USING ERRCODE = 'check_violation'; END IF;
        UPDATE inventory_count_lines SET recount_qty_base = q, recount_by = p_by, note = COALESCE(p_note, note) WHERE id = p_line_id RETURNING * INTO l;
    ELSE
        UPDATE inventory_count_lines SET entries = COALESCE(p_entries, '[]'::jsonb), gross_weight_g = p_gross_g, container_id = COALESCE(p_container_id, container_id), counted_qty_base = q,
               state = CASE WHEN q = 0 THEN 'zero' ELSE 'counted' END, counted_by = p_by, counted_at = now(), note = COALESCE(p_note, note) WHERE id = p_line_id RETURNING * INTO l;
    END IF;
    RETURN l;
END$$;

-- Review: every line's variance against the frozen book figure, tolerance flags; the count goes to review (or recount
-- when any line is over tolerance and the setting asks for one).
CREATE OR REPLACE FUNCTION ri_count_review(p_count_id bigint, p_by bigint) RETURNS inventory_counts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE; n_over integer; n_pending integer;
BEGIN
    SELECT * INTO c FROM inventory_counts WHERE id = p_count_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such count' USING ERRCODE = 'no_data_found'; END IF;
    IF c.status NOT IN ('open', 'review', 'recount') THEN RAISE EXCEPTION 'Count % is %', c.number, c.status USING ERRCODE = 'check_violation'; END IF;
    SELECT count(*) INTO n_pending FROM inventory_count_lines WHERE count_id = p_count_id AND state = 'pending';
    IF n_pending > 0 AND c.status = 'open' THEN RAISE EXCEPTION '% lines are still pending — count them, zero them or skip them', n_pending USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('ri.balance_writer', 'on', true);
    UPDATE inventory_count_lines l SET
        variance_base = CASE WHEN l.state IN ('counted', 'zero') THEN COALESCE(l.recount_qty_base, l.counted_qty_base) - l.system_qty_base END,
        variance_value = CASE WHEN l.state IN ('counted', 'zero') THEN round((COALESCE(l.recount_qty_base, l.counted_qty_base) - l.system_qty_base) * ri_cost_as_of(l.ingredient_id, c.scope_id, c.as_of), 2) END,
        over_tolerance = CASE WHEN l.state IN ('counted', 'zero') AND l.recount_qty_base IS NULL THEN
            (abs(COALESCE(l.counted_qty_base, 0) - l.system_qty_base) > greatest(abs(l.system_qty_base) * COALESCE(i.variance_tolerance_pct, 5) / 100.0, 0)
             AND abs((COALESCE(l.counted_qty_base, 0) - l.system_qty_base) * ri_cost_as_of(l.ingredient_id, c.scope_id, c.as_of)) > COALESCE(i.variance_tolerance_value, 10))
            ELSE false END
      FROM ingredients i WHERE i.id = l.ingredient_id AND l.count_id = p_count_id;
    PERFORM set_config('ri.balance_writer', '', true);
    SELECT count(*) INTO n_over FROM inventory_count_lines WHERE count_id = p_count_id AND over_tolerance AND recount_qty_base IS NULL;
    UPDATE inventory_counts SET status = CASE WHEN n_over > 0 THEN 'recount' ELSE 'review' END, total_variance_value = (SELECT sum(variance_value) FROM inventory_count_lines WHERE count_id = p_count_id)
     WHERE id = p_count_id RETURNING * INTO c;
    RETURN c;
END$$;

-- Approval: the corrections posted at the count's moment; the balance becomes the counted figure (counted − book as of
-- the moment, so a receipt posted during the count is not counted twice). Skipped lines post nothing.
CREATE OR REPLACE FUNCTION ri_count_approve(p_count_id bigint, p_by bigint, p_force boolean DEFAULT false) RETURNS inventory_counts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE; l record; g uuid := gen_random_uuid(); rc bigint; delta numeric; n_over integer;
BEGIN
    SELECT * INTO c FROM inventory_counts WHERE id = p_count_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such count' USING ERRCODE = 'no_data_found'; END IF;
    IF c.status NOT IN ('review', 'recount') THEN RAISE EXCEPTION 'Count % is % — review it first', c.number, c.status USING ERRCODE = 'check_violation'; END IF;
    SELECT count(*) INTO n_over FROM inventory_count_lines WHERE count_id = p_count_id AND over_tolerance AND recount_qty_base IS NULL;
    IF n_over > 0 AND NOT p_force THEN RAISE EXCEPTION '% lines over tolerance await a recount (approve with force to accept them)', n_over USING ERRCODE = 'check_violation'; END IF;
    SELECT id INTO rc FROM reason_codes WHERE code = 'count_correction';
    FOR l IN SELECT * FROM inventory_count_lines WHERE count_id = p_count_id AND state IN ('counted', 'zero') ORDER BY storage_area_id, shelf_order LOOP
        delta := COALESCE(l.recount_qty_base, l.counted_qty_base) - l.system_qty_base;
        IF delta <> 0 THEN
            PERFORM ri_post_txn('count_correction', c.scope_id, l.ingredient_id, l.storage_area_id, delta, NULL, 'count', p_count_id, 'count:' || l.id, 'none', NULL, rc, g, p_by, l.note, c.as_of);
        END IF;
    END LOOP;
    UPDATE inventory_counts SET status = 'approved', approved_by = p_by, approved_at = now(), group_id = g,
           total_variance_value = (SELECT sum(variance_value) FROM inventory_count_lines WHERE count_id = p_count_id) WHERE id = p_count_id RETURNING * INTO c;
    RETURN c;
END$$;

CREATE OR REPLACE FUNCTION ri_count_discard(p_count_id bigint, p_by bigint) RETURNS inventory_counts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE;
BEGIN
    SELECT * INTO c FROM inventory_counts WHERE id = p_count_id FOR UPDATE;
    IF c.status = 'approved' THEN RAISE EXCEPTION 'An approved count is never discarded — reverse its corrections' USING ERRCODE = 'check_violation'; END IF;
    UPDATE inventory_counts SET status = 'discarded' WHERE id = p_count_id RETURNING * INTO c;
    RETURN c;
END$$;

-- The areas a count skipped (every active area with no counted line).
CREATE OR REPLACE FUNCTION ri_count_areas_skipped(p_count_id bigint) RETURNS TABLE (storage_area_id bigint, name text, lines_skipped integer)
    LANGUAGE sql STABLE AS $$
    SELECT a.id, a.name, (SELECT count(*)::integer FROM inventory_count_lines l WHERE l.count_id = p_count_id AND l.storage_area_id = a.id AND l.state = 'skipped')
      FROM inventory_counts c JOIN storage_areas a ON a.scope_id = c.scope_id AND a.active
     WHERE c.id = p_count_id AND NOT EXISTS (SELECT 1 FROM inventory_count_lines l WHERE l.count_id = p_count_id AND l.storage_area_id = a.id AND l.state IN ('counted', 'zero'))
$$;

COMMIT;
