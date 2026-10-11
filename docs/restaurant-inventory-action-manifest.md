# Restaurant Inventory — Action Manifest

2026-10-11 · **Phase 1, for the checkpoint**

> The registry the command bar (through the kernel's chat endpoint), the kernel's Actions MCP and the screens resolve against. A screen
> or action missing here cannot be reached by voice or by an agent — unfinished design, like a question with no tool. Pairs with `db/`
> (the tables and the verbs), `docs/restaurant-inventory-mcp-tool-surface.md` (the read tools), the three build specs and the slice
> specs in `docs/build-specs/`. Generated into `mcp/action_registry.json` by `bin/build_action_registry.php`; an action whose file does
> not exist yet is registered but not exposed. The approval categories here ARE `maludb-os.json` `approvals[]` (`bin/sync_approvals.php`
> rewrites that block from this file; `--check` in every proof).

## Conventions

### Screens
- **Ids** are kebab-case `{entity}-list`, `{entity}-add`, `{entity}-view`, `{entity}-edit`, plus named screens.
- **Canonical URLs** need the rewrites in `deploy/apache-restaurant_inventory.conf`: `/{x}/new` → `form.php`, `/{x}/{id}/edit` →
  `form.php?id=`, `/{x}/{id}` → `view.php?id=`, `/{x}/{id}/{page}` → `{page}.php?id=`, `/reports/{report}` → `report.php?report=`, the
  door `/s/{token}` → `s.php`. A path parameter is written by its entity's name (`/ingredients/{ingredient}`, `/recipes/{recipe}`,
  `/purchasing/{purchase_order}`, `/counts/{count}`); every record is bigint-keyed. **Every screen is of the current site** (the
  switcher): a site row the person does not hold is 404. **Receiving, the count sheet and the waste log are designed at 375 px first**
  (a cook in the walk-in); the recipe editor, usage and the reports at 1280; no screen is a modal (the record picker and `hx-confirm` are
  the two exemptions); records get full-page create/edit; named things (ingredients, recipes, suppliers, sources, sheets) are cards;
  lines, movements, levels, usage rows and admin lists are tables.
- **Prefill params** become query-string values the GET controller reads.
- **Every screen partial stamps** `data-screen`, `data-entity`, `data-record-id`, `data-scope-id` on `#page-content`, so "this
  recipe", "count this" and "order that" resolve against the page the user is on.
- **The supplier's door** (`/s/{token}`) is a screen with no session; its three POSTs are **not actions** (no agent reaches them — the
  token is the authority, `source = door`); they are listed apart at the end.

### Actions
- **Name** `{entity}_{verb}`; the **log event** `{entity}.{verb}` is what the handler writes with `log_activity()` (with `scope_id`,
  `ingredient_id`, `recipe_id`, `purchase_order_id`, `count_id` as the record says — the audit keys of `activity_log`) and what the
  kernel's approval policies match — so an action an agent's call must pause on has **its own log event**. `.delete` destroys;
  `.archive` keeps a record out of the way, readable; `.cancel` ends a document never posted; `.void` reverses a posted run or log;
  `.reverse` reverses one ledger row.
- **Endpoints** are POST to `/{base}/{file}` (a file written absolutely names its own directory) with `require_post()`, `verify_csrf()`
  (or the relayed action token), the right at the site (`require_right()`), the record's reach (`require_site()`) and `log_activity()`.
  **A write calls the database's verb** (`ri_post_receipt()`, `ri_transfer_send()`, `ri_post_production()`, `ri_post_waste()`,
  `ri_recipe_version_activate()`, `ri_depletion_run()`, `ri_count_approve()`, `ri_period_close()`, `ri_suggested_order_draft()`, …) and
  shows its sentence as a field error; PHP never re-implements a rule the database keeps (a unit the ingredient cannot convert, a case
  under one base unit, a recipe that contains itself, a line changed after posting, a balance maintained by hand, a credential read
  back). Handlers report through `emit_action_status()`; a create's `location` ends in the new record's id and the reply carries
  `record_id` (the **record_id** column says what it names).
