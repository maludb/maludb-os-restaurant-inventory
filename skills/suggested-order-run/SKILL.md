---
name: suggested-order-run
description: Runbook for drafting a supplier's order in Restaurant Inventory — when a person asks "draft Tuesday's Sysco order" or the duty reaches a cut-off: the schedule, the suggested order with every line's arithmetic, the checks before drafting, one purchase order per supplier, what to say back, and that an agent never sends it.
kind: runbook
---

# A suggested-order run

**When**: a person asks for a supplier's order, or the morning duty finds a cut-off today or tomorrow. **Who**: the
Purchasing agent or the expert. **Writes**: one drafted purchase order per supplier. **Sends**: nothing.

## 1. The schedule — `get_supplier`, `supplier_schedules`
Which cut-off is next for this supplier at this site, which delivery it feeds, and the delivery after the next opportunity
(that is the protection interval the rule uses). A cut-off already passed today: say so, and draft for the next one.

## 2. The suggested order — `suggested_orders` (or `get_suggested_order` for one already computed)
Every line of the order guide: need, have (on hand + on order due before the delivery), cap, raw, rounded, the rule used
(fixed par / dynamic par), the flag "fixed par used — thin forecast". Read `ordering` for the rule.

## 3. Before drafting — look at the lines that stand out
- A line far above its four-week average: `item_forecast` or `ingredient_demand` — is a holiday or an override behind it?
- A line where the cap bit: say the shelf life and that the order stops at the cap.
- A line rounded up by a lot (a big case for a small need): say the overage %.
- A line with no price on the supplier item: say "no price — the draft carries $0 until a person sets it".
- An open draft for this supplier already (`purchase_orders_open`): list it; do not draft a second.

## 4. Draft — `purchase_order_draft`
One purchase order per supplier, the site's delivery address, the lines in the supplier item's purchase unit with the
arithmetic kept, `expected_on` the delivery day. Drafted, never sent.

## 5. Say it back
Number, supplier, delivery day, line count, total at cost; the three lines worth a look and why; "sending it is yours —
`purchase_order_send`". If the person says "send it", ask the kernel for `purchase_order_send` and tell them it waits for
approval (`money_out`).
