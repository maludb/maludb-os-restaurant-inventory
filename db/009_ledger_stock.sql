-- 009: THE LEDGER — balances per ingredient per storage area, the signed immutable transactions that alone move them,
-- the site's running weighted-average cost, and the documents that post: goods receipts, transfers (two halves, within a
-- site or between sites), adjustments, PRODUCTION (a prep batch), WASTE; reversals (design §6.3).
--
-- THE ESTATE'S DATA MODEL (design §6.9): REUSE WITH ONE RECORDED DEVIATION — Inventory's canonical ledger for unit-counted
-- goods (`inventory_balances`, `inventory_transactions`, the documents, the referee's rules) with `qty integer` keyed by
-- `variant_id` → `qty_base numeric(18,4)` keyed by `ingredient_id` in the ingredient's base unit, `unit_cost numeric(18,6)`
-- per base unit, `scope_id` on every document; `locations` are `storage_areas` (db/007). Proposed back to the catalogue as
-- "the canonical shape widened for measured goods". `waste_logs` keeps the cidery's `loss_events` names; `production_batches`
-- its `production_orders` family. `reason_codes` is Inventory's with `applies_to` widened.
--
-- THE REFEREE'S RULES: a balance is maintained ONLY by the ledger's trigger; a transaction is never changed or deleted —
-- reverse it; the site's average cost advances on receipt and production_in, never on an issue; a zero-cost receipt is
-- refused into the average (the design's rule) unless the line says so; occurred_at is the accounting moment and
-- business_date the day it belongs to; a storage area refuses a negative balance only when it says so (a kitchen depletes
-- before it counts).
BEGIN;

CREATE TABLE reason_codes (
    id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    code        text NOT NULL UNIQUE CHECK (code ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    applies_to  text[] NOT NULL DEFAULT '{adjustment}',                       -- adjustment, waste, count, transfer, receipt
    stage       text CHECK (stage IS NULL OR stage IN ('ingredient', 'prep', 'plate')),   -- waste reasons: Leanpath's stage default
    sort_order  integer NOT NULL DEFAULT 100,
    active      boolean NOT NULL DEFAULT true
);
INSERT INTO reason_codes (code, name, applies_to, stage, sort_order) VALUES
    ('spoilage', 'Spoilage', '{waste,adjustment}', 'ingredient', 10), ('expired', 'Expired', '{waste,adjustment}', 'ingredient', 11),
    ('trim', 'Trim', '{waste}', 'ingredient', 12), ('overproduction', 'Over-production', '{waste}', 'prep', 20),
    ('dropped', 'Dropped or spilled', '{waste}', 'prep', 21), ('burned', 'Burned', '{waste}', 'prep', 22), ('contaminated', 'Contaminated', '{waste}', 'ingredient', 23),
    ('plate_waste', 'Plate waste', '{waste}', 'plate', 30), ('returned_dish', 'Returned dish', '{waste}', 'plate', 31), ('comp', 'Comped', '{waste}', 'plate', 32),
    ('staff_meal', 'Staff meal', '{waste}', 'plate', 33), ('breakage', 'Breakage', '{waste,adjustment}', 'ingredient', 40),
    ('theft', 'Theft or loss', '{adjustment}', NULL, 50), ('count_correction', 'Count correction', '{count}', NULL, 60),
    ('data_entry', 'Data entry correction', '{adjustment}', NULL, 61), ('opening', 'Opening balance', '{adjustment}', NULL, 62),
    ('sample', 'Sample or tasting', '{waste,adjustment}', 'ingredient', 63), ('donation', 'Donated', '{waste,adjustment}', 'ingredient', 64);

CREATE TABLE inventory_balances (
    ingredient_id    bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    storage_area_id  bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    qty_base         numeric(18,4) NOT NULL DEFAULT 0,
    updated_at       timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (ingredient_id, storage_area_id)
);
CREATE INDEX inventory_balances_area_idx ON inventory_balances (storage_area_id) WHERE qty_base <> 0;
CREATE OR REPLACE FUNCTION ri_balances_guard() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF COALESCE(current_setting('ri.balance_writer', true), '') <> 'on' THEN
        RAISE EXCEPTION 'A balance is maintained only by transactions — post a receipt, a transfer, an adjustment, production, waste or a count' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    NEW.updated_at := now();
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_balances_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_balances FOR EACH ROW EXECUTE FUNCTION ri_balances_guard();

CREATE TABLE inventory_transactions (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id           bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    group_id           uuid NOT NULL DEFAULT gen_random_uuid(),
    txn_type           text NOT NULL CHECK (txn_type IN ('receipt', 'depletion', 'waste', 'transfer_out', 'transfer_in', 'production_in', 'production_out',
                                                         'count_correction', 'adjustment', 'return', 'reversal')),
    ingredient_id      bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    storage_area_id    bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    qty_base           numeric(18,4) NOT NULL CHECK (qty_base <> 0),           -- signed
    unit_cost_per_base numeric(18,6) CHECK (unit_cost_per_base IS NULL OR unit_cost_per_base >= 0),
    value              numeric(14,4) GENERATED ALWAYS AS (round(qty_base * COALESCE(unit_cost_per_base, 0), 4)) STORED,
    counterparty_kind  text NOT NULL DEFAULT 'none' CHECK (counterparty_kind IN ('none', 'storage_area', 'site', 'supplier', 'customer', 'disposal')),
    counterparty_id    bigint,
    reason_code_id     bigint REFERENCES reason_codes(id) ON DELETE RESTRICT,
    reference_kind     text NOT NULL CHECK (reference_kind IN ('goods_receipt', 'transfer', 'adjustment', 'count', 'production', 'waste', 'depletion_run', 'return', 'reversal', 'opening', 'invoice')),
    reference_id       bigint NOT NULL,
    reverses_id        bigint REFERENCES inventory_transactions(id) ON DELETE RESTRICT,
    idempotency_key    text NOT NULL UNIQUE DEFAULT gen_random_uuid()::text,
    note               text,
    occurred_at        timestamptz NOT NULL DEFAULT now(),                       -- the accounting moment
    business_date      date NOT NULL DEFAULT current_date,
    posted_at          timestamptz NOT NULL DEFAULT now(),
    actor_member_id    bigint REFERENCES members(id) ON DELETE SET NULL,
    CHECK (txn_type NOT IN ('receipt', 'transfer_in', 'production_in', 'return') OR qty_base > 0),
    CHECK (txn_type NOT IN ('depletion', 'waste', 'transfer_out', 'production_out') OR qty_base < 0),
    CHECK ((txn_type = 'reversal') = (reverses_id IS NOT NULL))
);
CREATE INDEX inventory_transactions_ingredient_idx ON inventory_transactions (ingredient_id, scope_id, occurred_at DESC);
CREATE INDEX inventory_transactions_site_date_idx ON inventory_transactions (scope_id, business_date, txn_type);
CREATE INDEX inventory_transactions_area_idx ON inventory_transactions (storage_area_id, occurred_at DESC);
CREATE INDEX inventory_transactions_reference_idx ON inventory_transactions (reference_kind, reference_id);
CREATE INDEX inventory_transactions_group_idx ON inventory_transactions (group_id);
CREATE UNIQUE INDEX inventory_transactions_reverses_once ON inventory_transactions (reverses_id) WHERE reverses_id IS NOT NULL;

CREATE OR REPLACE FUNCTION ri_transactions_before() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF TG_OP IN ('UPDATE', 'DELETE') THEN
        RAISE EXCEPTION 'A transaction is never changed or deleted — reverse it (ri_reverse_transaction)' USING ERRCODE = 'insufficient_privilege';
    END IF;
    IF (SELECT scope_id FROM storage_areas WHERE id = NEW.storage_area_id) <> NEW.scope_id THEN
        RAISE EXCEPTION 'The storage area is not at that site' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.actor_member_id IS NULL THEN NEW.actor_member_id := app_current_member_id(); END IF;
    IF NEW.unit_cost_per_base IS NULL THEN NEW.unit_cost_per_base := ri_cost_as_of(NEW.ingredient_id, NEW.scope_id); END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_transactions_before BEFORE INSERT OR UPDATE OR DELETE ON inventory_transactions FOR EACH ROW EXECUTE FUNCTION ri_transactions_before();

-- The one writer of a balance and of the site's running average (advanced on receipt / production_in / return / an
-- opening adjustment; never on an issue; a count correction keeps the average).
CREATE OR REPLACE FUNCTION ri_transactions_after() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE newq numeric; neg boolean; sc ingredient_site_costs%ROWTYPE; oh numeric; avg numeric; inbound boolean;
BEGIN
    PERFORM set_config('ri.balance_writer', 'on', true);
    INSERT INTO inventory_balances (ingredient_id, storage_area_id) VALUES (NEW.ingredient_id, NEW.storage_area_id) ON CONFLICT DO NOTHING;
    UPDATE inventory_balances SET qty_base = qty_base + NEW.qty_base WHERE ingredient_id = NEW.ingredient_id AND storage_area_id = NEW.storage_area_id RETURNING qty_base INTO newq;
    SELECT allow_negative INTO neg FROM storage_areas WHERE id = NEW.storage_area_id;
    IF newq < 0 AND NOT neg THEN
        RAISE EXCEPTION 'Only % on hand in that area — the move needs % and the area refuses a negative balance', newq - NEW.qty_base, -NEW.qty_base USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO ingredient_site_costs (ingredient_id, scope_id) VALUES (NEW.ingredient_id, NEW.scope_id) ON CONFLICT DO NOTHING;
    SELECT * INTO sc FROM ingredient_site_costs WHERE ingredient_id = NEW.ingredient_id AND scope_id = NEW.scope_id FOR UPDATE;
    inbound := NEW.txn_type IN ('receipt', 'production_in', 'return') OR (NEW.txn_type = 'adjustment' AND NEW.qty_base > 0 AND NEW.unit_cost_per_base IS NOT NULL AND NEW.unit_cost_per_base > 0)
               OR (NEW.txn_type = 'transfer_in' AND NEW.counterparty_kind = 'site');
    oh := sc.on_hand_base; avg := sc.avg_cost_per_base;
    IF inbound AND COALESCE(NEW.unit_cost_per_base, 0) > 0 THEN
        IF oh <= 0 THEN avg := NEW.unit_cost_per_base;
        ELSE avg := round((oh * avg + NEW.qty_base * NEW.unit_cost_per_base) / (oh + NEW.qty_base), 6); END IF;
        UPDATE ingredient_site_costs SET avg_cost_per_base = avg, last_cost_per_base = NEW.unit_cost_per_base, last_cost_at = NEW.occurred_at,
               on_hand_base = oh + NEW.qty_base, on_hand_value = round((oh + NEW.qty_base) * avg, 2)
         WHERE ingredient_id = NEW.ingredient_id AND scope_id = NEW.scope_id;
    ELSE
        UPDATE ingredient_site_costs SET on_hand_base = oh + NEW.qty_base, on_hand_value = round((oh + NEW.qty_base) * avg, 2)
         WHERE ingredient_id = NEW.ingredient_id AND scope_id = NEW.scope_id;
    END IF;
    PERFORM set_config('ri.balance_writer', '', true);
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_transactions_after AFTER INSERT ON inventory_transactions FOR EACH ROW EXECUTE FUNCTION ri_transactions_after();

CREATE OR REPLACE FUNCTION ri_post_txn(p_type text, p_scope bigint, p_ingredient bigint, p_area bigint, p_qty_base numeric, p_cost numeric,
                                       p_ref_kind text, p_ref_id bigint, p_idem text, p_cp_kind text DEFAULT 'none', p_cp_id bigint DEFAULT NULL,
                                       p_reason bigint DEFAULT NULL, p_group uuid DEFAULT NULL, p_by bigint DEFAULT NULL, p_note text DEFAULT NULL,
                                       p_occurred timestamptz DEFAULT now(), p_business_date date DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE tid bigint;
BEGIN
    INSERT INTO inventory_transactions (scope_id, group_id, txn_type, ingredient_id, storage_area_id, qty_base, unit_cost_per_base, counterparty_kind, counterparty_id,
                                        reason_code_id, reference_kind, reference_id, idempotency_key, note, occurred_at, business_date, actor_member_id)
    VALUES (p_scope, COALESCE(p_group, gen_random_uuid()), p_type, p_ingredient, p_area, p_qty_base, p_cost, p_cp_kind, p_cp_id, p_reason, p_ref_kind, p_ref_id, p_idem, p_note,
            p_occurred, COALESCE(p_business_date, (p_occurred AT TIME ZONE COALESCE((SELECT timezone FROM sites WHERE scope_id = p_scope), 'UTC'))::date), p_by)
    ON CONFLICT (idempotency_key) DO NOTHING RETURNING id INTO tid;
    RETURN tid;
END$$;

CREATE OR REPLACE FUNCTION ri_reverse_transaction(p_txn_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transactions%ROWTYPE;
BEGIN
    SELECT * INTO t FROM inventory_transactions WHERE id = p_txn_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transaction' USING ERRCODE = 'no_data_found'; END IF;
    IF EXISTS (SELECT 1 FROM inventory_transactions WHERE reverses_id = p_txn_id) THEN RAISE EXCEPTION 'Already reversed' USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO inventory_transactions (scope_id, group_id, txn_type, ingredient_id, storage_area_id, qty_base, unit_cost_per_base, counterparty_kind, counterparty_id, reason_code_id,
                                        reference_kind, reference_id, reverses_id, note, occurred_at, business_date, actor_member_id)
    VALUES (t.scope_id, t.group_id, 'reversal', t.ingredient_id, t.storage_area_id, -t.qty_base, t.unit_cost_per_base, t.counterparty_kind, t.counterparty_id, t.reason_code_id,
            'reversal', p_txn_id, p_txn_id, COALESCE(p_note, 'reverses ' || p_txn_id), t.occurred_at, t.business_date, p_by)
    RETURNING id INTO t.id;
    RETURN t.id;
END$$;

-- The on-hand of an ingredient at a site (Σ areas) and in one area.
CREATE OR REPLACE FUNCTION ri_on_hand(p_ingredient_id bigint, p_scope_id bigint, p_area_id bigint DEFAULT NULL) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE(sum(b.qty_base), 0) FROM inventory_balances b JOIN storage_areas a ON a.id = b.storage_area_id
     WHERE b.ingredient_id = p_ingredient_id AND a.scope_id = p_scope_id AND (p_area_id IS NULL OR b.storage_area_id = p_area_id)
$$;
-- The on-hand as of a moment: the balance now minus what moved since.
CREATE OR REPLACE FUNCTION ri_on_hand_as_of(p_ingredient_id bigint, p_scope_id bigint, p_as_of timestamptz) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT ri_on_hand(p_ingredient_id, p_scope_id) - COALESCE((SELECT sum(qty_base) FROM inventory_transactions
        WHERE ingredient_id = p_ingredient_id AND scope_id = p_scope_id AND occurred_at > p_as_of), 0)
$$;

-- ---------------------------------------------------------------------------------------------
-- Documents. Lines change only while the header is a draft; posting is a function; a number at creation.
-- ---------------------------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION ri_lines_only_while_draft() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text; hid bigint; col text := TG_ARGV[1]; tbl text := TG_ARGV[0];
BEGIN
    IF TG_OP = 'DELETE' THEN EXECUTE format('SELECT ($1).%I', col) INTO hid USING OLD;
    ELSE EXECUTE format('SELECT ($1).%I', col) INTO hid USING NEW; END IF;
    EXECUTE format('SELECT status FROM %I WHERE id = $1', tbl) INTO st USING hid;
    IF st IS DISTINCT FROM TG_ARGV[2] THEN
        RAISE EXCEPTION 'The lines of a % change only while it is %, it is %', replace(tbl, '_', ' '), TG_ARGV[2], st USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END$$;

-- Goods receipts: against a PO, or a delivery note alone.
CREATE TABLE goods_receipts (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text NOT NULL UNIQUE,
    scope_id           bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    supplier_id        bigint REFERENCES suppliers(id) ON DELETE RESTRICT,
    purchase_order_id  bigint,                                                   -- FK in db/010
    storage_area_id    bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'cancelled')),
    delivery_note_ref  text,
    invoice_ref        text,
    received_on        date NOT NULL DEFAULT current_date,
    received_at        timestamptz NOT NULL DEFAULT now(),                       -- the accounting moment of every line
    temperature_ok     boolean,                                                  -- the door check as a whole; per line below
    notes              text,
    posted_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at          timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX goods_receipts_site_idx ON goods_receipts (scope_id, received_on DESC);
CREATE INDEX goods_receipts_supplier_idx ON goods_receipts (supplier_id);
CREATE INDEX goods_receipts_po_idx ON goods_receipts (purchase_order_id);
CREATE TRIGGER goods_receipts_touch BEFORE UPDATE ON goods_receipts FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER goods_receipts_number BEFORE INSERT ON goods_receipts FOR EACH ROW EXECUTE FUNCTION ri_number_document('goods_receipt');

CREATE TABLE goods_receipt_lines (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    goods_receipt_id        bigint NOT NULL REFERENCES goods_receipts(id) ON DELETE CASCADE,
    line_no                 integer NOT NULL,
    purchase_order_line_id  bigint,                                              -- FK in db/010
    ingredient_id           bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    supplier_item_id        bigint REFERENCES supplier_items(id) ON DELETE SET NULL,
    qty_received            numeric(14,4) NOT NULL CHECK (qty_received > 0),     -- in purchase_unit_code
    purchase_unit_code      text NOT NULL,
    qty_base                numeric(18,4) NOT NULL,                              -- set by the trigger; catch weight: from weight_base
    weight_qty              numeric(14,4) CHECK (weight_qty IS NULL OR weight_qty > 0),   -- catch weight: the declared weight …
    weight_unit_code        text,                                                -- … in this unit (lb, kg)
    unit_cost               numeric(14,4) CHECK (unit_cost IS NULL OR unit_cost >= 0),    -- per purchase unit (or per weight unit when catch weight)
    cost_per_base           numeric(18,6),                                       -- set by the trigger
    allow_zero_cost         boolean NOT NULL DEFAULT false,                       -- a free sample: do not pull the average down
    discrepancy_kind        text NOT NULL DEFAULT 'none' CHECK (discrepancy_kind IN ('none', 'short', 'over', 'damaged', 'wrong_item', 'substitute')),
    discrepancy_note        text,
    temperature_c           numeric(5,1),
    temperature_ok          boolean,
    putaway_area_id         bigint REFERENCES storage_areas(id) ON DELETE RESTRICT,   -- NULL = the receipt's area
    created_at              timestamptz NOT NULL DEFAULT now(),
    updated_at              timestamptz NOT NULL DEFAULT now(),
    UNIQUE (goods_receipt_id, line_no)
);
CREATE INDEX goods_receipt_lines_ingredient_idx ON goods_receipt_lines (ingredient_id);
CREATE INDEX goods_receipt_lines_po_line_idx ON goods_receipt_lines (purchase_order_line_id);
CREATE TRIGGER goods_receipt_lines_touch BEFORE UPDATE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER goods_receipt_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION ri_lines_only_while_draft('goods_receipts', 'goods_receipt_id', 'draft');
CREATE OR REPLACE FUNCTION ri_receipt_lines_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE per_unit_base numeric;
BEGIN
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    IF NEW.weight_qty IS NOT NULL THEN
        -- catch weight: the quantity that lands is the declared weight; the cost is per weight unit
        NEW.qty_base := ri_to_base(NEW.ingredient_id, NEW.weight_qty, COALESCE(NEW.weight_unit_code, 'lb'));
        NEW.cost_per_base := CASE WHEN NEW.unit_cost IS NULL THEN NULL ELSE round(NEW.unit_cost / ri_to_base(NEW.ingredient_id, 1, COALESCE(NEW.weight_unit_code, 'lb')), 6) END;
    ELSE
        per_unit_base := ri_to_base(NEW.ingredient_id, 1, NEW.purchase_unit_code);
        NEW.qty_base := round(NEW.qty_received * per_unit_base, 4);
        NEW.cost_per_base := CASE WHEN NEW.unit_cost IS NULL THEN NULL ELSE round(NEW.unit_cost / per_unit_base, 6) END;
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER goods_receipt_lines_before BEFORE INSERT OR UPDATE ON goods_receipt_lines FOR EACH ROW EXECUTE FUNCTION ri_receipt_lines_before();

CREATE OR REPLACE FUNCTION ri_receipt_posted_hook(p_receipt_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$ BEGIN RETURN; END$$;   -- replaced in db/010 (the PO's quantities)

CREATE OR REPLACE FUNCTION ri_post_receipt(p_receipt_id bigint, p_by bigint) RETURNS goods_receipts
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r goods_receipts%ROWTYPE; l record; g uuid := gen_random_uuid(); c numeric; old numeric;
BEGIN
    SELECT * INTO r FROM goods_receipts WHERE id = p_receipt_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such receipt' USING ERRCODE = 'no_data_found'; END IF;
    IF r.status <> 'draft' THEN RAISE EXCEPTION 'Receipt % is % — only a draft posts', r.number, r.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM goods_receipt_lines WHERE goods_receipt_id = p_receipt_id) THEN RAISE EXCEPTION 'Receipt % has no lines', r.number USING ERRCODE = 'check_violation'; END IF;
    IF (SELECT scope_id FROM storage_areas WHERE id = r.storage_area_id) <> r.scope_id THEN RAISE EXCEPTION 'The receiving area is not at that site' USING ERRCODE = 'check_violation'; END IF;
    UPDATE goods_receipts SET status = 'posted', posted_by = p_by, posted_at = now() WHERE id = p_receipt_id RETURNING * INTO r;
    FOR l IN SELECT * FROM goods_receipt_lines WHERE goods_receipt_id = p_receipt_id ORDER BY line_no LOOP
        c := l.cost_per_base;
        IF (c IS NULL OR c = 0) AND NOT l.allow_zero_cost THEN c := ri_cost_as_of(l.ingredient_id, r.scope_id); END IF;   -- a costless line lands at the running cost, never at zero
        PERFORM ri_post_txn('receipt', r.scope_id, l.ingredient_id, COALESCE(l.putaway_area_id, r.storage_area_id), l.qty_base, c, 'goods_receipt', p_receipt_id, 'receipt:' || l.id,
                            CASE WHEN r.supplier_id IS NULL THEN 'none' ELSE 'supplier' END, r.supplier_id, NULL, g, p_by, l.discrepancy_note, r.received_at, r.received_on);
        IF l.cost_per_base IS NOT NULL AND l.cost_per_base > 0 THEN
            SELECT last_cost_per_base INTO old FROM ingredient_site_costs WHERE ingredient_id = l.ingredient_id AND scope_id = r.scope_id;
            INSERT INTO price_history (ingredient_id, scope_id, old_per_base, new_per_base, per_unit_code, per_unit_price, source_kind, reference_kind, reference_id, changed_by, changed_at)
            VALUES (l.ingredient_id, r.scope_id, old, l.cost_per_base, COALESCE(l.weight_unit_code, l.purchase_unit_code), l.unit_cost, 'receipt', 'goods_receipt', p_receipt_id, p_by, r.received_at);
            IF l.supplier_item_id IS NOT NULL THEN
                UPDATE supplier_items SET last_price = l.unit_cost, last_price_at = r.received_at WHERE id = l.supplier_item_id;
            END IF;
        END IF;
    END LOOP;
    PERFORM ri_receipt_posted_hook(p_receipt_id);
    RETURN r;
END$$;

-- Transfers: between areas of one site, or between sites (the receiving site takes the sender's average cost).
CREATE TABLE inventory_transfers (
    id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number         text NOT NULL UNIQUE,
    scope_id       bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,       -- the sending site
    to_scope_id    bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,       -- = scope_id within a site
    from_area_id   bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    to_area_id     bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    status         text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'in_transit', 'received', 'cancelled')),
    notes          text,
    shipped_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    shipped_at     timestamptz,
    received_by    bigint REFERENCES members(id) ON DELETE SET NULL,
    received_at    timestamptz,
    created_by     bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at     timestamptz NOT NULL DEFAULT now(),
    updated_at     timestamptz NOT NULL DEFAULT now(),
    CHECK (from_area_id <> to_area_id)
);
CREATE INDEX inventory_transfers_site_idx ON inventory_transfers (scope_id, status, created_at DESC);
CREATE INDEX inventory_transfers_to_site_idx ON inventory_transfers (to_scope_id, status);
CREATE TRIGGER inventory_transfers_touch BEFORE UPDATE ON inventory_transfers FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_transfers_number BEFORE INSERT ON inventory_transfers FOR EACH ROW EXECUTE FUNCTION ri_number_document('transfer');

CREATE TABLE inventory_transfer_lines (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    transfer_id        bigint NOT NULL REFERENCES inventory_transfers(id) ON DELETE CASCADE,
    line_no            integer NOT NULL,
    ingredient_id      bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    qty                numeric(14,4) NOT NULL CHECK (qty > 0),
    unit_code          text NOT NULL,
    qty_base           numeric(18,4) NOT NULL,
    qty_received_base  numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_received_base >= 0),
    unit_cost_per_base numeric(18,6),                                            -- the sender's average at shipping
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (transfer_id, line_no)
);
CREATE TRIGGER inventory_transfer_lines_touch BEFORE UPDATE ON inventory_transfer_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_transfer_lines_guard() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE st text;
BEGIN
    IF COALESCE(current_setting('ri.balance_writer', true), '') = 'on' THEN RETURN COALESCE(NEW, OLD); END IF;
    SELECT status INTO st FROM inventory_transfers WHERE id = COALESCE(NEW.transfer_id, OLD.transfer_id);
    IF st <> 'draft' THEN RAISE EXCEPTION 'The lines of a transfer change only while it is a draft' USING ERRCODE = 'check_violation'; END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    NEW.qty_base := ri_to_base(NEW.ingredient_id, NEW.qty, NEW.unit_code);
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_transfer_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_transfer_lines FOR EACH ROW EXECUTE FUNCTION ri_transfer_lines_guard();

CREATE OR REPLACE FUNCTION ri_transfer_send(p_transfer_id bigint, p_by bigint) RETURNS inventory_transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transfers%ROWTYPE; l record; g uuid := gen_random_uuid(); c numeric; cpk text; cpid bigint;
BEGIN
    SELECT * INTO t FROM inventory_transfers WHERE id = p_transfer_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transfer' USING ERRCODE = 'no_data_found'; END IF;
    IF t.status <> 'draft' THEN RAISE EXCEPTION 'Transfer % is % — only a draft is sent', t.number, t.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id) THEN RAISE EXCEPTION 'Transfer % has no lines', t.number USING ERRCODE = 'check_violation'; END IF;
    IF (SELECT scope_id FROM storage_areas WHERE id = t.from_area_id) <> t.scope_id OR (SELECT scope_id FROM storage_areas WHERE id = t.to_area_id) <> t.to_scope_id THEN
        RAISE EXCEPTION 'The areas are not at the sites named' USING ERRCODE = 'check_violation';
    END IF;
    IF t.scope_id = t.to_scope_id THEN cpk := 'storage_area'; cpid := t.to_area_id; ELSE cpk := 'site'; cpid := t.to_scope_id; END IF;
    UPDATE inventory_transfers SET status = 'in_transit', shipped_by = p_by, shipped_at = now() WHERE id = p_transfer_id RETURNING * INTO t;
    PERFORM set_config('ri.balance_writer', 'on', true);
    FOR l IN SELECT * FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id ORDER BY line_no LOOP
        c := ri_cost_as_of(l.ingredient_id, t.scope_id);
        UPDATE inventory_transfer_lines SET unit_cost_per_base = c WHERE id = l.id;
        PERFORM ri_post_txn('transfer_out', t.scope_id, l.ingredient_id, t.from_area_id, -l.qty_base, c, 'transfer', p_transfer_id, 'xfer_out:' || l.id, cpk, cpid, NULL, g, p_by);
    END LOOP;
    PERFORM set_config('ri.balance_writer', '', true);
    RETURN t;
END$$;

CREATE OR REPLACE FUNCTION ri_transfer_receive(p_transfer_id bigint, p_by bigint, p_received jsonb DEFAULT NULL) RETURNS inventory_transfers
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE t inventory_transfers%ROWTYPE; l record; g uuid := gen_random_uuid(); q numeric; cpk text; cpid bigint;
BEGIN
    SELECT * INTO t FROM inventory_transfers WHERE id = p_transfer_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such transfer' USING ERRCODE = 'no_data_found'; END IF;
    IF t.status <> 'in_transit' THEN RAISE EXCEPTION 'Transfer % is % — only one in transit is received', t.number, t.status USING ERRCODE = 'check_violation'; END IF;
    IF t.scope_id = t.to_scope_id THEN cpk := 'storage_area'; cpid := t.from_area_id; ELSE cpk := 'site'; cpid := t.scope_id; END IF;
    PERFORM set_config('ri.balance_writer', 'on', true);
    FOR l IN SELECT * FROM inventory_transfer_lines WHERE transfer_id = p_transfer_id ORDER BY line_no LOOP
        q := COALESCE((SELECT (e->>'qty_base')::numeric FROM jsonb_array_elements(COALESCE(p_received, '[]'::jsonb)) e WHERE (e->>'line_id')::bigint = l.id), l.qty_base);
        UPDATE inventory_transfer_lines SET qty_received_base = q WHERE id = l.id;
        IF q > 0 THEN
            PERFORM ri_post_txn('transfer_in', t.to_scope_id, l.ingredient_id, t.to_area_id, q, l.unit_cost_per_base, 'transfer', p_transfer_id, 'xfer_in:' || l.id, cpk, cpid, NULL, g, p_by);
        END IF;
    END LOOP;
    PERFORM set_config('ri.balance_writer', '', true);
    UPDATE inventory_transfers SET status = 'received', received_by = p_by, received_at = now() WHERE id = p_transfer_id RETURNING * INTO t;
    RETURN t;
END$$;

-- Adjustments: a quantity changes with a reason, no other document.
CREATE TABLE inventory_adjustments (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number           text NOT NULL UNIQUE,
    scope_id         bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    storage_area_id  bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,
    reason_code_id   bigint NOT NULL REFERENCES reason_codes(id) ON DELETE RESTRICT,
    status           text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'cancelled')),
    notes            text,
    posted_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at        timestamptz,
    created_by       bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX inventory_adjustments_site_idx ON inventory_adjustments (scope_id, created_at DESC);
