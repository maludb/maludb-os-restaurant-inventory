-- 005: the application's SETTINGS (one row), each SITE's own settings, the day parts, and the KEY rules that read them
-- (design §3, §6, D4, D8, D9, D11, D13).
--
-- THE ESTATE'S DATA MODEL (design §6.9): ri_settings is the siblings' one-row `app_settings` skeleton (Help Desk db/005: id = 1,
-- updated_at) with the kit's generic columns (business name, time zone, the key rates) and THIS application's columns: the cost
-- basis for the recipe card (D6), blind counts (D11), the price alert threshold (D10), the covers factor (D8), the forecast's and
-- the dynamic par's history minimums (D8, D9), the late-receipt flag (D13), the costing calendar's kind (D13), the Manager the
-- morning note goes to (D14 — RI_MANAGER_EMAIL seeds it at install, the first super-admin until then). site_settings and day_parts
-- are txtSchedules db/005's shapes (site_settings' columns ours: kitchen_sees_cost — the Kitchen's cost wall, design §3 —, the
-- depletion area, the Manager per site, the sales cut, the temperature check; day_parts verbatim, service_name mapping
-- Reservations' services for covers_by_service). ri_site_materialise() is txtSchedules db/013's: a kernel scope becomes a site with
-- its settings and two day parts. The key functions are MaluMail db/005's = Knowledge db/011's (ri_resolve_key, ri_rate_ok,
-- ri_key_calls_today, ri_key_rotate, ri_prune_key_usage) — here because they read the settings.
--
-- WHO CHANGES THEM: settings.manage (the admin) on the settings screen, sites.manage on a site's; never an agent (paused, design §5).
BEGIN;

