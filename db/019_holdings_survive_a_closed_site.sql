-- 019 — a holding survives a closed site (Phase 2, 2026-10-11).
-- db/005's ri_site_materialise() DELETED every member_site_roles row at a scope the kernel closed. Reach was already
-- withheld by ri_held_scope_ids() (it joins sites WHERE removed_at IS NULL), so the delete bought nothing — and when the
-- kernel reopened the scope the people who held it were gone until the feed happened to resend their access rows
-- (found by tests/phase2/sites.php: Marco lost Airport across a close and a reopen). The holdings stay; the site's
-- closure alone decides who reaches it. Same signature, same return.
CREATE OR REPLACE FUNCTION ri_site_materialise(p_scope bigint, p_location bigint, p_name text, p_address text,
                                               p_timezone text, p_removed_at timestamptz) RETURNS boolean
    LANGUAGE plpgsql AS $$
DECLARE
    v_new boolean;
    v_tz  text := CASE WHEN p_timezone IS NOT NULL AND EXISTS (SELECT 1 FROM pg_timezone_names WHERE name = p_timezone)
                       THEN p_timezone ELSE 'UTC' END;
BEGIN
    v_new := NOT EXISTS (SELECT 1 FROM sites WHERE scope_id = p_scope);
    INSERT INTO sites (scope_id, location_id, name, address, timezone, removed_at, synced_at)
    VALUES (p_scope, p_location, p_name, p_address, v_tz, p_removed_at, now())
    ON CONFLICT (scope_id) DO UPDATE SET location_id = EXCLUDED.location_id, name = EXCLUDED.name, address = EXCLUDED.address,
        timezone = EXCLUDED.timezone, removed_at = EXCLUDED.removed_at, synced_at = now();
    -- closed: nobody reaches it (ri_held_scope_ids), its history AND its holdings stay for the day it reopens
    IF v_new THEN
        INSERT INTO site_settings (scope_id) VALUES (p_scope) ON CONFLICT DO NOTHING;
        INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at, sort_order, service_name) VALUES
            (p_scope, 'lunch', 'Lunch', '11:00', '15:00', 1, 'Lunch'),
            (p_scope, 'dinner', 'Dinner', '17:00', '22:00', 2, 'Dinner');
    END IF;
    RETURN v_new;
END$$;