CREATE TRIGGER inventory_adjustments_touch BEFORE UPDATE ON inventory_adjustments FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_adjustments_number BEFORE INSERT ON inventory_adjustments FOR EACH ROW EXECUTE FUNCTION ri_number_document('adjustment');

CREATE TABLE inventory_adjustment_lines (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    adjustment_id   bigint NOT NULL REFERENCES inventory_adjustments(id) ON DELETE CASCADE,
    line_no         integer NOT NULL,
    ingredient_id   bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    qty_delta       numeric(14,4) NOT NULL CHECK (qty_delta <> 0),
    unit_code       text NOT NULL,
    qty_delta_base  numeric(18,4) NOT NULL,
    unit_cost_per_base numeric(18,6) CHECK (unit_cost_per_base IS NULL OR unit_cost_per_base >= 0),   -- an opening balance carries its cost
    note            text,
    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),
    UNIQUE (adjustment_id, line_no)
);
CREATE TRIGGER inventory_adjustment_lines_touch BEFORE UPDATE ON inventory_adjustment_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER inventory_adjustment_lines_draft BEFORE INSERT OR UPDATE OR DELETE ON inventory_adjustment_lines FOR EACH ROW EXECUTE FUNCTION ri_lines_only_while_draft('inventory_adjustments', 'adjustment_id', 'draft');
CREATE OR REPLACE FUNCTION ri_adjustment_lines_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    NEW.qty_delta_base := ri_to_base(NEW.ingredient_id, abs(NEW.qty_delta), NEW.unit_code) * sign(NEW.qty_delta);
    RETURN NEW;
