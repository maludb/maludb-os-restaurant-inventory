-- 026: the price alert's percentage from the PRICES THE SUPPLIER QUOTED (2026-10-11, found by slice 4's worker; fixed by the planning model).
-- ri_price_history_alert() compared old_per_base and new_per_base, each stored to six decimals per base unit: a $30 → $33 bag of flour is
-- $0.001323 → $0.001455 a gram, which reads 9.98 %, so the default 10 % alert never fired on a 10 % rise (db/025 fixed the same rounding in
-- the invoice match). The alert now takes the previous price-history row's per_unit_price when it was quoted in the SAME unit as this one
-- (a bag against a bag, a pound against a pound) and falls back to the per-base figures only across units. Additive: a function body replaced.
BEGIN;

CREATE OR REPLACE FUNCTION ri_price_history_alert() RETURNS trigger
    LANGUAGE plpgsql AS $$
DECLARE thr numeric; pct numeric; prev price_history%ROWTYPE;
BEGIN
    IF NEW.old_per_base IS NULL OR NEW.old_per_base = 0 OR NEW.source_kind NOT IN ('receipt', 'invoice', 'contract', 'import') THEN RETURN NEW; END IF;
    SELECT COALESCE(c.price_alert_pct, (SELECT price_alert_pct FROM ri_settings WHERE id = 1), 10) INTO thr FROM ingredients i JOIN ingredient_categories c ON c.id = i.category_id WHERE i.id = NEW.ingredient_id;
    SELECT * INTO prev FROM price_history
     WHERE ingredient_id = NEW.ingredient_id AND scope_id IS NOT DISTINCT FROM NEW.scope_id AND id <> NEW.id AND changed_at <= NEW.changed_at
     ORDER BY changed_at DESC, id DESC LIMIT 1;
    IF prev.id IS NOT NULL AND prev.per_unit_code IS NOT DISTINCT FROM NEW.per_unit_code AND COALESCE(prev.per_unit_price, 0) > 0 AND NEW.per_unit_price IS NOT NULL THEN
        pct := round((NEW.per_unit_price - prev.per_unit_price) / prev.per_unit_price * 100, 2);
    ELSE
        pct := round((NEW.new_per_base - NEW.old_per_base) / NEW.old_per_base * 100, 2);
    END IF;
    IF abs(pct) >= thr THEN
        INSERT INTO price_alerts (scope_id, ingredient_id, old_per_base, new_per_base, pct, price_history_id, raised_at) VALUES (NEW.scope_id, NEW.ingredient_id, NEW.old_per_base, NEW.new_per_base, pct, NEW.id, NEW.changed_at);
    END IF;
    RETURN NEW;
END$$;

COMMIT;
