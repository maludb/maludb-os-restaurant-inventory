-- 006: the things every record carries — ATTACHMENTS and NOTES — plus document NUMBERING and TAX RATES (design §6.3).
--
-- THE ESTATE'S DATA MODEL (design §6.9): GL db/009's `attachments` and `notes` and GL db/006's `document_sequences` and `tax_rates`,
-- copied VERBATIM as Inventory db/005–006 carry them (columns, types, index names); the `record_type` CHECK and the `kind` list are
-- this application's. A file lives under storage/attachments/<record_type>/<record_id>/ and is served through the gate; the records
-- role never sees `storage_path`. reason_codes is NOT here: db/009 (the ledger) creates it beside the documents that use it.
BEGIN;

CREATE TABLE attachments (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL CHECK (record_type IN ('ingredient', 'recipe', 'supplier', 'supplier_item', 'source', 'purchase_order', 'goods_receipt',
                                                       'supplier_invoice', 'inventory_adjustment', 'inventory_count', 'inventory_transfer', 'waste_log',
                                                       'production_batch', 'storage_area', 'yield_test', 'site')),
    record_id     bigint NOT NULL,
    filename      text NOT NULL,
    mime_type     text NOT NULL,
    byte_size     bigint NOT NULL CHECK (byte_size >= 0),
    sha256        text NOT NULL,
    storage_path  text NOT NULL,
    uploaded_by   bigint REFERENCES members(id) ON DELETE SET NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX attachments_record_idx ON attachments (record_type, record_id);
CREATE INDEX attachments_uploaded_by_idx ON attachments (uploaded_by);

CREATE TABLE notes (
    id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    record_type   text NOT NULL,
    record_id     bigint NOT NULL,
    member_id     bigint REFERENCES members(id) ON DELETE SET NULL,
    body          text NOT NULL,
    created_at    timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX notes_record_idx ON notes (record_type, record_id, created_at DESC);
CREATE INDEX notes_member_idx ON notes (member_id);

GRANT SELECT, INSERT, UPDATE, DELETE ON attachments, notes TO restaurant_inventory_rw;

-- ---------------------------------------------------------------------------------------------
-- Numbering (GL db/006 verbatim; the kind list ours — design §6.3).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE document_sequences (
    kind        text PRIMARY KEY CHECK (kind IN ('purchase_order', 'goods_receipt', 'adjustment', 'transfer', 'count', 'waste', 'production', 'invoice')),
    prefix      text NOT NULL CHECK (prefix ~ '^[A-Z][A-Z0-9]{0,7}-?$'),
    next_value  bigint NOT NULL DEFAULT 1 CHECK (next_value > 0),
    padding     smallint NOT NULL DEFAULT 5 CHECK (padding BETWEEN 1 AND 12),
    updated_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO document_sequences (kind, prefix, padding) VALUES
    ('purchase_order', 'PO-', 5), ('goods_receipt', 'GR-', 5), ('adjustment', 'ADJ-', 5), ('transfer', 'TR-', 5),
    ('count', 'CNT-', 5), ('waste', 'WST-', 5), ('production', 'PRD-', 5), ('invoice', 'INV-', 5)
ON CONFLICT DO NOTHING;

-- Row lock via UPDATE ... RETURNING: concurrent callers serialize; a rolled-back caller returns the number.
CREATE OR REPLACE FUNCTION next_document_number(p_kind text) RETURNS text
    LANGUAGE sql SECURITY DEFINER SET search_path = public AS $$
    UPDATE document_sequences
       SET next_value = next_value + 1, updated_at = now()
     WHERE kind = p_kind
    RETURNING prefix || lpad((next_value - 1)::text, padding, '0');
$$;
REVOKE ALL ON FUNCTION next_document_number(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION next_document_number(text) TO restaurant_inventory_rw;

-- A document's number at creation, by trigger: NEW.number NULL → the sequence's next (one generic trigger, the kind by argument):
--   CREATE TRIGGER purchase_orders_number BEFORE INSERT ON purchase_orders FOR EACH ROW EXECUTE FUNCTION ri_number_document('purchase_order');
CREATE OR REPLACE FUNCTION ri_number_document() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF NEW.number IS NULL OR NEW.number = '' THEN
        NEW.number := next_document_number(TG_ARGV[0]);
    END IF;
    RETURN NEW;
END$$;

-- ---------------------------------------------------------------------------------------------
-- Tax rates (GL db/006 verbatim: a percentage; rounded per line, never on the document total).
-- ---------------------------------------------------------------------------------------------
CREATE TABLE tax_rates (
    id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name         text NOT NULL,
    rate         numeric(7,4) NOT NULL CHECK (rate >= 0 AND rate < 100),
    is_default   boolean NOT NULL DEFAULT false,
    archived_at  timestamptz,
    created_at   timestamptz NOT NULL DEFAULT now(),
    updated_at   timestamptz NOT NULL DEFAULT now()
);
CREATE UNIQUE INDEX tax_rates_name_live ON tax_rates (lower(name)) WHERE archived_at IS NULL;
CREATE UNIQUE INDEX tax_rates_one_default ON tax_rates ((true)) WHERE is_default AND archived_at IS NULL;
CREATE TRIGGER tax_rates_touch BEFORE UPDATE ON tax_rates FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

GRANT SELECT, INSERT, UPDATE ON document_sequences, tax_rates TO restaurant_inventory_rw;

COMMIT;