END$$;
CREATE TRIGGER inventory_adjustment_lines_before BEFORE INSERT OR UPDATE ON inventory_adjustment_lines FOR EACH ROW EXECUTE FUNCTION ri_adjustment_lines_before();

CREATE OR REPLACE FUNCTION ri_post_adjustment(p_adjustment_id bigint, p_by bigint) RETURNS inventory_adjustments
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE a inventory_adjustments%ROWTYPE; rc reason_codes%ROWTYPE; l record; g uuid := gen_random_uuid();
BEGIN
    SELECT * INTO a FROM inventory_adjustments WHERE id = p_adjustment_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such adjustment' USING ERRCODE = 'no_data_found'; END IF;
    IF a.status <> 'draft' THEN RAISE EXCEPTION 'Adjustment % is % — only a draft posts', a.number, a.status USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO rc FROM reason_codes WHERE id = a.reason_code_id;
    IF NOT ('adjustment' = ANY (rc.applies_to)) THEN RAISE EXCEPTION 'The reason % is not an adjustment reason', rc.name USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM inventory_adjustment_lines WHERE adjustment_id = p_adjustment_id) THEN RAISE EXCEPTION 'Adjustment % has no lines', a.number USING ERRCODE = 'check_violation'; END IF;
    UPDATE inventory_adjustments SET status = 'posted', posted_by = p_by, posted_at = now() WHERE id = p_adjustment_id RETURNING * INTO a;
    FOR l IN SELECT * FROM inventory_adjustment_lines WHERE adjustment_id = p_adjustment_id ORDER BY line_no LOOP
        PERFORM ri_post_txn('adjustment', a.scope_id, l.ingredient_id, a.storage_area_id, l.qty_delta_base, l.unit_cost_per_base, 'adjustment', p_adjustment_id, 'adjustment:' || l.id,
                            CASE rc.code WHEN 'donation' THEN 'disposal' ELSE 'none' END, NULL, a.reason_code_id, g, p_by, l.note);
        IF l.unit_cost_per_base IS NOT NULL AND l.unit_cost_per_base > 0 AND l.qty_delta_base > 0 THEN
            INSERT INTO price_history (ingredient_id, scope_id, new_per_base, source_kind, reference_kind, reference_id, changed_by, reason)
            VALUES (l.ingredient_id, a.scope_id, l.unit_cost_per_base, 'manual', 'adjustment', p_adjustment_id, p_by, rc.name);
        END IF;
    END LOOP;
    RETURN a;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Production: a prep recipe made in quantity — its lines consumed (production_out), its output received (production_in) at
