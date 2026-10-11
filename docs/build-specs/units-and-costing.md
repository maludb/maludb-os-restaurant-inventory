# Build spec: units and costing — the contract every slice converts and costs by

Written by the planning-class model (Phase 1, 2026-10-11) from **the schema as built in Phase 0** (db/007 units, ingredients,
suppliers; db/008 recipes and the menu map; db/009 the ledger; db/016 the reads — proven by `db/proof/phase0_proof.sql`, 164 checks)
and design §1, §6.1, §6.2, §6.3, D5, D6, the research `docs/research/02-mechanics.md` §1 and §3. A worker relies on this document for
three things: **how a quantity is taken from a form or an action and stored** (always in the ingredient's base unit, always with the
unit it was given in), **how a cost is shown and never recomputed in PHP** (`ri_cost_as_of()`, `ri_recipe_cost()`), and **the three
shared PHP helpers** every slice calls for the same sentence on every screen. Nothing here changes the schema: where the spec says *as
built*, `db/*.sql` is the authority and this document transcribes it.

Schema (never modify; a change is a numbered additive migration with sign-off): `units`, `ingredient_categories`, `ingredients`,
`ingredient_units`, `ingredient_site_settings`, `ingredient_site_costs`, `price_history`, `yield_tests`, `supplier_items`,
`ri_unit_class()`, `ri_to_base()`, `ri_from_base()`, `ri_cost_as_of()`, `ri_supplier_item_cost_per_base()` (db/007); `recipes`,
`recipe_versions`, `recipe_lines`, `recipe_cost_cache`, `menu_items`, `menu_item_prices`, `modifiers`, `menu_item_modifiers`,
`ri_recipe_version_on()`, `ri_recipe_reaches()`, `ri_recipe_allergens()`, `ri_recipe_version_activate()`, `ri_recipe_version_draft()`,
`ri_explode()`, `ri_recipe_cost()`, `ri_portion_cost_cached()`, `ri_menu_price()` (db/008); `inventory_transactions`,
`ri_transactions_after()` (the moving average), `ri_post_txn()`, `ri_on_hand()`, `ri_on_hand_as_of()` (db/009);
`ri_menu_engineering()`, `ri_price_movers()`, `ri_days_on_hand()` (db/016); the views `mcp_units`, `mcp_ingredients`,
`mcp_ingredient_units`, `mcp_ingredient_site_costs`, `mcp_price_history`, `mcp_recipes`, `mcp_recipe_versions`, `mcp_recipe_lines` (db/017).

**The division of labour, in one sentence** (design §1): the database **converts** (`ri_to_base()`), **keeps the average**
(`ri_transactions_after()`), **walks the tree** (`ri_explode()`, `ri_recipe_cost()`) and **walls cost** (`ri_sees_cost()`, the views);
PHP **asks** with a quantity and its unit, **shows** the answer in the unit the person works in with the conversion sentence beside
it, and **never** multiplies a factor, averages a price or sums a tree itself.

---

## 1. Units — as built (db/007)

### 1.1 Three measure classes, three base units
| Class | Base | Global units seeded (`units`) |
|---|---|---|
| `weight` | `g` | `g`, `kg` (1 000), `oz` (28.349523125), `lb` (453.59237) |
| `volume` | `ml` | `ml`, `l` (1 000), `floz` (29.5735295625), `cup` (236.5882365), `pt` (473.176473), `qt` (946.352946), `gal` (3 785.411784), `tsp` (4.92892159375), `tbsp` (14.78676478125) |
| `each` | `each` | `each`, `dozen` (12) |

`units.to_base_factor` is base units per one of the unit; `is_base` is unique per class; a global unit code matches
`^[a-z][a-z0-9_]{0,15}$`. **Every stored quantity in the database is `numeric(18,4)` in the ingredient's base unit** (`qty_base`,
`on_hand_base`, `par_base`, `qty_ordered_base`, `system_qty_base`, …); every stored cost is `numeric(18,6)` **per base unit**
(`avg_cost_per_base`, `cost_per_base`, `unit_cost_per_base`, `new_per_base`). A screen that prints a base quantity raw is wrong.

