---
name: reading-variance
description: How to read actual vs theoretical usage in Restaurant Inventory — the identity, the buckets that explain a variance (waste logged, comps, transfers, production), what is left unexplained, why a negative variance is a data defect first, the pitfalls (receipt dates, credits, skipped areas, unmapped PLUs, prep counted twice), and how to say it. Read before explaining any variance or COGS number.
---

# Reading variance

## The identity (per ingredient, per period, per site — `usage_report`)
```
actual_usage      = opening + purchases + transfers_in + production_in − transfers_out − production_out − closing
theoretical_usage = Σ over the period's sales lines (qty_sold × recipe qty per unit sold, modifiers and line yields applied,
                    the version in force on each sale's date)
variance          = actual_usage − theoretical_usage         (units, and × weighted average cost = dollars)
explained         = waste_logged + comps_depleted (sold at $0, deplete anyway) + staff_meals
unexplained       = variance − explained
```
Opening and closing are the period's approved counts (or the book balance where the period has none). COGS for the
period = opening value + purchases ± transfers − closing value. Food-cost % = COGS ÷ food sales; theoretical food-cost % =
Σ plate cost × qty sold ÷ food sales. A well-run house keeps the gap under two points; over five is systemic.

## Read the sign first
- **Positive** (used more than sold): over-portioning, unlogged waste, theft, a missed receipt at the opening count, an
  unmapped PLU (sold but never depleted — check `unmapped_plus` first), a recipe quantity too small.
- **Negative** (used less than sold): **a data defect before good news** — a receipt not entered, a count line miscounted
  or in the wrong unit (a case counted as one each), a recipe quantity too large, a transfer in not received.
- **Zero on an uncounted item** means nothing: it was valued at theoretical because nobody counted it.

## The pitfalls, each a question to ask
1. **Receipt dates** — purchases count by the date received, not the invoice date; a delivery on the shelf at the count
   whose receipt was posted after it inflates usage.
2. **Credits** — a short-ship credit must reverse on the original receipt's date, or phantom stock sits in purchases.
3. **Skipped areas** (`areas_skipped`) — an uncounted walk-in shelf becomes "usage".
4. **The count's moment** — `as_of` must equal the sales cut; sales after it belong to the next period.
5. **Prep** — a counted batch of sauce is an ingredient at its rolled-up cost; its raw lines must not be counted again.
6. **Cross-use** — chicken in three recipes: variance is per ingredient; `recipes_using` shows who consumes it and how much
   of the period's theoretical each took. Attribution to one recipe from counts alone is not possible; say so.
7. **Voids and comps** — a void never depletes; a comp always does and is explained.

## How to say it
"Chicken thigh, week 41 at Main: opening 18.2 kg, purchases 40.0 kg, closing 9.6 kg → actual **48.6 kg**; sold 310 plates ×
140 g ÷ 0.92 line yield = theoretical **47.2 kg**; variance **+1.4 kg ($11.20)**, 0.6 kg logged as trim waste, **0.8 kg
unexplained** (1.7 %). Three PLUs with 22 sales had no recipe — mapping them would add about 3.1 kg of theoretical and turn
this negative." Name the numbers, the buckets, the first thing to check. Cost only to those who may see it.
