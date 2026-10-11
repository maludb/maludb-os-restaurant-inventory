# Build spec: ingredients, units, categories, suppliers and storage areas — THE CRUD PATTERN FOR WORKERS (slice 1)

Built by the planning-class model on the Phase 2 shell. What exists at the end: a Chef makes an ingredient in its measure class with the unit
the kitchen writes recipes in, gives it the units it is bought and counted in — "1 case = 6 × #10 = 18 390 g", never a case that is one each —,
records a yield test and applies its 70 %; a Manager makes a supplier with its order and delivery days at this site, the items it sells us in
their purchase units at their last price, and the order guide in shelf order; an admin lays out the storage areas in walk order; a Kitchen
member opens an ingredient and reads what is on hand by area in the count unit — cost only when the wall allows. Every screen is cards for
named things and a full-page form; every form that chooses a record uses the record picker; every save is a handler on the kit's shape
(`ri_handler_begin()` → the right at the site → `ri_guard()` → the SQL → `log_activity()` with `scope_id` and `ingredient_id` → `ri_done()`);
every row is logged. This slice fixes the shape every later CRUD screen copies, and the two rules every later slice leans on: **a quantity is
always carried with its unit and converted by `ri_to_base()`; cost is shown through `sees_cost()` and never computed in PHP.**
Schema: `units`, `ingredient_categories`, `ingredients` (+ `ri_ingredients_before()`), `ingredient_units` (+ `ri_ingredient_units_before()`),
`ri_to_base()`, `ri_from_base()`, `ri_unit_class()`, `ingredient_site_settings`, `ingredient_site_costs` (the ledger's alone — read here),
`price_history` (+ `ri_price_history_after()`, `ri_price_history_alert()` of db/008 and db/010), `ri_cost_as_of()`, `yield_tests`, `suppliers`,
`supplier_schedules`, `ri_next_order_windows()`, `supplier_items` (+ `ri_supplier_items_before()`), `ri_supplier_item_cost_per_base()`,
`order_guides`, `order_guide_lines`, `storage_areas`, `ri_depletion_area()` (db/007); `attachments`, `notes` (db/006); `ri_on_hand()` (db/009);
`ri_on_order()` (db/010); `ri_days_on_hand()`, `ri_find()` (db/016); the views `mcp_units`, `mcp_ingredient_categories`, `mcp_ingredients`,
`mcp_ingredient_units`, `mcp_ingredient_site_settings`, `mcp_ingredient_site_costs`, `mcp_price_history`, `mcp_yield_tests`, `mcp_containers`,
`mcp_suppliers`, `mcp_supplier_schedules`, `mcp_supplier_items`, `mcp_order_guides`, `mcp_storage_areas`, `mcp_inventory_balances`,
`mcp_inventory_transactions`, `mcp_recipe_lines`, `mcp_recipes`, `mcp_purchase_orders`, `mcp_supplier_invoices`, `mcp_price_alerts`, `mcp_notes`,
`mcp_attachments`, `mcp_activity_log`, `mcp_sites` (db/017). Never modify them. **The database is the referee**: a base unit is its class's base,
a recipe unit across classes needs a density or an each-weight, an ingredient's own unit never takes a global code, a purchase unit holds at
least one base unit, a supplier item's unit must convert, a code is unique, a par and a cost override are per site, a yield is in (0, 100], a
cost override and a cost history row are written only through their verbs, a site's cost and on-hand rows are the ledger's — the handlers call
the verb inside `ri_guard()` and translate `check_violation` / `unique_violation` / `foreign_key_violation` / `no_data_found` /
`insufficient_privilege` into its sentence as a 422 (403 for the last); PHP decides nothing twice.

## Screens (designed at 1280 px; usable at 375 px — cards stack, tables scroll inside the page; no modals but the picker; full-page forms)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `ingredient-list` | `/ingredients/?q=&category=&kind=&status=&is_prep=` | cards (`ingredient-card-{id}`) grouped by category in `sort_order`: name, code, the class icon (weight · volume · each), the chips (prep, catch weight, archived `dark`), on hand at this site in the default count unit and in the base unit (`mcp_ingredient_site_costs.on_hand_base` → `ri_from_base()`), the cost per recipe unit (walled: `avg_cost_per_base × ri_to_base(id, 1, recipe_unit)` — the view nulls it, the card says "cost withheld"), days on hand (`ri_days_on_hand()` joined once for the page); filters in one row: a search box (name, code — `ri_find()` with `q`, else `mcp_ingredients`), category select, kind (food · beverage · supplies), status (default: active), prep; **New ingredient** (`ingredients.write`); 48 a page |
| `ingredient-add` / `ingredient-edit` | `/ingredients/new?category=`, `/ingredients/{ingredient}/edit` | the form (below); `ingredient-edit` adds costing method and status; the measure class is read-only once a ledger row or a recipe line exists (the handler's sentence) |
| `ingredient-view` | `/ingredients/{ingredient}?tab=` | the header: name, code, category chip, class, recipe unit, yield % (with "from a test on …" when applied), allergens, prep / catch weight chips; **Edit**, **Archive** / **Restore** (`hx-confirm`), **Delete** (`records.delete`, `hx-confirm`; only when deletable), **Units**, **Sites**, **Yield test**; the tabs — **On hand** (by area at this site: `mcp_inventory_balances` joined to `mcp_storage_areas`, each in the default count unit + base; the site total; a "below par" mark when `on hand + on order < par`), **Units** (the table of `mcp_ingredient_units` + the class's global units with their factors — read-only here; a link to `ingredient-units`), **Suppliers** (`mcp_supplier_items` for this ingredient: supplier, SKU, pack, last price per purchase unit and per recipe unit (walled), contract until, MOQ, catch weight; the cheapest per recipe unit marked), **Pars** (`mcp_ingredient_site_settings` per held site; a link to `ingredient-site-settings`), **Cost** (the chart `ingredient-cost-chart` — `shared/series-chart.php` over `mcp_price_history` at this site, one step series, markers at changes; the table beneath: when, old → new per base, source, reference, by; walled whole: a Viewer sees "cost withheld" and no chart), **Recipes** (`mcp_recipe_lines` naming it, joined to `mcp_recipes` — the recipes that use it with the quantity per batch in the recipe's unit; a link to each), **Ledger** (the last 50 `mcp_inventory_transactions` at this site: when, type chip, area, quantity in the count unit, cost (walled), document), **Yield tests** (`mcp_yield_tests`: AP, EP, yield %, trim, cook loss, applied, by, when), **Containers** (`mcp_containers` — read here; slice 6's `container_save` makes them), **Notes** (`note_add` / `note_remove` / `attachment_add` / `attachment_remove` — this slice's), **Trail** (`find_record_activity('ingredient', id)` and `find_activity_by_key('ingredient_id', id)`); `data-entity="ingredient"` |
| `ingredient-units` | `/ingredients/{ingredient}/units` | the table `ingredient-units-table` (`ingredient-unit-row-{id}`): code, name, kind chip, the factor sentence "1 case = 18 390 g" (`ri_from_base`'d both ways), default count ★, active; **Retire** / **Restore**, **Make default count**; the add/edit form at the top (`unit-form`, Pattern C on a row's **Edit**): unit_code (≤ 24, `[a-z][a-z0-9_]*`), name, kind (purchase · count · recipe), **the factor entered as a quantity in a known unit** — "one `case` holds `6` × `#10 can` of `3.065 kg`" is too much for v1 (DECISION): the form takes `holds_qty` + `holds_unit` (a global unit of the class or an existing own unit) and the handler computes `to_base_factor = ri_to_base(ingredient, holds_qty, holds_unit)`; shown back as the sentence —, is_default_count |
| `ingredient-site-settings` | `/ingredients/{ingredient}/sites` | one row per held site (`site-settings-row-{scope_id}`, a form each — Pattern C): par (quantity + unit select of the ingredient's units), par mode (fixed · dynamic), reorder minimum (quantity + unit), service level (select 80 · 85 · 90 · 95 · 98 · 99 %), preferred supplier item (the picker `supplier_item` with param `ingredient`), default depletion area (select of that site's active areas), active; **Save** → `par_set`; a second small form **Cost override** (`cost-override-form-{scope_id}`: cost per unit + unit + reason; **Clear**) → `cost_override_set`, shown only for `prices.write` and `sees_cost()` at that site |
| `yield-test-add` | `/ingredients/{ingredient}/yield-tests/new` | the form (`yield-test-form`): ap_qty + ap_unit, ep_qty + ep_unit (both of the ingredient's class or own units), trim_loss_pct, cook_loss_pct, by-products (three rows: name, qty + unit, unit value — JSON on submit), apply (checkbox — "set the ingredient's yield to the test's"), notes; the computed yield % shown live (`hx-post` to the same file with `preview=1` — Pattern A fragment, no save) |
| `unit-list` | `/units/` | the table `units-table` (`unit-row-{code}`) by measure class in `display_order`: code, name, class, factor to the base ("1 lb = 453.592 g"), base ★, active; an inline row form (name, display order, active — Pattern C) and the add form at the top (code, name, class, factor, display order); `settings.manage`; a base unit's factor and class are read-only (the trigger's unique base) |
| `category-list` | `/categories/` | the table `categories-table` (`category-row-{id}`) in `sort_order`: name, key, kind chip, parent, safety-stock %, price-alert % (blank = the setting), temperature check ✓, ingredients count, active; an inline row form (name, kind, parent, safety %, alert %, temperature, sort order, active — Pattern C) and the add form at the top (name, key — from the name by default, kind, parent); `ingredients.write` |
| `supplier-list` | `/suppliers/?q=&kind=&status=` | cards (`supplier-card-{id}`): name, kind chip, order method chip (email · portal · phone · api · edi), "next cut-off Sat 13:00 → delivered Tue" at this site (`ri_next_order_windows()`; "no schedule here" when none), open POs at this site (`mcp_purchase_orders` sent/acknowledged/partial), items (`mcp_supplier_items` active), the commissary's site when `kind = commissary`, archived `dark`; filters: search (name), kind, status (default active); **New supplier** (`suppliers.write`); 48 a page |
| `supplier-add` / `supplier-edit` | `/suppliers/new`, `/suppliers/{supplier}/edit` | the form (below); `supplier-edit` adds active |
| `supplier-view` | `/suppliers/{supplier}?tab=` | the header: name, kind chip, order method and address, contact, rep, account number (walled to `suppliers.write`), lead time, minimum order value, split cases, the tolerances; **Edit**, **Archive** / **Restore** (`hx-confirm`), **Delete** (`records.delete`; only when deletable), **Schedules**, **Items**, **Order guide**, **New purchase order** (slice 4's `/purchasing/new?supplier=` — a link that lands on the placeholder until then); the tabs — **Schedules** (this site's rows in words), **Items** (`mcp_supplier_items`: SKU, ingredient (a link), purchase unit, pack, last price (walled) and when, contract price and until (walled), MOQ, catch weight, active; the cost per recipe unit beside each (walled)), **Order guide** (this site's guide in order with a link to the editor), **Purchase orders** (`mcp_purchase_orders` for this supplier at this site, newest first — empty until slice 4), **Invoices** (`mcp_supplier_invoices` — empty until slice 4; walled whole), **Price alerts** (`mcp_price_alerts` for its items — empty until slice 4), **Notes**, **Trail**; `data-entity="supplier"` |
| `supplier-schedules` | `/suppliers/{supplier}/schedules` | the table `schedules-table` (`schedule-row-{id}`) for THIS site: "Order Saturday by 13:00 → delivered Tuesday (3 days)", active; **Remove** (`hx-confirm`); the add form at the top (`schedule-form`): order_dow (select of the seven days), cutoff_time (time input), delivery_dow (select); the next three windows computed from the rows shown beneath (`ri_next_order_windows()` iterated) so a person sees what the rows mean |
| `supplier-items` | `/suppliers/{supplier}/items` | the table `supplier-items-table` (`supplier-item-row-{id}`): SKU, ingredient, purchase unit, pack, last price (walled), contract price · until (walled), MOQ, catch weight chip, lead time, active, **Edit** (Pattern C), **Retire** / **Restore**; the add form at the top (`supplier-item-form`): ingredient (the picker `ingredient`), purchase_unit (a select filled by `hx-get /ingredients/{id}/unit-options?kind=purchase` once the ingredient is picked — Pattern A; the ingredient's purchase units + the class's global units, `lb`/`kg` offered for catch weight), supplier_sku, description, pack, last_price (walled: shown and accepted only for `sees_cost()`), contract_price, contract_until, moq, catch_weight, lead_time_days |
| `order-guide-edit` | `/suppliers/{supplier}/guide` | the guide of THIS site (`mcp_order_guides` for the supplier + scope; made on first save): a list `guide-list` (`guide-row-{supplier_item_id}`) draggable (`Sortable.min.js` — the theme's; the handle on the left; keyboard: ↑ ↓ buttons per row) of supplier items with SKU, ingredient, pack, a **preferred** toggle, **Remove**; **Add an item** (the picker `supplier_item` with param `supplier`); **Save** posts the whole list as `lines` JSON (`order_guide_set`); without JavaScript the rows carry a `seq` number input each |
| `area-list` | `/areas/` | the list `areas-list` (`area-row-{id}`) of this site's areas in `walk_order`: name, kind chip, allows negative, items on hand (count of nonzero `mcp_inventory_balances`), archived `dark`; draggable (Sortable; ↑ ↓ buttons) → `area_reorder` on drop; **New area**, **Edit**, **Archive** (`hx-confirm`; refused in words while anything is on hand) |
| `area-add` / `area-edit` | `/areas/new`, `/areas/{area}/edit` | the form (`area-form`): name (≤ 80, unique at the site — `23505` → "There is already an area called …"), kind (select of the eight), walk_order (number; the next by default), allow_negative (checkbox, on — a kitchen depletes before it counts), active (`area-edit`) |

## The ingredient form (`ingredient-form`, ids `ingredient-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| name | text ≤ 120 | yes | trimmed |
| code | text ≤ 40 | no | from the name when absent: upper, `[^A-Z0-9]+` → `_`, ≤ 20, a numeric suffix on collision (`23505` → "That code is already taken." when typed) |
| category | the picker `category` (`mcp_ingredient_categories` active, in order) | yes | `ri_ref()`; `?category=` prefills |
| measure_class | radio weight · volume · each | yes | read-only on edit once a ledger row or a recipe line exists (the handler's sentence — the schema allows the change, the kitchen must not) |
| recipe_unit | select: the class's global units (`mcp_units` by class, active) + the other classes' when a density / each-weight is given (the control re-renders on class or density change — Pattern A, `hx-get /ingredients/unit-options?class=&density=&each=`) | yes | the class's base by default; across classes the trigger refuses without the fact — the form shows the trigger's sentence as the field error |
| density_g_per_ml | number > 0 (6 dp) | no | shown for weight and volume; "olive oil 0.916, honey 1.42" as placeholder |
| each_weight_g · each_volume_ml | numbers > 0 | no | shown for each (and for weight/volume when a recipe unit of each is wanted) |
| yield_pct | number in (0, 100] (2 dp) | yes | 100 by default; "the edible portion after trim at receipt — cost-bearing"; a test applies it (`yield_test_add`) |
| catch_weight | checkbox | no | "ordered by the piece, priced and received by weight" |
| shelf_life_days | number > 0 | no | the perishable cap of the suggested order (slice 8) |
| costing_method | radio avg · last (`ingredient-edit` only) | no | avg by default |
| variance_tolerance_pct · variance_tolerance_value | numbers ≥ 0 | no | blank = 5 % and 10 (the count review's defaults, db/012) |
| allergens | checkboxes of the fourteen (gluten, crustaceans, egg, fish, peanut, soy, milk, nuts, celery, mustard, sesame, sulphites, lupin, molluscs) + "other" text | no | `text[]`; the US nine are among the fourteen — DECISION: one list, the EU's, labelled in plain words |
| notes | textarea ≤ 2,000 | no | |
| status | radio active · archived (`ingredient-edit` only) | no | archiving goes through **Archive** on the view too |

`ingredient_update` refuses `measure_class` and `base_unit_code` once the ingredient has a ledger row or a recipe line ("The class of an
ingredient that has stock or recipes does not change — make a new ingredient."); `base_unit_code` is never a form field (the class's base, set by
the handler). A `cost` or `par` sent here is refused pointing at `cost_override_set` / `par_set` (DECISION — those are the paused actions).

## The supplier form (`supplier-form`, ids `supplier-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| name | text ≤ 120 | yes | unique (`23505` → "That supplier already exists.") |
| kind | select distributor · producer · wholesaler · retailer · commissary · other | yes | distributor by default |
| commissary_site | select of the held sites (shown when kind = commissary) | no | the site the commissary IS; the handler requires it for the kind |
| contact_name · email · phone · address | text / email / text / textarea | no | |
| order_method | select email · portal · phone · api · edi | yes | email by default; `order_email` required when email, `portal_url` when portal (the handler's sentences) |
| order_email · portal_url | email / url | no | |
| terms_days · lead_time_days | numbers ≥ 0 | no / yes | lead time 1 by default — the no-schedule fallback of `ri_next_order_windows()` |
| min_order_value | decimal ≥ 0 | no | shown on the suggested order |
| allow_split_case | checkbox | no | the suggested order rounds to two decimals instead of whole units |
| match_tolerance_pct · match_tolerance_value | numbers ≥ 0 | no | 2 % and 5 by default (db/007) — the invoice match's (slice 4) |
| account_number · rep_name · rep_phone | text | no | account number shown only to `suppliers.write` (the view's rule) |
| notes | textarea | no | |
| active | checkbox (`supplier-edit`) | no | |

## Saving, step by step (what each handler calls)
- **An ingredient** (`save.php`): `ingredient_from_request()` reads the form; `save_ingredient()` INSERTs or UPDATEs `ingredients` inside
  `ri_guard()` — the trigger `ri_ingredients_before()` decides the class/unit/density rules and its sentence is the field error on `recipe_unit`;
  `base_unit_code` = the class's base from `mcp_units`; the class-change refusal is the handler's (`ingredient_locked()`); `ingredient.create` /
  `ingredient.update` logged with `ingredient_id`; location `/ingredients/{id}`.
- **An own unit** (`units/save.php`): `holds_qty` + `holds_unit` → `to_base_factor = ri_to_base(ingredient, holds_qty, holds_unit)` (the
  conversion's own refusal, e.g. a volume for an each with no each-volume, is the field error on `holds_unit`); `save_ingredient_unit()` INSERTs
  or UPDATEs inside `ri_guard()` — `ri_ingredient_units_before()` refuses a global code and a purchase unit under one base unit ("a case is never
  one each") in its words; `is_default_count` clears the previous default in the same transaction; `ingredient.unit_set` logged (`after`: unit_code,
  name, kind, to_base_factor, is_default_count); location `/ingredients/{id}/units#ingredient-unit-row-{unit}`.
- **A par** (`sites/save.php`): `par` + `par_unit` → `par_base = ri_to_base(...)`, `reorder_min` likewise; `site` must be held and the caller must
  hold `ingredients.write` THERE (`require_right('ingredients.write', $site)`); `preferred_supplier_item` must sell this ingredient (the handler's
  sentence); `default_area` must be an active area of that site; `save_site_settings()` INSERT … ON CONFLICT (ingredient, scope) DO UPDATE;
  `ingredient.par_set` logged with `scope_id = site` (`after`: par_base, par_mode, reorder_min_base, service_level, preferred_supplier_item_id,
  default_area_id); **other** (an agent pauses); location `/ingredients/{id}/sites#site-settings-row-{site}`.
- **A cost override** (`sites/cost.php`): `prices.write` AND `sees_cost()` at the site (else 403 "You may not see cost."); `cost` + `unit` →
  `cost_per_base = cost / ri_to_base(ingredient, 1, unit)`; blank `cost` clears; `set_cost_override()` writes the row AND a `price_history` row
  (`source_kind manual`, `reason`, `scope_id = site`) in one transaction — so the chart and the alerts see it; `ingredient.cost_override` logged
  (`before.cost_override_per_base`, `after.cost_override_per_base`, `reason`); **other**; location `/ingredients/{id}/sites`.
- **A yield test** (`yield-tests/save.php`): the two quantities to base; `ep ≤ ap` (the CHECK's sentence); `add_yield_test()` INSERTs; with
  `apply` the ingredient's `yield_pct` is set to the test's and `applied = true`; `ingredient.yield_test` logged (`after`: ap_qty_base, ep_qty_base,
  yield_pct, applied); location `/ingredients/{id}?tab=yield`.
- **A supplier** (`/suppliers/save.php`): `supplier_from_request()`; the kind/commissary and method/email/portal sentences; `save_supplier()`
  inside `ri_guard()` (`23505` on the name); `supplier.create` / `supplier.update`; location `/suppliers/{id}`.
- **A schedule** (`/suppliers/schedules/save.php`): `scope_id` = the current site; `save_schedule()` INSERT (or UPDATE with `schedule`) — the
  UNIQUE (supplier, scope, order_dow, cutoff_time) → "That cut-off is already on the schedule."; `supplier.schedule_set` logged with `scope_id`
  (`after`: order_dow, cutoff_time, delivery_dow, lead_days); location `/suppliers/{supplier}/schedules`. `remove.php`: the row deleted;
  `supplier.schedule_remove` (`before` the row).
- **A supplier item** (`/suppliers/items/save.php`): `last_price` and `contract_price` accepted only for `sees_cost()` (a Chef sending one is
  refused in words); `save_supplier_item()` inside `ri_guard()` — `ri_supplier_items_before()` refuses a unit the ingredient cannot convert in its
  words (the field error on `purchase_unit`); the UNIQUE (supplier, ingredient, purchase_unit) → "That item is already on the list in that
  unit."; a `last_price` typed by hand writes a `price_history` row (`source_kind manual`, `scope_id NULL` — every site; `reason` "supplier
  item saved") so the ingredient's cost exists before the first receipt (DECISION — a restaurant types its price sheet in before it receives);
  `supplier_item.create` / `supplier_item.update` (`after`: supplier_sku, purchase_unit_code, last_price when seen, moq, catch_weight);
  location `/suppliers/{supplier}/items#supplier-item-row-{id}`. `retire.php`: `active` toggled; `supplier_item.retire`.
- **An order guide** (`/suppliers/guide.php`): `scope_id` = the current site; `lines` JSON `[{supplier_item, seq, preferred}]` (or `lines[n][…]`
  from the form); every supplier item must be the supplier's (the handler's sentence); `set_order_guide()` makes the guide row when absent, then
  DELETE + INSERT the lines in one transaction (replace semantics); `order_guide.set` logged with `scope_id` (`after`: lines count, supplier_item_ids);
  location `/suppliers/{supplier}/guide`.
- **An area** (`/areas/save.php`): `scope_id` = the current site; `save_area()` inside `ri_guard()` (`23505` on the name at the site);
  `area.create` / `area.update` with `scope_id`; location `/areas/`. `archive.php`: refused in words while `ri_on_hand(any, site, area) <> 0`
  (`area_archivable()`); `area.archive`. `reorder.php`: `areas[]` ids all of this site (else refused); `walk_order` = position × 10; `area.reorder`
  (`after.order`).
- **A note / an attachment** (`/notes/add.php`, `/attachments/add.php`): `record_type` ∈ `record_url()`'s map and the record visible to the caller
  (`require_visible()` on its `mcp_*` view); `attachment_store()` (the shell's); `note.add` / `attachment.add` (`after`: record_type, record_id,
  length / filename, mime, bytes — never a body); location the record's page `?tab=notes`. `remove.php`: own (or the admin); `note.remove` /
  `attachment.remove`.
- **A category / a global unit** (`/categories/save.php`, `/units/save.php`): the key / code from the name when absent; `save_category()` /
  `save_global_unit()` inside `ri_guard()` (`23505` in words; a base unit's class/factor change refused by the handler: "The base unit of a class
  does not change."); `category.save` / `unit.save`; a Pattern C request answers the row partial.

## Files (exactly these)
- `html/ingredients/index.php` (`ingredient-list`) · `form.php` (`ingredient-add`, `ingredient-edit`) · `view.php` (`ingredient-view`) · `units.php` (`ingredient-units`) · `sites.php` (`ingredient-site-settings`) · `save.php` · `archive.php` · `delete.php` · `unit-options.php` (GET, Pattern A: the unit select's options for an ingredient or a class) · `units/save.php` · `sites/save.php` · `sites/cost.php` · `yield-tests/form.php` (`yield-test-add`) · `yield-tests/save.php`
- `html/units/index.php` (`unit-list`) · `save.php`
- `html/categories/index.php` (`category-list`) · `save.php`
- `html/suppliers/index.php` (`supplier-list`) · `form.php` (`supplier-add`, `supplier-edit`) · `view.php` (`supplier-view`) · `schedules.php` (`supplier-schedules`) · `items.php` (`supplier-items`) · `guide.php` (`order-guide-edit` on GET, `order_guide_set` on POST) · `save.php` · `archive.php` · `delete.php` · `schedules/save.php` · `schedules/remove.php` · `items/save.php` · `items/retire.php`
- `html/areas/index.php` (`area-list`) · `form.php` (`area-add`, `area-edit`) · `save.php` · `archive.php` · `reorder.php`
- `html/notes/add.php` · `remove.php` · `html/attachments/add.php` · `remove.php`
- `app/features/catalog/{queries,present,write,handler}.php` (`handler.php`: `ingredient_from_request()`, `supplier_from_request()`, `qty_with_unit()` — reads `{name}` + `{name}_unit` and answers `[qty, unit, qty_base]` or a field error through `ri_to_base()` —, `catalog_log()`)
- `app/features/units/queries.php` (`unit_options()`, `format_qty()`, `format_factor_sentence()`, `display_qty()` — the ONE place a quantity is rendered in a unit; every later slice calls it)
- `app/views/catalog/{ingredients,ingredient-form,ingredient,ingredient-units,ingredient-sites,yield-test-form,units,categories,suppliers,supplier-form,supplier,supplier-schedules,supplier-items,order-guide,areas,area-form,partials/ingredient-card,partials/supplier-card,partials/unit-row,partials/site-settings-row,partials/schedule-row,partials/supplier-item-row,partials/guide-row,partials/area-row,partials/category-row,partials/global-unit-row,partials/cost-chart,partials/notes-tab}.php`
- `app/views/shared/series-chart.php` + `html/assets/js/series-chart.js` + the `.viz-root` rules in `html/assets/css/app-overrides.css` (Inventory's chart component copied verbatim — the step line, markers at changes, the table beneath, the `dataviz` palette's three slots as custom properties; born here for the cost history, reused by slice 2's cost history and slice 8's forecast)
- `app/pickers.php` gains `ingredient` (`find_ingredients()`, label "name · code", detail "category · recipe unit"), `supplier` (`find_suppliers()`), `supplier_item` (param `supplier`, param `ingredient`; label "SKU · ingredient", detail "pack · last price when seen"), `category` (`mcp_ingredient_categories`), `area` (this site's `mcp_storage_areas`)
- `app/partial_update.php`: `PARTIAL_UPDATE_TARGETS` gains `'/ingredients/save.php' => ['ingredients', 'ingredient', 'mcp_ingredients', 'ingredient_id']`, `'/suppliers/save.php' => ['suppliers', 'supplier', 'mcp_suppliers', 'supplier_id']`, `'/suppliers/items/save.php' => ['supplier_items', 'supplier_item', 'mcp_supplier_items', 'supplier_item_id']`, `'/areas/save.php' => ['storage_areas', 'area', 'mcp_storage_areas', 'storage_area_id']`
- `app/features/shell/nav.php`: `back_link()` learns `/ingredients/{id}` → "the ingredient", `/suppliers/{id}` → "the supplier"

## Query functions (signatures fixed; `app/features/catalog/queries.php`; the tools of Phase 4 call these and the resolvers)
- `find_ingredients(PDO, int $scopeId, array $filters, int $limit = 48, int $offset = 0): array` (`mcp_ingredients` + `mcp_ingredient_site_costs` at the site + `ri_days_on_hand()`; `q`, `category`, `kind`, `status`, `is_prep`; with `q` the ids come from `ri_find(q)` kind ingredient first) — tool `find_ingredients`
- `find_ingredient(PDO, int $id): ?array` (`mcp_ingredients`) · `ingredient_full(PDO, int $id, int $scopeId): ?array` (`{ingredient, units, on_hand_by_area, site_settings, cost (walled), supplier_items, recipes_using, yield_tests, containers, notes, attachments}`) — tool `get_ingredient`
- `ingredient_units(PDO, int $id, bool $activeOnly = true): array` (`mcp_ingredient_units` + the class's `mcp_units`, each with `to_base_factor` and the sentence) — tool `ingredient_units` · `unit_options(PDO, int $ingredientId, ?string $kind = null): array` (`[{code, label}]` for a select)
- `ingredient_cost(PDO, int $id, int $scopeId, ?string $asOf = null, ?string $basis = null): ?array` (`ri_cost_as_of()` per base, per recipe unit, per default count unit; null for a caller without `sees_cost($scopeId)` — the tool says `withheld`) — tool `ingredient_cost` · `cost_history(PDO, int $id, int $scopeId, int $limit = 100): array` (`mcp_price_history` — the view is the wall) — tool `cost_history` · `cost_series(array $rows): array` (the chart's series, oldest first)
- `cheapest_supplier(PDO, int $ingredientId, int $scopeId): array` (`mcp_supplier_items` active for the ingredient ranked by `ri_supplier_item_cost_per_base()`; walled: the order without the prices for a caller without cost) — tool `cheapest_supplier`
- `ingredient_on_hand(PDO, int $id, int $scopeId): array` (`[{storage_area_id, name, qty_base, count_unit_code, qty_in_count_unit}]` + the total) — part of `stock_on_hand` (slice 3's tool reads the same)
- `recipes_using(PDO, int $ingredientId, int $limit = 50): array` (`mcp_recipe_lines` → `mcp_recipes` for versions in force) — tool `recipes_using` (slice 2's tool reads this)
- `site_settings_rows(PDO, int $ingredientId, array $heldSiteIds): array` (`mcp_ingredient_site_settings`, a row per held site — the defaults when absent)
- `yield_tests(PDO, int $ingredientId): array` (`mcp_yield_tests`)
- `ingredient_deletable(PDO, int $id): ?string` (null, or the sentence: a ledger row, a recipe line, a supplier item, a count line) · `ingredient_locked(PDO, int $id): bool` (a ledger row or a recipe line exists — the class is locked)
- `find_suppliers(PDO, int $scopeId, array $filters, int $limit = 48, int $offset = 0): array` (`mcp_suppliers` + the next window at the site + open POs + item counts) — tool `find_suppliers` · `find_supplier(PDO, int $id): ?array` · `supplier_full(PDO, int $id, int $scopeId): ?array` (`{supplier, schedules, next_windows, items, guide, open_purchase_orders, notes, attachments}`) — tool `get_supplier`
- `supplier_schedules(PDO, int $supplierId, int $scopeId): array` + `next_windows(PDO, int $supplierId, int $scopeId, int $n = 3): array` (`ri_next_order_windows()` iterated from each cut-off) — tool `supplier_schedules`
- `supplier_items(PDO, ?int $supplierId = null, ?int $ingredientId = null, bool $activeOnly = true): array` (`mcp_supplier_items` — the view walls the prices) — tool `supplier_items` · `supplier_item_pick(PDO, string $q, ?int $supplierId, ?int $ingredientId, int $page): array` (the picker)
- `order_guide(PDO, int $supplierId, int $scopeId): ?array` (`mcp_order_guides` with its lines joined to the items) — tool `order_guides`
- `supplier_deletable(PDO, int $id): ?string` (a PO, a receipt, an invoice names it)
- `find_storage_areas(PDO, int $scopeId, bool $activeOnly = true): array` (`mcp_storage_areas` in walk order + items on hand) — tool `find_storage_areas` · `area_archivable(PDO, int $areaId): ?string`
- `categories(PDO, bool $activeOnly = true): array` (`mcp_ingredient_categories`) · `global_units(PDO, bool $activeOnly = true): array` (`mcp_units` by class)
- `format_qty(float $qtyBase, int $ingredientId, ?string $unitCode = null, PDO $pdo): string` ("2 cases + 3 cans" is slice 6's; here: the quantity in the unit with the unit's code, thousands separated, ≤ 2 dp; `display_qty()` chooses the person's `display_units` for a global weight/volume) — `app/features/units/queries.php`
- Writes (`write.php`): `save_ingredient(PDO, ?int $id, array $fields, int $by): int` · `archive_ingredient(PDO, int $id, bool $archived): void` · `delete_ingredient(PDO, int $id): void` · `save_ingredient_unit(PDO, int $ingredientId, ?int $unitId, array $fields): int` · `save_site_settings(PDO, int $ingredientId, int $scopeId, array $fields): array` (before/after) · `set_cost_override(PDO, int $ingredientId, int $scopeId, ?string $costPerBase, ?string $unitCode, ?string $perUnitPrice, ?string $reason, int $by): array` · `add_yield_test(PDO, int $ingredientId, array $fields, bool $apply, int $by): int` · `save_supplier(PDO, ?int $id, array $fields, int $by): int` · `archive_supplier(PDO, int $id, bool $archived): void` · `delete_supplier(PDO, int $id): void` · `save_schedule(PDO, int $supplierId, int $scopeId, ?int $id, array $fields): int` · `remove_schedule(PDO, int $id): array` · `save_supplier_item(PDO, int $supplierId, ?int $id, array $fields, bool $seesCost, int $by): int` · `retire_supplier_item(PDO, int $id, bool $active): void` · `set_order_guide(PDO, int $supplierId, int $scopeId, string $name, array $lines): int` · `save_area(PDO, int $scopeId, ?int $id, array $fields): int` · `archive_area(PDO, int $id, bool $archived): void` · `reorder_areas(PDO, int $scopeId, array $ids): void` · `save_category(PDO, ?int $id, array $fields): int` · `save_global_unit(PDO, ?string $code, array $fields): string` · `add_note(PDO, string $type, int $id, string $body, int $by): int` · `remove_note(PDO, int $id): array` · `remove_attachment(PDO, int $id): array`

## Handlers (every one: `ri_handler_begin()`; the right at the current site (or the row's site); `ri_guard()`; `log_activity` with `scope_id` and `ingredient_id`; `ri_done()` with a `location` ending in the record id; `HX-Trigger: ingredientChanged` — `supplierChanged` / `areaChanged` for those)
- `ingredients/save.php` (`ingredient_create` without `ingredient`, `ingredient_update` with): `ingredients.write`; `ingredient.create` (`after`: name, code, category_id, measure_class, recipe_unit_code, yield_pct, catch_weight, shelf_life_days) / `ingredient.update` (`ri_diff()`); `record_id`.
- `ingredients/archive.php` (`ingredient_archive`): `ingredients.write`; `ingredient.archive` (`after.archived`).
- `ingredients/delete.php` (`ingredient_delete`): `records.delete`; `ingredient_deletable()` else 422 in words; `ingredient.delete` (`after`: name, code); **deletion**; location `/ingredients/`.
- `ingredients/units/save.php` (`unit_set`), `ingredients/sites/save.php` (`par_set`, **other**), `ingredients/sites/cost.php` (`cost_override_set`, **other**), `ingredients/yield-tests/save.php` (`yield_test_add`): as "Saving, step by step".
- `units/save.php` (`global_unit_save`): `settings.manage`; `unit.save`; location `/units/#unit-row-{code}`.
- `categories/save.php` (`category_save`): `ingredients.write`; `category.save`; location `/categories/#category-row-{id}`.
- `suppliers/save.php` (`supplier_create`, `supplier_update`), `archive.php` (`supplier_archive`), `delete.php` (`supplier_delete`, **deletion**, `records.delete`), `schedules/save.php` (`supplier_schedule_set`), `schedules/remove.php` (`supplier_schedule_remove`), `items/save.php` (`supplier_item_create`, `supplier_item_update`), `items/retire.php` (`supplier_item_retire`): `suppliers.write`; `guide.php` POST (`order_guide_set`): `purchasing.draft`.
- `areas/save.php` (`area_create`, `area_update`), `archive.php` (`area_archive`), `reorder.php` (`area_reorder`): `ingredients.write` at the current site.
- `notes/add.php` (`note_add`), `attachments/add.php` (`attachment_add`): `notes.write`; `notes/remove.php` (`note_remove`), `attachments/remove.php` (`attachment_remove`): own or the admin.

## Manifest rows claimed
Screens (19): `ingredient-list`, `ingredient-add`, `ingredient-view`, `ingredient-edit`, `ingredient-units`, `ingredient-site-settings`, `yield-test-add`, `unit-list`, `category-list`, `supplier-list`, `supplier-add`, `supplier-view`, `supplier-edit`, `supplier-schedules`, `supplier-items`, `order-guide-edit`, `area-list`, `area-add`, `area-edit`
Actions (28): `ingredient_create`, `ingredient_update`, `ingredient_archive`, `ingredient_delete`, `unit_set`, `par_set`, `cost_override_set`, `yield_test_add`, `global_unit_save`, `category_save`, `supplier_create`, `supplier_update`, `supplier_archive`, `supplier_delete`, `supplier_schedule_set`, `supplier_schedule_remove`, `supplier_item_create`, `supplier_item_update`, `supplier_item_retire`, `order_guide_set`, `area_create`, `area_update`, `area_archive`, `area_reorder`, `note_add`, `note_remove`, `attachment_add`, `attachment_remove`
Agent approvals: `ingredient_delete`, `supplier_delete` (`deletion`); `par_set`, `cost_override_set` (`other`).

Every row of the manifest's slice-1 section is claimed; none is left to another slice.

## Activity log events
`ingredient.create|update|archive|delete|unit_set|par_set|cost_override|yield_test`, `unit.save`, `category.save`,
`supplier.create|update|archive|delete|schedule_set|schedule_remove`, `supplier_item.create|update|retire`, `order_guide.set`,
`area.create|update|archive|reorder`, `note.add|remove`, `attachment.add|remove`, `screen.view` (`ingredient-view` and `supplier-view` with the
record's id). Every ingredient row carries `ingredient_id`; a par, a cost override, a schedule, a guide and an area carry the site's `scope_id`.
**No row carries a note's body or a file's bytes** — names, codes, units, factors, quantities with their unit and prices (when seen) are expected.

## Notifications this slice queues
None (a cost override writes `price_history`, whose trigger raises a `price_alert` row when over the threshold — the notification of it is
slice 4's `price_alert`).

## Status vocabulary
Class icons: weight `feather-anchor`, volume `feather-droplet`, each `feather-hash`. Chips: prep `info` "prep", catch weight `warning` "catch
weight", archived `dark`, below par `danger` "below par". Unit kind chips: purchase `primary`, count `secondary`, recipe `info`; the base unit ★.
Supplier kind chips: distributor `primary`, producer `success`, wholesaler `info`, retailer `secondary`, commissary `warning`, other `light`.
Order method chips: email `info`, portal `primary`, phone `secondary`, api / edi `dark`. Cost withheld shown as "—" with the title "cost withheld".
Ids: `ingredient-list`, `ingredient-list-filters`, `ingredient-card-{id}`, `ingredient-form`, `ingredient-form-field-{name}`,
`ingredient-form-field-allergen-{key}`, `ingredient-view-header`, `ingredient-on-hand-table`, `on-hand-row-{area}`, `ingredient-units-table`,
`ingredient-unit-row-{id}`, `unit-form`, `unit-form-field-{name}`, `site-settings-row-{scope_id}`, `site-settings-form-{scope_id}-field-{name}`,
`cost-override-form-{scope_id}`, `yield-test-form`, `yield-test-form-field-{name}`, `yield-test-preview`, `ingredient-cost-chart`,
`ingredient-cost-table`, `ingredient-recipes-table`, `ingredient-ledger-table`, `units-table`, `unit-row-{code}`, `global-unit-form`,
`categories-table`, `category-row-{id}`, `category-form`, `supplier-list`, `supplier-card-{id}`, `supplier-form`, `supplier-form-field-{name}`,
`supplier-view-header`, `schedules-table`, `schedule-row-{id}`, `schedule-form`, `schedule-form-field-{name}`, `schedule-windows`,
`supplier-items-table`, `supplier-item-row-{id}`, `supplier-item-form`, `supplier-item-form-field-{name}`, `guide-list`, `guide-row-{supplier_item_id}`,
`guide-add`, `guide-save`, `areas-list`, `area-row-{id}`, `area-form`, `area-form-field-{name}`, `notes-tab`, `note-row-{id}`, `note-form`,
`attachment-row-{id}`, `attachment-form`.

## Mobile rule (375 × 740)
Cards stack one a row; every table scrolls inside `.table-responsive`; the forms one column with the quantity and its unit select on one
line (`input-group`); the picker full screen; the draggable lists keep their ↑ ↓ buttons (≥ 44 px) beside the handle; the chart scales with
its card; `scrollWidth` = viewport.

## Vocabulary
*ingredient* (anything the kitchen stocks — the estate's `items` family), *measure class* (weight · volume · each), *base unit* (g · ml · each —
every stored quantity), *recipe unit* (what the chef writes), *own unit* (a case, a can, a bottle — with its factor), *default count unit* (what
the count sheet shows), *density / each-weight* (the one fact that crosses classes), *yield %* (the edible portion after trim at receipt —
cost-bearing), *catch weight* (ordered by the piece, priced and received by weight), *par* (per site; fixed or dynamic), *cost override* (per
site; the one hand-set cost), *supplier item* (what a supplier sells us in its unit at its last price), *schedule* (an order day, a cut-off, a
delivery day — per site), *order guide* (the supplier's items in shelf order — per site), *storage area* (where stock sits inside a site, in
walk order).

## Out of scope for this slice
Recipes and the menu map (2); any ledger posting — receipts, on hand changes, production, waste (3); purchase orders, invoices, price
alerts' notifications (4); counts, containers' editing (6); the suggested order the pars feed (8); reports (9); CSV import of a price sheet or
an order guide (Extended — the forms and `supplier_item_create` with `lines` are enough for v1; a distributor's order guide is typed or pasted
row by row).

## Proof (`tests/phase3/slice1/run.sh`: the scratch database `ri_dev1`, the application on 9301 (`php -S`, or `RI_APP=apache` with the internal
vhost on 9307), the fake kernel 9302, the fake MaluDB + MaluMail 9303; members through dev hand-offs with their site, curl with signed action and
run tokens, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`; `tests/phase1/check_specs.sh`)
Target: **≥ 230 checks** (units 25, ingredients 40, own units 20, pars and overrides 20, yield 10, categories 10, suppliers 25, schedules 15,
supplier items 25, guides 15, areas 15, notes 10, visibility 25, json 20, browser 35).
The world (`tests/phase3/slice1/lib.php` `catalog_world()`): Dana (Chef at Downtown) makes flour (weight, g; a 50 lb bag), chicken breast (weight,
yield 85 %, catch weight, tolerances 3 % / 5), eggs (each, each-weight 50 g; a case of 15 dozen, a flat of 30 as the default count), limes
(each; a case of 200), gin (volume, recipe unit floz; a bottle of 750 ml as the default count, a case of 6), olive oil (volume, density 0.916,
recipe unit floz; a case of 4 × 1 gal); Marco (Manager at Downtown, Kitchen at Airport) makes Sysco (email, Saturday 13:00 → Tuesday and
Wednesday 13:00 → Friday at Downtown; items in those units at prices) and Produce Co (phone, split cases); the owner makes the areas Walk-in,
Dry store, Bar at Downtown and Walk-in at Airport; Ann (Viewer), Priya (Kitchen at Airport), Bea (Kitchen at Downtown), the expert (agent 40,
Chef at both).
- [ ] **Global units**: the fifteen seeded with their factors; the owner adds `gal_uk`; a second base for weight refused (the unique base) in words;
  a factor change on `g` refused by the handler; Dana (no `settings.manage`) → 403; `unit.save` logged.
- [ ] **Ingredients**: each created with its class and recipe unit; a volume recipe unit on flour without a density refused with the trigger's
  sentence on `recipe_unit`; olive oil with 0.916 accepted and `ri_to_base(oil, 1, 'lb')` = 495 ml on its page; a code made from the name and a
  typed duplicate refused; the class locked after a ledger row (the proof posts one `inventory_transactions` row by SQL through `ri_post_txn()` as
  the super-admin — slice 3's world is not here) and the update refused in words; `cost` and `par` on save refused pointing at the paused
  actions; archived and restored; the cards with filters (q by name and by code through `ri_find`, category, kind, status default hiding archived,
  prep); `ingredient.create|update|archive` logged with `ingredient_id`; `record_id` and `location` in the JSON reply.
- [ ] **Own units**: "1 bag50 = 50 lb" entered as `holds_qty 50` + `holds_unit lb` → `to_base_factor` 22 679.6185 and the sentence "1 bag50 =
  22 679.6 g"; a case of eggs as `180 each`; a flat of 30 as the default count (the previous default cleared); a `case2 = 0.5 each` refused "a case is
  never one each"; a code `lb` refused "global unit"; a factor given in a unit the ingredient cannot convert (limes in g) refused on `holds_unit`;
  retired and restored; `ingredient.unit_set` logged.
- [ ] **Pars and overrides**: Dana sets flour's par at Downtown as `250 kg` + fixed → `par_base` 250 000; eggs dynamic at 95 %; a preferred supplier
  item of another ingredient refused in words; a default area of Airport refused for Downtown; Priya (Kitchen) → 403; the expert's `par_set` under a
  run token lands with `source agent` (the pause is the kernel's hook — Phase 4); a cost override of `$3.50 per lb` on chicken by Marco → `cost_override_per_base`
  0.007716 AND a `price_history` row with `reason` and `scope_id` 101; a Chef without `prices.write` (Dana) → 403; Marco at Airport (Kitchen, no cost)
  → 403 "You may not see cost."; cleared with a blank cost; `ingredient.par_set` / `ingredient.cost_override` logged with `scope_id`.
- [ ] **Yield**: the textbook tenderloin on chicken (2 500 g AP, 1 750 g EP) → 70.00 % with the live preview; applied → `ingredients.yield_pct` 70;
  an EP above AP refused with the CHECK's sentence; `ingredient.yield_test` logged.
- [ ] **Categories**: thirteen seeded in order; "SMOKE Specialty" added with key `smoke_specialty`, renamed inline, safety 30 %, temperature on;
  Ann → 403; `category.save` logged.
- [ ] **Suppliers**: Sysco and Produce Co created; a duplicate name refused; `email` method without an order email refused in words; a commissary
  without its site refused; archived and restored; the cards show the next cut-off at Downtown ("Sat 13:00 → Tue") and "no schedule here" at Airport
  (Marco's switch); the account number hidden from Dana and shown to Marco; `supplier.create|update|archive` logged.
- [ ] **Schedules**: the two Sysco rows at Downtown with lead days 3 and 2; a duplicate cut-off refused; the next three windows listed; removed and
  re-added; `supplier.schedule_set` logged with `scope_id` 101; Airport has none (the rows are per site).
- [ ] **Supplier items**: flour `SY-FL50` in `bag50` at $25 (the price accepted from Marco, refused from Dana — no `sees_cost`? Dana is Chef and
  sees cost: accepted; refused from Priya at Airport), chicken `SY-CHK` priced per `lb` with catch weight, eggs, oil, limes at Produce Co; a unit the
  ingredient cannot convert (limes in `kg`) refused with the trigger's sentence; the same item twice in one unit refused; a typed `last_price` writes
  a `price_history` row with `scope_id NULL`; the cheapest per recipe unit marked on the ingredient's Suppliers tab; retired and restored; the picker
  `supplier_item` with `supplier` answers Sysco's five; `supplier_item.create|update|retire` logged.
- [ ] **Order guides**: Sysco's guide at Downtown saved as the whole list in shelf order with flour preferred; a second save with three rows leaves
  three; an item of Produce Co refused in words; the drag order and the ↑ ↓ buttons; without JavaScript the `seq` inputs; Dana (`purchasing.draft`)
  may save it, Bea may not; `order_guide.set` logged with `scope_id`.
- [ ] **Areas**: the four created (two sites); a duplicate name at a site refused; reordered by drag → `walk_order` 10, 20, 30; an area with stock
  (the SQL row above lands in Dry store) refused archiving in words; an empty one archived; Priya at Airport sees Airport's one area only; `area.*`
  logged with `scope_id`.
- [ ] **Notes and attachments**: a note on flour by Bea (Kitchen, `notes.write`) and a photo of the bag; Ann (Viewer) → 403 on both; Bea removes
  her own note, Dana cannot remove Bea's (own) but the owner can; `/files/{id}` serves the photo to Ann and 401 anonymous; `note.add` logged with
  the length, never the body; a PDF accepted, an `.exe` refused by the sniff.
- [ ] **Who sees**: Ann reads every ingredient, unit and supplier with cost "—" (`cost_withheld`), no chart on the Cost tab, no last prices on
  the items, no account number; Bea (Kitchen) likewise until `kitchen_sees_cost` is set on Downtown by SQL — then the cost per recipe unit and the
  chart appear; Priya at Airport sees no Downtown on-hand row; the owner deletes an untouched ingredient and is refused on flour (a ledger row)
  and on gin (a supplier item) in words; `supplier_delete` refused on Sysco (items) and allowed on an empty one.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts; a quantity sent as
  `par` + `par_unit` lands in base; 422 `{error: {code: invalid, fields}}` naming the field; the expert (run token + relay) creates an ingredient and a
  supplier item at Downtown as `source agent`, is refused `ingredient_delete` (no `records.delete`) in words, and at a `site` it does not hold (none —
  it holds both) a bogus `site=999` → 404.
- [ ] **375 × 740 and 1280 × 800**: the cards stack; the ingredient form's class radios re-render the recipe unit select; the quantity + unit
  input groups; the picker chooses a category, a supplier item (dependent on the supplier) and an area; the units table's factor sentences; the
  guide's drag; the cost chart scales and its tooltip stays inside; every control ≥ 44 px, `scrollWidth` = viewport, no console errors;
  JavaScript off saves an ingredient, a par and a guide (the `seq` inputs); the registry reads 26 screens and 33 actions built.

**Decisions taken in this spec (not questions):**
- An own unit's factor is entered as "holds N of U" and computed through `ri_to_base()` — a person types what the case holds, never a factor.
- A supplier item's typed `last_price` writes `price_history` (`scope_id NULL`, every site) so the recipe card costs before the first receipt.
- The class of an ingredient with a ledger row or a recipe line is locked by the handler; `cost` and `par` on the ingredient form are refused
  pointing at `cost_override_set` / `par_set`.
- One allergen list (the EU fourteen, which contain the US nine) with an "other" text.
- Categories, global units and schedules have no add/edit screen in the manifest: their list pages carry an add form and inline row forms (Pattern C).
- The chart component is Inventory's `shared/series-chart.php` copied verbatim (the `dataviz` palette's three slots); no chart library is added.
- `app/features/units/queries.php` is the ONE place a quantity is rendered in a unit (`format_qty()`, `display_qty()` by the person's
  `display_units`); every later slice calls it and never formats a quantity itself.
- A CSV import of a price sheet or an order guide is Extended; `supplier_item_create` with `lines` JSON serves an agent reading one aloud.

## Open questions
(none)

## Built and proven (2026-10-11)

Built by the planning model's fork in one pass: the 19 screens and 28 actions of this spec — `html/ingredients/*` (the cards, the form, the page with
eleven tabs, the own-units page, the sites page with the par and the cost override, the unit-options fragment, the yield test form with its live
preview, save / archive / delete), `html/suppliers/*` (the cards with the next cut-off, the form, the page, schedules, items, the order guide
editor), `html/areas/*`, `html/units/*`, `html/categories/*`, `html/notes/*`, `html/attachments/*`; `app/features/units/queries.php` (the ONE
place a quantity renders — `format_qty()`, `display_qty()`, `ri_qty_sentence()`, `ri_cost_sentence()`, `fmt_num()`, `fmt_input()`),
`app/features/catalog/{queries,write,present,handler}.php`, the views under `app/views/catalog/`, the chart `app/views/shared/series-chart.php`
+ `html/assets/js/series-chart.js` (Inventory's, verbatim), the five picker sources in `app/pickers.php` (ingredient, supplier, supplier_item
with `supplier` / `ingredient`, category, area), the partial-update targets for the four `*_update` tools.

**Proof:** `tests/phase3/slice1/run.sh` on `ri_dev3a` — `catalog.php` 197 checks + `browser.mjs` 37 + the registry, approvals and specs
checks = **237 under php -S and 237 under the user-level Apache** (`RI_APP=apache`), the world of this spec made through the handlers
(Dana's six ingredients with their own units, Marco's Sysco and Produce Co with schedules and items, the owner's four areas, Bea's note and
photo, the expert and the Purchasing agent under run tokens). `tests/phase2/run.sh` 347 (the placeholder count now read from the registry's
stub flags; the `/ingredients` vhost rules and the command bar's navigate follow the built list), `tests/phase0/run.sh` 124,
`tests/phase1/check_specs.sh` 52, `php bin/build_action_registry.php --check` clean (40 screens and 50 actions built with slice 2's).

**Decisions taken while building:**
- A typed `code` on `/units/save.php` makes a unit (a taken code is refused "exists already"); `unit` names an existing row for an inline edit.
- A supplier's `account_number` is withheld from everyone without `suppliers.write` — on the page and in the JSON presenter.
- The `cheapest` mark on an ingredient's Suppliers tab is drawn only for one who sees cost (the prices it ranks are walled).
- A par at a site the person does not hold answers 404 "That site is not one you hold" (the spec said 403 for Dana at Airport; the handler
  cannot tell a site she lacks from one that does not exist without leaking the list, so both are 404 — Priya, who holds Airport as
  Kitchen, is 403 as written).
- `supplier_item.update` logs `pack`, `description`, `lead_time_days` and `contract_until` beside the fields the spec named.
- The order guide renumbers its `seq` inputs on submit as well as on drag end, so a save always reads the list as it stands.
- `fmt_input()`: a number in the `value` of an `<input type="number">` carries no thousands separator — the browser drops "3,000" (found by
  the JavaScript-off par check; the pars, reorder minimums, own-unit factors, MOQs and category percentages were rendered with `fmt_num()`).

**Found, for the parent session:**
- Slice 2's `app/features/recipes/queries.php` defined `find_record_activity()` and `recipes_using()` beside the shell's and this slice's;
  the sibling renamed the first, and this slice's `recipes_using()` is guarded by `function_exists()` (same signature).
- `tests/phase2/servers.sh stop` kills any `php -S` on 9301–9303 and the proofs bind fixed ports: two slice proofs on one tree cannot run
  at once (the sibling moved to 9304–9306 by hand). A `RI_PORT_BASE` in `servers.sh` and `lib.php` is owed.
- `bin/build_action_registry.php` did not resolve `/ingredients/{ingredient}/yield-tests/new` to `yield-tests/form.php` (the `{id}` segment
  before `new`); fixed in the `new` branch.
- The ingredient-units page is a write screen (`ingredients.write`, as the manifest has it); a Viewer reads an ingredient's units on the
  ingredient page and its JSON, not at `/ingredients/{id}/units`.
- No schema defect: db/007–019 carried every rule the handlers needed; no `db/020` from this slice.
