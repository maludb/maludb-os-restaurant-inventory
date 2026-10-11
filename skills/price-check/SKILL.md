---
name: price-check
description: Runbook for the price questions in Restaurant Inventory — "what does X cost today", "what moved this week", "who is cheapest for X" — the basis and the date, the cost history, invoice-driven price alerts and contract drift, comparing suppliers in one unit, which plates a move hits, and what an agent never sets.
kind: runbook
---

# A price check

**When**: a person asks what something costs or what moved, or the morning duty's heading 3. **Who**: either agent.
**Writes**: notes, a proposal. **Sets**: nothing — `cost_override_set` and the thresholds are a person's.

## 1. One ingredient — `ingredient_cost`, `cost_history`
Say the site, the basis (weighted average for valuation; last price for the card) and the date. The history as the last five
receipts or invoice lines: date, supplier, purchase unit, price per purchase unit and per base unit. A jump: name the
invoice (`get_invoice`).

## 2. What moved — `price_exceptions`, `price_movers`
Alerts raised by invoices over the category's threshold (default 10 %); contract price vs invoice price where a contract
exists (`supplier_items.contract_price`); the week's biggest movers by dollars of usage (move × the period's theoretical
usage). For each, the plates it hits most (`recipes_using`) and the plate's new food-cost % (`recipe_cost`).

## 3. Who is cheapest — `cheapest_supplier`, `supplier_items`
Every supplier's item for the ingredient converted to cost per base unit — **the only honest comparison**: a $42 case of
6 × #10 against a $28 case of 4 × #10 is $0.002465/ml against $0.002465/ml (the same). Say pack, price, per-unit cost, lead
days, the delivery schedule, the minimum order. The preferred supplier is set by a person
(`ingredient_site_settings.preferred_supplier_item_id`).

## 4. What-if — `recipe_what_if`
"If romaine goes to $X a case": every recipe using it with the plate cost before and after and the food-cost % change;
the menu price that would hold the target.

## 5. Say it
"Romaine, Main: $31.50 a case of 24 heads (Sysco, invoice 48812, 2026-10-10) — up 18 % from $26.70; $1.31 a head.
Caesar's plate cost $3.42 → $3.86, 24.1 % of $16. Produce Co. has it at $29.00 (Tue/Fri). Changing the preferred supplier
or the menu price is yours." Cost only to those who may see it.
