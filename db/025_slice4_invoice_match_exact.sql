-- 025: SLICE 4 (purchasing) found that the three-way match compared the invoice's price per base unit with the receipt's STORED cost_per_base, which the
-- column keeps to six decimals. For a cheap-per-gram ingredient (flour at $30 a 50-lb bag is $0.001323 a gram) six decimals are three significant figures,
-- so two lines at EXACTLY the same price per bag differed by a few cents ("price differs by -$0.02") and a $3-a-bag move over two bags read $5.99, not $6.00.
-- The receipt line also keeps the price it was received at, per its own unit (unit_cost, per weight unit for a catch-weight line): the match now divides THAT
-- by the unit's factor and falls back to cost_per_base only for a line received at the running cost (unit_cost NULL). Everything else in ri_invoice_match()
-- is db/010's, unchanged: the tolerances, the states, the dispute. Additive — a CREATE OR REPLACE of one function's body; nothing is dropped.
BEGIN;

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
            rec_cost := COALESCE(CASE WHEN rl.unit_cost IS NOT NULL THEN rl.unit_cost / ri_to_base(rl.ingredient_id, 1, CASE WHEN rl.weight_qty IS NOT NULL THEN rl.weight_unit_code ELSE rl.purchase_unit_code END) END,
                                 rl.cost_per_base, inv_cost);
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

COMMIT;
