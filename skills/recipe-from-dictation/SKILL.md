---
name: recipe-from-dictation
description: Runbook for drafting a recipe from what a chef says in Restaurant Inventory — resolve every ingredient, keep the chef's units and show the conversion, a prep the chef names becomes a sub-recipe, the batch yield and portions, yields, allergens, the cost read back, and that a draft version is never activated by an agent.
kind: runbook
---

# A recipe from dictation

**When**: a chef says "new recipe: the short rib — 180 g braised short rib, 60 ml jus, 120 g polenta, a sprig of thyme,
makes one plate, sells at $22". **Who**: the expert. **Writes**: `recipe_create` (a draft version), `recipe_line_set` per
line. **Activates**: never — `recipe_version_activate` is a person's.

## 1. The header — `recipe_create`
Kind (`menu_item` when it is sold; `prep` when it makes stock of an ingredient — ask which output ingredient; `modifier`
when it is an add/remove/swap), name, category, portions per batch or the batch yield in a unit ("makes 4 l", "makes 24
portions"), the portion size, the method as said. A menu item with a PLU already selling: offer `menu_item_map` after.

## 2. Each line — `find`, `get_ingredient`, `ingredient_units`, then `recipe_line_set`
- Resolve the ingredient by name; two candidates → ask, never guess ("short rib" — bone-in or boneless?).
- **Keep the chef's unit** on the line (180 g, 60 ml, "a sprig" → each) and say what it converts to in the base unit. A
  volume of something bought by weight needs the ingredient's density; without it, say so and leave the line in the
  chef's unit for a person to settle.
- **A prep the chef names** ("the jus", "house stock") is a **sub-recipe** line: find the recipe (`find_recipes`); none
  exists → say so and offer to draft it next.
- **Line yield**: the ingredient's default applies; the chef's "after trimming" means the line yield; say the raw quantity
  that implies (`qty ÷ yield`).
- Garnish and packaging are lines too, grouped.

## 3. Read it back — `recipe_tree`, `recipe_cost`, `recipe_allergens`
The lines in the chef's units with the base units beside, the batch yield, the portion, the plate cost at this site today
(basis stated) and the food-cost % against the price said, the allergens rolled up. A line that looks wrong (a plate using
2 kg of salt) — ask before saving the version as drafted.

## 4. What happens next
The version is a **draft**; the chef (or a Manager) activates it, which sets its effective date; the previous version's
`effective_to` follows. Until then depletion uses the version in force. Say: "Draft v1 saved — activate it on the recipe
page when it is right."

## 5. Say it
"Drafted *Short rib plate* v1 at Main: short rib 180 g (÷0.88 yield → 205 g raw), jus 60 ml (sub-recipe *Beef jus* v2),
polenta 120 g, thyme 1 each. Plate cost **$6.84** (weighted average, today), **31.1 %** of $22. Allergens: dairy (polenta).
Activate when ready; PLU 4471 'Short Rib' is unmapped — map it to this recipe?"
