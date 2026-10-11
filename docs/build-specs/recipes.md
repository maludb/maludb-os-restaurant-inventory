# Build spec: recipes, the menu map and costing — THE FIRST EXEMPLAR (slice 2)

Built by the planning-class model on slice 1's pattern. What exists at the end: a Chef makes a prep recipe (chicken stock: 2 kg of chicken,
makes 4 l less 10 % loss) and a menu-item recipe (the chicken sandwich: 170 g of chicken at a 90 % line yield, 50 ml of the stock as a
sub-recipe, one egg) in **the recipe editor** — every line a row form where the record picker chooses an ingredient OR a recipe, the quantity
is typed in any unit with the conversion said live ("2 kg = 2 000 g"), the line yield sits beside it, and the cost per portion (walled)
recomputes on every change; activates the draft from a date and reads the sentence of what ends; makes a new draft from the live version to
change it next week; maps the POS's PLU 1001 to it through the picker and the "extra chicken" modifier to its modifier recipe; reads the plate
cost against the menu price, what the plate costs if chicken goes to $3.50 a pound, and the menu-engineering matrix with its four quadrants. A
Kitchen member reads the recipe's quantities and method and never its cost. Every screen is cards for named things and a full-page form; the
editor is one page that saves a line at a time (Pattern C) and never a modal; every save is a handler on the kit's shape (`ri_handler_begin()`
→ the right → `ri_guard()` → the SQL verb → `log_activity()` with `recipe_id` → `ri_done()`). **This slice is the exemplar of a screen that
computes through the database**: PHP never sums a cost, converts a unit, walks the tree or decides a version — `ri_recipe_cost()`,
`ri_to_base()`, `ri_explode()`, `ri_recipe_version_on()` and the triggers do, and the screen shows their answer.
Schema: `menu_categories`, `recipes` (+ `ri_recipes_after()`), `recipe_versions` (+ `ri_recipe_versions_before()`), `recipe_lines`
(+ `ri_recipe_lines_before()`), `ri_recipe_reaches()`, `ri_recipe_version_on()`, `ri_recipe_allergens()`, `ri_recipe_version_activate()`,
`ri_recipe_version_draft()`, `ri_explode()`, `ri_recipe_cost()`, `recipe_cost_cache`, `ri_portion_cost_cached()`, `ri_price_history_after()`,
`menu_items` (+ `ri_menu_items_before()`), `menu_item_prices`, `ri_menu_price()`, `modifiers`, `menu_item_modifiers` (db/008); `ingredients`,
`ingredient_units`, `ri_to_base()`, `ri_from_base()`, `ri_cost_as_of()`, `price_history` (db/007); `ri_menu_engineering()`, `ri_find()` (db/016);
`sales_daily` (db/011 — read by menu engineering; empty until slice 5); the views `mcp_menu_categories`, `mcp_recipes`, `mcp_recipe_versions`,
`mcp_recipe_lines`, `mcp_menu_items`, `mcp_menu_item_prices`, `mcp_modifiers`, `mcp_menu_item_modifiers`, `mcp_ingredients`,
`mcp_ingredient_units`, `mcp_units`, `mcp_ingredient_site_costs`, `mcp_sources`, `mcp_sales_daily`, `mcp_notes`, `mcp_attachments`,
`mcp_activity_log` (db/017). Never modify them. **The database is the referee**: a prep recipe names its output ingredient and no other kind
does, a recipe never contains itself through any path, a version's lines change only while it is a draft, one draft per recipe, one open
active version per recipe, activation ends the previous version at the new one's start and refuses a date before an active one's, an active
version is immutable (a new version carries the change), a menu item maps only to a menu-item recipe, a line's quantity is converted through
the ingredient's units (a prep sub-recipe in its output's units; a menu-item or modifier sub-recipe in portions), cost is computed as of a
date and never stored on the version — the handlers call the verb inside `ri_guard()` and show its sentence as a 422; PHP decides nothing twice.

