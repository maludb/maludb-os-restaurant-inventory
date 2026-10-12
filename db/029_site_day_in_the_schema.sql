-- 029: THE SITE'S DAY IN THE SCHEMA (2026-10-12, found by the planning model when slice 2's proof failed between 00:00 and 04:00 UTC after the
-- application's clock was made to follow the site — app/db.php db_use_site_zone()). Five places turned a moment into a day with UTC:
--   ri_recipe_cost()          the version of a sub-recipe in force "on" the costing moment's day;
--   ri_portion_cost_cached()  the end of the day a cached plate cost is computed for;
--   ri_price_history_after()  the first cached day a price change empties — the app caches by the site's day, so from 20:00 in New York the
--                             trigger started a day late and left today's stale plate cost in place;
--   ri_post_production()      (twice) the prep recipe's version in force when the batch was made.
-- All five use the site's zone now, through two helpers. Additive: two functions added, four bodies replaced (SECURITY DEFINER restated for
-- the two read functions, as db/024 taught; the schema proof guards it).
BEGIN;

-- the site's calendar day of a moment (UTC when the site is unknown)
CREATE OR REPLACE FUNCTION ri_site_date(p_scope_id bigint, p_at timestamptz) RETURNS date
    LANGUAGE sql STABLE AS $$
    SELECT (p_at AT TIME ZONE COALESCE((SELECT timezone FROM sites WHERE scope_id = p_scope_id), 'UTC'))::date
$$;
-- the last second of a site's calendar day
CREATE OR REPLACE FUNCTION ri_site_day_end(p_scope_id bigint, p_day date) RETURNS timestamptz
    LANGUAGE sql STABLE AS $$
    SELECT ((p_day + 1)::timestamp AT TIME ZONE COALESCE((SELECT timezone FROM sites WHERE scope_id = p_scope_id), 'UTC')) - interval '1 second'
$$;
GRANT EXECUTE ON FUNCTION ri_site_date(bigint, timestamptz), ri_site_day_end(bigint, date) TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

CREATE OR REPLACE FUNCTION ri_recipe_cost(p_version_id bigint, p_scope_id bigint, p_as_of timestamptz DEFAULT now(), p_basis text DEFAULT NULL)
    RETURNS TABLE (batch_cost numeric, portion_cost numeric, batch_yield_qty numeric, lines jsonb, uncosted integer)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
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
            sub_v := ri_recipe_version_on(l.sub_recipe_id, ri_site_date(p_scope_id, p_as_of));
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
REVOKE ALL ON FUNCTION ri_recipe_cost(bigint, bigint, timestamptz, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ri_recipe_cost(bigint, bigint, timestamptz, text) TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

CREATE OR REPLACE FUNCTION ri_portion_cost_cached(p_version_id bigint, p_scope_id bigint, p_on date, p_basis text DEFAULT NULL) RETURNS numeric
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b text := COALESCE(p_basis, (SELECT cost_basis FROM ri_settings WHERE id = 1), 'avg'); c numeric; rr record;
BEGIN
    SELECT portion_cost INTO c FROM recipe_cost_cache WHERE version_id = p_version_id AND scope_id = p_scope_id AND as_of_date = p_on AND basis = b;
    IF FOUND THEN RETURN c; END IF;
    SELECT * INTO rr FROM ri_recipe_cost(p_version_id, p_scope_id, ri_site_day_end(p_scope_id, p_on), b);
    INSERT INTO recipe_cost_cache (version_id, scope_id, as_of_date, basis, batch_cost, portion_cost, uncosted)
    VALUES (p_version_id, p_scope_id, p_on, b, rr.batch_cost, rr.portion_cost, rr.uncosted) ON CONFLICT DO NOTHING;
    RETURN rr.portion_cost;
END$$;
REVOKE ALL ON FUNCTION ri_portion_cost_cached(bigint, bigint, date, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ri_portion_cost_cached(bigint, bigint, date, text) TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

CREATE OR REPLACE FUNCTION ri_price_history_after() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    -- the cache is keyed by the SITE's day (the app's and ri_portion_cost_cached's), so empty from the change's day there
    DELETE FROM recipe_cost_cache c USING sites s
     WHERE s.scope_id = c.scope_id AND c.as_of_date >= ri_site_date(c.scope_id, NEW.changed_at) AND (NEW.scope_id IS NULL OR c.scope_id = NEW.scope_id);
    RETURN NEW;
END$$;

CREATE OR REPLACE FUNCTION ri_post_production(p_batch_id bigint, p_by bigint) RETURNS production_batches
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b production_batches%ROWTYPE; r recipes%ROWTYPE; v recipe_versions%ROWTYPE; e record; g uuid := gen_random_uuid(); c numeric; tot numeric := 0; outq numeric; area bigint;
BEGIN
    SELECT * INTO b FROM production_batches WHERE id = p_batch_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such batch' USING ERRCODE = 'no_data_found'; END IF;
    IF b.status <> 'draft' THEN RAISE EXCEPTION 'Batch % is % — only a draft posts', b.number, b.status USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO r FROM recipes WHERE id = b.recipe_id;
    IF r.kind <> 'prep' THEN RAISE EXCEPTION 'Only a prep recipe is produced into stock' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO v FROM recipe_versions WHERE id = COALESCE(b.recipe_version_id, ri_recipe_version_on(b.recipe_id, ri_site_date(b.scope_id, b.made_at)));
    IF v.id IS NULL THEN RAISE EXCEPTION 'No version of % is in force', r.name USING ERRCODE = 'check_violation'; END IF;
    FOR e IN SELECT * FROM ri_explode(v.id, ri_site_date(b.scope_id, b.made_at), false) LOOP
        c := ri_cost_as_of(e.ingredient_id, b.scope_id);
        area := COALESCE(b.consume_area_id, ri_depletion_area(e.ingredient_id, b.scope_id));
        PERFORM ri_post_txn('production_out', b.scope_id, e.ingredient_id, area, -(e.qty_base * b.batches), c, 'production', p_batch_id, 'prod_out:' || p_batch_id || ':' || e.ingredient_id,
                            'none', NULL, NULL, g, p_by, NULL, b.made_at);
        tot := tot + e.qty_base * b.batches * c;
    END LOOP;
    outq := COALESCE(b.output_qty_base, ri_to_base(r.output_ingredient_id, v.batch_yield_qty * b.batches * (1 - v.expected_loss_pct / 100.0), v.batch_yield_unit_code));
    c := CASE WHEN outq > 0 THEN round(tot / outq, 6) ELSE 0 END;
    PERFORM ri_post_txn('production_in', b.scope_id, r.output_ingredient_id, b.storage_area_id, outq, c, 'production', p_batch_id, 'prod_in:' || p_batch_id, 'none', NULL, NULL, g, p_by, NULL, b.made_at);
    INSERT INTO price_history (ingredient_id, scope_id, new_per_base, source_kind, reference_kind, reference_id, changed_by, reason)
    VALUES (r.output_ingredient_id, b.scope_id, c, 'production', 'production', p_batch_id, p_by, 'batch ' || b.number);
    UPDATE production_batches SET status = 'posted', recipe_version_id = v.id, output_qty_base = outq, cost_total = round(tot, 4), posted_by = p_by, posted_at = now() WHERE id = p_batch_id RETURNING * INTO b;
    RETURN b;
END$$;

COMMIT;
