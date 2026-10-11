-- 002: activity memory -- the one funnel (memory.md §2). Exists before the first feature ships; it cannot be
-- backfilled. Every row is shipped to the tenant's one MaluDB as an `activity` episode by mcp/activity_ingest.py
-- (payload key "application": "restaurant_inventory" first). What is logged is what people and agents DO: ids, names, codes,
-- quantities, units, costs, counts, states and reasons in `before`/`after` -- never a POS check's raw payload, never a source's
-- credential, never a token or a file path (design §6.9). Bulk actions are ONE row with a count; polling and the worker's
-- empty passes are never logged.
--
-- THE ESTATE'S DATA MODEL (design §6.9): the contract table (memory.md / Consultant Tracking db/002) with token_id as Knowledge
-- db/002 appended it (the newest kits carry it), txtSchedules db/002's scope_id (every row carries the site, scoped-applications.md
-- §4.6 — memory can answer "what happened at Airport"), and this application's audit keys APPENDED: ingredient_id, recipe_id,
-- purchase_order_id, count_id (a record's TRAIL is one index each). No FK: history outlives a record, and the records come in
-- db/007+. `source` widens the contract's list with 'system' (the worker's own passes are no one's click).
BEGIN;

CREATE TABLE activity_log (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    occurred_at     timestamptz NOT NULL DEFAULT now(),
    actor_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    source          text NOT NULL DEFAULT 'web'
                    CHECK (source IN ('web', 'assistant', 'mcp', 'cron', 'agent', 'desk', 'webhook', 'portal', 'api', 'application', 'system')),
    action          text NOT NULL,                 -- entity.verb
    screen          text,
    route           text,                          -- "METHOD /path"
    entity_type     text,
    entity_id       bigint,
    before          jsonb,
    after           jsonb,                         -- changed fields only
    request_id      text,
    session_id      text,
    ip_address      inet,
    agent_run_id    bigint,                        -- the kernel's run id when an agent acted (no FK)
    department_id   bigint,                        -- the department the event concerns
    location_id     bigint,                        -- the contract's (the kernel's location id, when known)
    kernel_request_id text,                        -- the kernel's request id for a call that crossed to it
    created_at      timestamptz NOT NULL DEFAULT now(),
    token_id        bigint,                        -- appended (Knowledge db/002): the token (mcp_access_tokens.id) an event ran under; its LABEL is in `after`, never the token
    scope_id        bigint,                        -- appended (txtSchedules db/002): the site the event happened at; no FK — history outlives a site
    -- appended (design §6.9): this application's audit keys (no FK — history outlives a record; the records come in db/007+)
    ingredient_id      bigint,
    recipe_id          bigint,
    purchase_order_id  bigint,
    count_id           bigint
);
CREATE INDEX activity_log_actor_idx    ON activity_log (actor_member_id, occurred_at DESC);
CREATE INDEX activity_log_entity_idx   ON activity_log (entity_type, entity_id, occurred_at DESC);
CREATE INDEX activity_log_action_idx   ON activity_log (action, occurred_at DESC);
CREATE INDEX activity_log_time_idx     ON activity_log (occurred_at DESC);
CREATE INDEX activity_log_request_idx  ON activity_log (request_id);
CREATE INDEX activity_log_token_idx    ON activity_log (token_id, occurred_at DESC) WHERE token_id IS NOT NULL;
CREATE INDEX activity_log_scope_idx    ON activity_log (scope_id, occurred_at DESC);
CREATE INDEX activity_log_ingredient_idx ON activity_log (ingredient_id, occurred_at DESC) WHERE ingredient_id IS NOT NULL;
CREATE INDEX activity_log_recipe_idx     ON activity_log (recipe_id, occurred_at DESC) WHERE recipe_id IS NOT NULL;
CREATE INDEX activity_log_po_idx         ON activity_log (purchase_order_id, occurred_at DESC) WHERE purchase_order_id IS NOT NULL;
CREATE INDEX activity_log_count_idx      ON activity_log (count_id, occurred_at DESC) WHERE count_id IS NOT NULL;

GRANT INSERT, SELECT ON activity_log TO restaurant_inventory_rw;
REVOKE ALL ON activity_log FROM restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

-- The ingest checkpoint: only ever moves forward.
CREATE TABLE activity_ingest_state (
    id          smallint PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    last_id     bigint NOT NULL DEFAULT 0,
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO activity_ingest_state (id, last_id) VALUES (1, 0) ON CONFLICT DO NOTHING;
GRANT SELECT, UPDATE ON activity_ingest_state TO restaurant_inventory_rw;

COMMIT;