-- the rolled-up cost of what went in. The cidery's production family, one document.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE production_batches (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text NOT NULL UNIQUE,
    scope_id           bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    recipe_id          bigint NOT NULL REFERENCES recipes(id) ON DELETE RESTRICT,
    recipe_version_id  bigint REFERENCES recipe_versions(id) ON DELETE RESTRICT,     -- the version in force when posted
    batches            numeric(10,4) NOT NULL DEFAULT 1 CHECK (batches > 0),          -- how many batches of the recipe
    output_qty_base    numeric(18,4),                                                 -- what actually came out (NULL = the recipe's yield)
    storage_area_id    bigint NOT NULL REFERENCES storage_areas(id) ON DELETE RESTRICT,   -- where the output lands
    consume_area_id    bigint REFERENCES storage_areas(id) ON DELETE RESTRICT,            -- where the inputs come from (NULL = each ingredient's depletion area)
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'posted', 'cancelled')),
    cost_total         numeric(14,4),
    made_by            bigint REFERENCES members(id) ON DELETE SET NULL,
    made_at            timestamptz NOT NULL DEFAULT now(),
    notes              text,
    posted_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    posted_at          timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX production_batches_site_idx ON production_batches (scope_id, made_at DESC);
CREATE TRIGGER production_batches_touch BEFORE UPDATE ON production_batches FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER production_batches_number BEFORE INSERT ON production_batches FOR EACH ROW EXECUTE FUNCTION ri_number_document('production');

