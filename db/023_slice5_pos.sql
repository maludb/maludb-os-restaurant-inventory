-- 023: SLICE 5 (the POS) — three things the slice found (docs/build-specs/pos.md "Built and proven"), additive.
--
--  1. ri_depletion_run() rebuilt sales_daily with one row per item per day part AND one per item for the whole day (day_part_id NULL) —
--     but a check closed outside every day part (03:30, before the sales cut) has day_part_id NULL too, so the first branch made a
--     second NULL row for the same item and the primary key (day_part_key = COALESCE(day_part_id, 0)) refused the run. The per-day-part
--     branch now takes only checks that HAVE a day part; the whole-day row still counts every check (pos.md: "the day's row still counts it").
--     And a run's number counts every run of the day: db/011 took the live run's number + 1, so a day run, voided and run again was
--     "run 1" twice; now it is run 3 (the void's number is never reused).
--  2. The mapping templates' vocabulary for voids and comps (pos-and-depletion.md §2.2): each builtin row gains `void_values`,
--     `comp_values`, `comp_rule` and `modifier_split`, so a vendor's own words ("Refund", "Y", a full discount) read as a void or a comp
--     without code. Merged into the jsonb (`||`), never replacing a column a person may have copied. Square's template also loses its
--     `unit_price` column: db/018 named "Price Point Name", which in Square's Item Sales export is the variation's NAME, not a price — the
--     price is derived from Gross Sales ÷ Qty.
BEGIN;

-- 1: the daily sales rebuilt without a second whole-day row
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
    VALUES (p_scope_id, p_date, g, COALESCE((SELECT max(x.run_no) FROM depletion_runs x WHERE x.scope_id = p_scope_id AND x.business_date = p_date), 0) + 1,
            p_by, n_total, n_unmapped, n_void, n_mod) RETURNING * INTO r;
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
      FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id WHERE s.scope_id = p_scope_id AND s.business_date = p_date AND NOT l.voided AND s.day_part_id IS NOT NULL GROUP BY 1, 2, 3, 4
    UNION ALL
    SELECT s.scope_id, l.menu_item_id, s.business_date, NULL, sum(l.qty), COALESCE(sum(COALESCE(l.line_total, 0)) FILTER (WHERE NOT l.comp), 0), COALESCE(sum(l.qty) FILTER (WHERE l.comp), 0)
      FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id WHERE s.scope_id = p_scope_id AND s.business_date = p_date AND NOT l.voided GROUP BY 1, 2, 3;
    UPDATE depletion_runs SET status = CASE WHEN n_unmapped > 0 OR n_mod > 0 THEN 'partial' ELSE 'done' END, lines_depleted = n_total - n_unmapped - n_void, ingredients = n, value = round(tot, 2), finished_at = now()
     WHERE id = r.id RETURNING * INTO r;
    RETURN r;
END$$;


-- 2: the builtin mappings' void/comp vocabulary (merged; the column names stay as seeded — to be confirmed against real exports in Phase 5)
UPDATE sales_file_mappings SET mapping = mapping || '{"void_values": ["yes", "true", "1", "y", "void", "voided"], "comp_values": ["yes", "true", "1", "y", "comp", "comped"], "modifier_split": ";"}'::jsonb, updated_at = now() WHERE key = 'generic';
UPDATE sales_file_mappings SET mapping = jsonb_set(mapping || '{"void_values": ["refund"], "comp_rule": "full_discount", "modifier_split": ","}'::jsonb, '{columns,unit_price}', 'null'::jsonb), updated_at = now() WHERE key = 'square_items';   -- db/018 named "Price Point Name" (Square's variation NAME) as the price: the price is Gross Sales ÷ Qty
UPDATE sales_file_mappings SET mapping = mapping || '{"void_values": ["yes", "true", "y", "void"], "comp_values": ["yes", "true", "y", "comp"], "modifier_split": ","}'::jsonb, updated_at = now() WHERE key = 'toast_items';
UPDATE sales_file_mappings SET mapping = mapping || '{"void_values": ["yes", "true", "y", "refunded"], "comp_rule": "full_discount", "modifier_split": ","}'::jsonb, updated_at = now() WHERE key = 'clover_items';
UPDATE sales_file_mappings SET mapping = mapping || '{"void_values": ["yes", "true", "y", "1"], "comp_rule": "full_discount", "modifier_split": ";"}'::jsonb, updated_at = now() WHERE key = 'lightspeed_items';

-- 3: ri_unmapped_plus() (db/016) counted every sale a PLU ever had: its window (`business_date >= current_date - p_days`) sat on a LEFT JOIN
--    to pos_sales that never limited the lines. The lines are now joined to their checks inside the window, so "sold" and "net" cover the
--    last p_days days, and a voided line still counts nothing. Same signature, same order (by what sold).
CREATE OR REPLACE FUNCTION ri_unmapped_plus(p_scope_id bigint, p_days integer DEFAULT 28)
    RETURNS TABLE (menu_item_id bigint, plu text, name text, qty_sold numeric, net numeric, last_sold_at timestamptz)
    LANGUAGE sql STABLE AS $$
    SELECT m.id, m.plu, m.name, COALESCE(sum(w.qty), 0), COALESCE(sum(w.line_total), 0), m.last_sold_at
      FROM menu_items m JOIN sources s ON s.id = m.pos_source_id
      LEFT JOIN (SELECT l.menu_item_id, l.qty, l.line_total FROM pos_sale_lines l JOIN pos_sales ps ON ps.id = l.sale_id
                  WHERE NOT l.voided AND ps.business_date >= current_date - p_days) w ON w.menu_item_id = m.id
     WHERE s.scope_id = p_scope_id AND m.recipe_id IS NULL AND m.active GROUP BY m.id ORDER BY 4 DESC, m.plu
$$;

COMMIT;
