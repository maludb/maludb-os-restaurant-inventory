-- 010: PURCHASING — purchase orders with the suggested order's arithmetic on every line, the supplier's events and secure
-- door, receiving against a PO; SUPPLIER INVOICES and the three-way match; PRICE ALERTS (design §6.4).
--
-- THE ESTATE'S DATA MODEL (design §6.9): `purchase_orders`, `purchase_order_lines`, `purchase_order_events`,
-- `supplier_links_secure` REUSE Inventory's canonical purchase order without the drop-ship half (`kind`, `sales_order_id`,
-- the ship-to snapshot omitted — a kitchen's PO ships to its site), measured quantities in the supplier item's purchase
-- unit, `scope_id`, and the need / have / on-order / cap / rounding columns APPENDED so the screen shows the arithmetic.
-- `supplier_invoices(+lines)` and `price_alerts` are new (the GL's `bills` are the payable, fed through the share).
--
-- THE REFEREE'S RULES: a PO is sent or placed BY A PERSON (money_out pauses an agent); a receipt against a PO advances
-- qty_received on its lines and the PO's status; an invoice line matches a receipt line within the supplier's tolerance or
-- is a difference a person settles; a settled price difference writes price history on the receipt's date; a credit
-- reverses on the original date.
BEGIN;

CREATE TABLE purchase_orders (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number              text NOT NULL UNIQUE,
    scope_id            bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    supplier_id         bigint NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
    status              text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'sent', 'acknowledged', 'partial', 'received', 'closed', 'closed_short', 'cancelled')),
    origin              text NOT NULL DEFAULT 'manual' CHECK (origin IN ('manual', 'suggested', 'agent', 'requisition')),
    storage_area_id     bigint REFERENCES storage_areas(id) ON DELETE SET NULL,   -- where it will be received
    ordered_on          date NOT NULL DEFAULT current_date,
    cutoff_at           timestamptz,                                            -- the schedule it was drafted for
    expected_on         date,
    supplier_order_ref  text,
    sent_via            text CHECK (sent_via IS NULL OR sent_via IN ('email', 'portal', 'api', 'edi', 'phone')),
    sent_at             timestamptz,
    sent_by             bigint REFERENCES members(id) ON DELETE SET NULL,
    acknowledged_at     timestamptz,
    subtotal            numeric(12,2) NOT NULL DEFAULT 0,
    total               numeric(12,2) NOT NULL DEFAULT 0,
    notes               text,                                                   -- to the supplier (on the door)
    internal_notes      text,
    closed_at           timestamptz,
    cancelled_by        bigint REFERENCES members(id) ON DELETE SET NULL,
    cancelled_at        timestamptz,
    cancel_reason       text,
    created_by          bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX purchase_orders_site_idx ON purchase_orders (scope_id, status, ordered_on DESC);
CREATE INDEX purchase_orders_supplier_idx ON purchase_orders (supplier_id, ordered_on DESC);
CREATE INDEX purchase_orders_expected_idx ON purchase_orders (expected_on) WHERE status IN ('sent', 'acknowledged', 'partial');
CREATE TRIGGER purchase_orders_touch BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER purchase_orders_number BEFORE INSERT ON purchase_orders FOR EACH ROW EXECUTE FUNCTION ri_number_document('purchase_order');