CREATE OR REPLACE FUNCTION ri_post_production(p_batch_id bigint, p_by bigint) RETURNS production_batches
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE b production_batches%ROWTYPE; r recipes%ROWTYPE; v recipe_versions%ROWTYPE; e record; g uuid := gen_random_uuid(); c numeric; tot numeric := 0; outq numeric; area bigint;
BEGIN
    SELECT * INTO b FROM production_batches WHERE id = p_batch_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such batch' USING ERRCODE = 'no_data_found'; END IF;
    IF b.status <> 'draft' THEN RAISE EXCEPTION 'Batch % is % — only a draft posts', b.number, b.status USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO r FROM recipes WHERE id = b.recipe_id;
    IF r.kind <> 'prep' THEN RAISE EXCEPTION 'Only a prep recipe is produced into stock' USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO v FROM recipe_versions WHERE id = COALESCE(b.recipe_version_id, ri_recipe_version_on(b.recipe_id, (b.made_at AT TIME ZONE 'UTC')::date));
    IF v.id IS NULL THEN RAISE EXCEPTION 'No version of % is in force', r.name USING ERRCODE = 'check_violation'; END IF;
    FOR e IN SELECT * FROM ri_explode(v.id, (b.made_at AT TIME ZONE 'UTC')::date, false) LOOP
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

