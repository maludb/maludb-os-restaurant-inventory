---
name: costing-a-recipe
description: How a recipe is costed in Restaurant Inventory — the tree walked bottom-up with a yield on every edge, sub-recipes by their finished yield, the cost basis (weighted average or last price) as of a date, portion and plate cost, food-cost % and the menu price from a target, modifiers, menu engineering's four quadrants; which recipe version counts for a past period. Read before answering what a plate costs.
---

# Costing a recipe

## The tree
A recipe's lines name an ingredient **or another recipe** (a prep: stock, a sauce, prepped onions). Cost is a depth-first
walk: every leaf ingredient at its cost per recipe unit, every sub-recipe at **its batch cost ÷ its batch yield** (the yield
already net of cook loss), every line divided by its line yield.

```
line_cost (ingredient) = qty_in_recipe_unit × cost_per_recipe_unit / line_yield_pct
line_cost (sub-recipe) = qty × (sub_batch_cost / sub_batch_yield)
batch_cost             = Σ line_cost
cost_per_portion       = batch_cost / portions_per_batch   (or per portion_qty)
plate_cost             = Σ line_cost of the plate recipe, garnish and packaging lines included
food_cost_pct          = plate_cost / menu_price
menu_price_from_target = plate_cost / target_food_cost_pct   ($3.20 / 0.30 = $10.67 → round to a price point)
```
A cycle (a recipe inside itself by any path) is refused at save. `recipe_cost` does the walk; `recipe_tree` shows it.

## Which price feeds a leaf — say the basis
| Basis | What it is | When |
|---|---|---|
| **Weighted average** (default) | the site's moving average, advanced by every receipt, never by an issue | valuation, variance, COGS |
| **Last price** | the most recent receipt or invoice line at this site | the chef's card, "what does it cost today" |
A zero-cost receipt would drag the average under true cost — it is refused. The same ingredient may carry a different
average at each site; say which site's cost the card shows. `recipe_what_if` re-costs with one ingredient at a new price.

## As of a date, by the version then in force
Cost is **computed as of a date from price history, never stored on a version**. "What did the burger cost in July" =
`recipe_cost` as of a July date on the version whose effective dates held then (`recipe_version_at`). A new version's
activation sets the previous one's `effective_to`; activation is a person's (`recipe_version_activate` pauses).

## Modifiers
A modifier recipe has an action: `add` depletes its lines, `remove` returns the parent's line, `swap` does both, `none` is
cosmetic. A generic "no tomato" carries a quantity per parent where it must (`menu_item_modifiers.qty_base_override`).
A plate's cost on the card is the base recipe; the sales mix of modifiers shows in theoretical usage, not on the card.

## Menu engineering (Kasavana & Smith) — `menu_engineering`
```
CM_item       = price − plate_cost            CM_avg = Σ(CM × qty) / Σ qty
mix_pct       = qty_item / Σ qty              threshold = (1 / items_in_category) × 0.70
Star = high CM, high mix · Plowhorse = low CM, high mix · Puzzle = high CM, low mix · Dog = low CM, low mix
```
Say the period, the category and the mix the quadrant was judged on.

## Saying it
"The short rib plate costs **$6.84** at this site today (weighted average, version 3 in force since 2026-09-01): rib
$4.90 (72 %), jus $0.62, polenta $0.80, garnish $0.52 — **31.1 %** of the $22 menu price." Cost only to those who may see it.