## Screens (the editor, the what-if and menu engineering are designed at 1280 px; a recipe's page is read at 375 px on the line — the method and the quantities first)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `recipe-list` | `/recipes/?q=&kind=&category=&status=` | cards (`recipe-card-{id}`) grouped by kind (menu items, preps, modifiers) and menu category: the photo (or the kind's icon), name, code, the category chip, the version in force ("v3 since 4 Oct", or "draft only" `warning`, or "none" `dark`), the cost per portion at this site (walled — `ri_portion_cost_cached(version, site, today)`; "cost withheld"), the food-cost % against the menu price when a menu item maps to it (`ri_menu_price()` of the first mapped item at this site — walled), the sub-recipe count, allergen chips (≤ 3 + "+n"), archived `dark`; filters in one row: a search box (name, code — `ri_find()` kind recipe), kind, category, status (default active); **New recipe** (`recipes.write`); 48 a page |
| `recipe-add` / `recipe-edit` | `/recipes/new?kind=&output_ingredient=`, `/recipes/{recipe}/edit` | the form (below); `recipe-add` creates the recipe AND its first draft version (`recipe_create`) and lands on `recipe-version-edit`; `recipe-edit` changes the recipe's own fields only |
| `recipe-view` | `/recipes/{recipe}?version=&as_of=&site=&tab=` | the header: name, code, kind chip, category, the output ingredient (a prep — a link), portion, allergens rolled up as chips, the photo; the **version strip** (`version-strip`): every version as a chip "v1 · 1 Sep – 4 Oct", "v2 · 4 Oct – (in force)" `success`, "v3 · draft" `warning`, the chosen one active (`?version=` — the in-force one by default); **Edit**, **New draft** (`recipe_version_draft`; disabled with "a draft exists" when one does), **Edit the draft** (a link to `recipe-version-edit`), **Archive** / **Restore** (`hx-confirm`), **Delete** (`records.delete`; only when deletable), **What if**, **Cost history**; the cards — **Lines** (the table `recipe-lines-table` of the chosen version, read-only: seq, the ingredient or the sub-recipe (a link, with ↳ for a sub-recipe and its kind chip), quantity in the line's unit + in base (`format_qty()`), line yield % when ≠ 100, group, optional; **the cost column** (walled): the line's cost and its share of the batch from `ri_recipe_cost(version, site, as_of)`'s `lines` JSON — a sub-recipe's cost per unit says "from its own recipe"; the footer: batch cost, per portion, the number of lines uncosted with "no cost yet for …"), **The tree** (`recipe-tree`: the lines nested through the sub-recipes' versions in force on `as_of` — `ri_explode(version, as_of, true)` for the leaf totals beside the nested view: "this plate uses 216.7 g of chicken in all"), **Batch** (batch yield and unit, expected loss, the net yield; a prep: "4 l makes 3.6 l"), **Method** (the text, rendered as paragraphs), **Menu items** (`mcp_menu_items` with `recipe_id` = this: PLU, name, portions, the price at this site; the plate cost and food-cost % per item (walled)), **Used in** (`mcp_recipe_lines` where `sub_recipe_id` = this → the parent recipes), **Notes** (slice 1's `note_add` / `attachment_add`), **Trail** (`find_record_activity('recipe', id)` + `find_activity_by_key('recipe_id', id)`); `?as_of=` re-costs the page at that date (the header says "costed as of 1 Oct at Downtown"); `?site=` a held site (this site by default); `data-entity="recipe"` |
| `recipe-version-edit` | `/recipes/{recipe}/versions/{version}` | **THE RECIPE EDITOR** (a draft; an active or retired version → 303 to `recipe-view?version=`): the header (the recipe's name, "draft v3", the change note); **the batch form** (`version-form`, Pattern C, saved on change): batch_yield_qty + batch_yield_unit (a prep: a select of the output ingredient's units; a menu item or modifier: "portions" fixed), expected_loss_pct, change_note; **the lines table** `editor-lines-table` (`editor-line-row-{id}`): each line a row with its fields editable in place (quantity, unit, line yield, group, optional, notes — Pattern C: `hx-post` to `recipe_line_set` on change, the row re-rendered), the drag handle for `seq` (Sortable; ↑ ↓ buttons), **Remove**; **the add row** `editor-add-row` at the bottom: a kind toggle (ingredient · recipe), the record picker (`ingredient`, or `recipe` with params `kind` ≠ this one's exclusion `exclude` = this recipe), quantity, the unit select (filled by `hx-get /recipes/line-units?ingredient=` or `?sub_recipe=` once picked — the ingredient's own units + its class's global units, or the prep's output units, or "portion"), line yield (100), group, optional; **the conversion sentence** `editor-conversion` live beside the quantity ("2 kg = 2 000 g"; "1.5 floz = 44.36 ml"; "cannot: limes have no each-weight" in `danger` — `hx-get /recipes/convert?ingredient=&qty=&unit=` on change, Pattern A); **the cost panel** `editor-cost` (walled): batch cost, cost per portion, the lines' shares as a bar, "N lines uncosted" — re-rendered after every line save (`HX-Trigger: recipeChanged` → the panel's `hx-trigger="recipeChanged from:body"`); **Activate** (`activate-form`: effective_from date, today by default; the sentence beneath it says what happens — "v2 ends 11 Oct; v3 is in force from 11 Oct" or "v3 is the first version" — `hx-get /recipes/activate-preview?version=&from=`; `hx-confirm`), **Discard the draft** (`hx-confirm`); every save answers the row or the panel it changed, never the page |
| `recipe-what-if` | `/recipes/{recipe}/what-if?ingredient=&price=&unit=` | (walled whole — 403 "You may not see cost." without `sees_cost()`): the version in force's leaf ingredients (`ri_explode(version, today, true)`) as rows with their current cost per the ingredient's recipe unit and a **new price** input + unit per row; **Recalculate** → `what_if_cost()` re-costs through `ri_recipe_cost()` with the typed prices as overrides (a temp table of overrides read by `ri_cost_as_of()`? — no: DECISION, the screen calls `ri_recipe_cost()` and REPLACES each leaf's `cost_per_base` in the answer's `lines` JSON by the typed one, re-summing in PHP for this screen alone — the one exception to "PHP sums no cost", named here, because the function has no override argument and the what-if is a scratchpad, never a record); the old and new plate cost, the old and new food-cost % against the menu price; nothing is saved; `?ingredient=&price=&unit=` prefills one row (the command bar's "what if chicken goes to $3.50 a pound") |
| `recipe-cost-history` | `/recipes/{recipe}/cost?site=&weeks=` | (walled whole): the chart `recipe-cost-chart` (`shared/series-chart.php`): the cost per portion by week over `weeks` (52 by default) at the site — `ri_portion_cost_cached(ri_recipe_version_on(recipe, d), site, d)` for each Monday `d` —, the versions marked as changes; the table beneath: week, version, cost per portion, the menu price then, food-cost % |
| `menu-item-list` | `/menu/?q=&source=&category=&unmapped=` | the table `menu-items-table` (`menu-item-row-{id}`) with the **unmapped queue first** (`recipe_id IS NULL`, active, sold in the last 28 days — `ri_unmapped_plus()` of db/016 — in a `warning` band with "N PLUs sold with no recipe"): PLU, name, source (a chip; "typed" when none), category, price at this site (`ri_menu_price()`), the recipe (a link) or **Map** (`recipes.write`), portions, last sold, archived `dark`; filters: search (name, PLU — `ri_find()` kind menu_item), source (`mcp_sources` of this site), category, unmapped only; **New menu item** (a typed one, no POS) |
| `menu-item-view` | `/menu/{menu_item}` | the header: PLU, name, source chip, category, the recipe (a link) and portions, "sells nothing from stock" when `no_depletion`; **Map**, **Set price**, **Archive**; the cards — **Prices** (`mcp_menu_item_prices` per held site, newest first, + the item's `sale_price` as the default), **Modifiers** (`mcp_modifiers` of the same source seen on this item's sales (`pos_sale_lines.modifiers` — slice 5 fills them) with their action chip and recipe; the per-item override from `mcp_menu_item_modifiers`; a link to each `modifier-map`), **Sales by week** (`mcp_sales_daily` where `day_part_id IS NULL`, the last 12 weeks summed — empty until slice 5), **Cost** (walled: the plate cost today = `ri_portion_cost_cached(...) × portions`, the food-cost % against the price at this site, the recipe's lines as a short list); `data-entity="menu_item"` |
| `menu-item-map` | `/menu/{menu_item}/map` | the form (`map-form`): recipe (the picker `recipe` with param `kind=menu_item`; **New recipe** → `/recipes/new?kind=menu_item` with `?back=` here; **Clear** unmaps), portions (1), no_depletion (checkbox — "a PLU that sells nothing from stock: a service charge, a gift card"), menu_category, price (this site's — optional; posts `menu_item_price_set` after the map when given); the line "mapped by Dana on 4 Oct" when mapped |
| `modifier-list` | `/modifiers/?source=&unmapped=` | the table `modifiers-table` (`modifier-row-{id}`) with the unmapped first (`action <> 'none' AND recipe_id IS NULL` — and the never-decided `action = 'none'` with no `recipe_id` shown as "undecided" `warning`): code, name, source, action chip (add `success` · remove `danger` · swap `info` · none `secondary`), the recipe (a link), factor, first seen; **Map** per row |
| `modifier-map` | `/modifiers/{modifier}/map` | the form (`modifier-form`): action (radio add · remove · swap · none with one sentence each — "remove: the parent's quantity of the modifier recipe's lines is taken back"), recipe (the picker `recipe` with param `kind=modifier`; required unless none; **New recipe** → `/recipes/new?kind=modifier`), qty_factor (1); **Per menu item** beneath: a table of the menu items this modifier was seen on (`pos_sale_lines` — slice 5; empty until then, with an **Add** row: the picker `menu_item`) each with its own recipe and factor override (`menu_item_modifiers`) — saved through the same action with `menu_item` + `parent_recipe` + `parent_qty_factor` |
| `menu-engineering` | `/menu/engineering?from=&to=&category=&site=` | (walled whole): the window (the last 28 days by default; `from`/`to`), the site (held); `ri_menu_engineering(site, from, to)` as a table `engineering-table` (`engineering-row-{menu_item_id}`) by category: item (a link), sold, mix %, price, plate cost, contribution margin, CM total, food-cost %, the quadrant chip (star `success` · plowhorse `warning` · puzzle `info` · dog `danger`) with its one-line advice on hover; a category's CM average and mix threshold in its header row; the four-quadrant **scatter** `engineering-chart` (an inline SVG, the chart component's palette: x = mix %, y = CM, each item a marker labelled, the two threshold lines) above the table; empty until slice 5 fills `sales_daily` ("no sales in the window — the POS import is slice 5") |
| `menu-category-list` | `/menu/categories` | the table `menu-categories-table` (`menu-category-row-{id}`) in `sort_order`: name, key, kind chip (food · beverage · other), recipes, menu items, active; an inline row form (name, kind, sort order, active — Pattern C) and the add form at the top (name, key — from the name by default, kind); `recipes.write` |

## The recipe form (`recipe-form`, ids `recipe-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| name | text ≤ 120 | yes | trimmed |
| code | text ≤ 40 | no | from the name when absent (upper, `[^A-Z0-9]+` → `_`, ≤ 20, a numeric suffix on collision; `R-` prefix for a recipe, `M-` for a modifier — DECISION); typed duplicate → "That code is already taken." |
| kind | radio menu_item · prep · modifier | yes | `?kind=` prefills; read-only on edit once a version was activated or a menu item maps to it (the handler's sentence) |
| output_ingredient | the picker `ingredient` (param `is_prep` any — an ingredient the prep makes; shown for kind = prep) | prep: yes | the CHECKs' sentences ("A prep recipe names the ingredient it makes"; a non-prep with one refused); the trigger flags the ingredient `is_prep`; `?output_ingredient=` prefills |
| menu_category | select (`mcp_menu_categories` active, in order) | no | a prep defaults to `prep`, a modifier to `modifiers` |
| portion_qty + portion_unit | number > 0 + select (the global units) | no | informational — "one portion is 220 g" on the card |
| method | textarea ≤ 20,000 | no | paragraphs |
| photo | file (image) (`recipe-edit` and `recipe-add`) | no | `attachment_store('recipe', …)` → `photo_attachment_id` |
| batch_yield_qty + batch_yield_unit | number > 0 + select (a prep: the output ingredient's units; else "portions") (`recipe-add` only — the first draft's) | yes | 1 portion by default; a prep's unit required at activation (the verb's sentence) |
| expected_loss_pct | number in [0, 100) (`recipe-add` only) | no | 0 by default; "cook loss on the batch as a whole — the net yield is what the batch makes" |
| lines | JSON (an agent) `[{ingredient|sub_recipe, qty, unit, line_yield_pct, group_name, optional, notes}]` (`recipe-add` only) | no | each through the same validation as `recipe_line_set` |
| status | radio active · archived (`recipe-edit` only) | no | |

`recipe_update` refuses `lines` pointing at `recipe_line_set` ("Lines are changed on the draft version.") and refuses `kind` / `output_ingredient`
once activated or mapped (the handler's sentences — the schema allows a change that would break the ledger's prep flag).

## The line (`recipe_line_set`; the add row and each row form share `line-form-field-{name}` with the row's id as a prefix)
| Field | Input | Required | Rule |
|---|---|---|---|
| ingredient / sub_recipe | the picker (`ingredient`; or `recipe` with `exclude` = this recipe and `kind` ≠ … — any kind: a menu item may hold a prep OR a modifier OR another menu item taken in portions) | one of the two | the CHECK's sentence when both or neither; the cycle refused by `ri_recipe_lines_before()` in its words ("A recipe may not contain itself — Chicken stock reaches this recipe") |
| qty | number > 0 (4 dp) | yes | |
| unit | select (filled per the pick: an ingredient's own units + its class's global units, and the other classes' when it has a density / each-weight; a prep sub-recipe: its output ingredient's units; a menu-item or modifier sub-recipe: `portion` alone) | yes | `ri_to_base()`'s refusal (through the trigger) is the field error — "limes have no each-weight" |
| line_yield_pct | number in (0, 100] | no | 100 by default; "a dice yields less than a julienne — depletion-bearing, not cost-bearing" |
| group_name | text ≤ 40 ("garnish", "sauce") | no | |
| optional | checkbox | no | shown on the card; costed all the same (DECISION) |
| notes | text ≤ 200 | no | |
| seq | number (the drag order) | no | the next by default; `area_reorder`-style reordering posts `seq` per row through the same action |

## Computing, step by step (what each handler and partial calls)
- **The conversion sentence** (`/recipes/convert`, GET, Pattern A): `ri_to_base(ingredient, qty, unit)` → "`qty unit` = `format_qty(base)`"; a
  refusal → the sentence in `danger`; a prep sub-recipe converts through its output ingredient; a portion sub-recipe says "`qty` portion(s)".
- **A line** (`versions/line.php`): the version must be a draft and the recipe's (`require_visible()` on `mcp_recipe_versions`); `qty_base` is the
  trigger's (`ri_recipe_lines_before()` — never computed here); `save_recipe_line()` INSERTs or UPDATEs inside `ri_guard()`; `recipe.line_set` logged
  with `recipe_id` (`after`: ingredient_id or sub_recipe_id, qty, unit_code, qty_base, line_yield_pct, seq); the reply (HTML) is the row partial +
  `HX-Trigger: recipeChanged` so the cost panel refreshes; JSON: `record_id` = the line.
- **The cost panel** (`/recipes/{recipe}/versions/{version}/cost`, GET, Pattern A, walled): `recipe_cost(version, site, now)` →
  `ri_recipe_cost()`'s row; shows batch cost, per portion, the lines' shares (each `line_cost / batch_cost`), the uncosted count; for a caller
  without `sees_cost()` the panel renders "cost withheld" and the handler never calls the function (the function itself is not walled — the
  screens are; the Phase 4 tools wall it through `ri_sees_cost()` before calling).
- **Activation** (`versions/activate.php`): `effective_from` (today by default); `ri_recipe_version_activate(version, by, from)` inside
  `ri_guard()` — the verb refuses an empty draft, a prep without a batch unit, a date at or before an active version's start, in its words; the
  verb ends the previous version and rolls allergens up; `recipe.version_activate` logged (`after`: version_no, effective_from, ended_version_no,
  ended_at); **other** (an agent pauses — every cost changes from the date); location `/recipes/{recipe}?version={id}`; refresh `recipeChanged`.
  The preview (`/recipes/activate-preview`, GET) reads `mcp_recipe_versions` and says the sentence without writing.
- **A new draft** (`versions/draft.php`): `ri_recipe_version_draft(recipe, by, note)` — copies the version in force today (or starts empty);
  the verb refuses a second draft; `recipe.version_draft` (`after`: version_no, copied_from); location `/recipes/{recipe}/versions/{id}`.
- **Discard** (`versions/discard.php`): a draft only (the handler's check on `mcp_recipe_versions.status`); DELETE the version (its lines cascade);
  `recipe.version_discard`; location `/recipes/{recipe}`. **Retire** (`versions/retire.php`): an active version with `effective_to IS NULL` and no
  later version; `UPDATE recipe_versions SET status = 'retired', effective_to = :to` (the trigger admits the closing of the window); the recipe
  leaves the menu from that date — `ri_recipe_version_on()` answers null after it; `recipe.version_retire`.
- **A recipe** (`save.php`): `recipe_from_request()`; `save_recipe()` INSERT inside `ri_guard()` (the CHECKs' sentences on `output_ingredient`);
  on create, `recipe_versions` INSERT (the first draft, `version_no` 1 by the trigger) and the `lines` JSON through `save_recipe_line()` each;
  the photo through `attachment_store()`; `recipe.create` (`after`: name, code, kind, output_ingredient_id, menu_category_id, version_id) /
  `recipe.update` (`ri_diff()`); location `/recipes/{id}/versions/{version}` on create, `/recipes/{id}` on update.
- **The menu map** (`/menu/map.php`): `recipe` blank unmaps; `no_depletion` → `recipe_id NULL` AND `active` kept with a note? — DECISION: `menu_items`
  has no `no_depletion` column; the map stores it as `recipe_id NULL` + `portions = 0` (the CHECK `portions > 0` refuses that) — so **db/018 of slice 2
  appends `no_depletion boolean NOT NULL DEFAULT false` to `menu_items`** and `ri_unmapped_plus()` / `ri_day_depletion()` / `ri_depletion_run()` learn to
  skip it (an additive migration: the column, the three functions replaced — the kernel's rule; the manifest's `menu_item_map` already names the
  parameter); the trigger `ri_menu_items_before()` refuses a non-menu-item recipe in its words and stamps `mapped_by/at`; `menu_item.map` logged
  (`before.recipe_id`, `after.recipe_id`, `portions`, `no_depletion`); with `price` the handler also calls `set_menu_price()` and logs
  `menu_item.price_set`; location `/menu/{id}`; refresh `menuItemChanged`.
- **A price** (`/menu/price.php`): `site` blank → `menu_items.sale_price` (every site); else `menu_item_prices` INSERT (site, effective_from — a
  second save the same day UPDATEs); `menu_item.price_set` (`after`: price, scope_id, effective_from).
- **A modifier** (`/modifiers/map.php`): without `menu_item`: `modifiers` UPDATE (action, recipe_id — required unless none, qty_factor); with
  `menu_item`: `menu_item_modifiers` INSERT … ON CONFLICT DO UPDATE (recipe_id = parent_recipe, qty_factor = parent_qty_factor); a recipe of
  kind ≠ modifier refused in words (the handler's — the schema has no CHECK); `modifier.map` logged (`after`: action, recipe_id, qty_factor,
  menu_item_id when per item).
- **Menu engineering**: `menu_engineering(site, from, to, category)` → `ri_menu_engineering()` rows grouped by category; the scatter from the
  same rows; `report.run`-style logging is slice 9's — here `screen.view` with `after.window`.

## Files (exactly these)
- `html/recipes/index.php` (`recipe-list`) · `form.php` (`recipe-add`, `recipe-edit`) · `view.php` (`recipe-view`) · `what-if.php` (`recipe-what-if`) · `cost.php` (`recipe-cost-history`) · `save.php` · `archive.php` · `delete.php` · `convert.php` (GET, Pattern A) · `line-units.php` (GET, Pattern A) · `activate-preview.php` (GET) · `versions/edit.php` (`recipe-version-edit`) · `versions/cost.php` (GET, the panel) · `versions/draft.php` · `versions/save.php` · `versions/line.php` · `versions/line-remove.php` · `versions/activate.php` · `versions/discard.php` · `versions/retire.php`
- `html/menu/index.php` (`menu-item-list`) · `view.php` (`menu-item-view`) · `map.php` (`menu-item-map` on GET, `menu_item_map` on POST) · `save.php` · `price.php` · `archive.php` · `engineering.php` (`menu-engineering`) · `categories.php` (`menu-category-list`) · `categories/save.php`
- `html/modifiers/index.php` (`modifier-list`) · `map.php` (`modifier-map` on GET, `modifier_map` on POST)
- `db/018_phase1_fixes.sql` — ADDITIVE (the DECISION above): `menu_items` appends `no_depletion`; `ri_unmapped_plus()`, `ri_day_depletion()` and `ri_depletion_run()` replaced to skip it (`WHERE NOT m.no_depletion`) and to count such lines as neither depleted nor unmapped (`lines_no_depletion` appended to `depletion_runs`); `mcp_menu_items` and `mcp_depletion_runs` replaced with the column appended last. (If sso-shell.md's `db/018` lands first, this is `db/018` — the builder numbers it after the shell's.)
- `app/features/recipes/{queries,present,write,handler}.php` (`handler.php`: `recipe_from_request()`, `line_from_request()`, `recipes_log()` — `entity_type` recipe / recipe_version / recipe_line / menu_item / modifier / menu_category with `recipe_id` on every recipe-side row)
- `app/views/recipes/{recipes,recipe-form,recipe,editor,what-if,cost-history,partials/recipe-card,partials/version-strip,partials/lines-table,partials/tree,partials/editor-row,partials/editor-add-row,partials/cost-panel,partials/conversion,partials/activate-preview,partials/menu-items-tab,partials/used-in}.php`
- `app/views/menu/{menu-items,menu-item,map,engineering,categories,partials/menu-item-row,partials/unmapped-band,partials/modifiers-tab,partials/engineering-row,partials/engineering-chart,partials/menu-category-row}.php`
- `app/views/modifiers/{modifiers,map,partials/modifier-row,partials/per-item-row}.php`
- `app/pickers.php` gains `recipe` (params `kind`, `exclude`; label "name · code", detail "kind · category · v in force", badge the kind), `menu_item` (params `source`; label "PLU · name"), `modifier` (params `source`)
- `app/partial_update.php`: `PARTIAL_UPDATE_TARGETS` gains `'/recipes/save.php' => ['recipes', 'recipe', 'mcp_recipes', 'recipe_id']`, `'/menu/save.php' => ['menu_items', 'menu_item', 'mcp_menu_items', 'menu_item_id']`
- `app/features/shell/nav.php`: `back_link()` learns `/recipes/{id}` → "the recipe", `/menu/{id}` → "the menu item"

## Query functions (signatures fixed; `app/features/recipes/queries.php`; the tools of Phase 4 call these and the resolvers)
- `find_recipes(PDO, int $scopeId, array $filters, int $limit = 48, int $offset = 0): array` (`mcp_recipes` + the version in force + the cached portion cost at the site (walled by the caller's `sees_cost`) + the first mapped item's price; `q`, `kind`, `category`, `status`) — tool `find_recipes`
- `find_recipe(PDO, int $id): ?array` · `recipe_full(PDO, int $id, int $scopeId, ?int $versionId = null, ?string $asOf = null): ?array` (`{recipe, versions, version (chosen), lines (with cost shares when seen), batch, allergens, menu_items, used_in, notes, attachments}`) — tool `get_recipe`
- `recipe_versions(PDO, int $recipeId): array` (`mcp_recipe_versions`) · `recipe_version(PDO, int $versionId): ?array` · `version_in_force(PDO, int $recipeId, ?string $on = null): ?int` (`ri_recipe_version_on()`) — tool `recipe_version_at`
- `recipe_lines(PDO, int $versionId): array` (`mcp_recipe_lines` joined to the ingredient's or sub-recipe's name, each with `qty_display` through `format_qty()`)
- `recipe_tree(PDO, int $versionId, string $on, int $depth = 0): array` (the nested view through the sub-recipes' versions in force; ≤ 12 deep) + `recipe_leaves(PDO, int $versionId, string $on): array` (`ri_explode(version, on, true)` joined to the ingredient names) — tool `recipe_tree`
- `recipe_cost(PDO, int $versionId, int $scopeId, ?string $asOf = null, ?string $basis = null): ?array` (`ri_recipe_cost()`'s row — `batch_cost`, `portion_cost`, `batch_yield_qty`, `lines` (decoded), `uncosted`; **null for a caller without `sees_cost($scopeId)`** — the tool answers `withheld`) — tool `recipe_cost` · `portion_cost_cached(PDO, int $versionId, int $scopeId, string $on): ?float`
- `what_if_cost(PDO, int $versionId, int $scopeId, array $overrides): ?array` (`[{ingredient_id, cost_per_base}]` replacing the leaves' costs in the function's answer and re-summed — the scratchpad; walled) — tool `recipe_what_if`
- `cost_history_weeks(PDO, int $recipeId, int $scopeId, int $weeks = 52): array` (`[{week, version_no, portion_cost, menu_price, food_cost_pct}]`; walled)
- `recipes_using(PDO, int $ingredientId, int $limit = 50): array` (slice 1's — shared) · `used_in(PDO, int $recipeId): array` (`mcp_recipe_lines` by `sub_recipe_id` → parents)
- `recipe_allergens(PDO, int $recipeId): array` (`mcp_recipes.allergens_rollup`) — tool `recipe_allergens`
- `line_unit_options(PDO, ?int $ingredientId, ?int $subRecipeId): array` (`[{code, label}]`) · `convert_sentence(PDO, int $ingredientId, float $qty, string $unit): array` (`['ok', 'text', 'qty_base']`)
- `activation_preview(PDO, int $versionId, string $from): array` (`['ok', 'text', 'ends_version_no']`)
- `recipe_deletable(PDO, int $id): ?string` (null, or: a version activated, a menu item mapped, a line of another recipe names it, a production batch) · `recipe_locked(PDO, int $id): bool` (activated or mapped — kind and output locked)
- `find_menu_items(PDO, int $scopeId, array $filters, int $limit = 100, int $offset = 0): array` (`mcp_menu_items` of this site's sources + typed ones; `q`, `source`, `category`, `unmapped`; the price through `ri_menu_price()`) — tool `find_menu_items` · `find_menu_item(PDO, int $id): ?array` · `menu_item_full(PDO, int $id, int $scopeId): ?array` (`{menu_item, prices, modifiers, sales_by_week, cost (walled)}`) — tool `get_menu_item`
- `unmapped_plus(PDO, int $scopeId, int $days = 28): array` (`ri_unmapped_plus()`) — tool `unmapped_plus`
- `find_modifiers(PDO, int $scopeId, array $filters): array` (`mcp_modifiers` + per-item rows) — tool `find_modifiers` · `modifier_parents(PDO, int $modifierId): array` (the menu items it was seen on — `pos_sale_lines`, slice 5; empty before)
- `menu_engineering(PDO, int $scopeId, string $from, string $to, ?int $categoryId = null, ?string $basis = null): array` (`ri_menu_engineering()` rows grouped by category with the category's `cm_avg` and `mix_threshold`; walled) — tool `menu_engineering`
- `menu_categories(PDO, bool $activeOnly = true): array`
- Writes (`write.php`): `save_recipe(PDO, ?int $id, array $fields, int $by): array` (`['id', 'version_id']` — the first draft on create) · `archive_recipe(PDO, int $id, bool $archived): void` · `delete_recipe(PDO, int $id): void` · `new_draft(PDO, int $recipeId, ?string $note, int $by): int` (`ri_recipe_version_draft()`) · `save_version(PDO, int $versionId, array $fields): array` (before/after; a draft) · `save_recipe_line(PDO, int $versionId, ?int $lineId, array $fields): int` · `remove_recipe_line(PDO, int $lineId): array` · `reorder_lines(PDO, int $versionId, array $lineIds): void` · `activate_version(PDO, int $versionId, string $from, int $by): array` (`ri_recipe_version_activate()`; `['version', 'ended']`) · `discard_version(PDO, int $versionId): void` · `retire_version(PDO, int $versionId, string $to, int $by): void` · `save_menu_item(PDO, ?int $id, array $fields, int $by): int` · `map_menu_item(PDO, int $id, ?int $recipeId, float $portions, bool $noDepletion, ?int $categoryId, int $by): array` · `set_menu_price(PDO, int $id, ?int $scopeId, string $price, string $from): void` · `archive_menu_item(PDO, int $id, bool $archived): void` · `map_modifier(PDO, int $modifierId, string $action, ?int $recipeId, float $factor, ?int $menuItemId, ?int $parentRecipeId, ?float $parentFactor): array` · `save_menu_category(PDO, ?int $id, array $fields): int`

## Handlers (every one: `ri_handler_begin()`; the right at the current site; `ri_guard()`; `log_activity` with `recipe_id` (and `scope_id`); `ri_done()`; `HX-Trigger: recipeChanged` — `menuItemChanged` / `modifierChanged` for those)
- `recipes/save.php` (`recipe_create` without `recipe`, `recipe_update` with): `recipes.write`; `record_id`; location as above.
- `recipes/archive.php` (`recipe_archive`): `recipes.write`; `recipe.archive` (`after.archived`).
- `recipes/delete.php` (`recipe_delete`): `records.delete`; `recipe_deletable()` else 422; `recipe.delete` (`after`: name, code, kind); **deletion**; location `/recipes/`.
- `recipes/versions/draft.php` (`recipe_version_draft`), `save.php` (`recipe_version_update` — `recipe.version_update`, `ri_diff()`), `line.php` (`recipe_line_set`), `line-remove.php` (`recipe_line_remove` — `recipe.line_remove`, `before` the line), `activate.php` (`recipe_version_activate`, **other**), `discard.php` (`recipe_version_discard`), `retire.php` (`recipe_version_retire`): `recipes.write`; as "Computing, step by step".
- `menu/save.php` (`menu_item_create`): `recipes.write`; a PLU unique per source (`23505` → "That PLU is already on the list for this source."); `menu_item.create`; location `/menu/{id}`.
- `menu/map.php` POST (`menu_item_map`), `menu/price.php` (`menu_item_price_set`), `menu/archive.php` (`menu_item_archive` — `menu_item.archive`): `recipes.write`.
- `modifiers/map.php` POST (`modifier_map`): `recipes.write`; location `/modifiers/{id}/map`.
- `menu/categories/save.php` (`menu_category_save`): `recipes.write`; `menu_category.save`; a Pattern C request answers the row partial.

## Manifest rows claimed
Screens (14): `recipe-list`, `recipe-add`, `recipe-view`, `recipe-edit`, `recipe-version-edit`, `recipe-what-if`, `recipe-cost-history`, `menu-item-list`, `menu-item-view`, `menu-item-map`, `modifier-list`, `modifier-map`, `menu-engineering`, `menu-category-list`
Actions (17): `recipe_create`, `recipe_update`, `recipe_archive`, `recipe_delete`, `recipe_version_draft`, `recipe_version_update`, `recipe_line_set`, `recipe_line_remove`, `recipe_version_activate`, `recipe_version_discard`, `recipe_version_retire`, `menu_item_create`, `menu_item_map`, `menu_item_price_set`, `menu_item_archive`, `modifier_map`, `menu_category_save`
Agent approvals: `recipe_delete` (`deletion`); `recipe_version_activate` (`other`).

Every row of the manifest's slice-2 section is claimed; none is left to another slice. (The manifest's Size line says 13 screens for this slice;
the section lists fourteen — `menu-category-list` is the fourteenth; reported to the lead.)

## Activity log events
`recipe.create|update|archive|delete|version_draft|version_update|version_activate|version_discard|version_retire|line_set|line_remove`,
`menu_item.create|map|price_set|archive`, `modifier.map`, `menu_category.save`, `screen.view` (`recipe-view`, `recipe-version-edit`,
`menu-engineering` with `after.window`). Every recipe-side row carries `recipe_id`; a menu item's and a modifier's rows carry `after.recipe_id`;
a price carries `scope_id`. **No row carries the method's text** (its length only) — names, codes, quantities with their units, versions, dates
and prices are expected; **a cost is never in a payload** (the cost panel is a read).

## Notifications this slice queues
None.

## Status vocabulary
Kind chips: menu item `primary`, prep `info`, modifier `secondary`. Version chips: in force `success`, draft `warning`, ended / retired `dark`.
Modifier action chips: add `success`, remove `danger`, swap `info`, none `secondary`, undecided `warning`. Quadrants: star `success` "★ Star",
plowhorse `warning` "Plowhorse", puzzle `info` "Puzzle", dog `danger` "Dog". Cost withheld "—" with the title "cost withheld"; an uncosted line
`warning` "no cost yet". Allergen chips `light` with the allergen's word.
Ids: `recipe-list`, `recipe-list-filters`, `recipe-card-{id}`, `recipe-form`, `recipe-form-field-{name}`, `recipe-view-header`, `version-strip`,
`version-chip-{id}`, `recipe-lines-table`, `recipe-line-row-{id}`, `recipe-cost-footer`, `recipe-tree`, `tree-node-{line_id}`, `recipe-batch`,
`recipe-method`, `recipe-menu-items`, `recipe-used-in`, `editor`, `version-form`, `version-form-field-{name}`, `editor-lines-table`,
`editor-line-row-{id}`, `editor-line-row-{id}-field-{name}`, `editor-add-row`, `line-form-field-{name}`, `line-form-field-kind-ingredient`,
`line-form-field-kind-recipe`, `editor-conversion`, `editor-cost`, `editor-cost-batch`, `editor-cost-portion`, `editor-cost-shares`,
`editor-cost-uncosted`, `activate-form`, `activate-form-field-effective-from`, `activate-preview`, `discard-draft`, `what-if-form`,
`what-if-row-{ingredient_id}`, `what-if-row-{ingredient_id}-field-price`, `what-if-result`, `recipe-cost-chart`, `recipe-cost-table`,
`menu-items-table`, `unmapped-band`, `menu-item-row-{id}`, `menu-item-view-header`, `menu-item-prices`, `menu-item-modifiers`,
`menu-item-sales`, `menu-item-cost`, `map-form`, `map-form-field-{name}`, `modifiers-table`, `modifier-row-{id}`, `modifier-form`,
`modifier-form-field-{name}`, `per-item-table`, `per-item-row-{menu_item_id}`, `engineering-table`, `engineering-row-{menu_item_id}`,
`engineering-category-{id}`, `engineering-chart`, `menu-categories-table`, `menu-category-row-{id}`, `menu-category-form`.

## Mobile rule (375 × 740)
A recipe's page at 375 px shows the method and the lines first (the cost cards after); the editor is usable at 375 px — each row's fields
stack in two lines (the pick and the quantity + unit on the first, yield · group · optional on the second), the add row the same, the cost
panel a sticky footer; the picker full screen; the what-if rows stack; the engineering table scrolls inside `.table-responsive` and the
scatter scales with its card; every control ≥ 44 px; `scrollWidth` = viewport.

## Vocabulary
*recipe* (a menu item, a prep or a modifier), *version* (a draft, in force or ended — with effective dates), *line* (an ingredient or a
sub-recipe with a quantity in a unit and a line yield), *sub-recipe* (a recipe named by a line — a prep taken from stock in its output's units;
a menu item or modifier taken in portions), *batch yield* (what one batch makes — a prep's output, or portions), *expected loss* (cook loss on
the batch), *line yield* (what the line's preparation keeps — depletion-bearing), *plate cost* (the cost per portion as of a date at a site),
*food-cost %* (plate cost ÷ the menu price), *menu item* (what the POS sells, by PLU), *modifier* (a POS modifier with an action and a
modifier recipe), *no depletion* (a PLU that sells nothing from stock), *menu engineering* (Kasavana & Smith's four quadrants).

## Out of scope for this slice
The ledger (3 — production from a prep recipe); the POS import that fills the sales behind menu engineering and the modifiers' parents (5);
the depletion that uses the versions in force (5); the forecast that explodes through the recipes (8); nutrition (Extended); menu price
suggestions from a target food-cost % (Extended); a recipe's photo gallery beyond one photo (Extended).

## Proof (`tests/phase3/slice2/run.sh`: the scratch database `ri_dev2b`, the application on 9301 (`php -S`, or `RI_APP=apache` with the internal
vhost on 9307), the fake kernel 9302, the fake MaluDB + MaluMail 9303; slice 1's world (`catalog_world()`) plus this slice's; members through dev
hand-offs with their site, curl with signed action and run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`;
`sync_approvals --check`; `tests/phase1/check_specs.sh`)
Target: **≥ 240 checks** (recipes 30, editor 50, versions 30, costing 35, what-if 10, menu items 30, modifiers 15, engineering 10, categories 8,
visibility 20, json 20, browser 40).
The world (`tests/phase3/slice2/lib.php` `recipes_world()`): Dana (Chef at Downtown) makes the prep "Chicken stock" (output: the `stock` ingredient;
2 kg chicken; 4 l, 10 % loss; activated 30 days back), the menu item "Chicken sandwich" (170 g chicken at 90 %, 50 ml of the stock, 1 egg; 1
portion; activated 20 days back), the modifier "Extra chicken" (85 g); the receipts of slice 1's proof (by SQL through `ri_post_receipt()`) give
chicken $3.10/lb, eggs $54/case, flour $25/bag at Downtown; the POS source "SMOKE Toast export" with PLU 1001 mapped and the modifier XCHK; Ann
(Viewer), Bea (Kitchen), Marco (Manager), the expert (agent 40).
- [ ] **Recipes**: the three created with their first drafts (`record_id` and `location` on the editor); a prep without an output ingredient refused
  with the CHECK's sentence; a menu item with one refused; the code `R-CHICKEN_STOCK` made; the output ingredient flagged `is_prep`; `kind` locked
  after activation (the handler's sentence); archived and restored; the cards grouped by kind with the version chip and the walled cost; the
  filters; `recipe.create|update|archive` logged with `recipe_id`.
- [ ] **The editor**: a line picked (the picker `ingredient` and `recipe` with `exclude`), `2 kg` of chicken → the conversion sentence "2 kg = 2 000 g"
  and `qty_base` 2 000 by the trigger; `1.5 floz` of gin → 44.36 ml; limes in `g` → the sentence in `danger` and the save refused on `unit`; the stock
  as a sub-recipe `50 ml` → `qty_base` 50 (its output's base); the sandwich as a sub-recipe of the stock refused "may not contain itself"; a
  menu-item sub-recipe in `g` refused "taken in portions"; a line's quantity edited in place (Pattern C) and the row re-rendered; a line removed;
  the rows reordered by drag → `seq`; the batch form saves yield, unit and loss (a prep's unit from the output's units); an active version's line
  save refused "only while it is a draft"; the cost panel after each save (Dana sees it; Bea sees "cost withheld" and the panel never calls the
  function); `recipe.line_set` logged with `qty_base`.
- [ ] **Versions**: the stock's draft activated 30 days back and the sandwich's 20 days back; an empty draft refused; a prep without a batch unit
  refused; a new draft of the stock copies its line; the second draft refused; the draft changed to 2.5 kg and activated from tomorrow → v1 ends
  tomorrow (the preview said so first), `ri_recipe_version_on(stock, today)` = v1 and tomorrow = v2 on the page's version strip; a date before v2's
  start refused in words; v1's batch yield change refused "a new version carries the change"; a draft discarded (its lines gone); a version retired
  from a date → the card says "none" after it; allergens rolled up (`{egg}` on the sandwich) and the stock's output ingredient inherits none;
  `recipe.version_activate` logged with `ended_version_no`.
- [ ] **Costing**: the sandwich's cost per portion at Downtown = 188.89 g × $3.10/453.59 + 50 ml × (2 000 × that ÷ 3 600) + $0.30 (the Phase 0
  proof's number, 1.7808 ± 0.01) on the page, the panel and `recipe_cost()`; the lines' shares sum to the batch; the prep's cost per unit "from its
  own recipe"; `?as_of=` 10 days back (before the $30 flour receipt — irrelevant to the sandwich; use chicken's override instead): a cost override of
  $3.50/lb on chicken by Marco today → the page today shows the new cost and `?as_of=yesterday` the old; the cache row written and invalidated by
  the override (`recipe_cost_cache` emptied for today); the food-cost % against PLU 1001's $14 price (12.7 %); an ingredient with no cost yet (a
  new one on a draft) counted `uncosted` and named; the cost history by week shows the versions marked; Bea's `recipe_cost()` null → the tool
  surface's `withheld`.
- [ ] **What-if**: chicken at $3.50/lb → the new plate cost and % beside the old; nothing saved (no `price_history` row, the cache untouched);
  Ann/Bea → 403 "You may not see cost."; `?ingredient=&price=&unit=` prefills the row.
- [ ] **Menu items**: PLU 1001 created on the source (typed) and mapped to the sandwich through the picker — `mapped_by/at` stamped; a map to the
  stock (a prep) refused "A menu item maps to a menu-item recipe"; `no_depletion` on a "Service charge" PLU → db/018's column, the item leaves the
  unmapped queue; a duplicate PLU on the source refused; a price of $14 at Downtown → `menu_item_prices`, and `ri_menu_price()` answers it at
  Downtown and the `sale_price` at Airport; archived; the unmapped band lists a PLU inserted by SQL as sold (a `pos_sales` + line row through SQL —
  slice 5's world is not here) and not the `no_depletion` one; `menu_item.map|price_set|archive` logged.
- [ ] **Modifiers**: XCHK mapped `add` to the "Extra chicken" recipe with factor 1; a map to a menu-item recipe refused in words; `remove` without a
  recipe refused; `none` without one accepted; a per-item override for PLU 1001 (`parent_recipe`, factor 2) → `menu_item_modifiers`; `modifier.map`
  logged with `menu_item_id` when per item.
- [ ] **Menu engineering**: with `sales_daily` rows inserted by SQL for four weeks (the sandwich 20–40 a day, a "SMOKE Salad" PLU 1002 mapped to a
  cheap recipe 5 a day) the table shows two rows with their quadrants (the sandwich a star or plowhorse by its CM against the average; the salad a
  dog or puzzle), the category's CM average and the 70 % threshold; the scatter with two markers; an empty window says so; Ann → 403.
- [ ] **Categories**: ten seeded; "SMOKE Specials" added and renamed inline; Bea → 403.
- [ ] **Who sees**: Ann and Bea read every recipe's lines, method and versions with no cost column, no cost panel, no what-if, no cost history, no
  engineering (403 in words), no Edit; Bea with `kitchen_sees_cost` on at Downtown (SQL) sees the cost column; the owner deletes a never-activated
  recipe and is refused on the stock (activated, used as a sub-recipe) in words.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `recipe_create` with `lines` JSON
  makes the draft's lines in one POST (the dictation route — `recipe-from-dictation`); a line with `qty` + `unit` lands in base; 422 naming the field;
  the expert (run token + relay) creates a recipe with lines and maps a PLU as `source agent`, is refused `recipe_delete` in words; its
  `recipe_version_activate` lands (the pause is the kernel's hook — Phase 4).
- [ ] **375 × 740 and 1280 × 800**: the editor's rows stack in two lines and the cost panel sticks; the picker chooses an ingredient and a recipe
  (the `exclude` honoured — the recipe itself absent from the rows); the conversion sentence updates on typing (300 ms); the drag order; the
  version strip; the what-if; the engineering scatter scales; JavaScript off saves a line (the add row is a plain form), activates with the
  preview as a static sentence, and maps a PLU; every control ≥ 44 px, `scrollWidth` = viewport, no console errors; the registry reads 40 screens
  and 50 actions built.

**Decisions taken in this spec (not questions):**
- The editor saves one line at a time (Pattern C) and never the whole form; the cost panel is a partial refreshed by `recipeChanged`; the
  conversion sentence is a GET fragment over `ri_to_base()`.
- PHP sums no cost anywhere but the what-if scratchpad, which replaces the leaves' costs in `ri_recipe_cost()`'s own `lines` answer and re-sums
  for the screen alone (nothing is saved; the function has no override argument — Phase 4's `recipe_what_if` tool does the same in Python over the
  same answer).
- `no_depletion` gets its column (db/018 of this slice, additive; the three depletion functions replaced to skip it) — the manifest's parameter had
  no home in the schema.
- A recipe's `kind` and `output_ingredient` are locked once activated or mapped; `recipe_update` refuses `lines` pointing at `recipe_line_set`.
- A sub-recipe of any kind may sit on any kind's line: a prep in its output's units, a menu item or a modifier in portions (the trigger's rule).
- Optional lines are costed all the same (the card marks them optional).
- Codes are prefixed `R-` / `M-` when made from the name.
- Menu engineering, the what-if and the cost history are walled whole (403) — not a column nulled.

## Open questions
(none)

## Built and proven

Built 2026-10-11 by the planning model (a fork beside slice 1), on `/srv/apps/restaurant_inventory`; the application's commits are not pushed.

**Proof:** `tests/phase3/slice2/run.sh` — recipes 82, costing 25, menu 40, visibility 30, the browser 35 (headless Chromium at 1280 × 800, 375 × 740
and with JavaScript off), the registry, the approvals and the Phase 1 claim check: **215 checks under php -S (scratch `ri_dev3b`, :9304–9306) and 215 under
a user-level Apache rendering `deploy/apache-restaurant_inventory.conf` (scratch `ri_dev3c`, the internal vhost on :9314 — :9307 is phase 2's).**
`tests/phase2/run.sh`, `tests/phase0/run.sh` (124), `tests/phase1/check_specs.sh` (52) and `php bin/build_action_registry.php --check` are green; the
registry reads 40 screens and 50 actions built (14 + 17 of them this slice's). Screenshots in `/tmp/ri-shots-s2`.

**Files:** `app/features/recipes/{queries,write,handler,present}.php`; the unit sentences from `app/units.php` (the two copies were reconciled into `app/features/units/queries.php` after the build, 2026-10-11; `app/recipes_units.php` is gone) — formerly deferring to slice 1's helpers when
present); `app/pickers/recipes.php` (sources `recipe` with `kind`/`exclude`, `menu_item`, `modifier`) loaded by a glob in `app/pickers.php`;
`app/partial_targets/recipes.php` loaded by `partial_update_targets()` in `app/partial_update.php`; `html/recipes/*` with `html/recipes/versions/*`
(the editor `versions/view.php`, the cost panel, draft/save/line/line-remove/reorder/activate/discard/retire), `html/menu/*` with `menu/categories/*`,
`html/modifiers/*`; the views under `app/views/recipes`, `app/views/menu`, `app/views/modifiers`; the slice's CSS at the end of
`html/assets/css/app-overrides.css`; `db/021_slice2_menu_items_view.sql`; `tests/phase3/slice2/*`; `tests/setup_dev.sh` (one scratch password per host,
`RI_SCRATCH_PW_FILE`, so two proofs in flight never invalidate each other's roles).

**Decisions taken while building (reported, not questions):**
- **The editor's URL.** The manifest's `/recipes/{recipe}/versions/{version}` had no route: the vhost's rewrites know `/x/{id}` and `/x/{id}/page`
  only. The editor lives at `/recipes/versions/{version}` (what every link and `location` says) and a new generic rewrite — *a child record of a
  record*, `/x/{id}/y/{id2}` → `x/y/view.php?parent=&id=` — in both vhosts of `deploy/apache-restaurant_inventory.conf`, in `tests/dev_router.php`
  and in `bin/build_action_registry.php`'s controller mapping makes the manifest's shape reach the same editor (proven) and the registry count it built.
- **Version edits are refused by the trigger, not by PHP.** `recipe_version_update`, `recipe_line_set`, `recipe_line_remove` and the reorder send the
  write and let `recipe_versions_immutable` / `recipe_lines_draft_only` speak (422 through `ri_guard()`); `version_or_404($pdo, $id, draft: true)`
  only chooses the 404 wording.
- **The CHECKs' sentences live in PHP once:** "A prep recipe names the ingredient it makes." and "Only a prep recipe makes an ingredient." are said
  by `recipe_from_request()` as field errors, because a CHECK's name is not a sentence a cook can read.
- **`ri_resolve_menu_item()` reads an id first, then a PLU** (the manifest's `menu_item` parameter is either); a recipe by id, then code, then an
  exact name.
- **`map_menu_item()` touches `pos_sales.imported_at`** for the item's unmapped sales so slice 5's depletion picks them up on its next pass (the spec's
  "the unmapped band shrinks").
- **The modifier map is one handler for both levels:** `modifier_map` with `menu_item` sets a per-item override in `menu_item_modifiers`; without, the
  modifier's own recipe; a non-modifier recipe is refused in words before the database is asked.
- **The what-if posts to the page it is on** (`/recipes/{recipe}/what-if`, GET renders, POST answers the result partial) — no separate action, nothing
  saved, `ri_recipe_what_if()` (db/018) does the sum.
- **The add row's fragments name their target** (`hx-target="this"` on the unit select and the conversion sentence, and on a row's unit select): inside
  a form that carries `hx-target`, a fragment inherits the form's target — the first browser run swapped the options into the add row itself.
- The reorder posts once, from Sortable's `onEnd` (the tbody's `hx-trigger="end"` would have posted a second, malformed body).

**What the spec or the schema got wrong:**
- `mcp_menu_items` lacked `no_depletion` (db/018 added the column and not the view) — `db/021_slice2_menu_items_view.sql` replaces the view with the
  column appended last.
- The manifest's editor URL (above).
- The spec's browser proof asked for the picker "`exclude` honoured — the recipe itself absent": the picker sends `exclude` as a fixed param and the
  source honours it; the first assertion matched another recipe whose name contained the same words — the check now asserts by id.
- A row's remove button was 43 px wide: `.btn.btn-touch { min-width: 44px }` added (every button of the application gains it).
- `tests/phase2`'s placeholder checks named Recipes and Modifiers; moved to Counts (slice 6), Count sheets and Projected stock (slice 8), and the
  vhost's `/recipes/3/cost` expectation (404 "not built") now reads 404 by the record for `/recipes/999999/cost` and `/recipes/999999/versions/1`.
- Two proofs on one host collide on more than ports: `tests/setup_dev.sh` reset the three scratch roles' passwords on every run (fixed, above), and
  `tests/phase2/servers.sh stop` kills any `php -S` on 9301–9303 — a second phase 2 run takes the first one's servers down mid-run (left to the
  lead; slice 1 and slice 2 agreed a window by message).
