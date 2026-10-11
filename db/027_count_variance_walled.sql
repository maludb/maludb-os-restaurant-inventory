-- 027: ri_count_variance() WALLED (2026-10-11, found by slice 6's worker; fixed by the planning model in review). db/018 made the read
-- functions SECURITY DEFINER so the records role can call them, with the WALL left to the caller (the tool surface's "fn" wall). For this
-- function that is not enough: it returns a blind count's book figure while the count is open, and the variance at cost — the screens read the
-- lines view (where the blind rule and the cost wall live), but Phase 4's `count_variance` tool and ri_morning_note() call the function. It now
-- walls itself, the way mcp_inventory_count_lines does:
--   * a member must hold the count's site (ri_require_reader);
--   * on a BLIND count still OPEN, the book figure and the variance are withheld unless the member holds counts.approve there;
--   * the variance at cost is withheld unless the member sees cost there;
--   * with NO member set: the application's own role (the worker, the shares' system calls) sees everything; the two read roles are refused.
-- Additive: a function body replaced (SECURITY DEFINER and the grants restated, as db/024 taught).
BEGIN;

CREATE OR REPLACE FUNCTION ri_count_variance(p_count_id bigint)
    RETURNS TABLE (line_id bigint, storage_area_id bigint, ingredient_id bigint, system_qty_base numeric, counted_qty_base numeric, variance_base numeric, variance_value numeric, over_tolerance boolean, state text)
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE c inventory_counts%ROWTYPE; me bigint := app_current_member_id(); see_book boolean := true; see_cost boolean := true;
BEGIN
    SELECT * INTO c FROM inventory_counts WHERE id = p_count_id;
    IF NOT FOUND THEN RETURN; END IF;
    IF me IS NULL THEN
        IF session_user IN ('restaurant_inventory_records_ro', 'restaurant_inventory_activity_ro')
           OR current_setting('role', true) IN ('restaurant_inventory_records_ro', 'restaurant_inventory_activity_ro') THEN   -- logged in as a read role, or SET ROLE to one
            RAISE EXCEPTION 'Not a site you hold' USING ERRCODE = 'insufficient_privilege';
        END IF;                                                       -- the application's own role (the worker): no wall
    ELSE
        PERFORM ri_require_reader(c.scope_id);
        see_book := NOT c.blind OR c.status <> 'open' OR ri_has_right('counts.approve', c.scope_id);
        see_cost := ri_sees_cost(c.scope_id);
    END IF;
    RETURN QUERY
    SELECT l.id, l.storage_area_id, l.ingredient_id,
           CASE WHEN see_book THEN l.system_qty_base END,
           COALESCE(l.recount_qty_base, l.counted_qty_base),
           CASE WHEN see_book THEN l.variance_base END,
           CASE WHEN see_book AND see_cost THEN l.variance_value END,
           l.over_tolerance, l.state
      FROM inventory_count_lines l WHERE l.count_id = p_count_id ORDER BY abs(COALESCE(l.variance_value, 0)) DESC;
END$$;
REVOKE ALL ON FUNCTION ri_count_variance(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ri_count_variance(bigint) TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

COMMIT;
