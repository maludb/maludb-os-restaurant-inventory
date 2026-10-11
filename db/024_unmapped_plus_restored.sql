-- 024: ri_unmapped_plus() RESTORED (2026-10-11, found by the planning model's verification of slices 3 and 5). db/023 rewrote it with
-- CREATE OR REPLACE to fix its 28-day window (it had counted every sale ever made) and, in doing so, dropped two things db/018 had given it:
--   1. the filter `AND NOT m.no_depletion` — a no-depletion PLU (a service charge, a gift card) came back into the unmapped queue;
--   2. SECURITY DEFINER + search_path — CREATE OR REPLACE resets a function to SECURITY INVOKER, so the records role (which sees only the
--      mcp_* views) could no longer call it.
-- This keeps db/023's window and restores both. The schema proof now checks that every read function is SECURITY DEFINER and callable by
-- the records role, so a later rewrite cannot undo db/018 silently. Additive: a function body replaced, nothing dropped.
BEGIN;

CREATE OR REPLACE FUNCTION ri_unmapped_plus(p_scope_id bigint, p_days integer DEFAULT 28)
    RETURNS TABLE (menu_item_id bigint, plu text, name text, qty_sold numeric, net numeric, last_sold_at timestamptz)
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT m.id, m.plu, m.name, COALESCE(sum(w.qty), 0), COALESCE(sum(w.line_total), 0), m.last_sold_at
      FROM menu_items m JOIN sources s ON s.id = m.pos_source_id
      LEFT JOIN (SELECT l.menu_item_id, l.qty, l.line_total FROM pos_sale_lines l JOIN pos_sales ps ON ps.id = l.sale_id
                  WHERE NOT l.voided AND ps.business_date >= current_date - p_days) w ON w.menu_item_id = m.id
     WHERE s.scope_id = p_scope_id AND m.recipe_id IS NULL AND NOT m.no_depletion AND m.active GROUP BY m.id ORDER BY 4 DESC, m.plu
$$;
REVOKE ALL ON FUNCTION ri_unmapped_plus(bigint, integer) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ri_unmapped_plus(bigint, integer) TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

COMMIT;
