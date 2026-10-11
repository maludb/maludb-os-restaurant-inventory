---
name: ordering
description: How Restaurant Inventory decides what to order — the supplier's schedule (order day, cut-off, delivery day, lead days), fixed par versus dynamic par, the periodic-review rule with its protection interval and safety stock, the perishables cap over shelf life, rounding to the pack and the minimum order, one drafted purchase order per supplier with the arithmetic on every line, never placed by an agent. Read before drafting or explaining an order.
---

# Ordering

## The supplier's schedule
`supplier_schedules(supplier, site, order_dow, cutoff_time, delivery_dow, lead_days)` — "order by Saturday 13:00 for Tuesday
delivery". A cut-off missed moves the order to the next opportunity. Several rows a week are normal.

## Two rules, chosen per ingredient per site (`par_mode`)
**Fixed par** (every operator understands it; the default until four weeks of history exist):
```
quantity = max(par − on_hand − on_order, 0), rounded UP to the purchase unit, over the goal by at most 20 %
```
**Dynamic par** — periodic review, the rule that fits delivery days (the default once history exists):
```
P        = the protection interval: from THIS delivery to the delivery after the NEXT order opportunity
need     = Σ forecast demand for the ingredient over P  (exploded through recipes; the weekday shape inside)
           + safety stock = z × σ_daily × √P           (z: 90 % → 1.28, 95 % → 1.645, 98 % → 2.05; σ from the forecast's residuals)
           (thin history: the category's safety-stock % instead — proteins 20–30 %, dairy 15–20 %, dry 8–15 %)
have     = on_hand + on_order due before the delivery
cap      = for a perishable, the newsvendor quantity over its shelf life: the critical fractile
           CF = (price − cost) / ((price − cost) + cost) of the plates it goes into, taken as an empirical quantile of recent daily demand
raw      = max(min(need, cap) − have, 0)
rounded  = ceil(raw / base_per_purchase_unit) purchase units (down to a split case only when the supplier allows it)
then     the supplier item's minimum order quantity; the supplier's minimum order value for the whole draft
```
Units must match: a daily σ with days in P. Every step is kept on the drafted line (`need_base`, `have_base`,
`on_order_base`, `cap_base`, `rounding_note`) so the screen and the note show it.

## The run (`suggested_orders` → `purchase_order_draft`)
1. For each supplier whose cut-off falls in the window (today, tomorrow) at the site, take its order guide (or every
   ingredient it is the preferred supplier for).
2. Compute every line by the rule above. Lines whose forecast is thin are flagged "fixed par used".
3. **One draft per supplier.** A draft already open for that supplier is listed, never doubled.
4. Say each draft's number, the delivery day, the total at cost, the lines where the cap bit, the lines rounded up a lot.
5. **Never send.** `purchase_order_send` is `money_out` — a person's; `purchase_order_place` records a portal order a person made.

## Reading a suggested line
"Limes — need 9.6 kg over Tue→Sat (forecast 8.1 kg + safety 1.5 kg at 95 %), have 2.3 kg on hand + 0 on order, cap 12 kg
(5-day shelf life) → 7.3 kg → **2 cases of 4.5 kg** (9.0 kg, +23 % over the need because the case is the unit)."

## Catch weight and substitutions
A catch-weight item is ordered by piece; the draft says the pieces and the expected weight; the receipt carries the actual.
A supplier's substitution is theirs to declare through their link; it lands as a receipt discrepancy, not an order change.