-- ---------------------------------------------------------------------------------------------
-- Waste: Leanpath's shape — stage × reason, station, day part, weight net of tare, cost at the time, disposition. A plate's
-- waste explodes through its recipe. Posted on log (a log is its own document; a review may approve it after).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE waste_logs (
    id               bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number           text NOT NULL UNIQUE,
    scope_id         bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    logged_at        timestamptz NOT NULL DEFAULT now(),
    business_date    date NOT NULL DEFAULT current_date,
    logged_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    station          text,
    day_part_id      bigint REFERENCES day_parts(id) ON DELETE SET NULL,
    stage            text NOT NULL CHECK (stage IN ('ingredient', 'prep', 'plate')),
    reason_code_id   bigint NOT NULL REFERENCES reason_codes(id) ON DELETE RESTRICT,
    ingredient_id    bigint REFERENCES ingredients(id) ON DELETE RESTRICT,
    recipe_id        bigint REFERENCES recipes(id) ON DELETE RESTRICT,            -- plate or prep stage: the recipe wasted
    qty              numeric(14,4) NOT NULL CHECK (qty > 0),
    unit_code        text NOT NULL,                                                -- ingredient: any unit; recipe: 'portion' or 'batch'
    qty_base         numeric(18,4),                                                -- ingredient stage
    gross_weight_g   numeric(12,2),
    tare_g           numeric(12,2),
    storage_area_id  bigint REFERENCES storage_areas(id) ON DELETE SET NULL,
    cost             numeric(14,4),                                                -- at the time, set on post
    disposition      text NOT NULL DEFAULT 'trash' CHECK (disposition IN ('trash', 'compost', 'donation', 'staff_meal', 'comp', 'other')),
    photo_attachment_id bigint,
    notes            text,
    status           text NOT NULL DEFAULT 'posted' CHECK (status IN ('posted', 'reviewed', 'voided')),
    reviewed_by      bigint REFERENCES members(id) ON DELETE SET NULL,
    reviewed_at      timestamptz,
    group_id         uuid,
    created_at       timestamptz NOT NULL DEFAULT now(),
    updated_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((ingredient_id IS NULL) <> (recipe_id IS NULL)),
    CHECK (stage <> 'ingredient' OR ingredient_id IS NOT NULL)
);
CREATE INDEX waste_logs_site_idx ON waste_logs (scope_id, business_date DESC);
CREATE INDEX waste_logs_ingredient_idx ON waste_logs (ingredient_id);
CREATE TRIGGER waste_logs_touch BEFORE UPDATE ON waste_logs FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER waste_logs_number BEFORE INSERT ON waste_logs FOR EACH ROW EXECUTE FUNCTION ri_number_document('waste');