CREATE TABLE purchase_order_lines (
    id                  bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id   bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    line_no             integer NOT NULL,
    ingredient_id       bigint NOT NULL REFERENCES ingredients(id) ON DELETE RESTRICT,
    supplier_item_id    bigint REFERENCES supplier_items(id) ON DELETE SET NULL,
    supplier_sku        text,
    purchase_unit_code  text NOT NULL,
    qty_ordered         numeric(14,4) NOT NULL CHECK (qty_ordered > 0),           -- in purchase units
    qty_ordered_base    numeric(18,4) NOT NULL,
    unit_cost           numeric(14,4) NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),  -- per purchase unit
    qty_received_base   numeric(18,4) NOT NULL DEFAULT 0 CHECK (qty_received_base >= 0),
    expected_on         date,
    status              text NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'acknowledged', 'declined', 'partial', 'received', 'closed_short', 'cancelled')),
    supplier_note       text,
    -- the suggested order's arithmetic (design §6.8), kept so the screen shows it
    need_base           numeric(18,4),
    have_base           numeric(18,4),
    on_order_base       numeric(18,4),
    cap_base            numeric(18,4),
    safety_base         numeric(18,4),
    raw_base            numeric(18,4),
    rounding_note       text,
    created_at          timestamptz NOT NULL DEFAULT now(),
    updated_at          timestamptz NOT NULL DEFAULT now(),
    UNIQUE (purchase_order_id, line_no)
);
CREATE INDEX purchase_order_lines_ingredient_idx ON purchase_order_lines (ingredient_id, status);
CREATE TRIGGER purchase_order_lines_touch BEFORE UPDATE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_po_lines_before() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE st text;
BEGIN
    SELECT status INTO st FROM purchase_orders WHERE id = COALESCE(NEW.purchase_order_id, OLD.purchase_order_id);
    IF st <> 'draft' THEN
        -- after sending, only what the supplier and the receipts write changes: status, received quantity, note, date
        IF TG_OP = 'UPDATE' AND NEW.qty_ordered = OLD.qty_ordered AND NEW.unit_cost = OLD.unit_cost AND NEW.ingredient_id = OLD.ingredient_id AND NEW.purchase_unit_code = OLD.purchase_unit_code
           AND NEW.supplier_item_id IS NOT DISTINCT FROM OLD.supplier_item_id AND NEW.line_no = OLD.line_no THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'The lines of a purchase order change only while it is a draft' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    NEW.qty_ordered_base := ri_to_base(NEW.ingredient_id, NEW.qty_ordered, NEW.purchase_unit_code);
    RETURN NEW;
END$$;
CREATE TRIGGER purchase_order_lines_before BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION ri_po_lines_before();
CREATE OR REPLACE FUNCTION ri_po_totals(p_po_id bigint) RETURNS void
    LANGUAGE sql AS $$
    UPDATE purchase_orders p SET subtotal = s.t, total = s.t FROM (SELECT COALESCE(round(sum(qty_ordered * unit_cost), 2), 0) AS t FROM purchase_order_lines WHERE purchase_order_id = p_po_id) s WHERE p.id = p_po_id
$$;
CREATE OR REPLACE FUNCTION ri_po_lines_after() RETURNS trigger
    LANGUAGE plpgsql AS $$ BEGIN PERFORM ri_po_totals(COALESCE(NEW.purchase_order_id, OLD.purchase_order_id)); RETURN NULL; END$$;
CREATE TRIGGER purchase_order_lines_after AFTER INSERT OR UPDATE OR DELETE ON purchase_order_lines FOR EACH ROW EXECUTE FUNCTION ri_po_lines_after();

ALTER TABLE goods_receipts ADD CONSTRAINT goods_receipts_po_fk FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL;
ALTER TABLE goods_receipt_lines ADD CONSTRAINT goods_receipt_lines_po_line_fk FOREIGN KEY (purchase_order_line_id) REFERENCES purchase_order_lines(id) ON DELETE SET NULL;