CREATE TABLE ri_settings (
    id                          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    business_name               text,
    timezone                    text NOT NULL DEFAULT 'UTC',
    -- costing (D6, D13)
    cost_basis                  text NOT NULL DEFAULT 'avg' CHECK (cost_basis IN ('avg', 'last')),          -- the recipe card's basis; valuation and variance are always the weighted average
    calendar_kind               text NOT NULL DEFAULT 'week' CHECK (calendar_kind IN ('week', 'month', '4-4-5', '4-5-4', '5-4-4', '13x4')),
    late_receipt_flag           boolean NOT NULL DEFAULT true,                                              -- a receipt dated inside a closed period lands in the next open one, flagged
    -- counts (D11)
    blind_counts                boolean NOT NULL DEFAULT true,
    -- prices (D10)
    price_alert_pct             numeric(6,2) NOT NULL DEFAULT 10 CHECK (price_alert_pct >= 0 AND price_alert_pct <= 1000),
    -- the forecast and the reorder rule (D8, D9)
    covers_factor_threshold_pct numeric(6,2) NOT NULL DEFAULT 15 CHECK (covers_factor_threshold_pct >= 0 AND covers_factor_threshold_pct <= 1000),
    forecast_history_weeks_min  smallint NOT NULL DEFAULT 4 CHECK (forecast_history_weeks_min BETWEEN 1 AND 104),
    dynamic_par_after_weeks     smallint NOT NULL DEFAULT 4 CHECK (dynamic_par_after_weeks BETWEEN 1 AND 104),
    -- the Purchasing agent's morning note (D14): the business-wide Manager; a site may name its own (site_settings)
    manager_member_id           bigint REFERENCES members(id) ON DELETE SET NULL,
    -- keys (the kit's; db/003)
    key_rate_per_minute         integer NOT NULL DEFAULT 60 CHECK (key_rate_per_minute BETWEEN 1 AND 100000),
    key_rate_per_day            integer NOT NULL DEFAULT 5000 CHECK (key_rate_per_day BETWEEN 1 AND 10000000),
    key_rotation_overlap_hours  smallint NOT NULL DEFAULT 24 CHECK (key_rotation_overlap_hours BETWEEN 0 AND 168),
    storage_dir                 text,                                                                       -- NULL = RI_STORAGE_DIR from the environment (attachments)
    updated_by                  bigint REFERENCES members(id) ON DELETE SET NULL,
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO ri_settings (id) VALUES (1) ON CONFLICT DO NOTHING;
CREATE TRIGGER ri_settings_touch BEFORE UPDATE ON ri_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- One row per site, created with the site (ri_site_materialise(), below).
CREATE TABLE site_settings (
    scope_id                    bigint PRIMARY KEY REFERENCES sites(scope_id) ON DELETE CASCADE,
    kitchen_sees_cost           boolean NOT NULL DEFAULT false,              -- the Kitchen role sees cost here (design §3, D2)
    depletion_area_id           bigint,                                      -- the storage area a depletion lands in when the recipe names none (db/007+; no FK — the area comes later)
    manager_member_id           bigint REFERENCES members(id) ON DELETE SET NULL,   -- the Manager the morning note goes to for this site (D14); NULL = the business's
    sales_cut_time              time NOT NULL DEFAULT '04:00',               -- the business day ends here (a count's as_of equals the sales cut — design §6.6)
    temperature_check           boolean NOT NULL DEFAULT true,               -- the door check at receiving (D10)
    updated_by                  bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at                  timestamptz NOT NULL DEFAULT now(),
    updated_at                  timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER site_settings_touch BEFORE UPDATE ON site_settings FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

-- Day-parts (lunch, dinner, …) per site: sales, waste and the forecast speak in them. txtSchedules db/005 verbatim.
CREATE TABLE day_parts (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    key         text NOT NULL CHECK (key ~ '^[a-z][a-z0-9_]{0,29}$'),
    name        text NOT NULL,
    starts_at   time NOT NULL,
    ends_at     time NOT NULL,                  -- may be before starts_at (runs past midnight)
    sort_order  integer NOT NULL DEFAULT 0,
    archived_at timestamptz,
    service_name text,                          -- the name Reservations gives this service (Lunch, Dinner, Brunch): how its covers land in the forecast (K7)
    UNIQUE (scope_id, key)
);

-- A kernel scope → a site (txtSchedules db/013's shape). New: its settings and two day parts. Known: name, address and time
-- zone follow the kernel; removed → closed (nobody reaches it; its history stays).
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
    IF p_removed_at IS NOT NULL THEN
        DELETE FROM member_site_roles WHERE scope_id = p_scope;         -- closed: nobody reaches it; its history stays
    END IF;
    IF v_new THEN
        INSERT INTO site_settings (scope_id) VALUES (p_scope) ON CONFLICT DO NOTHING;
        INSERT INTO day_parts (scope_id, key, name, starts_at, ends_at, sort_order, service_name) VALUES
            (p_scope, 'lunch', 'Lunch', '11:00', '15:00', 1, 'Lunch'),          -- service_name: Reservations' own word (ZozoCal's default services)
            (p_scope, 'dinner', 'Dinner', '17:00', '22:00', 2, 'Dinner');
    END IF;
    RETURN v_new;
END$$;
GRANT EXECUTE ON FUNCTION ri_site_materialise(bigint, bigint, text, text, text, timestamptz) TO restaurant_inventory_rw;

-- One setting by name, for the rules that read them (never for a column that is not a number / not text).
CREATE OR REPLACE FUNCTION ri_setting_int(p_name text) RETURNS bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v bigint;
BEGIN
    EXECUTE format('SELECT %I::bigint FROM ri_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION ri_setting_text(p_name text) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v text;
BEGIN
    EXECUTE format('SELECT %I::text FROM ri_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;
CREATE OR REPLACE FUNCTION ri_setting_bool(p_name text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean;
BEGIN
    EXECUTE format('SELECT %I::boolean FROM ri_settings WHERE id = 1', p_name) INTO v;
    RETURN v;
END$$;

-- ---------------------------------------------------------------------------------------------
-- KEYS (db/003): a key's defaults, its resolution, the rate rule, rotation and pruning — Knowledge db/011 minus the base
-- (Sales CRM's and the Data Lake's copies, without the Data Lake's per-key SQL switch).
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_access_tokens_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.scope = 'api' THEN
        NEW.rate_per_minute := COALESCE(NEW.rate_per_minute, ri_setting_int('key_rate_per_minute')::integer);
        NEW.rate_per_day := COALESCE(NEW.rate_per_day, ri_setting_int('key_rate_per_day')::integer);
        IF NEW.rotated_from IS NOT NULL AND NOT EXISTS (SELECT 1 FROM mcp_access_tokens o WHERE o.id = NEW.rotated_from AND o.member_id = NEW.member_id AND o.scope = 'api') THEN
            RAISE EXCEPTION 'A key is rotated from a key of the same person' USING ERRCODE = 'check_violation';
        END IF;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER mcp_access_tokens_before BEFORE INSERT OR UPDATE ON mcp_access_tokens FOR EACH ROW EXECUTE FUNCTION ri_access_tokens_before();

-- Resolve a key by its hash: the token, its person, its label and its limits, with last_used_at touched. Nothing for a
-- revoked, expired or mcp-scope token, or one whose person is no longer admitted (a revoked grant shuts this door too).
CREATE OR REPLACE FUNCTION ri_resolve_key(p_hash text)
    RETURNS TABLE (token_id bigint, member_id bigint, label text, rate_per_minute integer, rate_per_day integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    UPDATE mcp_access_tokens t SET last_used_at = now()
      FROM members m
     WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
       AND m.id = t.member_id AND m.status = 'active' AND m.capability IS NOT NULL;
    RETURN QUERY
        SELECT t.id, t.member_id, t.label, t.rate_per_minute, t.rate_per_day
          FROM mcp_access_tokens t JOIN members m ON m.id = t.member_id
         WHERE t.token_hash = p_hash AND t.scope = 'api' AND t.revoked_at IS NULL AND (t.expires_at IS NULL OR t.expires_at > now())
           AND m.status = 'active' AND m.capability IS NOT NULL;
END$$;

-- Count one call. ok = the call may proceed; when not, limit_hit says which bucket and retry_after how many seconds until it
-- opens. The minute bucket is charged first; a call the minute refuses is not charged to the day (so a burst never eats the
-- day); a call the day refuses stays counted in the minute (it was made). `refused` is kept per bucket for the keys screen.
CREATE OR REPLACE FUNCTION ri_rate_ok(p_token_id bigint, p_at timestamptz DEFAULT now())
    RETURNS TABLE (ok boolean, limit_hit text, retry_after integer, minute_calls integer, day_calls integer)
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t mcp_access_tokens%ROWTYPE; m_start timestamptz; d_start timestamptz; m_calls integer; d_calls integer;
BEGIN
    SELECT * INTO t FROM mcp_access_tokens WHERE id = p_token_id AND scope = 'api' FOR UPDATE;   -- serialises the two upserts per key
    IF NOT FOUND THEN
        RETURN QUERY SELECT false, 'no_key'::text, 0, 0, 0; RETURN;
    END IF;
    IF t.revoked_at IS NOT NULL OR (t.expires_at IS NOT NULL AND t.expires_at <= p_at) THEN
        RETURN QUERY SELECT false, 'revoked'::text, 0, 0, 0; RETURN;
    END IF;
    m_start := date_trunc('minute', p_at);
    d_start := date_trunc('day', p_at);
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'minute', m_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO m_calls;
    IF m_calls > t.rate_per_minute THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'minute' AND bucket_start = m_start;
        SELECT COALESCE(calls, 0) INTO d_calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'minute'::text, GREATEST(1, ceil(extract(epoch FROM (m_start + interval '1 minute' - p_at)))::integer), m_calls - 1, COALESCE(d_calls, 0);
        RETURN;
    END IF;
    INSERT INTO key_usage (token_id, bucket_kind, bucket_start, calls) VALUES (p_token_id, 'day', d_start, 1)
    ON CONFLICT (token_id, bucket_kind, bucket_start) DO UPDATE SET calls = key_usage.calls + 1
    RETURNING calls INTO d_calls;
    IF d_calls > t.rate_per_day THEN
        UPDATE key_usage SET calls = calls - 1, refused = refused + 1 WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = d_start;
        RETURN QUERY SELECT false, 'day'::text, GREATEST(1, ceil(extract(epoch FROM (d_start + interval '1 day' - p_at)))::integer), m_calls, d_calls - 1;
        RETURN;
    END IF;
    RETURN QUERY SELECT true, NULL::text, 0, m_calls, d_calls;
END$$;

-- Today's count for a key (the keys screen, find_keys).
CREATE OR REPLACE FUNCTION ri_key_calls_today(p_token_id bigint) RETURNS integer
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$
    SELECT COALESCE((SELECT calls FROM key_usage WHERE token_id = p_token_id AND bucket_kind = 'day' AND bucket_start = date_trunc('day', now())), 0);
$$;

-- Rotate: a new key for the same person, label and limits; the old one expires after the overlap (24 h) and points nowhere —
-- the new one says `rotated_from`. Returns the new row's id; the raw key is the caller's (PHP mints the 48 hex, hashes them, and
-- passes the hash in).
CREATE OR REPLACE FUNCTION ri_key_rotate(p_old_token_id bigint, p_new_hash text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE o mcp_access_tokens%ROWTYPE; nid bigint; overlap integer;
BEGIN
    SELECT * INTO o FROM mcp_access_tokens WHERE id = p_old_token_id AND scope = 'api' FOR UPDATE;
    IF NOT FOUND OR o.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'Only a live key is rotated' USING ERRCODE = 'check_violation'; END IF;
    overlap := ri_setting_int('key_rotation_overlap_hours')::integer;
    INSERT INTO mcp_access_tokens (member_id, label, token_hash, scope, rate_per_minute, rate_per_day, rotated_from)
    VALUES (o.member_id, o.label, p_new_hash, 'api', o.rate_per_minute, o.rate_per_day, o.id)
    RETURNING id INTO nid;
    UPDATE mcp_access_tokens SET expires_at = LEAST(COALESCE(expires_at, 'infinity'::timestamptz), now() + make_interval(hours => overlap)) WHERE id = o.id;
    RETURN nid;
END$$;

-- The worker prunes buckets once a minute: minute buckets older than a day, day buckets older than 35 days.
CREATE OR REPLACE FUNCTION ri_prune_key_usage() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    DELETE FROM key_usage WHERE (bucket_kind = 'minute' AND bucket_start < now() - interval '1 day') OR (bucket_kind = 'day' AND bucket_start < now() - interval '35 days');
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n;
END$$;

GRANT SELECT, UPDATE ON ri_settings TO restaurant_inventory_rw;
GRANT SELECT, INSERT, UPDATE ON site_settings, day_parts TO restaurant_inventory_rw;
GRANT EXECUTE ON FUNCTION ri_setting_int(text), ri_setting_text(text), ri_setting_bool(text), ri_key_calls_today(bigint) TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;
REVOKE ALL ON FUNCTION ri_resolve_key(text), ri_rate_ok(bigint, timestamptz), ri_key_rotate(bigint, text), ri_prune_key_usage() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION ri_resolve_key(text), ri_rate_ok(bigint, timestamptz), ri_key_rotate(bigint, text), ri_prune_key_usage() TO restaurant_inventory_rw;

COMMIT;