- **Params:** **bold** = required; `[]` = repeated; parentheses = a hint (no comma or semicolon inside; no note after the last
  parameter). A param named for a record (`ingredient`, `recipe`, `sub_recipe`, `menu_item`, `modifier`, `supplier`, `supplier_item`,
  `area`, `from_area`, `to_area`, `source`, `member`, `agent`, `site`, `to_site`, `category`, `menu_category`) accepts an id or a name,
  resolved through the application's own tool (`deploy/kernel-registry-restaurant-inventory.json`'s resolve block, the tool surface's
  resolution table); an ingredient also accepts its code, a menu item its PLU, a supplier item its SKU, a purchase order, receipt,
  transfer, count, invoice, waste log or batch its number. Every other record param (`version`, `line`, `receipt`, `receipt_line`,
  `transfer`, `adjustment`, `batch`, `waste`, `purchase_order`, `invoice`, `invoice_line`, `count`, `count_line`, `sheet`,
  `sheet_line`, `container`, `period`, `calendar`, `run`, `forecast_run`, `override`, `watch`, `proposal`, `alert`, `credential`,
  `pull`, `yield_test`, `schedule`, `guide`, `unit`, `reason`, `note`, `attachment`, `transaction`, `token`, `dispatch`) is an id (or the
  document's number where it has one). Dates are `YYYY-MM-DD`; money is decimal in the settings' currency; **a quantity is always given
  with its unit** (`qty` + `unit` — a global unit's code or the ingredient's own unit's code; the handler converts through
  `ri_to_base()`); weights for a scale are grams; `lines` on a document is JSON when an agent sends several at once
  (`[{ingredient, qty, unit, …}]`). `any field of X` = X's fields, all optional, a partial update.
- **Who** is the right of design §3 enforced in the endpoint at the current site (`ri_has_right(right, scope)`): `ri.read` (Viewer);
  `stock.count`, `stock.waste`, `stock.receive`, `stock.transfer`, `stock.produce`, `notes.write` (Kitchen); `recipes.write`,
  `ingredients.write`, `counts.approve`, `waste.review`, `purchasing.draft`, `reports.read` (Chef); `suppliers.write`, `purchasing.send`,
  `invoices.write`, `prices.write`, `periods.close`, `forecast.adjust`, `exports.all` (Manager); `settings.manage`, `sources.credentials`,
  `agents.settings`, `records.delete`, `sequences.manage`, `sites.manage` (admin); `tokens.own` (everyone). `own` = the record's own
  person. A super-admin holds every right at every site. An agent is granted like a person; `is_eval` refuses every write. **Cost is the
  wall**: a param or a reply carrying cost is withheld from anyone `ri_sees_cost(scope)` refuses.
- **Undo** is the inverse `undo_last` applies; — means it cannot be undone and the reply says so (a posted movement is reversed by
  `stock_reverse`, never undone).
- **Confirm (✔)** = the command bar asks first (destructive, reaches a supplier, commits money, approves a count, closes a period, or
  changes stock with no document).
- **Agent approval** names the kernel's category that pauses the action when an **agent** performs it (design §5, D15): `money_out`
  (sending or placing a purchase order), `external_send` (a message to a supplier beyond the order), `other` for the policy defaults
  that pause (approving a count, closing or reopening a period, settling a match, activating a recipe version) and for configuration
  (a cost override, a par, a threshold, a source, its credential, its schedule, the settings, a sequence, the calendar), `deletion`
  (deleting, cancelling a purchase order, discarding a count, adjusting stock with no document, voiding a depletion run). People are
  never paused. The categories are exactly `maludb-os.json` `approvals[]` — 25 actions.
- **Agent** says whether the endpoint admits an agent's token at all: `yes`, or `people` (the handler calls `require_human()`): the
  supplier's door and a person's own tokens and preferences are people's.
- **Refresh:** a data action returns `HX-Trigger: {entity}Changed` (camelCase entity).
- **Log payloads** (what `before`/`after` must carry) are in the tool surface; **a credential, a secure token and a POS check's raw
  payload are never in a payload** (ids, names, codes, quantities with their unit, states and amounts are).

### Result contract
```json
{"status": "success", "did": "Posted GR-00012: 5 lines into Walk-in — 2 bags of flour at $30, a 38.5 lb case of chicken", "record_id": 12, "undo_id": null, "refresh": "stockChanged"}
```

## Home, me and the shell (Phase 2 and slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `home` | `/` | the site's day: projected stock-outs before the next delivery, suggested orders due today, unmapped PLUs, the last count's variance, price movers, open POs and deliveries due, the forecast's thin items, the period's status; one card per heading, each a link (params: `site`) |
| `site-switch` | `/switch-site?site=` | to change the current site (the sites the person holds) |
| `my-settings` | `/me/settings` | their own preferences: display units (metric or imperial), the default storage area for counts, email and text notifications and their kinds |
| `my-notifications` | `/me/notifications` | their notifications, unread first; mark read (params: `unread`) |
| `my-tokens` | `/me/tokens` | their MCP tokens: mint one with a label, see the last four, revoke |
| `my-trail` | `/me/trail` | their own activity, newest first (params: `from`, `to`) |
| `search` | `/search?q=` | one search across ingredients, recipes, menu items, supplier items and suppliers (`ri_find`) |

Actions (base `/me/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `site_switch` | `/switch-site.php` | **site** (one the person holds) | site_switch | | | `member.site_switch` | ri.read | yes | the site |
| `preferences_save` | `settings/save.php` | units (metric or imperial), default_area, email_enabled (yes or no), text_enabled (yes or no), kinds[] (notification kinds), text_kinds[] | restore prior | | | `member.preferences_save` | own | people | the member |
| `notification_read` | `notifications/read.php` | notification (one id — all when absent) | — | | | `member.notification_read` | own | yes | the notification |
| `token_mint` | `tokens/mint.php` | **label** (up to 80 characters), expires_in_days (blank for never) | token_revoke | | | `token.mint` | own and tokens.own | people | the token |
| `token_revoke` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | own | people | the token |

## Ingredients, units, categories, suppliers and storage areas (slice 1 — the CRUD pattern)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `ingredient-list` | `/ingredients/` | the ingredients as cards by category with on hand at this site in the count unit, the cost per recipe unit (walled), days on hand; filters (params: `q`, `category`, `kind`, `status`, `is_prep`) |
| `ingredient-add` | `/ingredients/new` | to make an ingredient: code, name, category, measure class, recipe unit, density or each-weight when the recipe unit crosses classes, yield %, catch weight, shelf life, allergens, tolerances (params: `category`) |
| `ingredient-view` | `/ingredients/{ingredient}` | one ingredient: on hand by area, the units (purchase, count, recipe) with their factors, the suppliers' items with last price per purchase unit and per recipe unit (walled), pars per site, the cost history chart, the recipes that use it, the ledger, yield tests, containers, notes, attachments, the trail (params: `tab`) |
| `ingredient-edit` | `/ingredients/{ingredient}/edit` | to change an ingredient's name, category, recipe unit, density, each-weight, yield %, catch weight, shelf life, allergens, tolerances, costing method, status |
| `ingredient-units` | `/ingredients/{ingredient}/units` | the ingredient's own units: add a purchase or count unit with its factor shown as "1 case = 6 × #10 = 18 390 g", set the default count unit, retire one |
| `ingredient-site-settings` | `/ingredients/{ingredient}/sites` | the per-site rows: par and its mode (fixed or dynamic), reorder minimum, service level, the preferred supplier item, the default depletion area, a cost override (walled) |
| `yield-test-add` | `/ingredients/{ingredient}/yield-tests/new` | to record a yield test: as-purchased and edible-portion quantities with their unit, trim and cook loss, by-products; apply it to the ingredient's yield % |
| `unit-list` | `/units/` | the global units by measure class with their factors; add one, retire one (`settings.manage`) |
| `category-list` | `/categories/` | the ingredient categories in order with kind, safety-stock %, price-alert %, temperature check; add or rename one |
| `supplier-list` | `/suppliers/` | the suppliers as cards: kind, order method, next cut-off and delivery at this site, open POs, items (params: `q`, `kind`, `status`) |
| `supplier-add` | `/suppliers/new` | to add a supplier: name, kind, contact, order method and address, terms, lead time, minimum order value, split cases, match tolerances, account number, rep |
| `supplier-view` | `/suppliers/{supplier}` | one supplier: the schedules at this site, the items with last and contract prices (walled), the order guide, open POs, invoices, price alerts, notes, the trail (params: `tab`) |
| `supplier-edit` | `/suppliers/{supplier}/edit` | to change a supplier's details, terms, tolerances, status |
| `supplier-schedules` | `/suppliers/{supplier}/schedules` | the supplier's order and delivery schedule at this site: "order Saturday by 13:00, delivered Tuesday"; add, remove |
| `supplier-items` | `/suppliers/{supplier}/items` | what the supplier sells us: SKU, ingredient, purchase unit, pack, last price, contract price and until, MOQ, catch weight; add, edit, retire |
| `order-guide-edit` | `/suppliers/{supplier}/guide` | the order guide at this site in shelf order: drag to reorder, add and remove supplier items, mark preferred |
| `area-list` | `/areas/` | the storage areas of this site in walk order: kind, allows negative, items on hand; drag to reorder; add, archive (`ingredients.write`) |
| `area-add` | `/areas/new` | to add a storage area: name, kind, walk order, allows negative |
| `area-edit` | `/areas/{area}/edit` | to change a storage area's name, kind, walk order, allows negative, active |

Actions (base `/ingredients/`, suppliers under /suppliers/, units under /units/, categories under /categories/, areas under /areas/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `ingredient_create` | `save.php` | **name**, **category**, **measure_class** (weight or volume or each), recipe_unit (a unit code — the class's base by default), code (from the name by default), density_g_per_ml, each_weight_g, each_volume_ml, yield_pct (100 by default), catch_weight (yes or no), shelf_life_days, allergens[], variance_tolerance_pct, variance_tolerance_value, costing_method (avg or last), notes | ingredient_delete | | | `ingredient.create` | ingredients.write | yes | the ingredient |
| `ingredient_update` | `save.php` | **ingredient**, any field of ingredient_create | restore prior | | | `ingredient.update` | ingredients.write | yes | the ingredient |
| `ingredient_archive` | `archive.php` | **ingredient**, archived (yes or no — no restores) | ingredient_archive | ✔ | | `ingredient.archive` | ingredients.write | yes | the ingredient |
| `ingredient_delete` | `delete.php` | **ingredient** (one with no ledger row and no recipe line) | — | ✔ | deletion | `ingredient.delete` | records.delete | yes | the ingredient |
| `unit_set` | `units/save.php` | **ingredient**, **unit_code** (the ingredient's own code — not a global unit), **name**, **to_base_factor** (base units per one — a case is never one each), kind (purchase or count or recipe), is_default_count (yes or no), active (yes or no), unit (to change an existing one) | restore prior | | | `ingredient.unit_set` | ingredients.write | yes | the ingredient unit |
| `par_set` | `sites/save.php` | **ingredient**, **site**, par (a quantity), par_unit (its unit), par_mode (fixed or dynamic), reorder_min, reorder_min_unit, service_level (0.80 to 0.99), preferred_supplier_item, default_area, active (yes or no) | restore prior | | other | `ingredient.par_set` | ingredients.write | yes | the ingredient |
| `cost_override_set` | `sites/cost.php` | **ingredient**, **site**, cost (per the unit given — blank clears the override), unit, reason | restore prior | ✔ | other | `ingredient.cost_override` | prices.write | yes | the ingredient |
| `yield_test_add` | `yield-tests/save.php` | **ingredient**, **ap_qty**, **ap_unit**, **ep_qty**, **ep_unit**, trim_loss_pct, cook_loss_pct, byproducts (JSON — name and quantity and value), apply (yes or no — sets the ingredient's yield %), notes | — | | | `ingredient.yield_test` | ingredients.write | yes | the yield test |
| `global_unit_save` | `/units/save.php` | **code**, **name**, **measure_class**, **to_base_factor**, display_order, active (yes or no) | restore prior | | | `unit.save` | settings.manage | yes | the unit |
| `category_save` | `/categories/save.php` | **name**, key (from the name by default), kind (food or beverage or supplies), parent, safety_stock_pct, price_alert_pct, temperature_check (yes or no), sort_order, active (yes or no), category (to change an existing one) | restore prior | | | `category.save` | ingredients.write | yes | the category |
| `supplier_create` | `/suppliers/save.php` | **name**, kind (distributor or producer or wholesaler or retailer or commissary or other), contact_name, email, phone, address, order_method (email or portal or phone or api or edi), order_email, portal_url, terms_days, lead_time_days, min_order_value, allow_split_case (yes or no), match_tolerance_pct, match_tolerance_value, account_number, rep_name, rep_phone, commissary_site (the site a commissary is), notes | supplier_delete | | | `supplier.create` | suppliers.write | yes | the supplier |
| `supplier_update` | `/suppliers/save.php` | **supplier**, any field of supplier_create | restore prior | | | `supplier.update` | suppliers.write | yes | the supplier |
| `supplier_archive` | `/suppliers/archive.php` | **supplier**, archived (yes or no) | supplier_archive | ✔ | | `supplier.archive` | suppliers.write | yes | the supplier |
| `supplier_delete` | `/suppliers/delete.php` | **supplier** (one with no purchase order and no receipt) | — | ✔ | deletion | `supplier.delete` | records.delete | yes | the supplier |
| `supplier_schedule_set` | `/suppliers/schedules/save.php` | **supplier**, **order_dow** (0 Sunday to 6 Saturday), **cutoff_time** (HH:MM), **delivery_dow**, active (yes or no), schedule (to change an existing one) | restore prior | | | `supplier.schedule_set` | suppliers.write | yes | the schedule |
| `supplier_schedule_remove` | `/suppliers/schedules/remove.php` | **schedule** | supplier_schedule_set | ✔ | | `supplier.schedule_remove` | suppliers.write | yes | the schedule |
| `supplier_item_create` | `/suppliers/items/save.php` | **supplier**, **ingredient**, **purchase_unit** (the ingredient's purchase unit code or a global unit for catch weight), supplier_sku, description, pack, last_price (per purchase unit), contract_price, contract_until, moq (1 by default), catch_weight (yes or no), lead_time_days | restore prior | | | `supplier_item.create` | suppliers.write | yes | the supplier item |
| `supplier_item_update` | `/suppliers/items/save.php` | **supplier_item**, any field of supplier_item_create | restore prior | | | `supplier_item.update` | suppliers.write | yes | the supplier item |
| `supplier_item_retire` | `/suppliers/items/retire.php` | **supplier_item**, active (yes or no) | supplier_item_retire | | | `supplier_item.retire` | suppliers.write | yes | the supplier item |
| `order_guide_set` | `/suppliers/guide.php` | **supplier**, **lines** (JSON — the whole list of supplier_item and seq and preferred), name (Order guide by default) | restore prior | | | `order_guide.set` | purchasing.draft | yes | the order guide |
| `area_create` | `/areas/save.php` | **name**, kind (walk_in or freezer or dry or bar or line or office or other), walk_order, allow_negative (yes or no — yes by default) | area_archive | | | `area.create` | ingredients.write | yes | the area |
| `area_update` | `/areas/save.php` | **area**, any field of area_create, active (yes or no) | restore prior | | | `area.update` | ingredients.write | yes | the area |
| `area_archive` | `/areas/archive.php` | **area** (one with nothing on hand), archived (yes or no) | area_archive | ✔ | | `area.archive` | ingredients.write | yes | the area |
| `area_reorder` | `/areas/reorder.php` | **areas[]** (ids in walk order) | restore prior | | | `area.reorder` | ingredients.write | yes | the site |
| `note_add` | `/notes/add.php` | **record_type**, **record_id**, **body** | note_remove | | | `note.add` | notes.write | yes | the note |
| `note_remove` | `/notes/remove.php` | **note** | — | ✔ | | `note.remove` | own | yes | the note |
| `attachment_add` | `/attachments/add.php` | **record_type**, **record_id**, **file** (multipart) | attachment_remove | | | `attachment.add` | notes.write | yes | the attachment |
| `attachment_remove` | `/attachments/remove.php` | **attachment** | — | ✔ | | `attachment.remove` | own | yes | the attachment |

## Recipes, the menu map and costing — THE FIRST EXEMPLAR (slice 2)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `recipe-list` | `/recipes/` | the recipes as cards by kind and menu category with the cost per portion and the food-cost % against the menu price at this site (walled), the version in force; filters (params: `q`, `kind`, `category`, `status`) |
| `recipe-add` | `/recipes/new` | to make a recipe: code, name, kind (menu item or prep or modifier), menu category, the output ingredient for a prep, portion size; a first draft version opens (params: `kind`, `output_ingredient`) |
| `recipe-view` | `/recipes/{recipe}` | one recipe: the version in force (or the draft) with its lines and the cost share of each (walled), the tree expanded for sub-recipes, batch yield and loss, the method, allergens rolled up, the menu items mapped to it, the versions' history with effective dates, where it is used as a sub-recipe, notes, the trail (params: `version`, `as_of`, `site`, `tab`) |
| `recipe-edit` | `/recipes/{recipe}/edit` | to change a recipe's name, kind, category, output ingredient, portion, method, photo, status |
| `recipe-version-edit` | `/recipes/{recipe}/versions/{version}` | **the recipe editor** (a draft): the lines table with a row form — pick an ingredient or a recipe through the record picker, quantity in any unit with the conversion shown, line yield %, group, optional —, batch yield and its unit, expected loss, the change note, the live cost per portion (walled); **Activate from a date**; **Discard the draft** |
| `recipe-what-if` | `/recipes/{recipe}/what-if` | what the plate costs if an ingredient's price changes: pick ingredients, type a price per unit, see the new cost and food-cost % (walled) (params: `ingredient`, `price`) |
| `recipe-cost-history` | `/recipes/{recipe}/cost` | the cost per portion by week at this site over the last year, with the versions marked (walled) (params: `site`, `weeks`) |
| `menu-item-list` | `/menu/` | the menu items by source and category: PLU, name, price, the recipe mapped, portions, last sold; the **unmapped queue** first; filters (params: `q`, `source`, `category`, `unmapped`) |
| `menu-item-view` | `/menu/{menu_item}` | one menu item: the PLU, the recipe and portions, prices per site, its modifiers and their mapping, sales by week, the plate cost and food-cost % (walled) |
| `menu-item-map` | `/menu/{menu_item}/map` | to map a PLU to a recipe (the record picker — or make a new recipe from here), set portions, the price, the category; or mark it as not depleting |
| `modifier-list` | `/modifiers/` | the POS modifiers by source: code, name, action (add or remove or swap or none), the recipe; the unmapped first (params: `source`, `unmapped`) |
| `modifier-map` | `/modifiers/{modifier}/map` | to map a modifier: the action, the modifier recipe, the quantity factor; per parent menu item when it differs |
| `menu-engineering` | `/menu/engineering?from=&to=` | the menu-engineering matrix for the window at this site: by category, each item's sales, mix %, plate cost, contribution margin, quadrant (star, plowhorse, puzzle, dog) (walled) (params: `category`, `site`) |
| `menu-category-list` | `/menu/categories` | the menu categories in order; add or rename one |

Actions (base `/recipes/`, menu items under /menu/, modifiers under /modifiers/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `recipe_create` | `save.php` | **name**, **kind** (menu_item or prep or modifier), code (from the name by default), menu_category, output_ingredient (a prep's — required for a prep), portion_qty, portion_unit, method, batch_yield_qty (1 by default), batch_yield_unit (a prep's output unit), expected_loss_pct, lines (JSON — ingredient or sub_recipe and qty and unit and line_yield_pct — the first draft's lines) | recipe_delete | | | `recipe.create` | recipes.write | yes | the recipe |
| `recipe_update` | `save.php` | **recipe**, any field of recipe_create (the lines through recipe_line_set) | restore prior | | | `recipe.update` | recipes.write | yes | the recipe |
| `recipe_archive` | `archive.php` | **recipe**, archived (yes or no) | recipe_archive | ✔ | | `recipe.archive` | recipes.write | yes | the recipe |
| `recipe_delete` | `delete.php` | **recipe** (one never activated and not mapped) | — | ✔ | deletion | `recipe.delete` | records.delete | yes | the recipe |
| `recipe_version_draft` | `versions/draft.php` | **recipe**, change_note (a new draft copied from the version in force) | recipe_version_discard | | | `recipe.version_draft` | recipes.write | yes | the version |
| `recipe_version_update` | `versions/save.php` | **version** (a draft), batch_yield_qty, batch_yield_unit, expected_loss_pct, change_note | restore prior | | | `recipe.version_update` | recipes.write | yes | the version |
| `recipe_line_set` | `versions/line.php` | **version** (a draft), ingredient (or sub_recipe — one of the two), sub_recipe, **qty**, **unit** (the ingredient's units or portion for a menu-item or modifier sub-recipe), line_yield_pct (100 by default), group_name, optional (yes or no), notes, seq, line (to change an existing one) | recipe_line_remove | | | `recipe.line_set` | recipes.write | yes | the line |
| `recipe_line_remove` | `versions/line-remove.php` | **line** | recipe_line_set | | | `recipe.line_remove` | recipes.write | yes | the line |
| `recipe_version_activate` | `versions/activate.php` | **version** (a draft with lines), effective_from (today by default — never before an active version's start) | — | ✔ | other | `recipe.version_activate` | recipes.write | yes | the version |
| `recipe_version_discard` | `versions/discard.php` | **version** (a draft) | — | ✔ | | `recipe.version_discard` | recipes.write | yes | the version |
| `recipe_version_retire` | `versions/retire.php` | **version** (an active one with no later version — the recipe leaves the menu from a date), effective_to | — | ✔ | | `recipe.version_retire` | recipes.write | yes | the version |
| `menu_item_create` | `/menu/save.php` | **plu**, **name**, source (the POS source — none for a hand-entered item), menu_category, sale_price, recipe, portions (1 by default) | restore prior | | | `menu_item.create` | recipes.write | yes | the menu item |
| `menu_item_map` | `/menu/map.php` | **menu_item**, recipe (a menu-item recipe — blank unmaps), portions (1 by default), no_depletion (yes or no — a PLU that sells nothing from stock), menu_category | restore prior | | | `menu_item.map` | recipes.write | yes | the menu item |
| `menu_item_price_set` | `/menu/price.php` | **menu_item**, **price**, site (this site — blank for every site), effective_from (today by default) | restore prior | | | `menu_item.price_set` | recipes.write | yes | the menu item |
| `menu_item_archive` | `/menu/archive.php` | **menu_item**, archived (yes or no) | menu_item_archive | | | `menu_item.archive` | recipes.write | yes | the menu item |
| `modifier_map` | `/modifiers/map.php` | **modifier**, **action** (add or remove or swap or none), recipe (a modifier recipe — required unless none), qty_factor (1 by default), menu_item (to map it for one parent only), parent_recipe (that parent's modifier recipe), parent_qty_factor | restore prior | | | `modifier.map` | recipes.write | yes | the modifier |
| `menu_category_save` | `/menu/categories/save.php` | **name**, key (from the name by default), kind (food or beverage or other), sort_order, active (yes or no), menu_category (to change an existing one) | restore prior | | | `menu_category.save` | recipes.write | yes | the menu category |

## The ledger: receiving, transfers, adjustments, production, waste (slice 3)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `stock-levels` | `/stock/` | on hand by ingredient and area at this site in the count unit and the base unit, days on hand, below par marked; filters; CSV (params: `area`, `category`, `q`, `below_par`, `format`) |
| `stock-movements` | `/stock/movements` | the ledger as a table: when, type, ingredient, area, quantity with its unit, cost (walled), document, reason, by; **Reverse** on a posted row (params: `ingredient`, `area`, `type`, `from`, `to`) |
| `stock-value` | `/stock/value` | the stock value at this site by category as of a date (walled) (params: `as_of`) |
| `receipt-list` | `/receipts/` | the receipts: number, status, supplier, PO, area, received on, lines, temperature check; drafts first (params: `status`, `supplier`, `from`, `to`) |
| `receipt-add` | `/receipts/new` | to start a receipt: supplier, purchase order (its open lines prefilled), area, received on, delivery note; lands on the receiving screen (params: `purchase_order`, `supplier`, `area`) |
| `receipt-view` | `/receipts/{receipt}` | **the receiving screen** at 375 px: the header; the line form — ingredient through the picker (or the PO line), quantity in the purchase unit, the declared weight for catch weight, the cost per unit (walled), the temperature for a checked category, discrepancy, put-away area; the lines; **Post**, **Cancel**; posted: the movements and the trail |
| `receipt-edit` | `/receipts/{receipt}/edit` | a draft's header |
| `transfer-list` | `/transfers/` | the transfers from or to this site: number, status, from → to, lines, sent, received (params: `status`) |
| `transfer-add` | `/transfers/new` | to start a transfer: to a site (this one or another), from area, to area, notes (params: `to_site`, `from_area`, `to_area`, `ingredient`) |
| `transfer-view` | `/transfers/{transfer}` | a draft: the lines (ingredient, quantity with its unit); **Send**; in transit: quantities received per line, **Receive**; received: both halves' movements |
| `adjustment-list` | `/adjustments/` | the adjustments: number, status, area, reason, lines, posted (params: `status`, `reason`) |
| `adjustment-add` | `/adjustments/new` | to start an adjustment: area, reason, notes; a first line when an ingredient is given (params: `area`, `reason`, `ingredient`) |
| `adjustment-view` | `/adjustments/{adjustment}` | a draft: the lines (ingredient, signed quantity with its unit, a cost for an opening balance (walled), note); **Post**, **Cancel**; posted: the movements |
| `production-list` | `/production/` | the prep batches at this site: number, recipe, batches, output, made at, status, cost (walled) (params: `status`, `recipe`, `from`, `to`) |
| `production-add` | `/production/new` | to post a prep batch at 375 px: the prep recipe (the picker), how many batches, the area the output lands in, where the inputs come from, the actual output when it differs; what it will consume shown first (params: `recipe`) |
| `production-view` | `/production/{batch}` | one batch: what it consumed and what it made, at cost (walled), the movements |
| `waste-list` | `/waste/` | the waste logs at this site by day: stage, reason, item or plate, quantity, cost (walled), station, who; the week's totals by stage and reason; review (params: `from`, `to`, `stage`, `reason`, `station`) |
| `waste-add` | `/waste/new` | **the waste log at 375 px**: stage (ingredient, prep, plate), reason, the ingredient or the plate (the picker), quantity with its unit or a gross weight with the tare, station, disposition, a photo; posts on save (params: `stage`, `ingredient`, `recipe`) |
| `waste-view` | `/waste/{waste}` | one waste log with its movements; **Review**; **Void** |

Actions (base `/receipts/`, stock under /stock/, transfers under /transfers/, adjustments under /adjustments/, production under /production/, waste under /waste/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `receipt_draft` | `save.php` | supplier, purchase_order (its open lines become the draft's lines), **area**, received_on (today by default), delivery_note_ref, invoice_ref, notes, lines (JSON — ingredient and qty and unit and unit_cost and weight_qty and weight_unit and temperature_c and discrepancy_kind and putaway_area) | receipt_cancel | | | `receipt.draft` | stock.receive | yes | the receipt |
| `receipt_update` | `save.php` | **receipt** (a draft), any field of receipt_draft | restore prior | | | `receipt.update` | stock.receive | yes | the receipt |
| `receipt_line_add` | `line.php` | **receipt** (a draft), **ingredient**, **qty**, **unit** (the purchase unit), supplier_item, purchase_order_line, weight_qty (catch weight — the declared weight), weight_unit (lb by default), unit_cost (per purchase unit — per weight unit for catch weight), allow_zero_cost (yes or no), discrepancy_kind (none or short or over or damaged or wrong_item or substitute), discrepancy_note, temperature_c, temperature_ok (yes or no), putaway_area, receipt_line (to change an existing one) | receipt_line_remove | | | `receipt.line_add` | stock.receive | yes | the receipt line |
| `receipt_line_remove` | `line-remove.php` | **receipt_line** | receipt_line_add | | | `receipt.line_remove` | stock.receive | yes | the receipt line |
| `receipt_post` | `post.php` | **receipt** (a draft with lines) | — | ✔ | | `receipt.post` | stock.receive | yes | the receipt |
| `receipt_cancel` | `cancel.php` | **receipt** (a draft) | — | ✔ | | `receipt.cancel` | stock.receive | yes | the receipt |
| `stock_reverse` | `/stock/reverse.php` | **transaction** (a posted row with no reversal), note | — | ✔ | | `stock.reverse` | counts.approve | yes | the reversal |
| `transfer_draft` | `/transfers/save.php` | **from_area**, **to_area**, to_site (this site by default), notes, lines (JSON — ingredient and qty and unit) | transfer_cancel | | | `transfer.draft` | stock.transfer | yes | the transfer |
| `transfer_update` | `/transfers/save.php` | **transfer** (a draft), from_area, to_area, to_site, notes | restore prior | | | `transfer.update` | stock.transfer | yes | the transfer |
| `transfer_line_set` | `/transfers/line.php` | **transfer** (a draft), **ingredient**, **qty**, **unit**, line (to change an existing one) | transfer_line_remove | | | `transfer.line_set` | stock.transfer | yes | the transfer line |
| `transfer_line_remove` | `/transfers/line-remove.php` | **line** | transfer_line_set | | | `transfer.line_remove` | stock.transfer | yes | the transfer line |
| `transfer_send` | `/transfers/send.php` | **transfer** (a draft with lines) | — | ✔ | | `transfer.send` | stock.transfer | yes | the transfer |
| `transfer_receive` | `/transfers/receive.php` | **transfer** (in transit), received (JSON — line and qty and unit when short) | — | ✔ | | `transfer.receive` | stock.transfer | yes | the transfer |
| `transfer_cancel` | `/transfers/cancel.php` | **transfer** (a draft) | — | ✔ | | `transfer.cancel` | stock.transfer | yes | the transfer |
| `adjustment_draft` | `/adjustments/save.php` | **area**, **reason** (an adjustment reason), notes, lines (JSON — ingredient and qty_delta and unit and unit_cost and note) | adjustment_cancel | | | `adjustment.draft` | counts.approve | yes | the adjustment |
| `adjustment_line_set` | `/adjustments/line.php` | **adjustment** (a draft), **ingredient**, **qty_delta** (signed and never zero), **unit**, unit_cost (per that unit — an opening balance), note, line (to change an existing one) | adjustment_line_remove | | | `adjustment.line_set` | counts.approve | yes | the adjustment line |
| `adjustment_line_remove` | `/adjustments/line-remove.php` | **line** | adjustment_line_set | | | `adjustment.line_remove` | counts.approve | yes | the adjustment line |
| `stock_adjust` | `/adjustments/post.php` | **adjustment** (a draft with lines — a quantity changes with no other document) | — | ✔ | deletion | `adjustment.post` | counts.approve | yes | the adjustment |
| `adjustment_cancel` | `/adjustments/cancel.php` | **adjustment** (a draft) | — | ✔ | | `adjustment.cancel` | counts.approve | yes | the adjustment |
| `production_post` | `/production/save.php` | **recipe** (a prep), batches (1 by default), **area** (where the output lands), consume_area (each ingredient's depletion area by default), output_qty (the actual output when it differs), output_unit, made_at (now by default), notes, draft (yes or no — yes keeps it a draft) | production_cancel | ✔ | | `production.post` | stock.produce | yes | the batch |
| `production_cancel` | `/production/cancel.php` | **batch** (a draft) | — | ✔ | | `production.cancel` | stock.produce | yes | the batch |
| `waste_log` | `/waste/save.php` | **stage** (ingredient or prep or plate), **reason** (a waste reason), ingredient (or recipe — one of the two), recipe, **qty**, **unit** (portion or batch for a recipe), gross_weight_g (a weighed waste), tare_g, station, day_part, area, disposition (trash or compost or donation or staff_meal or comp or other), photo (multipart), notes, business_date (today by default) | waste_void | | | `waste.log` | stock.waste | yes | the waste log |
| `waste_review` | `/waste/review.php` | **waste** | — | | | `waste.review` | waste.review | yes | the waste log |
| `waste_void` | `/waste/void.php` | **waste**, note | — | ✔ | | `waste.void` | waste.review | yes | the waste log |

## Purchasing, the supplier's door, invoices and the match (slice 4)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `purchase-order-list` | `/purchasing/` | the purchase orders at this site: number, status, supplier, origin, ordered on, expected on, total (walled), acknowledged; filters (params: `status`, `supplier`, `from`, `to`) |
| `purchase-order-add` | `/purchasing/new` | to start a PO by hand: supplier, the area it will be received in, expected date; the supplier's order guide as the line list (params: `supplier`) |
| `purchase-order-view` | `/purchasing/{purchase_order}` | one PO: the header, the lines in purchase units with cost (walled) and — on a suggested one — need, have, on order, cap and the rounding shown per line; the events; the supplier's link; **Send** or **Mark placed**, **Receive** (opens a receipt), **Close short**, **Cancel** (params: `tab`) |
| `purchase-order-edit` | `/purchasing/{purchase_order}/edit` | a draft's header and lines: add a supplier item through the picker, quantities, costs (walled) |
| `deliveries-due` | `/purchasing/due` | the deliveries expected by a date at this site with whether a receipt exists; **Receive** (params: `on`) |
| `invoice-list` | `/invoices/` | the supplier invoices at this site: our number, theirs, supplier, date, total, status (draft, matched, disputed, settled) (params: `status`, `supplier`, `from`, `to`) |
| `invoice-add` | `/invoices/new` | to enter an invoice: supplier, their number, date, the receipt or PO it bills, tax, the attachment; lands on the lines (params: `receipt`, `purchase_order`, `supplier`) |
| `invoice-view` | `/invoices/{invoice}` | one invoice: the lines with quantity, unit and price, each matched to a receipt line with its state (ok, quantity differs, price differs, both, unordered); **Match**, **Settle**, **Dispute**, **Void**; the credit note it pairs with |
| `invoice-edit` | `/invoices/{invoice}/edit` | a draft's header and lines |
| `price-alert-list` | `/purchasing/alerts` | the cost moves over the threshold at this site: ingredient, supplier item, old and new per unit, %, when, the invoice or receipt; **Seen** (params: `seen`, `from`) |

Actions (base `/purchasing/`, invoices under /invoices/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `purchase_order_draft` | `save.php` | **supplier**, area (where it is received), expected_on, notes, internal_notes, lines (JSON — supplier_item or ingredient and qty and unit and unit_cost) | purchase_order_cancel | | | `purchase_order.draft` | purchasing.draft | yes | the purchase order |
| `purchase_order_update` | `save.php` | **purchase_order** (a draft), area, expected_on, notes, internal_notes | restore prior | | | `purchase_order.update` | purchasing.draft | yes | the purchase order |
| `purchase_order_line_set` | `line.php` | **purchase_order** (a draft), supplier_item (or ingredient), ingredient, **qty**, **unit** (the purchase unit), unit_cost (per purchase unit — the supplier item's last price by default), expected_on, line (to change an existing one) | purchase_order_line_remove | | | `purchase_order.line_set` | purchasing.draft | yes | the line |
| `purchase_order_line_remove` | `line-remove.php` | **line** | purchase_order_line_set | | | `purchase_order.line_remove` | purchasing.draft | yes | the line |
| `purchase_order_send` | `send.php` | **purchase_order** (a draft with lines), via (email by default — the supplier's order method), message (a note to the supplier on the email) | — | ✔ | money_out | `purchase_order.send` | purchasing.send | yes | the purchase order |
| `purchase_order_place` | `place.php` | **purchase_order** (a draft with lines — placed on the supplier's portal or by phone), via (portal or phone or api or edi), **supplier_order_ref** | — | ✔ | money_out | `purchase_order.place` | purchasing.send | yes | the purchase order |
| `purchase_order_acknowledge` | `acknowledge.php` | **purchase_order** (sent — the supplier confirmed by phone or email), expected_on, supplier_order_ref | — | | | `purchase_order.acknowledge` | purchasing.draft | yes | the purchase order |
| `purchase_order_line_decline` | `line-decline.php` | **line** (the supplier cannot fill it), reason | — | | | `purchase_order.line_decline` | purchasing.draft | yes | the line |
| `purchase_order_close` | `close.php` | **purchase_order** (received or partial), short (yes or no — the open lines are closed short) | — | ✔ | | `purchase_order.close` | purchasing.send | yes | the purchase order |
| `purchase_order_cancel` | `cancel.php` | **purchase_order** (a draft or one sent and nothing received), reason | — | ✔ | deletion | `purchase_order.cancel` | purchasing.send | yes | the purchase order |
| `supplier_link_rotate` | `link.php` | **purchase_order** (a new secure link — the old one dies) | — | ✔ | | `purchase_order.link_rotate` | purchasing.send | yes | the purchase order |
| `supplier_message` | `message.php` | **supplier**, **body**, purchase_order (the order it is about), subject | — | ✔ | external_send | `supplier.message` | purchasing.send | yes | the supplier |
| `invoice_create` | `/invoices/save.php` | **supplier**, **invoice_no**, **invoice_date**, kind (invoice or credit), receipt (the receipt it bills), purchase_order, tax, received_on (today by default), file (multipart — the invoice image), notes, lines (JSON — supplier_item or ingredient and description and qty and unit and unit_price) | invoice_void | | | `invoice.create` | invoices.write | yes | the invoice |
| `invoice_update` | `/invoices/save.php` | **invoice** (a draft or disputed), any field of invoice_create | restore prior | | | `invoice.update` | invoices.write | yes | the invoice |
| `invoice_line_set` | `/invoices/line.php` | **invoice**, supplier_item (or ingredient), ingredient, description, **qty**, **unit**, **unit_price**, receipt_line (the receipt line it bills — found by supplier item when absent), invoice_line (to change an existing one) | invoice_line_remove | | | `invoice.line_set` | invoices.write | yes | the invoice line |
| `invoice_line_remove` | `/invoices/line-remove.php` | **invoice_line** | invoice_line_set | | | `invoice.line_remove` | invoices.write | yes | the invoice line |
| `invoice_match` | `/invoices/match.php` | **invoice** (every line against its receipt line within the supplier's tolerances) | — | | | `invoice.match` | invoices.write | yes | the invoice |
| `invoice_match_settle` | `/invoices/settle.php` | **invoice** (matched or disputed — the invoice becomes the truth: price differences write price history and quantity differences correct the receipt), note | — | ✔ | other | `invoice.settle` | invoices.write | yes | the invoice |
| `invoice_void` | `/invoices/void.php` | **invoice** (not settled), reason | — | ✔ | | `invoice.void` | invoices.write | yes | the invoice |
| `price_alert_seen` | `alerts/seen.php` | alert (one id — every open alert at this site when absent) | — | | | `price_alert.seen` | purchasing.draft | yes | the alert |
| `price_alert_threshold_set` | `alerts/threshold.php` | pct (the default threshold — the setting), category (to set a category's own), category_pct | restore prior | | other | `price_alert.threshold_set` | prices.write | yes | the category |

## POS sources, sales and depletion — THE SECOND EXEMPLAR (slice 5)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `source-list` | `/sales/sources/` | the POS sources of this site as cards: connector, intake, schedule, last pull, failures, paused; **New source** (params: `status`) |
| `source-add` | `/sales/sources/new` | to add a source: name, connector (file or square — the rest Extended), for a file the mapping template and the intake (upload, email, SFTP host and path), for Square the location; the credential set apart (params: `connector`) |
| `source-view` | `/sales/sources/{source}` | one source: its settings, the credential's label and last four, the pulls with counts and errors, the days imported, the unmapped PLUs it brought; **Pull now**, **Upload a file**, **Pause**, **Resume** (params: `tab`) |
| `source-edit` | `/sales/sources/{source}/edit` | to change a source's name, settings, mapping, intake, schedule |
| `source-credential` | `/sales/sources/{source}/credential` | to set or rotate the source's credential: kind, label, the secret (never shown again) |
| `mapping-list` | `/sales/mappings/` | the sales-file mappings: the built-in templates and the business's own; the columns each expects |
| `mapping-edit` | `/sales/mappings/{mapping}` | to edit a mapping: the column names, the date format, the delimiter; a sample file previewed |
| `sales-upload` | `/sales/upload` | to drop a POS export: the source, the file, a preview of the first rows and the dates seen; **Import** (params: `source`) |
| `sales-day-list` | `/sales/` | the days with sales at this site: date, checks, covers, net, comps, voids, the depletion run's status; **Deplete** on a day not run (params: `from`, `to`) |
| `sales-day-view` | `/sales/{date}` | one day: sales by item and day part, the checks, the modifiers seen, what the day depleted by ingredient (cost walled), the unmapped lines; **Re-run** (params: `day_part`) |
| `depletion-run-list` | `/sales/runs/` | the depletion runs: date, status, lines total, depleted, unmapped, voided, modifiers unmapped, ingredients, value (walled), run no (params: `status`, `from`, `to`) |
| `depletion-run-view` | `/sales/runs/{run}` | one run: the counts and the movements it posted; **Re-run**, **Void** |
| `pull-list` | `/sales/sources/{source}/pulls` | the source's pulls: kind, when, status, file, dates seen, sales new, PLUs new, error |

Actions (base `/sales/`, sources under /sales/sources/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `source_create` | `/sales/sources/save.php` | **name**, **connector** (file or square), settings (JSON — mapping_key and intake and sftp host and path and delimiter — or square location_id), base_url, schedule_minutes (0 for on arrival) | source_delete | | other | `source.create` | settings.manage | yes | the source |
| `source_update` | `/sales/sources/save.php` | **source**, any field of source_create | restore prior | | | `source.update` | settings.manage | yes | the source |
| `source_credential_set` | `/sales/sources/credential.php` | **source**, **kind** (api_key or oauth_client or basic or sftp_password or sftp_key or bearer), **label**, **secret** (never shown again) | — | ✔ | other | `source.credential_set` | sources.credentials | yes | the credential |
| `source_schedule_set` | `/sales/sources/schedule.php` | **source**, **schedule_minutes** (0 for on arrival) | restore prior | | other | `source.schedule_set` | settings.manage | yes | the source |
| `source_pause` | `/sales/sources/pause.php` | **source**, paused (yes or no — no resumes), reason | source_pause | | | `source.pause` | settings.manage | yes | the source |
| `source_pull` | `/sales/sources/pull.php` | **source** (a pull now — Square or SFTP) | — | | | `source.pull_start` | purchasing.draft | yes | the pull |
| `source_delete` | `/sales/sources/delete.php` | **source** (one with no sales) | — | ✔ | deletion | `source.delete` | records.delete | yes | the source |
| `sales_upload` | `upload.php` | **source** (a file source), **file** (multipart CSV or XLSX), mapping (the mapping key — the source's by default), deplete (yes or no — run the depletion when imported) | — | | | `sale.import` | stock.receive | yes | the pull |
| `mapping_save` | `mappings/save.php` | **key**, **name**, **mapping** (JSON — columns and date_format and delimiter), mapping_key (to change an existing one) | restore prior | | | `mapping.save` | settings.manage | yes | the mapping |
| `depletion_run` | `runs/run.php` | **business_date** (a day with sales — re-run when one exists) | — | | | `depletion.run` | stock.receive | yes | the run |
| `depletion_run_void` | `runs/void.php` | **run**, note | — | ✔ | deletion | `depletion.void` | counts.approve | yes | the run |

## Counts (slice 6)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `count-sheet-list` | `/counts/sheets/` | the count sheets of this site as cards: kind, areas, lines; **New sheet**, **Fill from the shelves** |
| `count-sheet-edit` | `/counts/sheets/{sheet}` | the sheet by storage area in walk order: drag lines to shelf order, add an ingredient to an area through the picker, set its count unit, remove |
| `container-list` | `/counts/containers/` | the bottles and kegs the bar weighs: ingredient, full and empty weights, pourer tare, volume; add, edit (params: `ingredient`) |
| `count-list` | `/counts/` | the counts at this site: number, kind, as of, status, started, lines counted, over tolerance, variance (walled), approved (params: `status`, `kind`, `from`, `to`) |
| `count-add` | `/counts/new` | to start a count: the sheet (or the whole site), kind, as of (the day's sales cut by default), blind (the setting); lands on the count (params: `sheet`, `kind`) |
| `count-view` | `/counts/{count}` | **the count sheet at 375 px**: one storage area at a time in walk order with a progress bar; each line in the unit on the shelf — a case + loose field, or the scale's gross weight for a bottle, zero, skip; entries kept in the browser until posted and resent on signal; the book figure hidden on a blind count; **Review** when every line is counted, zeroed or skipped (params: `area`) |
| `count-review` | `/counts/{count}/review` | the review: every line's book figure, counted figure, variance in units and at cost (walled), the lines over tolerance flagged, the areas skipped listed; **Recount** marks, **Approve**, **Discard** (params: `over_only`) |
| `count-history` | `/counts/{count}/history` | an approved count: the corrections it posted, the variance by category, who counted what |

Actions (base `/counts/`):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `count_sheet_save` | `sheets/save.php` | **name**, kind (full or cycle or spot), fill (yes or no — every ingredient with a balance in every area in walk order), sheet (to change an existing one), active (yes or no) | restore prior | | | `count_sheet.save` | ingredients.write | yes | the sheet |
| `count_sheet_line_set` | `sheets/line.php` | **sheet**, **area**, **ingredient**, shelf_order, count_unit, sheet_line (to change an existing one) | count_sheet_line_remove | | | `count_sheet.line_set` | ingredients.write | yes | the sheet line |
| `count_sheet_line_remove` | `sheets/line-remove.php` | **sheet_line** | count_sheet_line_set | | | `count_sheet.line_remove` | ingredients.write | yes | the sheet line |
| `count_sheet_reorder` | `sheets/reorder.php` | **sheet**, **area**, **lines[]** (sheet line ids in shelf order) | restore prior | | | `count_sheet.reorder` | ingredients.write | yes | the sheet |
| `container_save` | `containers/save.php` | **ingredient**, **name**, **full_weight_g**, **empty_weight_g**, **volume_ml**, pourer_tare_g, is_default (yes or no), container (to change an existing one), active (yes or no) | restore prior | | | `container.save` | ingredients.write | yes | the container |
| `count_start` | `start.php` | sheet (the whole site when absent), kind (full by default), as_of (the day's sales cut by default), blind (the setting by default), notes | count_discard | | | `count.start` | stock.count | yes | the count |
| `count_line_set` | `line.php` | **count_line**, entries (JSON — unit and qty — a case and loose), gross_weight_g (a weighed bottle or keg), container, state (counted or zero or skipped), note | restore prior | | | `count.line_set` | stock.count | yes | the count line |
| `count_line_add` | `line-add.php` | **count** (an ingredient found on the shelf that is not on the sheet), **area**, **ingredient**, entries (JSON) | — | | | `count.line_add` | stock.count | yes | the count line |
| `count_review` | `review.php` | **count** (every line counted or zeroed or skipped — the variances computed and the tolerances flagged) | — | | | `count.review` | stock.count | yes | the count |
| `count_recount` | `recount.php` | **count_line** (a line over tolerance — by another person), entries (JSON), gross_weight_g, note | — | | | `count.recount` | stock.count | yes | the count line |
| `count_approve` | `approve.php` | **count** (in review — the corrections post at the count's moment), force (yes or no — accept lines still awaiting a recount) | — | ✔ | other | `count.approve` | counts.approve | yes | the count |
| `count_discard` | `discard.php` | **count** (not approved), reason | — | ✔ | deletion | `count.discard` | counts.approve | yes | the count |

## Periods, usage, variance and the close (slice 7)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `period-list` | `/periods/` | the periods of this site by fiscal year: no, dates, status, opening and ending value, purchases, COGS, food-cost % (walled); **Close** on the first open one past its end; **Reopen** (params: `year`, `status`) |
| `period-view` | `/periods/{period}` | one period: the figures frozen or live, the opening and closing counts, the usage by category, the late rows that landed in it; **Close**, **Reopen**; exports (params: `tab`) |
| `usage-report` | `/usage/?from=&to=` | **actual vs theoretical** by ingredient for the window at this site with every column — opening, purchases, transfers, production, adjustments, closing, actual, theoretical, variance, waste, comps, unexplained — in base units and at cost (walled), sortable by unexplained value; by category; CSV (params: `category`, `basis`, `sort`, `format`) |
| `usage-ingredient` | `/usage/{ingredient}?from=&to=` | one ingredient's week: the buckets, the recipes that consumed it and how much each, the waste logged, the count lines, the movements |
| `cogs-report` | `/usage/cogs?year=` | COGS, food sales and food-cost % by period at this site, actual and theoretical, with the variance (walled) |
| `calendar-list` | `/periods/calendars` | the costing calendars: kind, year start, the default; **New**, **Make the year's periods** (`settings.manage`) |

Actions (base `/periods/`, usage under /usage/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `calendar_set` | `calendars/save.php` | **name**, **kind** (week or month or 4-4-5 or 4-5-4 or 5-4-4 or 13x4), **year_start_on**, week_starts (1 Monday by default), is_default (yes or no), calendar (to change an existing one) | restore prior | | other | `calendar.set` | settings.manage | yes | the calendar |
| `periods_ensure` | `ensure.php` | **fiscal_year**, calendar (the default by default) | — | | | `period.ensure` | periods.close | yes | the site |
| `period_close` | `close.php` | **period** (the first open one past its last day — every day's sales depleted), closing_count (an approved count as of its end) | — | ✔ | other | `period.close` | periods.close | yes | the period |
| `period_reopen` | `reopen.php` | **period** (the last closed one), reason | — | ✔ | other | `period.reopen` | settings.manage | yes | the period |
| `usage_export` | `/usage/export.php` | **from**, **to**, format (csv or json), by (ingredient or category) | — | | | `export.download` | exports.all | yes | the export |

## Projection, ordering, the Purchasing agent's data and the worker — THE THIRD EXEMPLAR (slice 8)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `forecast-list` | `/projection/` | the forecast per menu item at this site for the next three weeks: the baseline or the run, the band, the overrides; the accuracy (WAPE and bias) per item; items with thin history flagged (params: `category`, `horizon`, `sort`) |
| `forecast-item` | `/projection/items/{menu_item}` | one item: the chart of the forecast against the actuals by day with the band, the same weekdays, the overrides, the covers factor; **Override** |
| `calendar-days` | `/projection/calendar` | the site's trading calendar: closures, holidays, events by day; booked covers from Reservations; **Close a day**, **Name an event** (params: `month`) |
| `demand-report` | `/projection/demand?from=&to=` | ingredient demand for the window exploded through the recipes: by ingredient and day, in the count unit and the base unit; the recipes that drive it (params: `category`, `ingredient`) |
| `projected-stock` | `/projection/stock` | projected on hand per ingredient per day for two weeks: on hand, demand, receipts due, projected; the stock-out day marked; filters (params: `category`, `days`, `stockouts_only`) |
| `projected-ingredient` | `/projection/stock/{ingredient}` | one ingredient's projection: the chart, the open PO lines due, the suggested order that covers it |
| `suggested-order-list` | `/purchasing/suggested` | **the suggested orders** at this site: one card per supplier with its next cut-off and delivery, the lines with need, have, on order, cap, the rounding and the units, the par mode and a thin-history flag per line; **Draft the PO** per supplier; **Draft all due** (params: `supplier`, `due_hours`) |
| `special-check` | `/projection/special?recipe=&date=&portions=` | can we run the special: a recipe, a date and the portions — what it needs per ingredient against the projected stock that day, the shortfalls and the supplier that can bring them by then |
| `proposal-list` | `/projection/proposals` | the Purchasing agent's proposals by day at this site: orders drafted, stock-outs, prices, the POS, the count, the forecast, overdue POs, deliveries; **Accept**, **Dismiss** (params: `date`, `kind`, `status`) |
| `watch-list` | `/projection/watches` | the watches at this site: mine (and everyone's for an admin): ingredient, kind, threshold, text me, last fired; **New watch** |

Actions (base `/projection/`, suggested orders under /purchasing/suggested/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `forecast_run` | `run.php` | method (baseline or holt_winters — the setting by default), horizon_days (21 by default), history_days (364 by default) | — | | | `forecast.run` | forecast.adjust | yes | the forecast run |
| `forecast_override_set` | `override.php` | menu_item (every item when absent), **from_date**, **to_date**, factor (or qty — one of the two), qty, **reason** | forecast_override_remove | | | `forecast.override` | forecast.adjust | yes | the override |
| `forecast_override_propose` | `override-propose.php` | menu_item, **from_date**, **to_date**, factor (or qty), qty, **reason** (an agent's proposal — a person sets it) | — | | | `forecast.override_propose` | purchasing.draft | yes | the override |
| `forecast_override_decide` | `override-decide.php` | **override** (a proposed one), **decision** (set or dismiss) | — | | | `forecast.override_decide` | forecast.adjust | yes | the override |
| `forecast_override_remove` | `override-remove.php` | **override** | forecast_override_set | | | `forecast.override_remove` | forecast.adjust | yes | the override |
| `calendar_day_set` | `calendar/save.php` | **on_date**, closed (yes or no), holiday, event, note | restore prior | | | `calendar.day_set` | forecast.adjust | yes | the day |
| `covers_sync` | `calendar/covers.php` | from (today by default), to (62 days by default — the booked covers read from Reservations through K7) | — | | | `forecast.covers_sync` | forecast.adjust | yes | the site |
| `suggested_order_run` | `/purchasing/suggested/run.php` | supplier (one supplier — every supplier due within due_hours when absent), due_hours (36 by default), draft (yes or no — draft the POs or only show) | — | | | `purchase_order.suggest` | purchasing.draft | yes | the purchase order |
| `watch_set` | `watches/save.php` | **ingredient**, **kind** (below_par or stockout_projected or cost_above or cost_moved), threshold (a cost per recipe unit or a percentage), agent (an agent to dispatch when it fires), text_me (yes or no), note, watch (to change an existing one) | watch_clear | | | `watch.set` | ri.read | yes | the watch |
| `watch_clear` | `watches/clear.php` | **watch** | watch_set | | | `watch.clear` | own | yes | the watch |
| `buyer_propose` | `proposals/save.php` | **kind** (order or stockout or price or pos or count or forecast or po_overdue or delivery), **subject_type**, **subject_id**, **title**, detail (JSON), drafted_record_type, drafted_record_id | — | | | `buyer.propose` | purchasing.draft | yes | the proposal |
| `buyer_proposal_decide` | `proposals/decide.php` | **proposal**, **decision** (accept or dismiss) | — | | | `buyer.decide` | purchasing.draft | yes | the proposal |
| `morning_note_send` | `proposals/note.php` | date (today by default — the note assembled from the day's proposals and sent to the site's Manager and to Spaces when installed) | — | | | `buyer.note` | purchasing.draft | yes | the site |

## Reports, exports, admin and the agents (slice 9)

Screens:

| Screen id | URL | When the user wants… |
| --- | --- | --- |
| `report-list` | `/reports/` | the reports: stock value, usage and variance, COGS and food-cost %, days on hand, price movers, waste by stage and reason, menu engineering, forecast accuracy, purchases by supplier, sales by day; each a card with its window |
| `report-view` | `/reports/{report}?from=&to=` | one report with its filters, a chart where one helps, and **CSV** / **JSON** (params: `category`, `supplier`, `site`) |
| `export-list` | `/reports/exports` | the ledger's documents as downloads: purchases received, stock valuation as of a date, COGS by period, waste, sales by day — CSV and JSON, for an accountant without the General Ledger (params: `period`, `as_of`) |
| `multi-site` | `/reports/sites` | the business's sites side by side: stock value, food-cost %, variance, waste, stock-outs projected (an admin or a Manager of several) |
| `admin-settings` | `/admin/settings` | the business settings: cost basis, blind counts, the price-alert threshold, the covers factor threshold, the weeks before a dynamic par, the late-receipt rule, the costing calendar, the forecast method |
| `admin-site-settings` | `/admin/sites/{site}` | one site's settings: Kitchen sees cost, the depletion area, the sales cut time, the Manager who gets the morning note, the temperature check, the day parts |
| `admin-sequences` | `/admin/sequences` | the document numbers: prefix and next value per kind |
| `admin-tax-rates` | `/admin/tax-rates` | the tax rates (on supplier invoices) |
| `admin-reasons` | `/admin/reasons` | the reason codes: name, what they apply to, the waste stage, order; add, retire |
| `admin-agents` | `/admin/agents` | the agents here with their sites, roles and the duty's hour; the dispatches and their state; the proposals' acceptance rate |
| `admin-tokens` | `/admin/tokens` | every MCP token: who, label, last used, revoke |
| `admin-trail` | `/admin/trail` | the whole activity trail at this site with filters (params: `actor`, `entity`, `from`, `to`) |

Actions (base `/admin/`, reports under /reports/):

| Action | File | Params | Undo | Confirm | Agent approval | Log | Who | Agent | record_id |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `report_run` | `/reports/run.php` | **report** (stock_value or usage or cogs or days_on_hand or price_movers or waste or menu_engineering or forecast_accuracy or purchases or sales), from, to, as_of, category, supplier, site, format (html or csv or json) | — | | | `report.run` | reports.read | yes | the report |
| `export_download` | `/reports/export.php` | **document** (purchases_received or stock_valuation or cogs_summary or waste_summary or sales_by_day), from, to, as_of, period, format (csv or json) | — | | | `export.download` | exports.all | yes | the export |
| `settings_save` | `settings/save.php` | cost_basis (avg or last), blind_counts (yes or no), price_alert_pct, covers_factor_threshold_pct, dynamic_par_after_weeks, late_receipt_flag (yes or no), calendar_kind, forecast_method (baseline or holt_winters), business_name, timezone | restore prior | | other | `settings.save` | settings.manage | yes | the settings |
| `site_settings_save` | `sites/save.php` | **site**, kitchen_sees_cost (yes or no), depletion_area, sales_cut_time (HH:MM), manager (the member the morning note goes to), temperature_check (yes or no) | restore prior | | | `settings.site_save` | settings.manage | yes | the site |
| `day_part_save` | `sites/day-part.php` | **site**, **key**, **name**, **starts_at**, **ends_at**, service_name (Reservations' service it maps to), sort_order, day_part (to change an existing one) | restore prior | | | `settings.day_part_save` | settings.manage | yes | the day part |
| `sequence_set` | `sequences/save.php` | **kind**, prefix, next_value, padding | restore prior | | other | `settings.sequence_set` | sequences.manage | yes | the sequence |
| `tax_rate_save` | `tax-rates/save.php` | **name**, **rate**, is_default (yes or no), archived (yes or no), tax_rate (to change an existing one) | restore prior | | | `settings.tax_rate_save` | settings.manage | yes | the tax rate |
| `reason_save` | `reasons/save.php` | **name**, code (from the name by default), applies_to[] (adjustment or waste or count or transfer or receipt), stage (ingredient or prep or plate), sort_order, active (yes or no), reason (to change an existing one) | restore prior | | | `settings.reason_save` | settings.manage | yes | the reason |
| `agent_settings_save` | `agents/save.php` | **agent**, duty_hour (the morning note's hour at the site), sites[] (the sites it serves here), enabled (yes or no) | restore prior | | | `agent.settings_save` | agents.settings | yes | the agent |
| `token_revoke_any` | `tokens/revoke.php` | **token** | — | ✔ | | `token.revoke` | settings.manage | yes | the token |

## The supplier's door (no session; not actions)

| Screen id | URL | When the supplier wants… |
| --- | --- | --- |
| `supplier-door` | `/s/{token}` | their purchase order: the lines in their units, the delivery address and date, our notes; **Acknowledge** (with their reference and the delivery date), **Decline a line** (with a reason), **Set the delivery date** — three POSTs to `/s/{token}` handled by `html/s.php` (`source = door`, `member_id NULL`), each writing a `purchase_order_events` row through `ri_po_supplier_event()` and notifying the PO's creator; the token is the authority (`ri_supplier_link_resolve()`), 60 days after minting it dies; no cost target of ours, no other supplier's name |

## Size

Screens: 7 (home, me, the shell) + 19 (slice 1) + 13 (slice 2) + 19 (slice 3) + 10 (slice 4) + 13 (slice 5) + 7 (slice 6) + 6 (slice 7) + 10 (slice 8) + 12 (slice 9) + 1 (the door) = **117 screens**.
Actions: 5 + 28 + 17 + 23 + 21 + 11 + 12 + 5 + 13 + 10 = **145 actions**, 25 of them with an agent-approval category (= `maludb-os.json` `approvals[]`),
9 people-only — no: 3 (`preferences_save`, `token_mint`, `token_revoke`); the door's three POSTs are not actions. Events this manifest adds beyond
design §6's list follow the same `entity.verb` rule: the line-level edits of a document (`*.line_set`, `*.line_remove`, `*.line_add`), a draft's header
(`*.update`), `member.site_switch`, `member.preferences_save`, `member.notification_read`, `token.*`, `unit.save`, `category.save`, `menu_category.save`,
`order_guide.set`, `area.*`, `note.*`, `attachment.*`, `mapping.save`, `count_sheet.*`, `container.save`, `period.ensure`, `calendar.*`,
`forecast.override_propose|override_decide|override_remove|covers_sync`, `buyer.decide`, `settings.*`, `agent.settings_save`, `price_alert.*`.
