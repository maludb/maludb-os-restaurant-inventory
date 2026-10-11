-- 022 — slice 3 (the ledger, ledger.md), found while building: an adjustment line's cost is an OPENING balance's cost, so it belongs on an
-- increase. ri_post_adjustment() hands each line's cost to its movement, and ri_transactions_before() fills the running average only for a NULL —
-- so a cost typed on a DECREASE valued that movement at the typed cost instead of the running average (the period's usage and the value
-- report sum what the movements say). Refused here in words; additive (a function and a trigger), nothing existing changes.
BEGIN;
SET LOCAL client_min_messages = warning;

CREATE OR REPLACE FUNCTION ri_adjustment_line_cost() RETURNS trigger
    LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.unit_cost_per_base IS NOT NULL AND NEW.qty_delta < 0 THEN
        RAISE EXCEPTION 'A decrease leaves at the running average — a cost is entered only on an increase' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END$$;

DROP TRIGGER IF EXISTS inventory_adjustment_lines_cost ON inventory_adjustment_lines;
CREATE TRIGGER inventory_adjustment_lines_cost BEFORE INSERT OR UPDATE ON inventory_adjustment_lines FOR EACH ROW EXECUTE FUNCTION ri_adjustment_line_cost();

COMMIT;
