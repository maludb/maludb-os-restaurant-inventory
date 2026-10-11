---
name: units-and-yields
description: How units convert in Restaurant Inventory and how yield changes a cost — measure classes, the base unit, purchase and count and recipe units, density and each-weight across classes, the case that is never one each, AP vs EP, the tenderloin at 70 %, the cost factor, where yield lives (the ingredient for cost, the recipe line for depletion). Read before converting or costing anything.
---

# Units and yields

## Three measure classes
Every ingredient has one: **weight** (base g), **volume** (base ml) or **each** (base each). Within a class the units table
converts by a constant: 1 lb = 453.592 g, 1 oz = 28.3495 g, 1 kg = 1,000 g; 1 gal = 3,785.41 ml, 1 qt = 946.353 ml,
1 fl oz = 29.5735 ml, 1 cup = 236.588 ml, 1 l = 1,000 ml; 1 dozen = 12 each. Every quantity stored is in the base unit;
every quantity shown says its unit.

## Across classes — only with the ingredient's own fact
- weight ↔ volume needs a **density** (g per ml) on the ingredient: honey 1.42, oil 0.92, flour ≈ 0.55 (spooned).
- each ↔ weight or volume needs an **each-weight** (g) or **each-volume** (ml): one lemon 80 g, one egg 50 g.
- Without the fact the conversion is **refused**, not guessed; say "needs a density on the ingredient" and stop. Vendors'
  average tables are averages they disclaim; the kitchen's own measurement wins.

## The purchase hierarchy — a case is never "1 each"
A supplier item sells in a **purchase unit** with a factor to the base: "case of 6 × #10 cans (each 2.84 l)" = 17,040 ml;
"50 lb bag" = 22,679.6 g; "case of 24 × 12 fl oz" = 8,517.2 ml. The trap every product documents: a case entered as "1 each"
makes the cost per can six times too high and the count six times too low. When a chef says "a case", ask or look up what
the case holds before you convert.

```
cost_per_base = purchase_price / base_units_per_purchase_unit
cost_per_recipe_unit = cost_per_base × base_units_per_recipe_unit
```
A $42.00 case of 6 × #10 (17,040 ml) → $0.002465/ml → $0.0729 per fl oz → $0.58 per cup.

## Count units
A sheet counts in the unit on the shelf: 2 cases + 3 cans of the #10 → 2 × 6 + 3 = 15 cans = 42,600 ml; 2.5 cases; a 750 ml
bottle by weight. The system converts; you say both numbers.

## AP and EP — yield at receipt (cost-bearing)
```
yield_pct   = EP_weight / AP_weight
EP_cost     = AP_cost / yield_pct
cost_factor = 1 / yield_pct           (re-cost from a new price with one multiply)
AP_needed   = EP_needed / yield_pct   (what to order for a required edible amount)
```
Tenderloin: 2,500 g as purchased, 750 g trim → 1,750 g edible → **70 %**; at $23.00/kg AP the edible kilo costs
**$32.86** (23.00 ÷ 0.70). A yield test (`yield_tests`) records AP, EP, by-products with a value, trim and cook loss; it may
set the ingredient's `yield_pct`. Canned goods cost by the can's volume unless a test says drained weight.

## Yield on a recipe line (depletion-bearing)
A line's `line_yield_pct` says how much of the ingredient the preparation keeps — a dice keeps more than a julienne. It
changes how much is **depleted** per portion (qty ÷ line yield), not the ingredient's cost. A sub-recipe's batch yield is net
of cook loss: 4.0 l of stock from 6.0 l of water and bones is the batch's yield, and its cost per litre = batch cost ÷ 4.0.

## Catch weight
Ordered by each (one brisket), received, invoiced and counted by weight (12.4 lb). The delivered cost is recalculated at
receiving from the actual weight; the count converts a piece by the ingredient's average weight.

## Saying it
"2 cases (12 cans, 34.1 l) of crushed tomatoes at $42.00 a case — $0.0729 per fl oz." Never a number without its unit.