-- Posting a waste log: the transactions. Called by the handler right after the insert (one function so the tool and the
-- screen agree). A recipe's waste: portions × the explosion (menu item/modifier) or batches × the explosion (prep).
CREATE OR REPLACE FUNCTION ri_post_waste(p_waste_id bigint, p_by bigint) RETURNS waste_logs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE w waste_logs%ROWTYPE; rc reason_codes%ROWTYPE; e record; g uuid := gen_random_uuid(); c numeric; tot numeric := 0; vid bigint; v recipe_versions%ROWTYPE; mult numeric; area bigint; q numeric;
BEGIN
    SELECT * INTO w FROM waste_logs WHERE id = p_waste_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such waste log' USING ERRCODE = 'no_data_found'; END IF;
    IF w.group_id IS NOT NULL THEN RAISE EXCEPTION 'Waste % is already posted', w.number USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO rc FROM reason_codes WHERE id = w.reason_code_id;
    IF NOT ('waste' = ANY (rc.applies_to)) THEN RAISE EXCEPTION 'The reason % is not a waste reason', rc.name USING ERRCODE = 'check_violation'; END IF;
    IF w.ingredient_id IS NOT NULL THEN
        q := CASE WHEN w.gross_weight_g IS NOT NULL THEN ri_to_base(w.ingredient_id, w.gross_weight_g - COALESCE(w.tare_g, 0), 'g') ELSE ri_to_base(w.ingredient_id, w.qty, w.unit_code) END;
        c := ri_cost_as_of(w.ingredient_id, w.scope_id);
        area := COALESCE(w.storage_area_id, ri_depletion_area(w.ingredient_id, w.scope_id));
        PERFORM ri_post_txn('waste', w.scope_id, w.ingredient_id, area, -q, c, 'waste', p_waste_id, 'waste:' || p_waste_id, CASE w.disposition WHEN 'donation' THEN 'disposal' ELSE 'none' END, NULL, w.reason_code_id, g, p_by, w.notes, w.logged_at, w.business_date);
        tot := q * c;
        UPDATE waste_logs SET qty_base = q WHERE id = p_waste_id;
    ELSE
        vid := ri_recipe_version_on(w.recipe_id, w.business_date);
        IF vid IS NULL THEN RAISE EXCEPTION 'No version of the recipe is in force on %', w.business_date USING ERRCODE = 'check_violation'; END IF;
        SELECT * INTO v FROM recipe_versions WHERE id = vid;
        mult := CASE WHEN w.unit_code = 'batch' THEN w.qty ELSE w.qty / v.batch_yield_qty END;   -- portions → batches
        FOR e IN SELECT * FROM ri_explode(vid, w.business_date, false) LOOP
            c := ri_cost_as_of(e.ingredient_id, w.scope_id);
            area := COALESCE(w.storage_area_id, ri_depletion_area(e.ingredient_id, w.scope_id));
            PERFORM ri_post_txn('waste', w.scope_id, e.ingredient_id, area, -(e.qty_base * mult), c, 'waste', p_waste_id, 'waste:' || p_waste_id || ':' || e.ingredient_id,
                                'none', NULL, w.reason_code_id, g, p_by, w.notes, w.logged_at, w.business_date);
            tot := tot + e.qty_base * mult * c;
        END LOOP;
    END IF;
    UPDATE waste_logs SET cost = round(tot, 4), group_id = g WHERE id = p_waste_id RETURNING * INTO w;
    RETURN w;
END$$;

CREATE OR REPLACE FUNCTION ri_void_waste(p_waste_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS waste_logs
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE w waste_logs%ROWTYPE; t record;
BEGIN
    SELECT * INTO w FROM waste_logs WHERE id = p_waste_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such waste log' USING ERRCODE = 'no_data_found'; END IF;
    IF w.status = 'voided' THEN RAISE EXCEPTION 'Already voided' USING ERRCODE = 'check_violation'; END IF;
    FOR t IN SELECT id FROM inventory_transactions WHERE group_id = w.group_id AND txn_type = 'waste' LOOP PERFORM ri_reverse_transaction(t.id, p_by, p_note); END LOOP;
    UPDATE waste_logs SET status = 'voided' WHERE id = p_waste_id RETURNING * INTO w;
    RETURN w;
END$$;

COMMIT;
