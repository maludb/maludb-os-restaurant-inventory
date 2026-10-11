# Restaurant Inventory — MCP tool surface

2026-10-11 · **Phase 1, for the checkpoint.** The read tools that answer every question in `docs/restaurant-inventory-design.md` §7
(stock and cost SC1–SC7, recipes R1–R7, sales and depletion SD1–SD4, usage and variance U1–U6, purchasing P1–P5, projection F1–F4,
counts and waste CW1–CW2, suppliers SU1, health H1), the six K7 shares (§8) and the activity trail. Writes are never here: they are
the action tools the kernel's Actions MCP builds from `docs/restaurant-inventory-action-manifest.md`. **There is no bridge to PHP in
version 1** — every read is SQL (a view, a function, or a composition of two), so the records server holds no secret and reaches
nothing but the database.

Conventions (`mcp-and-api.md`): Python 3 + FastMCP (`mcp>=1.2,<2`), streamable HTTP at `/mcp`, bound to `127.0.0.1`, one systemd unit
each (`restaurant-inventory-records-mcp`, `restaurant-inventory-activity-mcp`, `deploy/`); `mcp/db.py` and `mcp/server_common.py` (the
kit's). Every query runs with `app.member_id` set **transaction-locally** from the verified bearer, so the `mcp_*` views (db/017) and the
read functions decide the rows and answer as the caller; a pooled connection never carries an identity past its transaction. Tool names are
snake_case. Every tool takes one `params` object (Pydantic v2, `extra="forbid"`, `str_strip_whitespace=True`, a described `Field` on every
field). **Every tool takes `site`** (a held scope id; when absent, the current site — the run facts' `scope` for an agent, the session's
site for a person's token; a person who holds several sites and names none gets `invalid_argument` with `fields: ["site"]` and the sites
held, never a guess). Every list tool takes `limit` (1–100, default 25) and `offset` (0–10,000). Every tool carries `readOnlyHint: true`,
`openWorldHint: false`, and every result is a JSON string (`db.to_json`). Ids are bigints; money is `numeric(14,2)` in the business's one
currency; **a quantity is answered three ways** — `qty_base` in the ingredient's base unit (g, ml, each), `qty_count` in its default count
unit with `count_unit`, and `qty_recipe` in its recipe unit with `recipe_unit` (`ri_from_base()`); a cost is `numeric(18,6)` per base
unit (`cost_per_base`) **and** per recipe unit (`cost_per_recipe_unit`); times are ISO 8601 with the zone, dates `YYYY-MM-DD`, a
business date is the site's day (the sales cut, `site_settings.sales_cut_time`). **The answers an agent reads aloud carry a `markdown`
field beside the facts** — `get_recipe`, `recipe_cost`, `usage_report`, `get_suggested_order`, `projected_stock`, `can_run_special`,
`morning_note` — three to twelve lines, cost named only when the caller may see it, every quantity with its unit.

**The reads are the SQL functions and views the screens call — never ad-hoc SQL in Python.** A tool names, below, the function or the
view it reads. Where §7 names a question that no db/016 function answers, the tool is composed over the views and the function it should
have is listed under "Owed to the schema" at the end.

**Errors are answers, not exceptions.** A tool that cannot answer returns `{"ok": false, "error": {"code", "message", "retry": bool}}`;
it never raises except for the run-facts gate's own refusal (the contract's ToolError). Codes: `not_found` (no such record, **or one the
caller may not see — the same words**; a site not held is `not_found` too), `invalid_argument` (`fields` names it — a unit the ingredient
cannot convert says so in the database's own sentence), `ambiguous` (a name matched several; `candidates[]` ≤ 6 with id and label),
`withheld` (the whole answer is cost — `stock_value` for a Viewer), `bridge_unavailable` (reserved; nothing uses it in v1), `too_large`
(a window over 400 days, a demand explosion over 92 days). **Every list answers `{"ok": true, "count", "rows": [...], "truncated": bool}`;
every detail answers `{"ok": true, ...fields}`** (the kernel's resolver reads `rows` from an envelope, `application_actions.py`).

## Cost is the wall — how every tool honours it (design §3, D2)

`ri_sees_cost(site)` = Chef, Manager, admin at that site, or Kitchen there when `site_settings.kitchen_sees_cost` is on; a Viewer never.
Cost is **per site**: a person who sees cost at Downtown and not at Airport gets it nulled on Airport's rows in the same answer. Three
mechanisms, and a tool says which it relies on (the **wall** column):

| Wall | Means |
|---|---|
| `view` | the `mcp_*` view nulls the cost column for a site the caller does not see cost at (`ARRAY(SELECT ri_cost_site_ids())`) and the tool passes the row through, adding `cost_withheld: true` when the column came back null and the quantity did not |
| `fn` | the read function answers cost unnulled and the **tool nulls it in Python only by asking the database** — `SELECT ri_sees_cost($1)` once per call, never a guess — before the row leaves; `cost_withheld: true` beside the null (`ri_usage`, `ri_stock_value`, `ri_days_on_hand`, `ri_price_movers`, `ri_recipe_cost`, `ri_menu_engineering`, `ri_suggested_order`, `ri_cost_as_of`) |
| `none` | the answer carries no cost field at all |

A tool **never** computes, copies or infers cost in Python beyond that one check: a withheld cost is the literal `null` beside
`cost_withheld: true`, the `markdown` says "cost withheld", and `recipe_cost` / `stock_value` / `usage_report` for a caller without the
right at that site answer `withheld` as a whole when every column they exist for is cost (the units still come through `stock_on_hand`
and `usage_report`'s quantity columns). The six share functions are the one place cost crosses unnulled: to the General Ledger (and the
siblings that read sales and waste), over a connection a super-admin approved, under the kernel's token.

## The two servers

| | Records | Activity |
|---|---|---|
| FastMCP name | `restaurant_inventory_records_mcp` | `restaurant_inventory_activity_mcp` |
| Port | `MCP_RECORDS_PORT` (pinned 8856) | `MCP_ACTIVITY_PORT` (pinned 8857) |
| Role / env | `restaurant_inventory_records_ro` (`MCP_RECORDS_DB_USER`/`_PASSWORD`) | `restaurant_inventory_activity_ro` (`MCP_ACTIVITY_DB_USER`/`_PASSWORD`) |
| Sees | 69 `mcp_*` views: the 70 of db/017 but `mcp_activity_log`, plus `mcp_app_roles` (db/004); the read functions (**owed as SECURITY DEFINER with a reader check — below**); `mcp_resolve_token`, `mcp_admit_agent`, `mcp_member_kind` | `mcp_activity_log` + `mcp_members`, `mcp_departments`, `mcp_sites`, `mcp_ingredients`, `mcp_recipes`, `mcp_suppliers`, `mcp_purchase_orders`, `mcp_inventory_counts`, `mcp_storage_areas` (names beside a row) |
| Public path | `kitchen.<domain>/mcp/records` (Apache, `ProxyPreserveHost Off`) | `/mcp/activity` |
| Kernel registry endpoint name | `Records MCP` | `Activity MCP` |
| Modules (Phase 4) | `mcp/ri_catalog.py` (ingredients, units, suppliers, areas), `ri_recipes.py`, `ri_stock.py` (the ledger, waste, production, transfers, receipts), `ri_purchasing.py` (POs, invoices, alerts, suggested orders), `ri_pos.py` (sources, sales, depletion), `ri_counts.py`, `ri_usage.py` (periods, usage, COGS, reports), `ri_projection.py` (forecasts, demand, projected stock, watches, proposals, the morning note), `ri_shares.py`, `ri_misc.py` (people, places, vocabulary, mine, search), each `register(mcp, q)` | `mcp/activity_server.py` |

**Who may call: three token shapes, one middleware** (`server_common.make_app`, the kit's — unchanged).

1. **A person's own token**: `mcp_` + 48 hex, scope `mcp`, minted on My tokens (`mcp_access_tokens`), only its sha256 stored, resolved
   by `mcp_resolve_token(hash, 'mcp')` (active, admitted). A person's token is never tool-filtered; the views and functions decide the
   rows, and `ri_held_scope_ids()` the sites.
2. **The tenant's signed tokens** (`ACTION_TOKEN_KEY`): `{mid}.{exp}.{hmac}` (the person at the keyboard, through the kernel's chat
   endpoint or the command bar) or the **agent run token** `{mid}.{exp}.{run}.{hmac}`. The member id must already be in the mirror and
   admitted — **an unknown id is refused, never created**. For a run token the server asks the kernel's run-facts call once per run,
   caches 300 s, offers exactly the tools named for `Records MCP` / `Activity MCP`, and takes the run's `scope` as the default `site`.
   On first contact a vouched agent is admitted (`mcp_admit_agent`) and resolved again. **Fail closed**: the kernel unreachable,
   `OS_APPLICATION_TOKEN` missing or `valid: false` → no tools listed, none callable. `is_eval` refuses nothing here — reads are reads.
   The agent's roles per site (Chef for the expert at both proof sites; the Purchasing agent's as hired) decide cost like anyone's.
3. **The kernel's own token** (`mint_kernel_token()`, kernel db/145): no member, no rows; **only `app_roles` and the six shares** are
   listed and callable (`KERNEL_TOOLS`), and a share reads with the kernel's `scope_id`, no caller.

**Search denial**: `SEARCH_DENY` keeps `ri_share_*`, `mcp_source_credentials`' absent `ciphertext`, token hashes and the outbox out of
`records_search`'s reach; `records_search` runs `ri_find` and nothing else.

## Records server: `restaurant_inventory_records_mcp`

### Roles (kernel db/145)
| Tool | Answers | Reads | Params | Wall |
|---|---|---|---|---|
| `app_roles` | the five roles and the rights each gives, `os.app-roles/1` — the kernel's token only | `mcp_app_roles` | — | none |

### Stock and cost (SC1–SC7)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `find` | SC1, SU1 | One search: an ingredient, a recipe, a menu item, a supplier item, a supplier by name, code, PLU or SKU; the registry's catch-all resolver | **`q`**, `kinds?[]` (ingredient, recipe, menu_item, supplier_item, supplier), `limit` | rows `{kind, id, code, name, detail, rank, label}` | `ri_find()` | none |
| `find_ingredients` | SC1 | Ingredients by words, category, class, prep flag, active; `q` alone resolves one by code or name (the registry's `ingredient`) | `q?`, `category?`, `measure_class?`, `is_prep?`, `active?` (default true), `site`, `limit`, `offset` | rows of `mcp_ingredients` + `{on_hand_base, qty_count, count_unit, qty_recipe, recipe_unit, days_on_hand, cost_per_base, cost_per_recipe_unit, cost_withheld, label}` at `site` (`mcp_ingredient_site_costs`, `ri_from_base`) | `mcp_ingredients`, `mcp_ingredient_site_costs`, `mcp_ingredient_units` | view |
| `get_ingredient` | SC1, SC2 | One ingredient at a site: units and factors, on hand by area, pars, the suppliers' items, allergens, yield, containers | **`ingredient`**, `site` | `{...mcp_ingredients, units: [mcp_ingredient_units], site_settings: mcp_ingredient_site_settings, cost: {avg_cost_per_base, last_cost_per_base, last_cost_at, on_hand_base, on_hand_value, cost_withheld}, by_area: [{storage_area_id, name, qty_base, qty_count}], supplier_items: [mcp_supplier_items + cost_per_base], containers, yield_tests (last 5), recipes_using_count}` | the views + `ri_cost_as_of()` | view |
| `ingredient_units` | SC1 | The units an ingredient is bought, counted and written in, with the conversions spelled out ("1 case = 6 × #10 = 18 390 g") | **`ingredient`** | `{ingredient_id, measure_class, base_unit, recipe_unit, density_g_per_ml, each_weight_g, each_volume_ml, units: [{unit_code, name, kind, to_base_factor, is_default_count, sentence}], global_units: [codes of its class]}` | `mcp_ingredient_units`, `mcp_units` | none |
| `ingredient_cost` | SC2 | What X costs today at a site on a basis, and on a date | **`ingredient`**, `site`, `as_of?` (date, default today), `basis?` (avg, last) | `{ingredient_id, site, as_of, basis, cost_per_base, cost_per_recipe_unit, cost_per_count_unit, source: override/last/avg, cost_withheld}` — `withheld` as a whole without the right | `ri_cost_as_of()`, `mcp_ingredient_site_costs` | fn |
| `cost_history` | SC2 | How an ingredient's cost moved at a site: every change with its source and the document | **`ingredient`**, `site`, `since?`, `limit`, `offset` | rows of `mcp_price_history` + `{per_recipe_unit_old, per_recipe_unit_new, pct}` (the view answers nothing to a caller without cost) | `mcp_price_history` | view |
| `cheapest_supplier` | SC7 | Which supplier is cheapest for X in a comparable unit — last or contract price per base unit, with pack and MOQ, lead time | **`ingredient`**, `site`, `limit` | rows `{supplier_item_id, supplier_id, supplier_name, supplier_sku, purchase_unit_code, pack, last_price, contract_price, cost_per_base, cost_per_recipe_unit, moq, lead_time_days, next_delivery_on, cost_withheld}` ordered by cost (ties by lead time) | `mcp_supplier_items`, `ri_supplier_item_cost_per_base()`, `ri_next_order_windows()` | view |
| `stock_on_hand` | SC1 | What we have of X at a site, by area, in the count unit and the base unit; or every ingredient under a par | `ingredient?`, `area?`, `category?`, `below_par?`, `site`, `limit`, `offset` | rows `{ingredient_id, code, name, storage_area_id, area_name, qty_base, qty_count, count_unit, qty_recipe, recipe_unit, par_base, below_par}` | `mcp_inventory_balances`, `mcp_ingredient_site_settings` | none |
| `stock_by_area` | SC1 | A storage area's shelf: every ingredient with a balance there, in walk and shelf order | **`area`**, `site`, `limit`, `offset` | rows as `stock_on_hand` ordered by the area's sheet order when a sheet names it | `mcp_inventory_balances`, `mcp_count_sheets` | none |
| `stock_movements` | SC1, U5 | The ledger: what moved X, where, when, by which document; or a document's movements | `ingredient?`, `area?`, `txn_type?[]`, `reference_kind?`, `reference_id?`, `from?`, `to?`, `site`, `limit`, `offset` | rows of `mcp_inventory_transactions` + `{qty_count, count_unit, document_number, reason, actor_name}` | `mcp_inventory_transactions` | view |
| `stock_value` | SC3 | What the stock is worth at a site as of a date, by category and in all | `site`, `as_of?`, `by?` (category, ingredient) | `{site, as_of, total, by: [{category_id, key, name, ingredients, on_hand_value}]}` — `withheld` as a whole without the right | `ri_stock_value()` | fn |
| `days_on_hand` | SC4 | Days on hand of X — on hand over the last 28 days' usage — and the slowest and fastest | `ingredient?`, `category?`, `days?` (28), `under?` (days), `site`, `limit`, `offset` | rows `{ingredient_id, code, name, on_hand_base, qty_count, count_unit, daily_usage_base, days_on_hand, value, cost_withheld}` | `ri_days_on_hand()` | fn |
| `price_movers` | SC5 | Which costs moved this week at a site, by how much, on which document | `days?` (7), `min_pct?`, `site`, `limit`, `offset` | rows of `ri_price_movers()` + `{code, name, per_recipe_unit_old, per_recipe_unit_new, document_number}` | `ri_price_movers()` | fn |
| `price_exceptions` | SC5 | The open price alerts at a site: cost moved over the threshold, contract vs invoice drift | `seen?` (default false), `since?`, `site`, `limit`, `offset` | rows of `mcp_price_alerts` + `{code, name, supplier_name, document_number}` | `mcp_price_alerts` | view |

### Recipes, the menu and costing (R1–R7)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `find_recipes` | R1 | Recipes by words, kind, category, status; `q` alone resolves one (the registry's `recipe`, `sub_recipe`) | `q?`, `kind?` (menu_item, prep, modifier), `category?`, `active?` (true), `site`, `limit`, `offset` | rows of `mcp_recipes` + `{portion_cost, food_cost_pct, menu_price, cost_withheld, label}` at `site` today | `mcp_recipes`, `ri_portion_cost_cached()`, `ri_menu_price()` | fn |
| `get_recipe` | R1, R7 | One recipe: the version in force (or on a date), its lines with each line's share of the cost, the tree, batch yield and loss, the method, allergens, the menu items mapped, where it is a sub-recipe; **`markdown`** | **`recipe`**, `as_of?` (date), `version?`, `site` | `{...mcp_recipes, version: mcp_recipe_versions, lines: [mcp_recipe_lines + {ingredient_name|sub_recipe_name, qty_recipe, recipe_unit, line_cost, share_pct}], tree (nested to depth 12), batch_cost, portion_cost, menu_items: [{menu_item_id, plu, name, price}], used_in: [{recipe_id, name}], versions: [mcp_recipe_versions], cost_withheld, markdown}` | the views, `ri_recipe_version_on()`, `ri_recipe_cost()` | fn |
| `recipe_tree` | R2 | The explosion: what one batch (or portion) of a recipe uses of every leaf ingredient, through sub-recipes with yields; with or without the prep made from stock | **`recipe`**, `as_of?`, `explode_prep?` (false), `portions?` (1), `site` | `{recipe_id, version_id, portions, leaves: [{ingredient_id, code, name, qty_base, qty_count, count_unit, qty_recipe, recipe_unit, cost_per_base, line_cost, cost_withheld}], total_cost}` | `ri_explode()`, `ri_cost_as_of()` | fn |
| `recipe_cost` | R1 | The plate cost today or on a date at a site, on a basis, food-cost % against the menu price; **`markdown`** | **`recipe`**, `as_of?`, `basis?`, `site` | `{recipe_id, version_id, site, as_of, basis, batch_cost, portion_cost, batch_yield_qty, lines: [...], uncosted, menu_price, food_cost_pct, markdown}` — `withheld` as a whole without the right | `ri_recipe_cost()`, `ri_menu_price()` | fn |
| `recipe_what_if` | R4 | What changes if X goes to a price: the plate cost and food-cost % of every recipe using it, before and after | **`ingredient`**, **`price`**, **`unit`** (the price's unit), `site`, `limit` | rows `{recipe_id, name, portion_cost_before, portion_cost_after, delta, menu_price, food_cost_pct_before, food_cost_pct_after}` | **owed** `ri_recipe_what_if()`; until then `ri_explode()` per recipe × the price difference, composed | fn |
| `recipes_using` | R2 | Which recipes use X, how much per portion, and how much of a day's demand for X each is | **`ingredient`**, `site`, `days?` (7), `limit`, `offset` | rows `{recipe_id, name, kind, qty_base_per_portion, qty_recipe, recipe_unit, demand_share_pct}` | **owed** `ri_recipes_using()`; until then `mcp_recipe_lines` ∪ the explosion of every active version, composed | none |
| `recipe_version_at` | R7 | Which version was in force on a date, and what changed from the one before | **`recipe`**, **`on`** | `{recipe_id, version: mcp_recipe_versions, previous: mcp_recipe_versions, lines_added, lines_removed, lines_changed: [{ingredient_name, from_qty, to_qty, unit}]}` | `ri_recipe_version_on()`, `mcp_recipe_lines` | none |
| `recipe_allergens` | R6 | The allergens of a plate, rolled up, and which line brings each | **`recipe`**, `as_of?` | `{recipe_id, allergens: [{allergen, from: [{ingredient_id, name, via_sub_recipe}]}]}` | `ri_recipe_allergens()`, `ri_explode()` | none |
| `menu_engineering` | R5 | Stars, plowhorses, puzzles and dogs for a window at a site, by category, with CM and mix | **`from`**, **`to`**, `category?`, `basis?`, `site`, `limit`, `offset` | rows of `ri_menu_engineering()` + `{plu, name, category_name}` | `ri_menu_engineering()` | fn |
| `find_menu_items` | R3, SD2 | Menu items by PLU, name, source, category, mapped or not; `q` alone resolves one (the registry's `menu_item`) | `q?`, `source?`, `category?`, `unmapped?`, `active?` (true), `site`, `limit`, `offset` | rows of `mcp_menu_items` + `{recipe_name, price_at_site, last_28d_qty, label}` | `mcp_menu_items`, `ri_menu_price()`, `mcp_sales_daily` | none |
| `get_menu_item` | R3 | One PLU: the recipe and portions, prices per site, its modifiers and their mapping, sales by week, plate cost and food-cost % | **`menu_item`**, `site` | `{...mcp_menu_items, prices: [mcp_menu_item_prices], modifiers: [mcp_modifiers + menu_item_modifiers override], sales_by_week: [{week, qty, net}], portion_cost, food_cost_pct, cost_withheld}` | the views, `ri_portion_cost_cached()` | fn |
| `unmapped_plus` | SD2 | Which PLUs sold with no recipe — the queue, most sold first | `days?` (28), `source?`, `site`, `limit`, `offset` | rows of `ri_unmapped_plus()` | `ri_unmapped_plus()` | none |
| `find_modifiers` | R3 | The POS modifiers by source, action, mapped or not; `q` alone resolves one (the registry's `modifier`) | `q?`, `source?`, `action?`, `unmapped?`, `site`, `limit`, `offset` | rows of `mcp_modifiers` + `{recipe_name, label}` | `mcp_modifiers` | none |

### Sales and depletion (SD1–SD4)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `sales_by_day` | SD1 | What sold on a day or over days at a site: checks, covers, net, comps, voids, by day part | **`from`**, `to?` (= from), `day_part?`, `site`, `limit`, `offset` | rows `{business_date, day_part_id, day_part, checks, covers, net, comps, voids, depletion_status}` | `mcp_pos_sales`, `mcp_depletion_runs` | none |
| `sales_by_item` | SD1 | Sales by menu item (and day part) for a window: quantity, net, comps; or one item by day | **`from`**, **`to`**, `menu_item?`, `category?`, `day_part?`, `by?` (item, day), `site`, `limit`, `offset` | rows `{menu_item_id, plu, name, category_name, business_date?, day_part?, qty, net, comps_qty}` | `mcp_sales_daily` (**owed** `ri_sales_by_item()` for the category roll-up) | none |
| `depletion_runs` | SD3 | Did the days run — the runs by date and status, and the days due | `from?`, `to?`, `status?[]`, `due_only?`, `site`, `limit`, `offset` | rows of `mcp_depletion_runs`; with `due_only` the rows of `ri_depletion_due()` | `mcp_depletion_runs`, `ri_depletion_due()` | view |
| `get_depletion_run` | SD3, SD4 | One run: the counts, and what the day used of every ingredient | **`run`** (or `business_date` + `site`), `business_date?`, `site` | `{...mcp_depletion_runs, by_ingredient: [{ingredient_id, code, name, qty_base, qty_count, count_unit, value, cost_withheld}], unmapped: [ri_unmapped_plus rows sold that day]}` | `mcp_depletion_runs`, `mcp_inventory_transactions`, `ri_unmapped_plus()` | view |
| `pos_health` | H1 | Which sources failed, which days had no sales where the site was open, PLUs and modifiers unmapped, runs pending | `site`, `limit` | rows of `ri_pos_health()` | `ri_pos_health()` | none |
| `find_sources` | H1 | The POS sources of a site; `q` alone resolves one (the registry's `source`) | `q?`, `connector?`, `active?`, `site`, `limit`, `offset` | rows of `mcp_sources` (settings for an admin) + `{credential_label, credential_last4, label}` | `mcp_sources`, `mcp_source_credentials` | none |
| `get_source` | H1 | One source: settings, the credential's label and last four, the last pulls, the days imported, the PLUs it brought | **`source`**, `site` | `{...mcp_sources, credential: {kind, label, last4, rotated_at}?, pulls: [last 10 of mcp_source_pulls], days: [{business_date, checks}], unmapped_count}` — never a secret | the views | none |
| `source_pulls` | H1 | A source's pulls with counts and errors | **`source`**, `status?[]`, `since?`, `site`, `limit`, `offset` | rows of `mcp_source_pulls` | `mcp_source_pulls` | none |

### Usage, variance, periods (U1–U6)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `usage_report` | U1, U2 | **Actual vs theoretical** per ingredient for a window at a site with every bucket — opening, purchases, transfers, production, adjustments, closing, actual, theoretical, variance, waste, comps, unexplained — in units and at cost; sorted by unexplained value; **`markdown`** | **`from`**, **`to`**, `category?`, `ingredient?`, `basis?`, `sort?` (unexplained_value, variance_value, actual_value, name), `site`, `limit`, `offset` | rows of `ri_usage()` + `{code, name, category_name, qty_count fields, cost_withheld}`; `summary {rows, actual_value, theoretical_value, variance_value, waste_value, unexplained_value, food_sales, actual_pct, theoretical_pct}`; `markdown` | `ri_usage()`, `ri_food_sales()` | fn |
| `usage_by_category` | U1 | The same by category | **`from`**, **`to`**, `basis?`, `site` | rows of `ri_usage_by_category()` + `{key, name, kind}` and the totals | `ri_usage_by_category()` | fn |
| `variance_top` | U3 | Where we lost the most money last period: the ingredients with the largest unexplained variance at cost, and what explains the rest | `period?` (default the last closed), `from?`, `to?`, `n?` (10), `site` | rows `{ingredient_id, code, name, unexplained_value, unexplained_base, variance_value, waste_value, comps_base, recipes: [top 3 consumers]}` | `ri_usage()` ordered (**owed** `ri_variance_top()` to add the consumers in one query) | fn |
| `food_cost_pct` | U4 | The actual and the theoretical food-cost % of a period or window, by kind (food, beverage) | `period?`, `from?`, `to?`, `kind?` (food, beverage, all), `site` | `{from, to, sales, actual_cogs, theoretical_cogs, actual_pct, theoretical_pct, variance_pct, waste_value}` | `ri_usage_by_category()`, `ri_food_sales()` | fn |
| `periods` | U6 | The periods of a site by year: dates, status, the frozen figures | `fiscal_year?`, `status?`, `site`, `limit`, `offset` | rows of `mcp_periods` + `{food_cost_pct}` | `mcp_periods` | view |
| `get_period` | U6 | One period: its figures, the opening and closing counts, usage by category, the late rows that landed in it | **`period`**, `site` | `{...mcp_periods, by_category: [ri_usage_by_category rows], late_rows: [mcp_inventory_transactions where note ~ 'late:'], opening_count, closing_count}` | the views, `ri_usage_by_category()` | fn |

### Counts and waste (CW1–CW2)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `counts` | CW1 | The counts at a site by status and kind; `q` alone resolves one by number (the registry's `count`) | `q?`, `status?[]`, `kind?`, `from?`, `to?`, `site`, `limit`, `offset` | rows of `mcp_inventory_counts` + `{lines, counted, skipped, over_tolerance, label}` | `mcp_inventory_counts`, `mcp_inventory_count_lines` | view |
| `get_count` | CW1 | One count: its header, progress by area, the lines over tolerance, the areas skipped | **`count`**, `site` | `{...mcp_inventory_counts, by_area: [{storage_area_id, name, lines, counted, zero, skipped, over_tolerance}], areas_skipped: [ri_count_areas_skipped rows]}` | the views, `ri_count_areas_skipped()` | view |
| `count_lines` | CW1 | A count's lines, one area at a time, in shelf order — the book figure hidden on a blind count unless the caller may approve | **`count`**, `area?`, `state?[]`, `site`, `limit`, `offset` | rows of `mcp_inventory_count_lines` + `{code, name, count_unit, counted_qty_count, system_qty_count}` | `mcp_inventory_count_lines` | view |
| `count_variance` | U5 | The last count's (or a count's) variance by line over tolerance, in units and at cost | `count?` (default the last approved), `over_only?`, `site`, `limit`, `offset` | rows of `ri_count_variance()` + `{code, name, area_name, cost_withheld}` | `ri_count_variance()` | fn |
| `areas_skipped` | U5 | Which areas a count skipped (they become "usage") | `count?` (default the last approved), `site` | rows of `ri_count_areas_skipped()` | `ri_count_areas_skipped()` | none |
| `count_sheets` | CW1 | The count sheets of a site and their lines in walk and shelf order | `sheet?`, `site`, `limit` | rows of `mcp_count_sheets` (lines nested) | `mcp_count_sheets` | none |
| `waste_logs` | CW2 | What was wasted: by day, stage, reason, station, item or plate, at cost | `from?`, `to?`, `stage?`, `reason?`, `station?`, `ingredient?`, `site`, `limit`, `offset` | rows of `mcp_waste_logs` + `{reason, ingredient_name|recipe_name, qty_count, count_unit}` | `mcp_waste_logs` | view |
| `waste_summary` | CW2 | The week's waste by stage, reason and disposition at cost | **`from`**, **`to`**, `site` | rows of `ri_share_waste_summary()` with cost nulled without the right | `ri_share_waste_summary()` (through `fn`) | fn |
| `production_batches` | SC1 | The prep batches made: recipe, batches, output, cost | `recipe?`, `status?`, `from?`, `to?`, `site`, `limit`, `offset` | rows of `mcp_production_batches` + `{recipe_name, output_name, output_qty_count}` | `mcp_production_batches` | view |
| `transfers_open` | SC1 | Transfers drafted or in transit from or to a site | `status?[]` (draft, in_transit), `site`, `limit`, `offset` | rows of `mcp_inventory_transfers` + `{from_site_name, to_site_name, from_area_name, to_area_name}` | `mcp_inventory_transfers` | none |
| `receipts_open` | P4 | Receipts in draft at a site, and deliveries due with no receipt | `on?` (today), `site`, `limit`, `offset` | `{drafts: [mcp_goods_receipts], due: [ri_deliveries_due rows]}` | `mcp_goods_receipts`, `ri_deliveries_due()` | view |

### Suppliers, purchasing, invoices (P1–P5, SU1)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `find_suppliers` | SU1 | Suppliers by words, kind, status; `q` alone resolves one (the registry's `supplier`) | `q?`, `kind?`, `active?`, `site`, `limit`, `offset` | rows of `mcp_suppliers` + `{next_cutoff_at, next_delivery_on, open_pos, label}` at `site` | `mcp_suppliers`, `ri_next_order_windows()` | view |
| `get_supplier` | SU1 | One supplier: schedules at the site, items with prices, the order guide, open POs, alerts | **`supplier`**, `site` | `{...mcp_suppliers, schedules: [mcp_supplier_schedules], windows: ri_next_order_windows, items: [mcp_supplier_items + cost_per_base], order_guide: mcp_order_guides, open_pos: [ri_purchase_orders_open rows], alerts: [mcp_price_alerts]}` | the views, the functions | view |
| `supplier_items` | SU1, SC7 | What a supplier sells us (or who sells X): SKU, unit, pack, prices, MOQ; `q` alone resolves one by SKU (the registry's `supplier_item`) | `q?`, `supplier?`, `ingredient?`, `active?`, `site`, `limit`, `offset` | rows of `mcp_supplier_items` + `{supplier_name, ingredient_name, cost_per_base, cost_per_recipe_unit, label}` | `mcp_supplier_items` | view |
| `supplier_schedules` | P1 | When a supplier takes orders and delivers at a site, and the next windows | `supplier?`, `site`, `limit` | rows of `mcp_supplier_schedules` + `{supplier_name}`; `windows` per supplier from `ri_next_order_windows()` | `mcp_supplier_schedules`, `ri_next_order_windows()` | none |
| `order_guides` | P1 | A supplier's order guide at a site in shelf order | `supplier?`, `site`, `limit` | rows of `mcp_order_guides` (lines nested with supplier item labels) | `mcp_order_guides`, `mcp_supplier_items` | none |
| `suggested_orders` | P1, F3 | What to order from every supplier due within N hours at a site — one row per supplier with its window and the lines' totals | `supplier?`, `due_hours?` (36), `site`, `limit` | rows `{supplier_id, supplier_name, cutoff_at, delivery_on, next_delivery_on, lines, lines_to_order, units, estimated_total, thin_history_lines, cost_withheld}` | `ri_suppliers_due()`, `ri_suggested_order()` | fn |
| `get_suggested_order` | P1, F3 | The suggested order for one supplier with the arithmetic on every line — need, have, on order, cap, safety, raw, rounding; **`markdown`** | **`supplier`**, `as_of?`, `site` | `{supplier_id, supplier_name, cutoff_at, delivery_on, next_delivery_on, protection_days, lines: [ri_suggested_order row + {code, name, qty_count fields}], estimated_total, cost_withheld, markdown}` | `ri_suggested_order()` | fn |
| `find_purchase_orders` | P2 | POs by status, supplier, origin, dates; `q` alone resolves one by number (the registry's `purchase_order`) | `q?`, `status?[]` (default open), `supplier?`, `origin?`, `from?`, `to?`, `site`, `limit`, `offset` | rows of `mcp_purchase_orders` + `{supplier_name, lines, label}` | `mcp_purchase_orders` | view |
| `purchase_orders_open` | P2 | What is on order and due when; unacknowledged past N days; overdue | `unacknowledged_days?`, `overdue?`, `supplier?`, `site`, `limit`, `offset` | rows of `ri_purchase_orders_open()` + `{supplier_name, cost_withheld}` | `ri_purchase_orders_open()` | fn |
| `get_purchase_order` | P2 | One PO: header, lines with the arithmetic when suggested, events, the link's state (never the token) | **`purchase_order`**, `site` | `{...mcp_purchase_orders, supplier_name, lines: [mcp_purchase_order_lines + {code, name}], events: [mcp_purchase_order_events], link_live: bool, receipts: [numbers]}` | the views | view |
| `purchase_order_events` | P2 | A PO's events: sent, placed, acknowledged, declined, dated, received | **`purchase_order`**, `site`, `limit`, `offset` | rows of `mcp_purchase_order_events` | `mcp_purchase_order_events` | none |
| `deliveries_due` | P4 | Which deliveries are due today (or by a date) with no receipt | `on?` (today), `site`, `limit` | rows of `ri_deliveries_due()` + `{supplier_name}` | `ri_deliveries_due()` | none |
| `find_invoices` | P5 | Supplier invoices by status, supplier, dates; `q` alone resolves one by our number or theirs | `q?`, `status?[]`, `supplier?`, `kind?`, `from?`, `to?`, `site`, `limit`, `offset` | rows of `mcp_supplier_invoices` + `{supplier_name, lines, mismatched_lines, label}` (the view answers nothing without cost) | `mcp_supplier_invoices` | view |
| `get_invoice` | P5 | One invoice with its lines and their match state | **`invoice`**, `site` | `{...mcp_supplier_invoices, lines: [mcp_supplier_invoice_lines + {receipt_number, ingredient_name}]}` | the views | view |
| `invoice_mismatches` | P5 | Which invoice lines do not match their receipt — quantity, price, both, unordered — across open invoices | `supplier?`, `since?`, `site`, `limit`, `offset` | rows of `mcp_supplier_invoice_lines` where `match_state` in (qty_diff, price_diff, both_diff, unordered) + `{invoice_no, supplier_name, ingredient_name}` | `mcp_supplier_invoice_lines` (**owed** `ri_invoice_mismatches()`) | view |

### Projection (F1–F4)
| Tool | Q | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|---|
| `item_forecast` | F1 | What we will sell of an item on the coming days (or every item on one day), how sure, from which method | `menu_item?`, `from?` (tomorrow), `days?` (7), `site`, `limit`, `offset` | rows `{menu_item_id, plu, name, on_date, qty, lo, hi, sigma, source: run/baseline/override, closed}` | `ri_item_forecast()`, `mcp_item_forecasts`, `mcp_calendar_days` | none |
| `ingredient_demand` | F2 | What we will use of X (or everything) over the coming days, exploded through the recipes | `ingredient?`, `category?`, **`from`**, **`to`** (≤ 92 days), `site`, `limit`, `offset` | rows of `ri_ingredient_demand()` + `{code, name, qty_count, count_unit}` and per-ingredient totals | `ri_ingredient_demand()` | none |
| `projected_stock` | F2 | When we run out of X at the current rate and on order — projected on hand per day; or every ingredient's stock-out day; **`markdown`** | `ingredient?`, `days?` (14), `stockouts_only?`, `site`, `limit`, `offset` | one ingredient: `{ingredient_id, on_hand_base, days: [ri_projected_stock rows + qty_count], stockout_on, markdown}`; else rows of `ri_stockouts_projected()` + `{code, name}` | `ri_projected_stock()`, `ri_stockout_on()`, `ri_stockouts_projected()` | none |
| `forecast_accuracy` | F4 | How accurate the forecast was by item: MAE, WAPE, bias over a window | `menu_item?`, `window_days?` (28), `worst_first?`, `site`, `limit`, `offset` | rows of `mcp_forecast_accuracy` + `{plu, name}` | `mcp_forecast_accuracy` | none |
| `can_run_special` | F3 | Can we run the special on a date for N portions with what is projected — the needs per ingredient against the projected stock that day, the shortfalls, the supplier that can bring them by then; **`markdown`** | **`recipe`**, **`on`**, **`portions`**, `site` | `{recipe_id, on, portions, lines: [{ingredient_id, code, name, needed_base, needed_count, count_unit, projected_base, shortfall_base, supplier_item_id?, supplier_name?, delivery_on?}], feasible, markdown}` | `ri_explode()` × portions against `ri_projected_stock()`, `ri_next_order_windows()` — composed in Python from the three answers | none |
| `calendar_days` | F1 | The site's trading calendar: closures, holidays, events, booked covers | `from?`, `to?`, `site`, `limit`, `offset` | rows of `mcp_calendar_days` left-joined `mcp_covers_expected` | the views | none |

### People, places, vocabulary, mine, agents (the registry's resolvers; views no question names)
| Tool | Call it when | Params | Returns | Reads | Wall |
|---|---|---|---|---|---|
| `find_sites` | The sites the caller holds; `q` resolves one (the registry's `site`, `to_site`) | `q?`, `limit` | rows of `mcp_sites` + `{roles, label}` | `mcp_sites`, `mcp_members.sites` | none |
| `find_storage_areas` | The areas of a site in walk order; `q` resolves one (the registry's `area`, `from_area`, `to_area`, `putaway_area`) | `q?`, `kind?`, `active?`, `site`, `limit` | rows of `mcp_storage_areas` + `{label}` | `mcp_storage_areas` | none |
| `find_members` | People and agents here; `q` resolves one (the registry's `member`, `agent`, `manager`) | `q?`, `kind?` (human, agent), `site?`, `limit`, `offset` | rows of `mcp_members` + `{label}` | `mcp_members` | none |
| `find_departments` | The departments (a dimension, never a wall) | `q?`, `limit`, `offset` | rows of `mcp_departments` | `mcp_departments` | none |
| `find_categories` | Ingredient categories (the registry's `category`) and menu categories (`menu_category`) | `q?`, `kind?` (ingredient, menu), `limit` | rows of `mcp_ingredient_categories` / `mcp_menu_categories` + `{label}` | the views | none |
| `units` | The global units by class with factors | `measure_class?` | rows of `mcp_units` | `mcp_units` | none |
| `reason_codes` | The reasons, by what they apply to | `applies_to?` | rows of `mcp_reason_codes` | `mcp_reason_codes` | none |
| `day_parts` | A site's day parts | `site` | rows of `mcp_day_parts` | `mcp_day_parts` | none |
| `settings` | The business settings a member may read | — | `mcp_settings` row | `mcp_settings` | none |
| `my_watches` | The caller's watches (every watch for an admin) at a site | `site`, `active?`, `limit`, `offset` | rows of `mcp_watches` + `{code, name, state_now}` | `mcp_watches`, `ri_watch_state()` | none |
| `watch_events` | When a watch fired and what was sent | `watch?`, `since?`, `site`, `limit`, `offset` | rows `{watch_id, fired_at, kind, ingredient_name, notified, texted, dispatched}` | **owed** `ri_watch_events()`; until then `mcp_notifications` where `kind = 'watch'` ∪ `mcp_agent_dispatches` where `watch_id` | none |
| `buyer_proposals` | The Purchasing agent's proposals by day, kind and status at a site | `date?`, `kind?`, `status?`, `site`, `limit`, `offset` | rows of `mcp_buyer_proposals` | `mcp_buyer_proposals` | none |
| `morning_note` | The morning note's seven headings for a site as data — the gatherers in one answer; **`markdown`** | `date?` (today), `site` | `{date, site, suggested_orders: [suggested_orders rows], stockouts: [ri_stockouts_projected], prices: [price_movers ≥ threshold + open alerts], pos: ri_pos_health row + unmapped count, count: {last approved + top variance 10}, forecast: {worst WAPE 5, closures ahead with no override}, purchase_orders: {unacknowledged, overdue, deliveries_due}, markdown}` | composed from the functions above (**owed** `ri_morning_note()` as one gatherer) | fn |
| `agents_here` | The agents granted a role here, their sites and roles, the duty's hour | `site?`, `limit` | rows of `mcp_members` where `is_agent` + `{sites, duty}` (**owed**: the duty's hour lives in the kernel — `agent_settings` is a Phase 2 table or a setting) | `mcp_members` | none |
| `agent_dispatches` | What was handed to an agent and what came back | `agent?`, `status?`, `kind?`, `since?`, `site`, `limit`, `offset` | rows of `mcp_agent_dispatches` | `mcp_agent_dispatches` | none |
| `my_notifications` | The caller's notifications, unread first | `unread?`, `limit`, `offset` | rows of `mcp_notifications` | `mcp_notifications` | none |
| `notes` / `attachments` | A record's notes and files | **`record_type`**, **`record_id`**, `limit`, `offset` | rows of `mcp_notes` / `mcp_attachments` | the views | none |
| `records_search` | The one search again, for the harness's generic "search" verb | **`q`**, `limit` | as `find` | `ri_find()` | none |

### The long tail (named once; built as thin wrappers over the views)
`get_count_sheet(sheet)`, `containers(ingredient?)`, `yield_tests(ingredient)`, `ingredient_site_settings(ingredient?, site)`,
`menu_item_prices(menu_item, site?)`, `sales_file_mappings()`, `calendars()`, `forecast_runs(site)`, `forecast_overrides(site, status?)`,
`covers_expected(site, from, to)`, `goods_receipt(receipt)` (+ lines), `transfer(transfer)`, `adjustment(adjustment)`, `production_batch(batch)`,
`waste_log(waste)`, `tax_rates()`, `document_sequences()` (sequences.manage), `my_tokens()` (**owed** a view `mcp_access_tokens_mine`).

## The shares — for the kernel's token (K7, design §8, `maludb-os.json` `shares[]`)

Each share is a records-server tool listed only to the kernel's token (`KERNEL_TOOLS`), answering the kernel's `scope_id` (the kernel sets
it from the consumer's `location_id` both applications serve — never the consumer's word), reading a `SECURITY DEFINER` function that
takes no caller into account (db/016's `ri_share_*`, granted to the read role — **the grant is owed**, below), paged `offset`/`limit` ≤
500, `timeout_seconds: 20`, under 256 kB. Every document carries `{"schema": "os.restaurant-inventory-<name>/1", "site": {scope_id, name,
timezone}, "generated_at", "rows": [...], "count", "offset", "limit", "truncated"}`.

| Tool | Document | Function | Args | Row | Consumer |
|---|---|---|---|---|---|
| `purchases_received` | `os.restaurant-inventory-purchases/1` | `ri_share_purchases_received(scope, from, to, offset, limit)` | **`from`**, **`to`**, `offset`, `limit` | `{receipt_number, received_on, supplier_id, supplier_name, invoice_ref, category_key, ingredient_code, ingredient_name, qty_base, base_unit, value}` — the GL's bills per supplier with `site_id`, `source = import` | the General Ledger (G5) |
| `stock_valuation` | `os.restaurant-inventory-valuation/1` | `ri_share_stock_valuation(scope, as_of)` | `as_of` (today) | `{category_key, category_name, kind, ingredients, value}` — the 1400 inventory adjustment at close | the General Ledger (G5) |
| `cogs_summary` | `os.restaurant-inventory-cogs/1` | `ri_share_cogs_summary(scope, period_id)` | **`period`** (id, or `period_no` + `fiscal_year`) | `{period_no, starts_on, ends_on, status, category_key, category_name, kind, opening_value, purchases_value, closing_value, actual_value, theoretical_value, waste_value, unexplained_value}` — 5000 COGS by site; an open period answers live figures with `status: open` | the General Ledger (G5) |
| `waste_summary` | `os.restaurant-inventory-waste/1` | `ri_share_waste_summary(scope, from, to)` | **`from`**, **`to`** | `{stage, reason, disposition, entries, cost}` | the GL, Spaces |
| `sales_by_day` | `os.restaurant-inventory-sales/1` | `ri_share_sales_by_day(scope, from, to)` | **`from`**, **`to`** | `{business_date, day_part, checks, covers, net, comps, voids}` — the daily sales journal; actual covers for txtSchedules (T2) | the GL (G5), txtSchedules (T2) |
| `ingredients_index` | `os.restaurant-inventory-ingredients/1` | **owed** `ri_share_ingredients_index(scope, q, offset, limit)` | `q?`, `offset`, `limit` | `{ingredient_id, code, name, category_key, measure_class, base_unit, recipe_unit, allergens, is_prep}` — names without quantities or cost | Spaces (S5), Help Desk (HD6) |

**The one read** (`reads[]`): `reservations.covers_by_service` — the worker's `covers_sync` pass asks the kernel
(`POST /api/v1/apps/read.php {app: reservations, tool: covers_by_service, location_id, args: {from, to}}`) and writes `covers_expected`
(`source = reservations`) through the site's `day_parts.service_name`; `no_connection` degrades to none, logged `forecast.covers_sync` with
`after.outcome`. Nothing here reads it live.

## Activity server: `restaurant_inventory_activity_mcp`

| Tool | Call it when | Params | Returns | Reads |
|---|---|---|---|---|
| `record_history` | Everything that happened to one record — a recipe, an ingredient, a PO, a count, a receipt — newest first, with who and the before/after | **`record_type`**, **`record_id`**, `since?`, `limit`, `offset` | rows of `mcp_activity_log` where `entity_type`/`entity_id` match, or the audit key does (`ingredient_id`, `recipe_id`, `purchase_order_id`, `count_id`) + the record's label from the names views | `mcp_activity_log` |
| `actor_timeline` | What a person or an agent did here, at a site, in a window | **`member`**, `site?`, `from?`, `to?`, `action?` (a prefix — `count.`), `limit`, `offset` | rows of `mcp_activity_log` where `actor_member_id` | `mcp_activity_log` |
| `site_activity` | The trail at a site by entity and action (an admin's and a Manager's view; the view walls the rest) | `site`, `entity_type?`, `action?`, `from?`, `to?`, `limit`, `offset` | rows of `mcp_activity_log` | `mcp_activity_log` |
| `screen_views` | Who opened which screens (a sysadmin's question) | `site?`, `member?`, `from?`, `to?`, `limit`, `offset` | rows where `action = 'screen.view'` | `mcp_activity_log` |

The activity role's names views (`mcp_members`, `mcp_sites`, `mcp_ingredients`, `mcp_recipes`, `mcp_suppliers`, `mcp_purchase_orders`,
`mcp_inventory_counts`, `mcp_storage_areas`, `mcp_departments`) put a label beside every row; nothing else is granted to it (db/017).
`mcp_activity_log` hides credential, settings and token rows from anyone but the actor and an admin.

## What the log must carry for these tools (the manifest's log-payload rules; design §6 "Activity memory")

Every `log_activity()` row: `scope_id` (the site — every event but `member.*`, `token.*`, `settings.save`, `unit.save`, `category.save`),
and the audit keys when the record has them: `ingredient_id`, `recipe_id`, `purchase_order_id`, `count_id`. **Never in a payload**: a
credential's secret or ciphertext, a secure token, a POS check's `raw`, the outbox body. Quantities in a payload are `{qty, unit, qty_base}`.

- `ingredient.create|update|archive|delete|unit_set|par_set|cost_override|yield_test`: `code`, `name`, `category_id`, `measure_class`, the fields
  changed by name with before/after; `unit_set`: `unit_code`, `to_base_factor`, `kind`; `par_set`: `scope_id`, `par_base`, `par_mode`, `service_level`;
  `cost_override`: `scope_id`, `old_per_base`, `new_per_base`, `reason`; `yield_test`: `yield_pct`, `applied`.
- `recipe.create|update|archive|delete|version_draft|version_update|line_set|line_remove|version_activate|version_retire|version_discard`: `code`, `name`,
  `kind`, `version_id`, `version_no`; a line: `line_id`, `ingredient_id` or `sub_recipe_id`, `qty`, `unit_code`, `qty_base`, `line_yield_pct`;
  `version_activate`: `effective_from`, `previous_version_id`, `portion_cost` (when the actor sees cost — else absent), `lines`.
- `menu_item.create|map|price_set|archive`, `modifier.map`, `menu_category.save`: `plu`, `name`, `recipe_id`, `portions`, `no_depletion`, `price`,
  `scope_id`, `effective_from`; `action`, `qty_factor`, `menu_item_id` (per parent).
- `supplier.create|update|archive|delete|schedule_set|schedule_remove|message`, `supplier_item.create|update|retire|price_change`, `order_guide.set`:
  `supplier_id`, `name`, `order_method`; a schedule: `order_dow`, `cutoff_time`, `delivery_dow`; an item: `supplier_item_id`, `supplier_sku`,
  `purchase_unit_code`, `last_price`, `contract_price`, `moq`; `message`: `purchase_order_id`, `subject` (never the body beyond 200 chars).
- `receipt.draft|update|line_add|line_remove|post|cancel|correct`: `number`, `supplier_id`, `purchase_order_id`, `storage_area_id`, `received_on`;
  a line: `ingredient_id`, `qty_received`, `purchase_unit_code`, `qty_base`, `weight_qty`, `weight_unit_code`, `cost_per_base` (walled by the
  actor's right), `discrepancy_kind`, `temperature_c`, `temperature_ok`; `post`: `lines`, `value` (walled), `transactions` (ids).
- `transfer.draft|update|line_set|line_remove|send|receive|cancel`: `number`, `scope_id`, `to_scope_id`, `from_area_id`, `to_area_id`, `lines`,
  `received: [{line_id, qty_received_base}]`. `adjustment.draft|line_set|line_remove|post|cancel`: `number`, `storage_area_id`, `reason_code_id`,
  `lines`. `production.post|cancel`: `number`, `recipe_id`, `recipe_version_id`, `batches`, `output_qty_base`, `consumed: [{ingredient_id, qty_base}]`,
  `cost_total` (walled). `waste.log|review|void`: `number`, `stage`, `reason_code_id`, `ingredient_id` or `recipe_id`, `qty`, `unit_code`, `qty_base`,
  `gross_weight_g`, `tare_g`, `station`, `disposition`, `cost` (walled). `stock.reverse`: `transaction_id`, `reversal_id`, `txn_type`, `qty_base`.
- `purchase_order.draft|suggest|update|line_set|line_remove|send|place|acknowledge|line_decline|close|cancel|link_rotate`: `number`, `supplier_id`,
  `origin`, `status`, `cutoff_at`, `expected_on`, `lines`, `total` (walled), `sent_via`, `supplier_order_ref`, `reason`; `suggest`: `lines: [{ingredient_id,
  need_base, have_base, on_order_base, cap_base, raw_base, qty_units, rounding_note}]`; `link_rotate`: `link_id` — never the token.
  `purchase_order.supplier_view|supplier_ack|supplier_decline|supplier_date` (source `door`, `member_id NULL`): `number`, `link_id`, `line_id`, `reason`,
  `expected_on`, `supplier_order_ref`.
- `invoice.create|update|line_set|line_remove|match|settle|void|credit`: `number`, `invoice_no`, `supplier_id`, `kind`, `goods_receipt_id`, `total`;
  a line: `invoice_line_id`, `supplier_item_id`, `qty`, `unit_code`, `unit_price`, `receipt_line_id`, `match_state`, `diff_qty`, `diff_value`;
  `settle`: `price_changes: [{ingredient_id, old_per_base, new_per_base}]`, `corrections: [transaction ids]`. `price_alert.raise|seen|threshold_set`:
  `alert_id`, `ingredient_id`, `pct`, `old_per_base`, `new_per_base`; `threshold_set`: `category_id`, `pct`.
- `source.create|update|credential_set|schedule_set|pause|pull_start|pull_done|pull_fail|delete`: `source_id`, `name`, `connector`, `settings`
  **minus any secret-bearing key**, `credential_id`, `kind`, `label`, `last4` (never the secret), `schedule_minutes`, `pull_id`, `kind`, `status`,
  `dates_seen`, `sales_new`, `plus_new`, `error`. `sale.import`: `pull_id`, `source_id`, `file_name`, `dates_seen`, `sales_seen`, `sales_new`,
  `lines_seen`, `plus_new`. `depletion.run|void`: `run_id`, `business_date`, `run_no`, `status`, `lines_total`, `lines_depleted`, `lines_unmapped`,
  `lines_voided`, `modifiers_unmapped`, `ingredients`, `value` (walled), `reversed` (count). `plu.map|queue`: `menu_item_id`, `plu`, `recipe_id`.
- `count_sheet.save|line_set|line_remove|reorder`, `container.save`: `sheet_id`, `name`, `kind`, `lines` (count), `storage_area_id`, `ingredient_id`,
  `shelf_order`, `count_unit_code`; `container_id`, `full_weight_g`, `empty_weight_g`, `volume_ml`.
- `count.start|line_set|line_add|review|recount|approve|discard`: `number`, `sheet_id`, `kind`, `as_of`, `blind`; a line: `count_line_id`,
  `ingredient_id`, `storage_area_id`, `entries`, `gross_weight_g`, `container_id`, `counted_qty_base`, `state` — **never `system_qty_base` on a blind
  count's `line_set`** (the counter must not learn it from the trail); `review`: `lines`, `counted`, `skipped`, `over_tolerance`,
  `total_variance_value` (walled); `recount`: `recount_qty_base`, `recount_by`; `approve`: `corrections: [transaction ids]`, `force`,
  `total_variance_value` (walled); `discard`: `reason`.
- `period.ensure|close|reopen`, `calendar.set`: `period_id`, `no`, `fiscal_year`, `starts_on`, `ends_on`, `closing_count_id`, `opening_value`,
  `purchases_value`, `ending_value`, `cogs_value`, `theoretical_value`, `waste_value`, `food_sales` (the values walled); `reopen`: `reason`;
  `calendar.set`: `calendar_id`, `kind`, `year_start_on`, `is_default`.
- `forecast.run|override|override_propose|override_decide|override_remove|covers_sync|accuracy`, `calendar.day_set`: `forecast_run_id`, `method`,
  `horizon_days`, `history_days`, `items`; `override_id`, `menu_item_id`, `from_date`, `to_date`, `factor`, `qty`, `reason`, `status`, `decision`;
  `covers_sync`: `from`, `to`, `rows`, `outcome` (ok, no_connection, error); `accuracy`: `window_days`, `items`; `day_set`: `on_date`, `closed`,
  `holiday`, `event`.
- `watch.set|clear|fire`: `watch_id`, `kind`, `ingredient_id`, `threshold`, `text_me`, `agent_member_id`; `fire`: `state`, `notified`, `texted`
  (bool), `dispatched` (dispatch id). `buyer.propose|accept|dismiss|decide|note`: `proposal_id`, `kind`, `subject_type`, `subject_id`, `title`,
  `drafted_record_type`, `drafted_record_id`, `decision`; `note`: `date`, `scope_id`, `headings` (counts per heading), `sent_to` (member ids),
  `spaces_channel`.
- `report.run`, `export.download`, `share.read` (a sibling read us — `consumer`, `tool`, `scope_id`, `args` without values over 200 chars, `rows`),
  `settings.save|site_save|day_part_save|sequence_set|tax_rate_save|reason_save`, `agent.settings_save`, `token.mint|revoke`, `member.sign_on|
  site_switch|preferences_save|notification_read`, `directory.sync`, `screen.view`: the fields changed by name; `token.*` never the token.

## Entity resolution for the action tools (the registry's `resolve` block — `deploy/kernel-registry-restaurant-inventory.json`)

Each tool, called with `q` alone (digits = an id, otherwise exact on a code / number / PLU / SKU, else `ILIKE` or trigram, at most 6
candidates), answers its normal envelope — `{"ok": true, "count", "rows": [...]}` — and the kernel reads `rows`
(`application_actions.resolve_via_mcp` accepts `rows`, `candidates`, `results`, `items`). Every resolver is **people-free and cheap**: one
view, one statement, no function that costs a recipe (`find_recipes` with `q` alone skips `ri_portion_cost_cached` — DECISION 3). Every row
carries a `label`. The placeholder wrapper written in Phase 0 is corrected as follows (Phase 4 rewrites the JSON):

| Entity | Param(s) | Tool (with `q`) | id field | label field | Was in the wrapper |
|---|---|---|---|---|---|
| `ingredient` | `ingredient`, `output_ingredient` | `find_ingredients` | `ingredient_id` | `label` ("CHKBR — Chicken breast, Protein, g") | ✓ |
| `recipe` | `recipe`, `sub_recipe`, `parent_recipe` | `find_recipes` | `recipe_id` | `label` ("R-SAND — Chicken sandwich, menu item") | ✓ (+ `sub_recipe`, `parent_recipe`) |
| `menu_item` | `menu_item` | `find_menu_items` | `menu_item_id` | `label` ("1001 — Chicken Sandwich, mapped") | ✓ |
| `modifier` | `modifier` | `find_modifiers` | `modifier_id` | `label` ("XCHK — Extra chicken, add") | ✓ |
| `supplier` | `supplier` | `find_suppliers` | `supplier_id` | `label` (the name) | ✓ |
| `supplier_item` | `supplier_item` | `supplier_items` | `supplier_item_id` | `label` ("SY-FL50 — Flour, AP, 50 lb bag, Sysco") | **added** |
| `storage_area` | `area`, `from_area`, `to_area`, `putaway_area`, `consume_area`, `default_area`, `depletion_area` | `find_storage_areas` | `storage_area_id` | `label` ("Walk-in — Downtown") | ✓ (+ `consume_area`, `default_area`, `depletion_area`) |
| `source` | `source` | `find_sources` | `source_id` | `label` | ✓ |
| `site` | `site`, `to_site`, `commissary_site` | `find_sites` | `scope_id` | `label` (the name) | ✓ (`find_sites`) |
| `member` | `member`, `manager` | `find_members` | `member_id` | `label` (display name, kind) | ✓ |
| `agent` | `agent` | `find_members` (`kind = agent`) | `member_id` | `label` | ✓ |
| `department` | `department` | `find_departments` | `department_id` | `name` | ✓ |
| `category` | `category` | `find_categories` (`kind = ingredient`) | `category_id` | `label` | **added** |
| `menu_category` | `menu_category` | `find_categories` (`kind = menu`) | `category_id` | `label` | **added** |
| `purchase_order` | `purchase_order` | `find_purchase_orders` | `purchase_order_id` | `label` ("PO-000077 — Sysco, suggested, draft") | ✓ |
| `count` | `count` | `counts` | `count_id` | `label` ("CNT-000012 — full, 2026-10-11, review") | **added** |
| `invoice` | `invoice` | `find_invoices` | `invoice_id` | `label` ("INV-5501 — Sysco, disputed") | **added** |
| `period` | `period` | `periods` | `period_id` | `label` ("Week 41 — 2026-10-06 → 2026-10-12, open") | **added** |
| `day_part` | `day_part` | `day_parts` | `day_part_id` | `name` | **added** |
| `reason` | `reason` | `reason_codes` | `reason_code_id` | `name` | **added** |

Everything else a param names (`version`, `line`, `receipt`, `receipt_line`, `transfer`, `adjustment`, `batch`, `waste`, `invoice_line`,
`count_line`, `sheet`, `sheet_line`, `container`, `calendar`, `run`, `forecast_run`, `override`, `watch`, `proposal`, `alert`, `credential`,
`pull`, `yield_test`, `schedule`, `guide`, `unit`, `note`, `attachment`, `transaction`, `token`, `dispatch`, `mapping`) is an id or a document
number the handler reads directly; no resolver.

## Coverage of the question inventory (design §7 → tools)

| §7 | Question | Tool(s) |
|---|---|---|
| SC1 | what we have of X, by area, in its units | `stock_on_hand`, `stock_by_area`, `get_ingredient`, `find_ingredients` |
| SC2 | what X costs today, on which basis, on a date | `ingredient_cost`, `cost_history`, `get_ingredient` |
| SC3 | stock worth as of a date by category and site | `stock_value`, `stock_valuation` (the share) |
| SC4 | days on hand | `days_on_hand` |
| SC5 | which costs moved, on which invoice | `price_movers`, `price_exceptions`, `cost_history` |
| SC7 | the cheapest supplier in a comparable unit | `cheapest_supplier`, `supplier_items` |
| R1 | the plate cost today and last period; food-cost % | `recipe_cost`, `get_recipe`, `find_recipes`, `food_cost_pct` |
| R2 | which recipes use X and how much | `recipes_using`, `recipe_tree` |
| R3 | the menu map, modifiers | `find_menu_items`, `get_menu_item`, `find_modifiers`, `unmapped_plus` |
| R4 | what changes if romaine goes to $X | `recipe_what_if` |
| R5 | stars, plowhorses, puzzles, dogs | `menu_engineering` |
| R6 | the allergens of a plate | `recipe_allergens` |
| R7 | which version was in force on a date | `recipe_version_at`, `get_recipe(as_of)` |
| SD1 | what sold yesterday by item and day part | `sales_by_day`, `sales_by_item` |
| SD2 | PLUs with no recipe | `unmapped_plus`, `find_menu_items(unmapped)` |
| SD3 | did yesterday's depletion run | `depletion_runs`, `get_depletion_run`, `pos_health` |
| SD4 | what yesterday used of X | `get_depletion_run`, `stock_movements(txn_type depletion)` |
| U1–U2 | actual vs theoretical with every bucket, by category, by site | `usage_report`, `usage_by_category` |
| U3 | where we lost the most money | `variance_top` |
| U4 | actual and theoretical food-cost % | `food_cost_pct` |
| U5 | the last count's variance over tolerance; areas skipped | `count_variance`, `areas_skipped`, `stock_movements` |
| U6 | periods open past their end | `periods`, `get_period` |
| P1 | what to order from S with the arithmetic | `suggested_orders`, `get_suggested_order`, `supplier_schedules`, `order_guides` |
| P2 | on order and due when; unacknowledged | `purchase_orders_open`, `find_purchase_orders`, `get_purchase_order`, `purchase_order_events` |
| P4 | deliveries due with no receipt | `deliveries_due`, `receipts_open` |
| P5 | invoice lines that do not match | `invoice_mismatches`, `find_invoices`, `get_invoice` |
| F1 | what we will sell of M next week, how sure | `item_forecast`, `forecast_accuracy`, `calendar_days` |
| F2 | what we will use of X and when it runs out | `ingredient_demand`, `projected_stock` |
| F3 | can we run the special | `can_run_special`, `get_suggested_order` |
| F4 | how accurate last month's forecast was | `forecast_accuracy` |
| CW1 | what is open on the count | `counts`, `get_count`, `count_lines`, `count_sheets` |
| CW2 | what was wasted, by stage, reason, station, at cost | `waste_logs`, `waste_summary` |
| SU1 | what we buy from S, at what price, how it moved | `get_supplier`, `supplier_items`, `cost_history` |
| H1 | sources failed, days with no sales, periods open | `pos_health`, `find_sources`, `get_source`, `source_pulls`, `periods` |
| ACT | a record's history; what a person or agent did | `record_history`, `actor_timeline`, `site_activity` |

## Decisions taken where the design or the schema is silent

1. **`site` on every tool, defaulted from the run facts.** The design says every row carries the site; an agent on a chat turn at Downtown
   should not have to say "Downtown". The run facts' `scope` (the kernel's K38 answer carries it for a scoped application) is the default;
   a person's own token defaults to their session's site when one is in the mirror's `member_sessions`, else asks.
2. **The `fn` wall is one database question per call.** The read functions answer cost unnulled (they are the screens' and the shares'); the
   tool asks `SELECT ri_sees_cost($site)` once and nulls in Python — never a cached or inferred answer. The views null themselves.
3. **A resolver never costs a recipe.** `find_recipes` with `q` alone (the registry's call) skips the cost columns; the same tool with filters
   and no `q` fills them.
4. **Three quantities on every row.** Base, count and recipe units — the design's "says what it converted from"; `ri_from_base()` is
   `STABLE` and cheap, and the count unit is the ingredient's default count unit (else the base).
5. **`can_run_special` is composed in Python.** The explosion × portions, the projected stock on the date, and the next windows of the
   suppliers whose items cover the shortfalls — three SQL answers joined by ingredient id; no rule lives in Python (the shortfall is a
   subtraction).
6. **`morning_note` is one tool for the duty.** The Purchasing agent's runbook calls it once and writes its proposals from the headings;
   the gatherers stay callable one by one for the command bar.
7. **A share answers an open period live.** `cogs_summary` on an open period returns the live `ri_usage_by_category` with `status: open`,
   so the GL can preview; it posts only closed ones (its rule, not ours).
8. **No bridge, no `source_pull` tool here.** A pull is an action (`source_pull`); the records server never reaches a POS.

## Owed to the schema (db/ not modified here — the lead adds an additive migration, db/018)

1. **The read functions must be callable by the read role without opening the base tables.** db/013, db/014 and db/016's read functions are
   `SECURITY INVOKER` and read `inventory_transactions`, `sales_daily`, `pos_sales`, `price_history`, … directly; `restaurant_inventory_records_ro`
   has `SELECT` on the `mcp_*` views only (db/017), so every `fn` tool above would fail with `permission denied` today. db/018 rewrites them
   `SECURITY DEFINER SET search_path = public` with a first line `PERFORM ri_require_reader(p_scope_id)` — a new function that raises
   `insufficient_privilege` unless `ri_is_member_here()` and `ri_holds_scope(p_scope_id)` — and `REVOKE ALL … FROM PUBLIC; GRANT EXECUTE … TO
   restaurant_inventory_records_ro, restaurant_inventory_rw`: `ri_usage`, `ri_usage_by_category`, `ri_food_sales`, `ri_stock_value`, `ri_days_on_hand`,
   `ri_price_movers`, `ri_pos_health`, `ri_unmapped_plus`, `ri_count_variance`, `ri_count_areas_skipped`, `ri_purchase_orders_open`, `ri_deliveries_due`,
   `ri_stockouts_projected`, `ri_item_forecast`, `ri_forecast_baseline`, `ri_covers_factor`, `ri_ingredient_demand`, `ri_projected_stock`, `ri_stockout_on`,
   `ri_suggested_order`, `ri_suppliers_due`, `ri_next_order_windows`, `ri_recipe_cost`, `ri_portion_cost_cached` (it writes the cache — DEFINER already
   needed), `ri_explode`, `ri_recipe_version_on`, `ri_recipe_allergens`, `ri_menu_engineering`, `ri_menu_price`, `ri_on_hand`, `ri_on_hand_as_of`,
   `ri_on_order`, `ri_cost_as_of`, `ri_to_base`, `ri_from_base`, `ri_supplier_item_cost_per_base`, `ri_container_remaining_base`, `ri_depletion_due`,
   `ri_find`, `ri_watch_state`. Cost stays unnulled in them (the `fn` wall is the tool's question); **the proof adds a check per function as
   the read role.**
2. **The shares' grant and the people-free path.** `ri_share_*` are `SECURITY INVOKER` too and the kernel's token has no member: db/018 makes
   the six `SECURITY DEFINER`, granted to the read role alone, reachable by **no view and no other tool** (`SEARCH_DENY`), and adds
   `ri_share_ingredients_index(p_scope_id, p_q text, p_offset, p_limit)`.
3. `ri_recipe_what_if(p_ingredient_id bigint, p_new_cost_per_base numeric, p_scope_id bigint, p_as_of timestamptz) RETURNS TABLE (recipe_id, version_id,
   portion_cost_before, portion_cost_after, delta, menu_price, food_cost_pct_before, food_cost_pct_after)` — every active menu-item recipe whose
   explosion reaches the ingredient (through preps), costed twice.
4. `ri_recipes_using(p_ingredient_id bigint, p_scope_id bigint, p_days integer) RETURNS TABLE (recipe_id, kind, qty_base_per_portion, demand_share_pct)` —
   the explosion inverted, with the last N days' `sales_daily` for the share.
5. `ri_sales_by_item(p_scope_id, p_from, p_to, p_by text) RETURNS TABLE (menu_item_id, category_id, business_date, day_part_id, qty, net, comps_qty)` — the
   category roll-up of `sales_daily`.
6. `ri_variance_top(p_scope_id, p_from, p_to, p_n integer) RETURNS TABLE (ingredient_id, unexplained_value, unexplained_base, variance_value, waste_value,
   comps_base, consumers jsonb)` — `ri_usage` ordered with the top three consuming recipes per row.
7. `ri_invoice_mismatches(p_scope_id, p_supplier_id, p_since date) RETURNS SETOF supplier_invoice_lines` joined to the invoice and the receipt.
8. `ri_watch_events(p_scope_id, p_watch_id, p_since) RETURNS TABLE (watch_id, fired_at, kind, ingredient_id, notified, texted, dispatched)` — today a
   watch keeps only `fired_at` and `fire_count`; a `watch_events` table (one row per firing) is the honest shape and is proposed for db/018.
9. `ri_morning_note(p_scope_id, p_date) RETURNS jsonb` — the seven headings in one call, so the duty's runbook and the home screen never disagree.
10. `mcp_access_tokens_mine` (a view: the caller's own tokens, label, last four, last used, never the hash) for `my_tokens`.
11. `agent_settings(agent_member_id, scope_id, duty_hour time, enabled)` — the manifest's `agent_settings_save` has no table; the duty's hour per
    site is this application's to keep until the kernel's K48 (a per-site duty time zone) lands.
12. `ri_find` admits `kinds[]` (today it searches all five kinds; the `find` tool filters in Python until then — acceptable, listed for honesty).

## Size

Records server: **83 tools the expert is granted** (every name in `maludb-os.json` has a row above) + `app_roles` + the six shares + `find_sites`,
`find_categories`, `units`, `reason_codes`, `day_parts`, `settings`, `my_notifications`, `notes`, `attachments` and the long tail's 18 = **~115 tools**,
of which 7 carry `markdown`. Activity server: **4 tools** (`record_history`, `actor_timeline` granted; `site_activity`, `screen_views` for people).
Walls: 31 `view`, 24 `fn`, the rest `none`. Owed to db/018: twelve items, the first two blocking Phase 4.

## Open questions

- The buyer's grant names `get_recipe` and `get_menu_item` but not `recipe_cost`: the morning note's "variance by recipe" line will use
  `get_recipe`'s `portion_cost` — fine, since the agent holds Chef and sees cost; noted so Phase 4 does not add a grant by reflex.
- `records_search` and `find` are the same function; the harness's generic verb keeps both names.
- Whether `sales_by_item` should answer by day part for a window longer than 31 days (row count): the tool caps `by = day` at 92 days.
