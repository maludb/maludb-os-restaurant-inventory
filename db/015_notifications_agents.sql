-- 015: NOTIFICATIONS, the OUTBOX, AGENT DISPATCHES, the Purchasing agent's PROPOSALS, WATCHES (design §5, §6).
--
-- THE ESTATE'S DATA MODEL (design §6.9): `notifications`, `notification_prefs`, `notification_outbox`, `agent_dispatches`
-- are GL db/014's canonical definitions with Spaces' substitution (every recipient a member; a supplier is reached by the
-- PO's own email from the handler); the `kind` lists are ours; `buyer_proposals` is Inventory's with scope_id; `watches`
-- is Inventory's shape on an ingredient at a site.
BEGIN;

CREATE TABLE notifications (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id    bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    scope_id     bigint REFERENCES sites(scope_id) ON DELETE CASCADE,
    kind         text NOT NULL CHECK (kind IN ('watch', 'stockout', 'price_alert', 'pull_failed', 'unmapped', 'po_ack', 'po_decline', 'po_date', 'delivery_due',
                                               'count_review', 'count_recount', 'variance', 'morning_note', 'mention', 'agent_drafted', 'period_due', 'invoice_dispute')),
    record_type  text,
    record_id    bigint,
    title        text NOT NULL,
    body         text,
    read_at      timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notifications_member_idx ON notifications (member_id, created_at DESC) WHERE read_at IS NULL;
CREATE INDEX notifications_record_idx ON notifications (record_type, record_id);

CREATE TABLE notification_prefs (
    member_id      bigint PRIMARY KEY REFERENCES members(id) ON DELETE CASCADE,
    email_enabled  boolean NOT NULL DEFAULT true,
    text_enabled   boolean NOT NULL DEFAULT false,
    kinds          text[] NOT NULL DEFAULT '{watch,stockout,price_alert,pull_failed,po_ack,po_decline,po_date,delivery_due,count_review,count_recount,variance,morning_note,mention,period_due,invoice_dispute}',
    text_kinds     text[] NOT NULL DEFAULT '{watch,stockout}',
    updated_at     timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER notification_prefs_touch BEFORE UPDATE ON notification_prefs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE notification_outbox (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    channel        text NOT NULL CHECK (channel IN ('email', 'text')),
    member_id      bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    to_email       citext,
    kind           text NOT NULL,
    record_type    text,
    record_id      bigint,
    dedupe_key     text,
    subject        text,
    body           text NOT NULL,
    body_html      text,
    status         text NOT NULL DEFAULT 'queued' CHECK (status IN ('queued', 'sent', 'skipped', 'failed')),
    attempts       integer NOT NULL DEFAULT 0,
    detail         text,
    provider_ref   text,
    send_after     timestamptz NOT NULL DEFAULT now(),
    sent_at        timestamptz,
    created_at     timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX notification_outbox_dedupe ON notification_outbox (dedupe_key) WHERE dedupe_key IS NOT NULL;
CREATE INDEX notification_outbox_queue_idx ON notification_outbox (status, send_after) WHERE status = 'queued';
CREATE INDEX notification_outbox_member_idx ON notification_outbox (member_id, created_at DESC);

CREATE OR REPLACE FUNCTION ri_notify(p_member_id bigint, p_kind text, p_record_type text, p_record_id bigint, p_title text, p_body text DEFAULT NULL,
                                     p_dedupe text DEFAULT NULL, p_force_text boolean DEFAULT false, p_scope_id bigint DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE nid bigint; pf notification_prefs%ROWTYPE; em citext;
BEGIN
    IF p_dedupe IS NOT NULL AND EXISTS (SELECT 1 FROM notification_outbox WHERE dedupe_key = p_dedupe || ':email') THEN RETURN NULL; END IF;
    INSERT INTO notifications (member_id, scope_id, kind, record_type, record_id, title, body) VALUES (p_member_id, p_scope_id, p_kind, p_record_type, p_record_id, p_title, p_body) RETURNING id INTO nid;
    INSERT INTO notification_prefs (member_id) VALUES (p_member_id) ON CONFLICT DO NOTHING;
    SELECT * INTO pf FROM notification_prefs WHERE member_id = p_member_id;
    SELECT email INTO em FROM members WHERE id = p_member_id;
    IF pf.email_enabled AND p_kind = ANY (pf.kinds) AND em IS NOT NULL THEN
        INSERT INTO notification_outbox (channel, member_id, to_email, kind, record_type, record_id, dedupe_key, subject, body)
        VALUES ('email', p_member_id, em, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':email' END, p_title, COALESCE(p_body, p_title)) ON CONFLICT DO NOTHING;
    END IF;
    IF p_force_text OR (pf.text_enabled AND p_kind = ANY (pf.text_kinds)) THEN
        INSERT INTO notification_outbox (channel, member_id, kind, record_type, record_id, dedupe_key, body)
        VALUES ('text', p_member_id, p_kind, p_record_type, p_record_id, CASE WHEN p_dedupe IS NULL THEN NULL ELSE p_dedupe || ':text' END, left(COALESCE(p_body, p_title), 300)) ON CONFLICT DO NOTHING;
    END IF;
    RETURN nid;
END$$;

CREATE TABLE agent_dispatches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type      text,
    record_id        bigint,
    scope_id         bigint REFERENCES sites(scope_id) ON DELETE SET NULL,
    agent_member_id  bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('watch', 'ask', 'duty_proposal')),
    via              text NOT NULL CHECK (via IN ('chat', 'wake', 'duty')),
    acting_member_id bigint REFERENCES members(id) ON DELETE SET NULL,
    run_id           bigint,
    request_id       text,
    status           text NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'answered', 'refused', 'failed', 'awaiting_approval')),
    reply_excerpt    text,
    detail           text,
    attempts         integer NOT NULL DEFAULT 1,
    created_at       timestamptz NOT NULL DEFAULT now(),
    answered_at      timestamptz,
    watch_id         bigint
);
CREATE INDEX agent_dispatches_agent_idx ON agent_dispatches (agent_member_id, created_at DESC);
CREATE INDEX agent_dispatches_pending_idx ON agent_dispatches (status, created_at) WHERE status = 'sent';

CREATE TABLE buyer_proposals (
    id                   bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id             bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    kind                 text NOT NULL CHECK (kind IN ('order', 'stockout', 'price', 'pos', 'count', 'forecast', 'po_overdue', 'delivery')),
    subject_type         text NOT NULL,
    subject_id           bigint NOT NULL,
    title                text NOT NULL,
    detail               jsonb NOT NULL DEFAULT '{}'::jsonb,
    drafted_record_type  text,
    drafted_record_id    bigint,
    status               text NOT NULL DEFAULT 'proposed' CHECK (status IN ('proposed', 'accepted', 'dismissed')),
    proposed_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    note_date            date NOT NULL DEFAULT current_date,
    decided_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    decided_at           timestamptz,
    created_at           timestamptz NOT NULL DEFAULT now(),
    updated_at           timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX buyer_proposals_site_date_idx ON buyer_proposals (scope_id, note_date DESC, kind);
CREATE INDEX buyer_proposals_open_idx ON buyer_proposals (status) WHERE status = 'proposed';
CREATE TRIGGER buyer_proposals_touch BEFORE UPDATE ON buyer_proposals FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

CREATE TABLE watches (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    member_id        bigint NOT NULL REFERENCES members(id) ON DELETE CASCADE,
    agent_member_id  bigint REFERENCES members(id) ON DELETE SET NULL,
    scope_id         bigint NOT NULL REFERENCES sites(scope_id) ON DELETE CASCADE,
    ingredient_id    bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    kind             text NOT NULL CHECK (kind IN ('below_par', 'stockout_projected', 'cost_above', 'cost_moved')),
    threshold        numeric(14,4),
    text_me          boolean NOT NULL DEFAULT false,
    last_state       boolean,
    fired_at         timestamptz,
    fire_count       integer NOT NULL DEFAULT 0,
    active           boolean NOT NULL DEFAULT true,
    note             text,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK (kind NOT IN ('cost_above', 'cost_moved') OR threshold IS NOT NULL)
);
CREATE INDEX watches_site_idx ON watches (scope_id, ingredient_id) WHERE active;
CREATE TRIGGER watches_touch BEFORE UPDATE ON watches FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
ALTER TABLE agent_dispatches ADD CONSTRAINT agent_dispatches_watch_fk FOREIGN KEY (watch_id) REFERENCES watches(id) ON DELETE SET NULL;

CREATE OR REPLACE FUNCTION ri_watch_state(p_watch_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE w watches%ROWTYPE; st boolean := false; par numeric; c numeric; lastc numeric;
BEGIN
    SELECT * INTO w FROM watches WHERE id = p_watch_id;
    IF NOT FOUND THEN RETURN NULL; END IF;
    IF w.kind = 'below_par' THEN
        SELECT par_base INTO par FROM ingredient_site_settings WHERE ingredient_id = w.ingredient_id AND scope_id = w.scope_id;
        st := par IS NOT NULL AND ri_on_hand(w.ingredient_id, w.scope_id) + ri_on_order(w.ingredient_id, w.scope_id) < par;
    ELSIF w.kind = 'stockout_projected' THEN
        st := ri_stockout_on(w.scope_id, w.ingredient_id, 7) IS NOT NULL;
    ELSIF w.kind = 'cost_above' THEN
        st := ri_cost_as_of(w.ingredient_id, w.scope_id) > w.threshold;
    ELSIF w.kind = 'cost_moved' THEN
        SELECT new_per_base, old_per_base INTO c, lastc FROM price_history WHERE ingredient_id = w.ingredient_id AND (scope_id = w.scope_id OR scope_id IS NULL) ORDER BY changed_at DESC LIMIT 1;
        st := lastc IS NOT NULL AND lastc > 0 AND abs(c - lastc) / lastc * 100 >= w.threshold;
    END IF;
    RETURN st;
END$$;

-- Fire once per state change; returns how many fired. The worker calls it after the ledger moves and the morning pass.
CREATE OR REPLACE FUNCTION ri_watches_fire() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE w record; st boolean; n integer := 0; nm text;
BEGIN
    FOR w IN SELECT * FROM watches WHERE active LOOP
        st := ri_watch_state(w.id);
        IF st AND NOT COALESCE(w.last_state, false) THEN
            SELECT name INTO nm FROM ingredients WHERE id = w.ingredient_id;
            PERFORM ri_notify(w.member_id, 'watch', 'ingredient', w.ingredient_id, replace(w.kind, '_', ' ') || ': ' || nm, w.note, 'watch:' || w.id || ':' || to_char(now(), 'YYYYMMDDHH24'), w.text_me, w.scope_id);
            IF w.agent_member_id IS NOT NULL THEN
                INSERT INTO agent_dispatches (record_type, record_id, scope_id, agent_member_id, kind, via, acting_member_id, watch_id) VALUES ('ingredient', w.ingredient_id, w.scope_id, w.agent_member_id, 'watch', 'chat', w.member_id, w.id);
            END IF;
            n := n + 1;
        END IF;
        UPDATE watches SET last_state = st, fired_at = CASE WHEN st AND NOT COALESCE(last_state, false) THEN now() ELSE fired_at END, fire_count = fire_count + CASE WHEN st AND NOT COALESCE(last_state, false) THEN 1 ELSE 0 END WHERE id = w.id;
    END LOOP;
    RETURN n;
END$$;

COMMIT;