### 1.2 An ingredient's own units (`ingredient_units`)
One row per unit the kitchen handles the ingredient in: `unit_code` (the ingredient's own code — `case`, `bag50`, `flat`, `bottle`,
`can`; **never a global unit's code**, the trigger refuses it), `name` ("case of 6 × #10"), `to_base_factor` (base units per one),
`kind` ∈ `purchase | count | recipe`, `is_default_count` (one per ingredient). **A purchase unit's factor is ≥ 1** — the trigger
refuses less with "a case is never 1 each" (the field's documented trap, research 01 §2).

### 1.3 Across classes — the ingredient's own facts
`ingredients.measure_class` fixes the base. `recipe_unit_code` may be of another class only when the ingredient carries the fact that
bridges them (`ri_ingredients_before()` refuses otherwise):

| From → to | Needs | Rule |
|---|---|---|
| volume unit on a weight ingredient | `density_g_per_ml` | g = ml × density |
| weight unit on a volume ingredient | `density_g_per_ml` | ml = g ÷ density |
| weight or volume unit on an `each` ingredient | `each_weight_g` / `each_volume_ml` | each = g ÷ each-weight |
| `each` unit on a weight or volume ingredient | `each_weight_g` / `each_volume_ml` | g = each × each-weight |

The operator's own measurement wins over any published table (research 02 §1.3 — vendors disclaim theirs). The proof's olive oil
carries `density_g_per_ml = 0.916` (1 lb = 495.19 ml); the eggs `each_weight_g = 50` (100 g = 2 eggs); limes carry nothing, so
`ri_to_base(lime, 1, 'g')` is refused with "has no each-weight".

### 1.4 The two converters
```
ri_to_base(p_ingredient_id bigint, p_qty numeric, p_unit_code text) RETURNS numeric     -- base units, rounded to 4
ri_from_base(p_ingredient_id bigint, p_qty_base numeric, p_unit_code text) RETURNS numeric
```
Resolution order of `p_unit_code`: the ingredient's own active unit of that code first, then a global unit of the same class, then a
global unit of another class through §1.3, else `check_violation` with the ingredient's name in the sentence. Worked: `ri_to_base(flour,
2, 'lb')` = 907.1847 g; `ri_to_base(gin, 1.5, 'floz')` = 44.3603 ml; `ri_from_base(egg, 90, 'flat')` = 3 (a flat is the eggs' own unit
of 30). Both are STABLE and granted to every role.

**The convention of every form and every action (design §6, the manifest's conventions): a quantity is always two fields, `qty` and
`unit`.** The handler calls `ri_to_base()` — never a factor from the row — and stores `qty_base`; the row keeps the unit it was given
in where the table has a column for it (`recipe_lines.unit_code`, `goods_receipt_lines.purchase_unit_code`, `waste_logs.unit_code`,
`inventory_count_lines.entries`). The triggers do this for `recipe_lines`, `goods_receipt_lines`, `inventory_transfer_lines`,
`inventory_adjustment_lines`, `purchase_order_lines`; a handler that writes `qty_base` itself for those tables is wrong.

### 1.5 How a screen shows a quantity — the sentence
Every quantity is shown in **the unit the person works in**, with the base beside it when they differ, and a purchase or count unit is
introduced once per screen by its conversion sentence:

> 1 case = 6 × #10 = 18 390 g · 2 cases = 36 780 g

The three shared helpers (built in slice 1, `app/features/ingredients/units.php`, `require_once` from every feature that shows a
quantity) — **signatures fixed**:

```php
/** "2 bag50 (45 359.24 g)" / "3 flat + 7 (97 each)" / "1.5 floz (44.36 ml)"; $unit null = the ingredient's default count unit, else its base. */
function ri_qty_sentence(PDO $pdo, int $ingredientId, string $qtyBase, ?string $unit = null, int $decimals = 2): string;
/** "$0.0011 / g · $25.00 / bag50 · $1.10 / lb" from a per-base cost; the per-unit figures for the units given; '' when $perBase is null (walled). */
function ri_cost_sentence(PDO $pdo, int $ingredientId, ?string $perBase, array $units = [], string $currency = '$'): string;
/** The <option>s of a unit <select> for an ingredient: its own units (kind-filtered) then the global units of its class (and the other classes it bridges); [['code','label','factor','kind']]. */
function ri_unit_options(PDO $pdo, int $ingredientId, ?string $kind = null): array;
```
`ri_qty_sentence()` calls `ri_from_base()` for each unit it prints; it never multiplies a factor it read. A number is formatted with
the thousands separator and at most `$decimals` decimals; a base `each` quantity prints as an integer when whole; a count unit with a
remainder prints "N unit + R" where R is in the base unit. The record picker's line form (design-system `record-picker.md`) shows
`ri_unit_options()` as the unit `<select>` beside `qty`, the default count unit selected for a count, the recipe unit for a recipe
line, the purchase unit for a receipt or PO line.

---

## 2. Yield — two homes (design §6.2; research 02 §1.1, 01 §0.6)

| Where | Column | Bears | Formula |
|---|---|---|---|
| the ingredient | `ingredients.yield_pct` (default 100) | **cost** — EP = AP ÷ yield: the edible portion after trim at receipt | `cost_per_recipe_unit_EP = cost_per_base_AP ÷ (yield_pct/100)` — shown on the card as "EP cost"; **the ledger and the average stay AP** |
| the recipe line | `recipe_lines.line_yield_pct` (default 100) | **depletion** — a dice yields less than a julienne | `qty_consumed_base = qty_base ÷ (line_yield_pct/100)` — `ri_explode()` and `ri_recipe_cost()` both divide |
| the batch | `recipe_versions.expected_loss_pct` | **the prep's output** — cook loss on the batch as a whole | `output = batch_yield × (1 − loss/100)`; `ri_post_production()` and the sub-recipe cost divide by it |

**Worked — the textbook tenderloin** (`yield_tests`): 2 500 g AP, 750 g waste → 1 750 g EP → `yield_pct` 70.00 (generated); cost
factor 1/0.7 = 1.4286; AP $23.00/kg → EP $32.86/kg. A yield test with `apply = yes` (the manifest's `yield_test_add`) sets the
ingredient's `yield_pct`; the card shows the test's date beside it.

**Where the card applies the ingredient yield:** the cost per recipe unit shown on an ingredient card and in the what-if is **EP**
(`ri_cost_as_of()` ÷ yield) only when the person toggles "edible portion"; `ri_recipe_cost()` costs a line at the **AP** per-base cost
divided by the **line** yield — the two yields are never both applied to one line (D6: cost-bearing on the ingredient, depletion-bearing
on the line; a recipe that needs 170 g of trimmed chicken writes 170 g at a line yield that says how much raw it takes).

---

## 3. Cost — one function, three bases (db/007 `ri_cost_as_of`)

```
ri_cost_as_of(p_ingredient_id, p_scope_id, p_as_of timestamptz DEFAULT now(), p_basis text DEFAULT NULL) RETURNS numeric  -- per base unit
```
Precedence, as built: (1) the site's `ingredient_site_settings.cost_override_per_base` when set; (2) basis `last`, **or any
`p_as_of` more than a minute in the past**: the last `price_history.new_per_base` at or before the moment for the site or for every site
(`scope_id IS NULL`); (3) the site's running `avg_cost_per_base` when > 0; (4) the last price history row of any date; (5) 0 — the
caller flags it (`ri_recipe_cost()` counts it as `uncosted`). `p_basis` NULL = `ri_settings.cost_basis` (D6: `avg`).

| Basis | Shown where | Note |
|---|---|---|
| `avg` | valuation, variance, the ledger's documents, the default card | the moving average of §4 — lags a price rise |
| `last` | the recipe card's toggle ("at last price"), the what-if | jumps on every invoice |
| standard | — Extended (design §11) | the price/usage split |

**The moving average is kept by the ledger's after-trigger, nowhere else** (`ri_transactions_after()`, db/009): on an inbound row
(`receipt`, `production_in`, `return`, a positive `adjustment` with a cost, a `transfer_in` from another site) with a cost > 0,
`avg' = (on_hand × avg + qty × cost) ÷ (on_hand + qty)`, or the cost itself when on hand ≤ 0; an issue never moves it; a
`count_correction` never moves it. **The zero-cost rule**: `ri_post_receipt()` lands a line with no cost or a zero cost **at the
running cost** (`ri_cost_as_of()`), never at zero, unless the line says `allow_zero_cost` (a free sample) — so a costless receipt
never pulls the average down (research 02 §1.2). The receipt writes `price_history` (`source_kind = receipt`) with the cost **before**
the ledger advanced it as `old_per_base`, and the `supplier_items.last_price`.

**Worked — the $25 bag of flour**: `bag50` = 22 679.6185 g; `ri_supplier_item_cost_per_base()` = 25 ÷ 22 679.6185 = **$0.001102 / g**.
Two bags received at $25 then two at $30: avg = (45 359.237 × 0.001102 + 45 359.237 × 0.001323) ÷ 90 718.474 = **$0.0012125 / g**.
The second receipt raised a `price_alerts` row (+20 %, dry goods threshold 10 %).

**Worked — catch weight**: `goods_receipt_lines` with `weight_qty = 38.5`, `weight_unit_code = 'lb'`, `unit_cost = 3.10` (per lb):
the trigger sets `qty_base` = 17 463.31 g (the declared weight, not the nominal 40 lb case) and `cost_per_base` = 3.10 ÷ 453.59237 =
**$0.006834 / g**. The count later counts it by the piece (`ingredient_units` `case` ≈ 18 143.69 g — the average) or by weight.

**Cost is the wall** (design §3): every per-base cost column is nulled by the views unless `ri_sees_cost(scope)`; a PHP screen reads
cost from `mcp_*` views and the functions **as the member** (`app.member_id` set) and prints `ri_cost_sentence()` only when
`sees_cost($scopeId)`; the functions `ri_cost_as_of()` and `ri_recipe_cost()` are not walled themselves — a handler never passes their
answer to a page unless `sees_cost()` says so.

---

## 4. The recipe tree (db/008)

### 4.1 Shape
`recipes.kind` ∈ `menu_item | prep | modifier`; a prep names `output_ingredient_id` (flagged `is_prep` by trigger). A
`recipe_versions` row is a draft until `ri_recipe_version_activate(version, by, from)`: one draft per recipe, one open active version
per recipe; activation ends the previous at the new `effective_from`; an active version is immutable (its lines too — the trigger
refuses); `ri_recipe_version_on(recipe, date)` is the version in force on a date — **every past-period computation (theoretical
usage, menu engineering, the cost history) must call it with the day in question**. `ri_recipe_version_draft(recipe, by, note)` copies
the version in force into a new draft (the way a chef edits a live recipe).

A `recipe_lines` row names **either** `ingredient_id` **or** `sub_recipe_id`: an ingredient line's `qty_base` is in the ingredient's
base unit (the trigger converts `qty` + `unit_code`); a **prep** sub-recipe line is in the prep's output ingredient's base unit
(`unit_code` any unit of that ingredient); a **menu-item or modifier** sub-recipe line is in `portion`s (`unit_code = 'portion'`,
`qty_base = qty`). `ri_recipe_reaches()` refuses a cycle at save ("A recipe may not contain itself").

### 4.2 The explosion
```
ri_explode(p_version_id, p_on date DEFAULT current_date, p_explode_prep boolean DEFAULT false) RETURNS TABLE (ingredient_id, qty_base)
```
One **batch** of the version into leaf ingredients: each line ÷ its line yield; a sub-recipe's lines scaled by `line_qty ÷
(sub.batch_yield_qty × (1 − sub.expected_loss_pct/100))` (and, for a prep, ÷ `ri_to_base(output, 1, sub.batch_yield_unit_code)`); the
sub's version is `ri_recipe_version_on(sub, p_on)`. **A prep is a leaf by default** — it is taken from stock as its output ingredient
(D12: the kitchen posts production); `p_explode_prep = true` opens it to raw ingredients (the "what does this really use" view and
`ri_post_production()` of a prep that contains a prep). A menu item's batch is `batch_yield_qty` portions: a sale of `qty` portions
depletes `ri_explode(...) × qty × menu_items.portions ÷ batch_yield_qty`.

**Worked — the chicken sandwich** (the proof): lines chicken 170 g at line yield 90 → 188.8889 g; stock (prep) 50 ml → 50 ml of the
`STOCK` ingredient; egg 1 each. With `p_explode_prep`: the stock's version (2 kg chicken per 4 l batch at 10 % loss = 3.6 l net) adds
50 × 2000 ÷ 3600 = 27.78 g of chicken.

### 4.3 The cost
```
ri_recipe_cost(p_version_id, p_scope_id, p_as_of timestamptz DEFAULT now(), p_basis text DEFAULT NULL)
    RETURNS TABLE (batch_cost, portion_cost, batch_yield_qty, lines jsonb, uncosted integer)
```
Depth-first: an ingredient line = `qty_base ÷ line_yield × ri_cost_as_of(ingredient, site, as_of, basis)`; a prep sub-recipe line =
`qty_base ÷ line_yield × (sub.batch_cost ÷ (sub.batch_yield in base × (1 − sub.loss)))` — **the prep costs by its own recipe net of
loss, never by what is on the shelf** (so a prep never made still costs); a menu-item/modifier sub-recipe line = `qty ×
sub.portion_cost`. `portion_cost` = `batch_cost ÷ batch_yield_qty` (a prep's: ÷ net yield). `uncosted` counts leaves at cost 0 and
subs with no version in force; the card prints "N lines uncosted" in warning colour. `lines` is the per-line JSON the card renders
(`line_id`, `ingredient_id`/`sub_recipe_id`, `qty`, `unit_code`, `qty_base`, `cost_per_base`, `line_cost`) — the cost share column is
`line_cost ÷ batch_cost`.

**Worked — the plate** (the proof, at the receipt's costs): chicken 188.8889 g × $0.006834 = $1.2909; stock 50 ml × ($13.668 per
batch ÷ 3 600 ml) = $0.1898; egg 1 × $0.30 = $0.3000; **portion cost $1.7807**; at a $14.00 menu price the food-cost % is 12.7.

**The cache**: `ri_portion_cost_cached(version, site, on_date, basis)` memoises a day's portion cost in `recipe_cost_cache`; **any
`price_history` insert deletes the cache from that day forward** (for the site, or every site when the row is site-less). The reports
(`ri_menu_engineering()`, the cost history, theoretical COGS at cost) read the cache; a card reads `ri_recipe_cost()` live. The cache
is never written by PHP.

### 4.4 The cost card — columns, in this order
Line · qty (in `unit_code`) · base (`qty_base` with the base unit) · yield % · cost per base (`ri_cost_sentence()`) · line cost ·
share %; the footer: batch cost · yield (portions or the prep's output) · **cost per portion** · the menu price at the site
(`ri_menu_price()`) · food-cost % · "as of" (the date and the basis, a toggle `avg | last`) · the version in force and its dates. A
Viewer sees the first four columns only.

### 4.5 Menu engineering (db/016 `ri_menu_engineering(scope, from, to, basis)`)
Per menu item over the window: `qty_sold`, `net`, `avg_price`, `plate_cost` (the cached portion cost sampled weekly × `portions`),
`food_cost_pct`, `cm` (price − cost), `cm_total`, `mix_pct`, the category's `cm_avg` (weighted by mix) and `mix_threshold`
(1/n × 0.7), the quadrant: star (high CM, high mix), plowhorse (low CM, high mix), puzzle (high CM, low mix), dog (Kasavana & Smith;
research 02 §1.5). The screen shows it as the 2 × 2 with the items as dots and the table below; walled.

---

## 5. Supplier items and purchase units (db/007)
`supplier_items.purchase_unit_code` is an `ingredient_units` code of kind `purchase`, **or a global unit for catch weight** (`lb`,
`kg` — priced per weight); the trigger refuses a unit the ingredient cannot convert. `last_price` and `contract_price` are **per
purchase unit**; `ri_supplier_item_cost_per_base(id)` = the live contract price (until `contract_until`) else the last price, ÷
`ri_to_base(ingredient, 1, purchase_unit_code)`. The "cheapest supplier" column of an ingredient card orders supplier items by that
function in a comparable unit — never by the raw price.

---

## 6. What a slice must not do
- Multiply a factor from `ingredient_units` or `units` in PHP; sum a recipe's lines in PHP; average two prices in PHP; write
  `qty_base`, `avg_cost_per_base`, `on_hand_base`, `recipe_cost_cache` or `price_history` from a handler (the receipt, the invoice
  settlement, production and the cost override write price history through their verbs; a manual catalog cost goes through
  `cost_override_set`).
- Show a cost from an unwalled function to a page without `sees_cost($scopeId)`.
- Store a quantity without the unit it was given in; accept a quantity field without a unit field.
- Round a stored base quantity to fewer than 4 decimals, or a per-base cost to fewer than 6.

---

## 7. Proof (every slice that converts or costs extends its `tests/phase3/slice*/run.sh` with these)
- `ri_qty_sentence(flour, 45359.2370, 'bag50')` = "2 bag50 (45 359.24 g)"; with `'lb'` = "100 lb (45 359.24 g)"; eggs 97 with
  `null` = "3 flat + 7 (97 each)".
- `ri_cost_sentence(flour, 0.001102, ['bag50','lb'])` contains "$25.00 / bag50" and "$0.50 / lb".
- `ri_unit_options(gin, 'count')` lists `bottle` first, then `ml`, `l`, `floz`, …; for limes no weight unit appears.
- A form posting `qty = 2, unit = bag50` lands 45 359.237 g; `qty = 2, unit = cup` on flour is refused with "needs a density" shown
  as a 422 under the unit field.
- The recipe card of the chicken sandwich shows $1.78 a portion with three costed lines and 0 uncosted; a Viewer sees no cost column;
  toggling `last` after a $33 invoice settlement shows a flour-based recipe's cost moved and the sandwich unchanged.
- A price change today empties the cache from today; yesterday's menu engineering is unchanged.

## 8. Owed to the schema
None found while writing this spec. Candidates a slice may raise (each a numbered additive migration, never silently):
- `ri_cost_as_of()` answers the last price for any past `p_as_of` even on the `avg` basis (the running average is not kept by date);
  a `cost_snapshots(ingredient_id, scope_id, on_date, avg_cost_per_base)` table written by the nightly worker would give a true
  historical average — proposed when the cost-history chart needs it: `ri_cost_snapshot_daily(p_scope_id bigint, p_on date) RETURNS integer`.
- `ri_qty_sentence()` would be simpler with `ri_default_count_unit(p_ingredient_id bigint) RETURNS text` in SQL; PHP reads
  `ingredient_units` directly until then.
