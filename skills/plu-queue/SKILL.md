---
name: plu-queue
description: Runbook for the unmapped-PLU queue in Restaurant Inventory — what an unmapped sale is, how to find the recipe it should deplete (name, category, price, history), when the expert may map and when it proposes, modifiers, combos and sizes, and why a day's depletion is re-run after a mapping.
kind: runbook
---

# The PLU queue

**When**: the morning duty's heading 4, or a person asks "which PLUs sold with no recipe". **Who**: the expert (may map
when the match is plain); the Purchasing agent (reports and proposes). **Writes**: `menu_item_map`, `modifier_map`, notes.

## 1. The queue — `unmapped_plus`
Every PLU that sold with no `recipe_id`, by site: PLU, the POS's name and category, sale price, quantity sold since first
seen, first and last sold. Modifiers unmapped (`find_modifiers`) beside it.

## 2. Finding the recipe — `find_recipes`, `get_recipe`
A match is plain when the POS name equals a recipe's name or code (case and punctuation aside), or the POS name carries a
size of a recipe that has sizes, and the category agrees. Then `menu_item_map` (PLU → recipe) and say so. Anything looser —
"Chx Sand" against three chicken sandwiches — is a **proposal** (`buyer_propose`, kind `plu`) with the candidates, the
quantities at stake and the reason; a person maps from the queue.

## 3. Shapes
- **A size** is a separate recipe or a quantity factor on one; map to the recipe of that size.
- **A combo** maps to a menu item whose recipe lists the components as sub-recipes.
- **A modifier** maps to a modifier recipe with its action: `add` (deplete), `remove` (give back the parent's line), `swap`,
  `none` (cosmetic — "well done"). A generic "no tomato" needs a quantity per parent; say when it does.
- **A non-food PLU** (a gift card, a delivery fee, a bottle deposit) maps to **no recipe** on purpose: `menu_item_map` with
  `no_depletion: true` so it leaves the queue without depleting.

## 4. After a mapping
Theoretical usage changes for every day the PLU sold: say which business dates need their depletion run re-run
(`depletion_runs`), and that `depletion_run_void` + re-run is a person's (deletion). The variance of a closed period does
not change; the next period carries the truth.

## 5. Say it
"14 PLUs unmapped at Main, 212 sales since Monday. Mapped 9 plain ones (listed). 'Chx Sand' (88 sales) — three candidates,
proposed the Grilled Chicken Sandwich by price ($14 = $14). 'Delivery fee' mapped to no depletion. Days to re-run: Oct 7–10."