CREATE TABLE purchase_order_events (
    id                      bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id       bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    purchase_order_line_id  bigint REFERENCES purchase_order_lines(id) ON DELETE CASCADE,
    kind                    text NOT NULL CHECK (kind IN ('sent', 'placed', 'acknowledge', 'decline', 'date', 'received', 'note', 'cancelled')),
    source                  text NOT NULL DEFAULT 'manual' CHECK (source IN ('door', 'manual', 'email', 'phone', 'agent')),
    supplier_order_ref      text,
    expected_on             date,
    reason                  text,
    note                    text,
    member_id               bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at              timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX purchase_order_events_po_idx ON purchase_order_events (purchase_order_id, created_at);

CREATE TABLE supplier_links_secure (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    purchase_order_id  bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE,
    token_hash         text NOT NULL UNIQUE,
    expires_at         timestamptz,
    rotated_at         timestamptz,
    last_used_at       timestamptz,
    view_count         integer NOT NULL DEFAULT 0,
    created_at         timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX supplier_links_secure_po_idx ON supplier_links_secure (purchase_order_id) WHERE rotated_at IS NULL;
CREATE OR REPLACE FUNCTION ri_supplier_link_mint(p_po_id bigint) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE raw text;
BEGIN
    raw := encode(gen_random_bytes(24), 'hex');
    UPDATE supplier_links_secure SET rotated_at = now() WHERE purchase_order_id = p_po_id AND rotated_at IS NULL;
    INSERT INTO supplier_links_secure (purchase_order_id, token_hash, expires_at) VALUES (p_po_id, encode(sha256(raw::bytea), 'hex'), now() + interval '60 days');
    RETURN raw;
END$$;
CREATE OR REPLACE FUNCTION ri_supplier_link_resolve(p_raw text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE pid bigint;
BEGIN
    UPDATE supplier_links_secure SET last_used_at = now(), view_count = view_count + 1
     WHERE token_hash = encode(sha256(p_raw::bytea), 'hex') AND rotated_at IS NULL AND (expires_at IS NULL OR expires_at > now()) RETURNING purchase_order_id INTO pid;
    RETURN pid;
END$$;

CREATE OR REPLACE FUNCTION ri_po_send(p_po_id bigint, p_by bigint, p_via text, p_ref text DEFAULT NULL) RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO p FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such purchase order' USING ERRCODE = 'no_data_found'; END IF;
    IF p.status <> 'draft' THEN RAISE EXCEPTION 'Purchase order % is % — only a draft is sent', p.number, p.status USING ERRCODE = 'check_violation'; END IF;
    IF NOT EXISTS (SELECT 1 FROM purchase_order_lines WHERE purchase_order_id = p_po_id) THEN RAISE EXCEPTION 'Purchase order % has no lines', p.number USING ERRCODE = 'check_violation'; END IF;
    UPDATE purchase_orders SET status = 'sent', sent_via = p_via, sent_at = now(), sent_by = p_by, supplier_order_ref = COALESCE(p_ref, supplier_order_ref) WHERE id = p_po_id RETURNING * INTO p;
    INSERT INTO purchase_order_events (purchase_order_id, kind, source, supplier_order_ref, member_id) VALUES (p_po_id, CASE WHEN p_via IN ('portal', 'phone') THEN 'placed' ELSE 'sent' END, 'manual', p_ref, p_by);
    RETURN p;
END$$;

CREATE OR REPLACE FUNCTION ri_po_supplier_event(p_po_id bigint, p_kind text, p_line_id bigint DEFAULT NULL, p_expected_on date DEFAULT NULL, p_reason text DEFAULT NULL, p_ref text DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO p FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF p.status NOT IN ('sent', 'acknowledged', 'partial') THEN RAISE EXCEPTION 'Purchase order % is %', p.number, p.status USING ERRCODE = 'check_violation'; END IF;
    INSERT INTO purchase_order_events (purchase_order_id, purchase_order_line_id, kind, source, expected_on, reason, supplier_order_ref) VALUES (p_po_id, p_line_id, p_kind, 'door', p_expected_on, p_reason, p_ref);
    IF p_kind = 'acknowledge' THEN
        UPDATE purchase_orders SET status = CASE WHEN status = 'sent' THEN 'acknowledged' ELSE status END, acknowledged_at = COALESCE(acknowledged_at, now()),
               expected_on = COALESCE(p_expected_on, expected_on), supplier_order_ref = COALESCE(p_ref, supplier_order_ref) WHERE id = p_po_id;
        UPDATE purchase_order_lines SET status = 'acknowledged' WHERE purchase_order_id = p_po_id AND status = 'open' AND (p_line_id IS NULL OR id = p_line_id);
    ELSIF p_kind = 'decline' THEN
        UPDATE purchase_order_lines SET status = 'declined', supplier_note = p_reason WHERE purchase_order_id = p_po_id AND id = p_line_id;
    ELSIF p_kind = 'date' THEN
        UPDATE purchase_orders SET expected_on = p_expected_on WHERE id = p_po_id;
        UPDATE purchase_order_lines SET expected_on = p_expected_on WHERE purchase_order_id = p_po_id AND (p_line_id IS NULL OR id = p_line_id);
    END IF;
END$$;

-- Receiving against a PO: the receipt's lines name the PO's lines; posting advances them and the PO's status.
CREATE OR REPLACE FUNCTION ri_receipt_posted_hook(p_receipt_id bigint) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE r goods_receipts%ROWTYPE; l record; n_open integer; n_partial integer;
BEGIN
    SELECT * INTO r FROM goods_receipts WHERE id = p_receipt_id;
    IF r.purchase_order_id IS NULL THEN RETURN; END IF;
    PERFORM set_config('ri.balance_writer', 'on', true);
    FOR l IN SELECT * FROM goods_receipt_lines WHERE goods_receipt_id = p_receipt_id AND purchase_order_line_id IS NOT NULL LOOP
        UPDATE purchase_order_lines SET qty_received_base = qty_received_base + l.qty_base,
               status = CASE WHEN qty_received_base + l.qty_base >= qty_ordered_base * 0.999 THEN 'received' ELSE 'partial' END
         WHERE id = l.purchase_order_line_id;
    END LOOP;
    PERFORM set_config('ri.balance_writer', '', true);
    INSERT INTO purchase_order_events (purchase_order_id, kind, source, note, member_id) VALUES (r.purchase_order_id, 'received', 'manual', 'receipt ' || r.number, r.posted_by);
    SELECT count(*) FILTER (WHERE status IN ('open', 'acknowledged')), count(*) FILTER (WHERE status = 'partial') INTO n_open, n_partial
      FROM purchase_order_lines WHERE purchase_order_id = r.purchase_order_id AND status NOT IN ('declined', 'cancelled');
    UPDATE purchase_orders SET status = CASE WHEN n_open = 0 AND n_partial = 0 THEN 'received' ELSE 'partial' END WHERE id = r.purchase_order_id AND status IN ('sent', 'acknowledged', 'partial');
END$$;

CREATE OR REPLACE FUNCTION ri_po_close(p_po_id bigint, p_by bigint, p_short boolean DEFAULT false) RETURNS purchase_orders
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE p purchase_orders%ROWTYPE;
BEGIN
    SELECT * INTO p FROM purchase_orders WHERE id = p_po_id FOR UPDATE;
    IF p.status NOT IN ('received', 'partial', 'acknowledged', 'sent') THEN RAISE EXCEPTION 'Purchase order % is %', p.number, p.status USING ERRCODE = 'check_violation'; END IF;
    PERFORM set_config('ri.balance_writer', 'on', true);
    UPDATE purchase_order_lines SET status = 'closed_short' WHERE purchase_order_id = p_po_id AND status IN ('open', 'acknowledged', 'partial');
    PERFORM set_config('ri.balance_writer', '', true);
    UPDATE purchase_orders SET status = CASE WHEN p_short OR p.status <> 'received' THEN 'closed_short' ELSE 'closed' END, closed_at = now() WHERE id = p_po_id RETURNING * INTO p;
    RETURN p;
END$$;

-- On order: what is still to come of an ingredient at a site, due on or before a date (the suggested order's "have").
CREATE OR REPLACE FUNCTION ri_on_order(p_ingredient_id bigint, p_scope_id bigint, p_due_by date DEFAULT NULL) RETURNS numeric
    LANGUAGE sql STABLE AS $$
    SELECT COALESCE(sum(greatest(l.qty_ordered_base - l.qty_received_base, 0)), 0)
      FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.purchase_order_id
     WHERE l.ingredient_id = p_ingredient_id AND p.scope_id = p_scope_id AND p.status IN ('sent', 'acknowledged', 'partial')
       AND l.status IN ('open', 'acknowledged', 'partial') AND (p_due_by IS NULL OR COALESCE(l.expected_on, p.expected_on, p.ordered_on + 1) <= p_due_by)
$$;

-- ---------------------------------------------------------------------------------------------
-- Supplier invoices and the three-way match.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE supplier_invoices (
    id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    number             text NOT NULL UNIQUE,                                     -- ours
    scope_id           bigint NOT NULL REFERENCES sites(scope_id) ON DELETE RESTRICT,
    supplier_id        bigint NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT,
    invoice_no         text NOT NULL,                                            -- theirs
    kind               text NOT NULL DEFAULT 'invoice' CHECK (kind IN ('invoice', 'credit')),
    invoice_date       date NOT NULL,
    received_on        date NOT NULL DEFAULT current_date,
    purchase_order_id  bigint REFERENCES purchase_orders(id) ON DELETE SET NULL,
    goods_receipt_id   bigint REFERENCES goods_receipts(id) ON DELETE SET NULL,
    subtotal           numeric(12,2) NOT NULL DEFAULT 0,
    tax                numeric(12,2) NOT NULL DEFAULT 0,
    total              numeric(12,2) NOT NULL DEFAULT 0,
    status             text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'matched', 'disputed', 'settled', 'void')),
    attachment_id      bigint,
    notes              text,
    settled_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    settled_at         timestamptz,
    created_by         bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at         timestamptz NOT NULL DEFAULT now(),
    updated_at         timestamptz NOT NULL DEFAULT now(),
    UNIQUE (supplier_id, invoice_no, kind)
);
CREATE INDEX supplier_invoices_site_idx ON supplier_invoices (scope_id, invoice_date DESC);
CREATE INDEX supplier_invoices_status_idx ON supplier_invoices (status);
CREATE TRIGGER supplier_invoices_touch BEFORE UPDATE ON supplier_invoices FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE TRIGGER supplier_invoices_number BEFORE INSERT ON supplier_invoices FOR EACH ROW EXECUTE FUNCTION ri_number_document('invoice');

CREATE TABLE supplier_invoice_lines (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    invoice_id        bigint NOT NULL REFERENCES supplier_invoices(id) ON DELETE CASCADE,
    line_no           integer NOT NULL,
    supplier_item_id  bigint REFERENCES supplier_items(id) ON DELETE SET NULL,
    ingredient_id     bigint REFERENCES ingredients(id) ON DELETE SET NULL,
    description       text,
    qty               numeric(14,4) NOT NULL CHECK (qty > 0),
    unit_code         text NOT NULL,
    unit_price        numeric(14,4) NOT NULL CHECK (unit_price >= 0),
    line_total        numeric(12,2) GENERATED ALWAYS AS (round(qty * unit_price, 2)) STORED,
    receipt_line_id   bigint REFERENCES goods_receipt_lines(id) ON DELETE SET NULL,
    match_state       text NOT NULL DEFAULT 'unmatched' CHECK (match_state IN ('unmatched', 'ok', 'qty_diff', 'price_diff', 'both_diff', 'unordered', 'settled')),
    diff_qty          numeric(14,4),
    diff_value        numeric(12,2),
    settle_note       text,
    created_at        timestamptz NOT NULL DEFAULT now(),
    updated_at        timestamptz NOT NULL DEFAULT now(),
    UNIQUE (invoice_id, line_no)
);
CREATE TRIGGER supplier_invoice_lines_touch BEFORE UPDATE ON supplier_invoice_lines FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
CREATE OR REPLACE FUNCTION ri_invoice_totals(p_invoice_id bigint) RETURNS void
    LANGUAGE sql AS $$
    UPDATE supplier_invoices i SET subtotal = s.t, total = s.t + i.tax FROM (SELECT COALESCE(sum(line_total), 0) AS t FROM supplier_invoice_lines WHERE invoice_id = p_invoice_id) s WHERE i.id = p_invoice_id
$$;
CREATE OR REPLACE FUNCTION ri_invoice_lines_after() RETURNS trigger
    LANGUAGE plpgsql AS $$ BEGIN PERFORM ri_invoice_totals(COALESCE(NEW.invoice_id, OLD.invoice_id)); RETURN NULL; END$$;
CREATE TRIGGER supplier_invoice_lines_after AFTER INSERT OR UPDATE OR DELETE ON supplier_invoice_lines FOR EACH ROW EXECUTE FUNCTION ri_invoice_lines_after();

-- The match: each invoice line against its receipt line (named, or found by supplier item on the invoice's receipt), within
-- the supplier's tolerances; the invoice is matched when every line is ok, disputed when any differs.
CREATE OR REPLACE FUNCTION ri_invoice_match(p_invoice_id bigint) RETURNS supplier_invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i supplier_invoices%ROWTYPE; s suppliers%ROWTYPE; l record; rl goods_receipt_lines%ROWTYPE; qd numeric; pd numeric; st text; n_bad integer := 0; inv_base numeric; rec_cost numeric; inv_cost numeric;
BEGIN
    SELECT * INTO i FROM supplier_invoices WHERE id = p_invoice_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such invoice' USING ERRCODE = 'no_data_found'; END IF;
    IF i.status IN ('settled', 'void') THEN RAISE EXCEPTION 'Invoice % is %', i.number, i.status USING ERRCODE = 'check_violation'; END IF;
    SELECT * INTO s FROM suppliers WHERE id = i.supplier_id;
    FOR l IN SELECT * FROM supplier_invoice_lines WHERE invoice_id = p_invoice_id ORDER BY line_no LOOP
        rl := NULL;
        IF l.receipt_line_id IS NOT NULL THEN SELECT * INTO rl FROM goods_receipt_lines WHERE id = l.receipt_line_id;
        ELSIF i.goods_receipt_id IS NOT NULL AND l.supplier_item_id IS NOT NULL THEN
            SELECT * INTO rl FROM goods_receipt_lines WHERE goods_receipt_id = i.goods_receipt_id AND supplier_item_id = l.supplier_item_id
               AND id NOT IN (SELECT receipt_line_id FROM supplier_invoice_lines WHERE invoice_id = p_invoice_id AND receipt_line_id IS NOT NULL AND id <> l.id) LIMIT 1;
        END IF;
        IF rl.id IS NULL THEN
            st := 'unordered'; qd := NULL; pd := NULL; n_bad := n_bad + 1;
        ELSE
            inv_base := ri_to_base(rl.ingredient_id, l.qty, l.unit_code);
            qd := inv_base - rl.qty_base;
            inv_cost := l.unit_price / ri_to_base(rl.ingredient_id, 1, l.unit_code);        -- per base
            rec_cost := COALESCE(rl.cost_per_base, inv_cost);
            pd := round((inv_cost - rec_cost) * inv_base, 2);
            st := CASE WHEN abs(qd) > rl.qty_base * s.match_tolerance_pct / 100.0 AND abs(pd) > s.match_tolerance_value THEN 'both_diff'
                       WHEN abs(qd) > rl.qty_base * s.match_tolerance_pct / 100.0 THEN 'qty_diff'
                       WHEN abs(pd) > s.match_tolerance_value AND abs(inv_cost - rec_cost) > rec_cost * s.match_tolerance_pct / 100.0 THEN 'price_diff'
                       ELSE 'ok' END;
            IF st <> 'ok' THEN n_bad := n_bad + 1; END IF;
        END IF;
        UPDATE supplier_invoice_lines SET receipt_line_id = rl.id, ingredient_id = COALESCE(l.ingredient_id, rl.ingredient_id), match_state = st, diff_qty = qd, diff_value = pd WHERE id = l.id;
    END LOOP;
    UPDATE supplier_invoices SET status = CASE WHEN n_bad = 0 THEN 'matched' ELSE 'disputed' END WHERE id = p_invoice_id RETURNING * INTO i;
    RETURN i;
END$$;

-- Settling: a person accepts the invoice as the truth — a price difference writes price history on the receipt's date (and
-- the supplier item's last price); a quantity difference posts a receipt correction (an adjustment with reason data_entry);
-- a credit note reverses value on the original date. Pauses for an agent (design §5).
CREATE OR REPLACE FUNCTION ri_invoice_settle(p_invoice_id bigint, p_by bigint, p_note text DEFAULT NULL) RETURNS supplier_invoices
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE i supplier_invoices%ROWTYPE; l record; rl goods_receipt_lines%ROWTYPE; r goods_receipts%ROWTYPE; inv_cost numeric; g uuid := gen_random_uuid(); rc bigint;
BEGIN
    SELECT * INTO i FROM supplier_invoices WHERE id = p_invoice_id FOR UPDATE;
    IF NOT FOUND THEN RAISE EXCEPTION 'No such invoice' USING ERRCODE = 'no_data_found'; END IF;
    IF i.status NOT IN ('matched', 'disputed') THEN RAISE EXCEPTION 'Invoice % is % — match it first', i.number, i.status USING ERRCODE = 'check_violation'; END IF;
    SELECT id INTO rc FROM reason_codes WHERE code = 'data_entry';
    FOR l IN SELECT * FROM supplier_invoice_lines WHERE invoice_id = p_invoice_id AND match_state IN ('price_diff', 'qty_diff', 'both_diff') LOOP
        SELECT * INTO rl FROM goods_receipt_lines WHERE id = l.receipt_line_id;
        SELECT * INTO r FROM goods_receipts WHERE id = rl.goods_receipt_id;
        inv_cost := round(l.unit_price / ri_to_base(rl.ingredient_id, 1, l.unit_code), 6);
        IF l.match_state IN ('price_diff', 'both_diff') AND i.kind = 'invoice' THEN
            INSERT INTO price_history (ingredient_id, scope_id, old_per_base, new_per_base, per_unit_code, per_unit_price, source_kind, reference_kind, reference_id, changed_by, changed_at, reason)
            VALUES (rl.ingredient_id, i.scope_id, rl.cost_per_base, inv_cost, l.unit_code, l.unit_price, 'invoice', 'invoice', p_invoice_id, p_by, r.received_at, 'invoice ' || i.invoice_no || ' settled');
            IF l.supplier_item_id IS NOT NULL THEN UPDATE supplier_items SET last_price = l.unit_price, last_price_at = r.received_at WHERE id = l.supplier_item_id; END IF;
        END IF;
        IF l.match_state IN ('qty_diff', 'both_diff') AND l.diff_qty <> 0 THEN
            PERFORM ri_post_txn(CASE WHEN i.kind = 'credit' THEN 'adjustment' ELSE 'adjustment' END, i.scope_id, rl.ingredient_id, COALESCE(rl.putaway_area_id, r.storage_area_id),
                                CASE WHEN i.kind = 'credit' THEN -abs(l.diff_qty) ELSE l.diff_qty END, inv_cost, 'invoice', p_invoice_id, 'invoice:' || l.id, 'supplier', i.supplier_id, rc, g, p_by,
                                COALESCE(p_note, 'settled against invoice ' || i.invoice_no), r.received_at, r.received_on);
        END IF;
        UPDATE supplier_invoice_lines SET match_state = 'settled', settle_note = p_note WHERE id = l.id;
    END LOOP;
    UPDATE supplier_invoices SET status = 'settled', settled_by = p_by, settled_at = now() WHERE id = p_invoice_id RETURNING * INTO i;
    RETURN i;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Price alerts: a cost that moved more than the threshold (the category's, else the setting), raised by price history.
-- ---------------------------------------------------------------------------------------------
CREATE TABLE price_alerts (
    id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    scope_id          bigint REFERENCES sites(scope_id) ON DELETE CASCADE,
    ingredient_id     bigint NOT NULL REFERENCES ingredients(id) ON DELETE CASCADE,
    supplier_item_id  bigint REFERENCES supplier_items(id) ON DELETE SET NULL,
    old_per_base      numeric(18,6) NOT NULL,
    new_per_base      numeric(18,6) NOT NULL,
    pct               numeric(8,2) NOT NULL,
    price_history_id  bigint REFERENCES price_history(id) ON DELETE CASCADE,
    raised_at         timestamptz NOT NULL DEFAULT now(),
    seen_by           bigint REFERENCES members(id) ON DELETE SET NULL,
    seen_at           timestamptz
);
CREATE INDEX price_alerts_open_idx ON price_alerts (scope_id, raised_at DESC) WHERE seen_at IS NULL;
CREATE OR REPLACE FUNCTION ri_price_history_alert() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE thr numeric; pct numeric;
BEGIN
    IF NEW.old_per_base IS NULL OR NEW.old_per_base = 0 OR NEW.source_kind NOT IN ('receipt', 'invoice', 'contract', 'import') THEN RETURN NEW; END IF;
    SELECT COALESCE(c.price_alert_pct, (SELECT price_alert_pct FROM ri_settings WHERE id = 1), 10) INTO thr FROM ingredients i JOIN ingredient_categories c ON c.id = i.category_id WHERE i.id = NEW.ingredient_id;
    pct := round((NEW.new_per_base - NEW.old_per_base) / NEW.old_per_base * 100, 2);
    IF abs(pct) >= thr THEN
        INSERT INTO price_alerts (scope_id, ingredient_id, old_per_base, new_per_base, pct, price_history_id, raised_at) VALUES (NEW.scope_id, NEW.ingredient_id, NEW.old_per_base, NEW.new_per_base, pct, NEW.id, NEW.changed_at);
    END IF;
    RETURN NEW;
END$$;
CREATE TRIGGER price_history_alert AFTER INSERT ON price_history FOR EACH ROW EXECUTE FUNCTION ri_price_history_alert();

COMMIT;
