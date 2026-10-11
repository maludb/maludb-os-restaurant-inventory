# Restaurant Inventory — the Phase 0 design

> Written 2026-10-11 by the planning session from the three research reports in `docs/research/` (the field, the
> mechanics, the estate) and their synthesis `docs/research/00-summary.md`. The owner's decisions are §13 (recommendation
> first); their answers go in §15; §16 is the record of what was built. Nothing is built yet.

**In one paragraph.** Restaurant Inventory is the kitchen's and the bar's stock desk as an application of the MaluDB
Business OS: what the restaurant buys, in the units it buys, counts and cooks it in; the recipes that turn it into what
the POS sells, with their yields and sub-recipes, so every plate has a cost as of any date; the purchase orders and the
receiving that bring stock in at a known cost; the POS sales that deplete it through the recipes; the waste, transfers
and production that move it; the physical counts that correct it; and, built around the two goals the owner named,
**the projection** — expected sales exploded through recipes into expected usage, into projected stock per ingredient per
day, into a suggested order per supplier for its next delivery — and **the costing** — plate cost, food-cost %, actual vs
theoretical usage with every column shown, cost of goods by period, handed to the ledger. Every restaurant is a kernel
site; the application is the first site-scoped inventory in the estate. Two agents ship with it: the expert on the
command bar and the Purchasing agent whose morning note carries the day's suggested orders and never places one.

## 0. What the research says (the reports are the record; this is the digest)

- **The field** (`01-field-survey.md`): twenty products, the distributor portals and the open-source projects agree on
  one shape — items with several units, vendors with order guides, pars and a suggested order, invoices as the source of
  cost, recipes with sub-recipes and yields, POS mapping with modifiers, counts by storage area in shelf order, waste with
  reasons, transfers and a commissary, actual vs theoretical, COGS by period. What users complain of is setup labour,
  counting in a walk-in with no Wi-Fi, weak mobile, hidden arithmetic, the bar and the kitchen kept in separate pools, and
  $239–$499 a location a month.
- **The mechanics** (`02-mechanics.md`): every formula this application needs, with the columns each needs — EP = AP ÷
  yield; a recipe is a tree walked bottom-up; actual usage = opening + purchases ± transfers − closing; theoretical = Σ
  sales × recipe; variance and its explained buckets; weighted average as the valuation; the forecast as same-weekday
  averages and Holt-Winters with a weekly cycle, judged by MAE and WAPE never MAPE; periodic review as the reorder rule
  that fits delivery days; the newsvendor cap for perishables; counts with partials and bottle weights; Leanpath's waste
  stages; three-way receiving with catch weight and the temperature check; GS1 and EDI for later; the bar's pours.
- **The estate** (`03-estate-survey.md`): the retail Inventory application's storeroom — locations, the ledger with
  balances by trigger, receipts, transfers, adjustments, counts, reason codes, suppliers, purchase orders with events and
  the supplier's secure link, the `sources` connector frame — fits a restaurant verbatim except that every quantity is an
  integer keyed by a retail variant with no unit. The two process applications hold the estate's only units tables and
  versioned recipes, in their `app` schema, manufacturing-shaped: no sub-recipes, no per-line yield, no menu item. No
  sibling holds a POS sale, a menu, a waste log, a count sheet or a usage period. Reservations' `covers_by_service` is the
  only forward signal; no sales history exists anywhere. The GL declares no reads. Category `inventory` and area
  Operations pass already. Next free ports 8200 / 8856 / 8857, scratch 9301–9307.

**The vocabulary this design adopts** (so a chef, a distributor's order guide and a POS export transcribe): an
**ingredient** is anything the restaurant stocks (a case of romaine, a bottle of gin, a roll of film) — the estate's
`items` family under the kitchen's word; a **unit** has a **measure class** (weight, volume, each); an ingredient has a
**base unit** (its class's base: g, ml or each), **purchase units** per supplier (a case of 6 × #10, a 50 lb bag), a
**recipe unit** (the unit the chef writes), and **count units** (what is on the shelf: a case, a can, a bottle); **AP**
and **EP** are as-purchased and edible-portion; a **recipe** is a menu item, a prep (batch) or a modifier; a **menu item**
is what the POS sells by **PLU**; **depletion** is the backflush of a sale through its recipe; a **storage area** is a
place stock sits inside a site (walk-in, freezer, dry store, bar, line); a **count** is a sheet per storage area in
shelf order; **usage** is actual (counted) or theoretical (from sales); a **period** is the costing calendar's unit.

## 1. What Restaurant Inventory is, and is not

Restaurant Inventory **owns what the restaurant stocks, what it costs, what it turns into and what it should buy next.**
It is the back-office desk a restaurant otherwise runs across a distributor's portal, a count spreadsheet, a recipe binder
and the POS's sales report, with the differences that matter here:

- **Units are first-class, and the arithmetic is shown.** Every quantity in the database is in the ingredient's base unit;
  every screen shows it in the unit the person works in and says what it converted from. A case is never "1 each". An
  actual-vs-theoretical line shows opening, purchases, transfers, production, waste, theoretical, actual, variance in
  units and at cost, and the explained buckets — the reports whose arithmetic users cannot see are the field's top complaint.
- **Recipes are a costed tree with a yield on every edge.** A menu item's recipe names ingredients and sub-recipes; a prep
  recipe makes stock of an ingredient; a modifier recipe adds, removes or swaps; every version carries effective dates so
  a past period's theoretical usage uses the recipe then in force; costs are computed as of a date from price history,
  never frozen on the version. The plate cost, the food-cost % and the menu-engineering matrix follow.
- **Perpetual balance, periodic close.** The ledger (Inventory's canonical shape, widened for measured goods) keeps a
  running balance per ingredient per storage area that receipts, depletion, waste, transfers, production and count
  corrections move; a period closes on an approved count, freezes the ending value at weighted average cost and hands
  COGS to the ledger. Both schools of the field are served, and every screen says which number it shows.
- **The POS is a source, not an integration project.** A sales export (CSV by upload, email or SFTP) or a keyed API is
  a `source` with pulls, exactly as Inventory reads a supplier's feed; a PLU with no recipe is work on the home screen,
  never a silent drop; a day's depletion is a run that can be re-run.
- **Projection is the point.** Sales forecast per menu item per site per day (same-weekday averages, then Holt-Winters
  with a weekly cycle, closures and holidays on a calendar, booked covers from Reservations as a factor), exploded through
  the active recipes into ingredient demand, into projected on hand per day, into one suggested order per supplier for its
  next delivery with the need, the have, the cap and the rounding shown on every line. The accuracy of every forecast is
  kept so the method can be judged and changed.
- **Agents propose; people commit money and approve counts.** The expert answers and drafts; the Purchasing agent's
  morning note carries the suggested orders due today, price movers, unmapped PLUs, the last count's variance and the
  forecast's misses; sending a purchase order pauses as `money_out`; approving a count and closing a period are a person's.
- **Full MCP coverage is a requirement**: every question a screen answers is a records tool, every button an action tool.
- **Nothing of identity, money or books lives here.** The kernel signs people in; the ledger keeps the books and reads
  purchases, stock value and COGS through K7; payments to suppliers are the ledger's; a supplier is a party with a secure
  link, never an account.

Not in version 1 (each an Extended item, §11): invoice OCR (an agent reading an invoice image into lines); EDI and API
ordering with the broadline distributors; GS1 barcode scanning on receiving and lot/expiry tracking for recall; nutrition
from USDA FoodData Central; labour cost for prime cost (txtSchedules' or HR's to share); the commissary-as-vendor pattern
where a transfer becomes a bill between legal entities; weather and event features in the forecast; gradient-boosted
forecasting; standard-cost variance split; keyed POS connectors beyond the first (Square); a storefront, a POS, a payment.

## 2. Who uses it (the actors, in the OS's words)

"Agent" always means an OS AI agent. A **member** is a kernel member mirrored here; a **site** is a restaurant (a kernel
location of kind `site`, the application's scope); a **storage area** a place inside it.

| Actor | Who | How they reach the application |
|---|---|---|
| **Kitchen** (line cook, prep, bar staff) | Counts, logs waste, receives a delivery, posts a prep batch, moves stock between areas | A phone in the walk-in (counts, waste and receiving are designed at 375 px first; the count sheet keeps its entries without a signal) |
| **Chef** (chef, sous chef, bar manager) | Keeps the recipes and the menu map, sets yields and pars, reviews and approves counts, reviews waste, drafts the orders | The desk and the phone |
| **Manager** (GM, owner-operator, purchasing) | Suppliers and their schedules, sends purchase orders, enters invoices and settles the match, closes periods, reads the reports, sets prices' alerts | The desk |
| **Restaurant Inventory admin** | Settings, units, categories, the costing calendar, every record, exports, the agents' settings, the POS sources' keys. A super-admin holds it | The admin area |
| **An agent** | The shipped **expert** and **Purchasing agent**; any other agent a super-admin grants a role at a site | Records MCP (run token) · Actions MCP (relayed) · the command bar · a duty (§5) |
| **A supplier** | Holds a purchase order's secure link: acknowledges, declines a line, sets the delivery date | `/s/<token>` — three actions, no account (§4) |
| **The POS** | Sends or is read for the day's sales: a file by upload, email or SFTP, or an API with a key | A `source` (§6.5) |
| **The kernel** | Signs people in, delivers the directory and the sites, hires and runs the agents, pauses their actions, answers the command bar, carries the siblings' reads (K7), texts members (K6) | — |
| **System** | The POS pulls and the depletion runs, the forecast passes, the suggested orders, price alerts, the outbox, link expiry, the morning note's data — the one timer worker | `source = 'cron'`, every row logged |

Tenancy: the business is the tenant — one installation, one database, the tenant's one memory. Several restaurants of one
business are **sites** in one installation, each a scope; a commissary is a site. A second business is a second installation.

## 3. Who sees what — the rules, in one place

**Roles** (published by `app_roles`, `os.app-roles/1`; exactly one `is_admin`; the base role's key is `user` on purpose; the
application is **not** granted to standing departments by default — `grant_standing_departments: false`).

| Role key | Name | Capability | Rights |
|---|---|---|---|
| `viewer` | Viewer | read | `ri.read` — ingredients, recipes (method and quantities), stock on hand, counts, waste, orders and their status; **never cost, price history, margin or a supplier's terms** |
| `user` | Kitchen | write | Viewer's + `stock.count` (enter a sheet), `stock.waste`, `stock.receive` (against a PO or a delivery note), `stock.transfer`, `stock.produce` (post a prep batch), `notes.write`; sees cost only when the setting `kitchen_sees_cost` is on (default off) |
| `chef` | Chef | write | Kitchen's + `recipes.write` (recipes, versions, activation, yields, the menu map, modifiers), `ingredients.write` (ingredients, units, pars, storage areas, count sheets), `counts.approve`, `waste.review`, `purchasing.draft` (order guides, suggested orders, a purchase order drafted — never sent), `reports.read`; sees **cost and food-cost %** |
| `manager` | Manager | write | Chef's + `suppliers.write` (suppliers, schedules, supplier items, contract prices), `purchasing.send` (send or place a purchase order — the money), `invoices.write` (lines, the match, credits), `prices.write` (cost overrides, price alerts' thresholds), `periods.close`, `forecast.adjust`, `exports.all`; sees everything of money |
| `admin` | Restaurant Inventory admin (is_admin) | admin | every right + `settings.manage`, `sources.credentials` (set and rotate — never read back), `agents.settings`, `records.delete`, `sequences.manage`, `sites.manage` |

**Reach (`ri_can_see(kind, id)` — one PL/pgSQL function, tested once per statement in every view):**

- **Scope: location.** A person holds roles **per site**; every stock row, document, count, sale, forecast and order
  carries `scope_id`; a person sees the sites they hold, and the site switcher changes the site. A Manager of two sites
  sees both; a transfer between sites is visible at both ends. Ingredients, units, recipes and suppliers are **shared
  across sites** (one catalog, one recipe book — the field's multi-unit shape); pars, costs, on hand, order guides and
  menu prices are **per site**.
- **Cost is the wall.** Unit cost, price history, a purchase order's and receipt's cost, plate cost, food-cost %, margin,
  stock value, COGS and variance at cost are visible to Chef, Manager and admin, to Kitchen when `kitchen_sees_cost` is
  on; the `mcp_*` views null the columns for everyone else (`ri_sees_cost()`); the records tools say "cost withheld".
- **A source's credential is nobody's.** The POS key's ciphertext appears in no view, tool, log or export.
- **A supplier sees their purchase order** by its token — lines in their units, the delivery address, never our cost
  targets, never another supplier's name.
- **An agent sees what its role may at the sites it is granted** — the same functions, no special case; `is_eval`
  refuses every write.
- **Departments are a dimension, never a wall.**

## 4. Doors for those who are not inside

- **The supplier's door `/s/<token>`** (Inventory's `supplier_links_secure` verbatim): acknowledge, decline a line with a
  reason, set the delivery date; 30 days after the PO closes the link dies. A supplier with `order_method = email` gets
  the PO as a PDF-less HTML email from MaluMail with the link; `portal` and `phone` mean a person places it and records
  the supplier's reference; `edi` is Extended.
- **The POS door.** No endpoint of our own is needed for a file: a `source` of connector `file` is fed by **upload** (a
  person drops the day's export), by **email** (the POS mails its report to an address at MaluMail that the worker reads
  — the estate's intake pattern, `\Seen` as handled), or by **SFTP pull** on a schedule; a `source` of connector `square`
  pulls Orders with a key sealed as a credential. Toast, Clover, Lightspeed are Extended on the same interface.
- **Exports**: CSV/JSON of every report, and the three ledger documents of §8 as downloads for an accountant without the GL.
- **No customer door, no public menu, no storefront.**

## 5. Agents and the application — the OS requirement, in detail

**Reading, drafting and proposing are free; money, approvals, deletions, prices and credentials are a person's.**

| Action | Category | Why |
|---|---|---|
| `purchase_order_send`, `purchase_order_place` | `money_out` | A commitment to pay a supplier |
| `supplier_message` (an email to a supplier beyond the PO) | `external_send` | Content leaves the business |
| `count_approve`, `period_close`, `period_reopen`, `invoice_match_settle` (accept a price or quantity difference), `recipe_version_activate` (changes every cost from its effective date) | `other` | **Policy default: pause for agents** — a person's word on the books |
| `ingredient_delete`, `recipe_delete`, `supplier_delete`, `source_delete`, `count_discard`, `purchase_order_cancel`, `stock_adjust` (a quantity changes with no document), `depletion_run_void` | `deletion` | |
| `cost_override_set`, `par_set` (per site), `price_alert_threshold_set`, `source_create`, `source_credential_set`, `source_schedule_set`, `settings_save`, `sequence_set`, `calendar_set` | `other` | Configuration and money |
| `ingredient_create|update`, `unit_set`, `supplier_item_create|update`, `recipe_create|update` (a draft version), `recipe_line_set`, `menu_item_map` (PLU → recipe), `modifier_map`, `purchase_order_draft`, `order_guide_set`, `suggested_order_run`, `receipt_draft`, `waste_log`, `transfer_draft`, `production_post` (a prep batch), `count_start`, `count_line_set`, `forecast_run`, `forecast_override_propose`, `report_run`, `export_download`, `note_add`, `watch_set` | — | An agent does these alone |

**Working — how an agent learns there is work.** (a) The command bar — a person's turn on the expert ("what does the
short rib cost today and what was it in July", "draft Tuesday's Sysco order", "which PLUs sold yesterday with no
recipe", "where did we lose chicken last period"). (b) A duty (cron) for the Purchasing agent. (c) A mention in Spaces.
(d) A watch that fires writes a notification to its person and, when it names an agent, an `agent_dispatches` row the
worker turns into one chat turn ("romaine's cost moved 18 % on today's invoice"). (e) The kernel's K8 when it lands.

**Shipped agents** (`maludb-os.json` `agents[]`; hired on install at the owner's word — D14):

| Key | Job | Reads | Acts | Pauses on |
|---|---|---|---|---|
| `expert` — Kitchen Inventory Expert | The command bar and the house name to mention: answers costs, usage, stock, variance, forecasts, "can we run the special Friday with what is projected"; **drafts** a recipe from a chef's dictation (lines in the units said, yields from the ingredient, a sub-recipe where the chef names a prep), a PO, a transfer, a waste log; explains a variance line (which recipes consume the item, the waste logged, the count's partials); never spends, approves or deletes unless the asker says so, and then pauses | every records tool, `record_history`, `actor_timeline` | the free actions above; `purchase_order_send` (paused); `count_approve` (paused) | `purchase_order_send`, `count_approve`, `period_close`, `recipe_version_activate`, `cost_override_set` |
| `buyer` — Purchasing agent | A duty every morning (default 06:00 site time, `0 6 * * *` in UTC adjusted per site): **the morning note** per site — (1) **suggested orders** for every supplier whose cut-off falls today or tomorrow, one drafted PO each, every line with need / have / on order / cap / rounded, flagged where the forecast is thin (fewer than four weeks of history); (2) **stock-outs projected** before the next delivery; (3) **prices**: cost moved more than the threshold on yesterday's invoices, contract price vs invoice drift, the week's price movers; (4) **the POS**: pulls failed, PLUs unmapped, modifiers unmapped, a day with no sales where the site was open; (5) **the last count**: variance over tolerance by ingredient with the explained buckets; (6) **the forecast**: items whose WAPE worsened, closures and holidays coming with no override; (7) purchase orders unacknowledged past N days, deliveries due with no receipt — one note to the Manager named per site (the first super-admin until then) and to `#kitchen` in Spaces when installed; drafts never sent | `suggested_orders`, `projected_stock`, `price_exceptions`, `pos_health`, `unmapped_plus`, `count_variance`, `forecast_accuracy`, `purchase_orders_open`, `get_*` | `purchase_order_draft`, `forecast_override_propose`, `watch_set`, `note_add`, `message_send` (the kernel's, to the Manager's assistant) | nothing (it never sends, spends, approves or deletes) |

**Skills** (`skills/`): `kitchen-inventory-basics` (what an ingredient, a unit, a recipe, a menu item, a depletion, a
count, a period *are*; which tool answers what), `units-and-yields` (AP vs EP; a case is never one each; when a density is
needed; say the unit on every number), `costing-a-recipe` (cost as of a date, the basis shown, sub-recipes by their
finished yield, portion and plate cost, food-cost % against the menu price), `reading-variance` (actual vs theoretical;
the buckets; a negative variance is a data defect first; which recipes consume the item), `ordering` (the periodic-review
rule in words; the vendor's cut-off and delivery; perishables by shelf life; one PO per supplier; never send),
`counting` (shelf to sheet, partials, bottle weights, what to do with a blank row); runbooks (`kind: runbook`):
`morning-note`, `suggested-order-run`, `post-count-review`, `price-check`, `plu-queue`, `recipe-from-dictation`.

## 6. Memory model — what Restaurant Inventory remembers

One PostgreSQL 17 database `<tenant>_restaurant_inventory`, `public` schema, prefix `ri_` on functions and the
application's own tables where a name would collide; the tenant's one MaluDB memory for activity episodes tagged
`restaurant_inventory`. Every quantity in the database is `numeric(18,4)` in the ingredient's **base unit**; every cost
`numeric(18,6)` per base unit; money `numeric(14,2)`. Every operational row carries `scope_id` (the site). Migrations are
additive after Phase 0.

### 6.1 Units, ingredients, suppliers, storage areas

- `units(code PK, name, measure_class weight|volume|each, to_base_factor numeric, is_base, display_order)` — seeded: g,
  kg, oz, lb; ml, l, floz, cup, pt, qt, gal; each, dozen. The cidery's / ProcessCore's shape, now canonical for the kit
  family. A global constant table within a class; across classes only through an ingredient's own facts.
- `ingredient_categories(id, key, name, kind food|beverage|supplies, parent_id, sort_order, safety_stock_pct default)` —
  produce, dairy, protein, dry, frozen, liquor, beer, wine, N/A beverage, paper, chemicals; seeded, editable.
- `ingredients(id, code, name, category_id, measure_class, base_unit_code, recipe_unit_code, density_g_per_ml NULL,
  each_weight_g NULL, each_volume_ml NULL, yield_pct default 100 (the EP after trim at receipt — cost-bearing),
  catch_weight bool, shelf_life_days, costing_method avg|last, variance_tolerance_pct, variance_tolerance_value,
  allergens text[], is_prep (made by a prep recipe), active, archived_at, …)` — the estate's `items` family under the
  kitchen's word (Inventory's `products` is recorded as a name collision of a different concept).
- `ingredient_units(ingredient_id, unit_code, name ("case of 6 × #10"), to_base_factor, kind purchase|count|recipe,
  is_default_count)` — the per-ingredient units (`item_units` canonical, extended with `kind`).
- `ingredient_site_settings(ingredient_id, scope_id, par_base, par_mode fixed|dynamic, reorder_min_base, service_level
  0.95, preferred_supplier_item_id, cost_override_per_base NULL, active)` — per-site pars and preferences.
- `price_history(ingredient_id, scope_id, kind cost, old, new, per_unit_code, changed_at, changed_by, source_kind
  receipt|invoice|manual|contract, reference_kind/id)` — Inventory's canonical, kind `cost` only.
- `ingredient_site_costs(ingredient_id, scope_id, avg_cost_per_base, last_cost_per_base, last_cost_at, on_hand_base,
  on_hand_value)` — maintained by the ledger's trigger (the moving average recomputed on every receipt, never on an issue).
- `suppliers` — the cidery's shape extended by Inventory (name, kind, contact, terms, `lead_time_days`, `order_method`
  email|portal|phone|edi, `order_email`, `portal_url`, `min_order_value`, `allow_split_case`, `active`); **appended**
  `account_number`, `rep_name`, `rep_phone`.
- `supplier_schedules(supplier_id, scope_id, order_dow, cutoff_time, delivery_dow, lead_days)` — "order by Saturday
  13:00 for Tuesday delivery"; several rows a week.
- `supplier_items(supplier_id, ingredient_id, supplier_sku, description, purchase_unit_code → ingredient_units, pack
  text, last_price per purchase unit, last_price_at, contract_price, contract_until, moq, catch_weight, active)` — the
  cidery's shape plus Inventory's `moq`; one ingredient related across suppliers.
- `order_guides(id, supplier_id, scope_id, name)` + `order_guide_lines(guide_id, supplier_item_id, seq, preferred)`.
- `storage_areas(id, scope_id, name, kind walk_in|freezer|dry|bar|line|office|other, walk_order, allow_negative,
  active)` — Inventory's `locations` with `scope_id` appended and `kind` widened; `kernel_location_id` dropped (the
  scope is the site).
- `yield_tests(id, ingredient_id, ap_qty_base, ep_qty_base, byproducts jsonb, trim_loss_pct, cook_loss_pct, tested_at,
  tested_by, notes)` — the textbook's butcher's and cooking-loss tests; a test may set the ingredient's `yield_pct`.

### 6.2 Recipes, menu items, costing

- `recipes(id, code, name, kind menu_item|prep|modifier, category_id (menu category), output_ingredient_id NULL (a prep
  recipe makes stock of this ingredient), portion_qty_base, portion_unit_code, portions_per_batch, method text, photo
  attachment, allergens_rollup text[] (maintained), active, archived_at)`.
- `recipe_versions(id, recipe_id, version_no, status draft|active|retired, effective_from, effective_to, batch_yield_base,
  batch_yield_unit_code, expected_loss_pct, change_note, activated_at, activated_by)` — immutable once active (the
  cidery's guard); **one active per recipe per date**; a new version's activation sets the previous `effective_to`.
- `recipe_lines(id, version_id, seq, ingredient_id XOR sub_recipe_id, qty, unit_code, qty_base (generated), line_yield_pct
  default 100 (depletion-bearing — a dice yields differently from a julienne), group_name (garnish, sauce), optional
  bool, notes)` — a line names an ingredient or another recipe; a cycle is refused at save (`ri_recipe_cycle()`).
- `menu_items(id, scope_id NULL (shared) , pos_source_id NULL, plu, name, category, sale_price, recipe_id NULL, active,
  first_seen_at, last_sold_at)` — what the POS sells; a PLU with no `recipe_id` is the **unmapped queue**; a site may
  override price per `menu_item_prices(menu_item_id, scope_id, price, effective_from)`.
- `modifiers(id, pos_source_id, code, name, action add|remove|swap|none, recipe_id NULL, qty_factor)` and
  `menu_item_modifiers(menu_item_id, modifier_id, qty_base_override NULL)` — a generic "no tomato" carries a quantity per
  parent where it must.
- Costing is **computed, never stored on the version**: `ri_recipe_cost(version_id, scope_id, as_of, basis avg|last)`
  walks the tree in topological order with memoisation, each leaf at the site's cost as of the date (price history), each
  sub-recipe at its batch cost ÷ batch yield, each line ÷ its yield; answers the batch cost, the cost per portion, the
  plate cost, and the lines' shares. `recipe_cost_cache(version_id, scope_id, as_of_date, basis, cost_per_portion,
  computed_at)` memoises a day's answers for the menu-engineering and theoretical-COGS reports and is invalidated by price
  history. `ri_menu_engineering(scope_id, period)` gives CM, mix, the four quadrants.

### 6.3 The ledger (Inventory's canonical shape, widened for measured goods — the one recorded deviation)

- `inventory_balances(ingredient_id, storage_area_id, qty_base, value, updated_at)` — maintained **only** by the ledger's
  trigger; a negative refused unless the area allows it.
- `inventory_transactions(id, scope_id, group_id, txn_type receipt|depletion|waste|transfer_out|transfer_in|
  production_in|production_out|count_correction|adjustment|return|reversal, ingredient_id, storage_area_id, qty_base
  (signed), unit_cost_per_base, value, counterparty_kind/id, reason_code_id, reference_kind/id, reverses_id,
  idempotency_key, occurred_at (the accounting moment), business_date, posted_at, posted_by)` — never changed or deleted,
  reversed; the moving average advanced on `receipt` and `production_in`, never on an issue.
- `reason_codes(applies_to text[])` widened with `waste`, `count`, `production`; `document_sequences`, `tax_rates`,
  `attachments`, `notes` the GL's canonical.
- `goods_receipts` / `goods_receipt_lines` — Inventory's verbatim, lines measured: `qty_received` in `purchase_unit_code`
  with its factor, `qty_base` generated, `weight_base NULL` (catch weight declared, ProcessCore's rule — no weigh ticket),
  `unit_cost` per purchase unit, `discrepancy_kind` none|short|over|damaged|wrong_item|substitute, `temperature_c NULL`,
  `temperature_ok NULL` (the FDA door check), `storage_area_id`.
- `inventory_transfers(+lines)` between storage areas of one site or between sites (two halves; the receiving site's
  cost = the sending site's average); `inventory_adjustments(+lines)` with a reason.
- `production_batches(id, scope_id, recipe_version_id, batch_qty_base, made_at, made_by, storage_area_id, status
  draft|posted, cost_total)` — a prep recipe posted: `production_out` for each line, `production_in` of the output
  ingredient at the rolled-up cost (the cidery's `intermediate` precedent).
- `waste_logs(id, scope_id, logged_at, business_date, logged_by, station, day_part_id, stage ingredient|prep|plate,
  reason_code_id, ingredient_id XOR recipe_id, qty, unit_code, qty_base, gross_weight_g NULL, tare_g NULL, cost,
  disposition trash|compost|donation|staff_meal|comp, photo attachment, approved_by NULL)` — posts `waste` rows (a plate's
  waste explodes through its recipe); the cidery's `loss_events` column names kept where the concept matches.

### 6.4 Purchasing, receiving and invoices

- `purchase_orders`, `purchase_order_lines`, `purchase_order_events`, `supplier_links_secure` — Inventory's canonical
  verbatim without the drop-ship half; lines in the supplier item's purchase unit with `qty_base` generated, `unit_cost`
  per purchase unit, `need_base`, `have_base`, `on_order_base`, `cap_base`, `rounding_note` (the suggested order's
  arithmetic kept on the line so the screen shows it); `status` draft→sent→acknowledged→partial→received→closed|
  closed_short|cancelled; `expected_on`; `sent_via`.
- `supplier_invoices(id, scope_id, supplier_id, invoice_no, invoice_date, received_on, total, tax, status
  draft|matched|disputed|settled, attachment, purchase_order_id NULL, goods_receipt_id NULL)` +
  `supplier_invoice_lines(invoice_id, supplier_item_id, description, qty, unit_code, unit_price, line_total,
  receipt_line_id NULL, match_state ok|qty_diff|price_diff|unordered, diff_qty, diff_value)` — the three-way match with
  per-supplier tolerances (`suppliers.match_tolerance_pct`, `match_tolerance_value`); settling a difference posts a
  `price_history` row and, when the quantity differs, a receipt correction; a credit reverses on the original date.
- `price_alerts(id, scope_id, ingredient_id, supplier_item_id, old, new, pct, invoice_line_id, raised_at, seen_by)` — the
  threshold per category or ingredient in settings.

### 6.5 POS sources, sales and depletion

- `sources`, `source_pulls`, `source_credentials` — Inventory's canonical connector frame verbatim; `connector` ∈ `file`
  (upload, email intake, SFTP), `square`, later `toast|clover|lightspeed`; `settings` jsonb holds the file's column
  mapping template (`sales_file_mappings` seeded for Square, Toast, Clover, Lightspeed exports and a generic shape).
- `pos_sales(id, scope_id, source_id, external_id, business_date, day_part_id NULL, closed_at, covers, subtotal, tax,
  total, comps, voids, raw jsonb)` and `pos_sale_lines(id, sale_id, external_line_id, plu, menu_item_id NULL, name,
  qty, unit_price, line_total, voided, comp, modifiers jsonb [{code, name, qty}])` — the imported checks; `menu_item_id`
  resolved from the PLU; **a void never depletes, a comp always does**.
- `depletion_runs(id, scope_id, business_date, source_id, status pending|done|partial|void, lines_total,
  lines_depleted, lines_unmapped, started_at, finished_at, group_id)` — **one run per site per business date**: it
  explodes every mapped line and modifier through the recipe version in force on the date, sums per ingredient, and posts
  **one `depletion` transaction per ingredient per storage area per date** (the line area from the recipe's default or the
  site's `depletion_area_id`); re-running voids the group and posts again; an unmapped PLU is counted and queued, never dropped.
- `day_parts(scope_id, key, name, starts_at, ends_at, service_name)` — txtSchedules' shape, mapping Reservations' services.

### 6.6 Counts

- `count_sheets(id, scope_id, name, kind full|cycle|spot, active)` + `count_sheet_lines(sheet_id, storage_area_id,
  ingredient_id, shelf_order, count_unit_code)` — shelf to sheet; an ingredient may be on several areas' sheets.
- `inventory_counts(id, scope_id, sheet_id, kind, as_of (the moment — equals the sales cut), status open|review|
  recount|approved|discarded, blind bool, started_by/at, approved_by/at, group_id)` + `inventory_count_lines(count_id,
  storage_area_id, ingredient_id, shelf_order, system_qty_base (frozen at as_of), entries jsonb [{unit_code, qty}] (a
  case + 3 loose), gross_weight_g NULL, container_id NULL, counted_qty_base, state pending|counted|zero|skipped,
  recount_qty_base NULL, counted_by/at, variance_base, variance_value, over_tolerance bool)`.
- `containers(ingredient_id, name "750 ml bottle", full_weight_g, empty_weight_g, pourer_tare_g, volume_ml)` — bottle
  and keg weights (the bar's count by scale: fraction = (gross − empty − pourer) ÷ (full − empty)).
- Approval posts `count_correction` rows for every counted line (never for skipped ones — a skipped area is listed on the
  period's close as uncounted), writes `periods.ending_value` when the count is the period's closing count, and is the
  moment the perpetual balance is corrected. Entries made in the walk-in are kept in the browser until posted (the sheet
  page keeps a draft per line in local storage and resends on signal — not a service worker, a form that survives).

### 6.7 Periods, usage, variance, valuation

- `costing_calendars(id, kind month|4-4-5|4-5-4|5-4-4|13x4|week, year_start_on)` and `periods(id, scope_id, calendar_id,
  no, starts_on, ends_on, status open|closed, opening_count_id, closing_count_id, ending_value, cogs_value, closed_at,
  closed_by, reopened_at)`.
- `ri_usage(scope_id, from, to, basis)` — the defining function: per ingredient opening (the opening count or the balance
  at from), purchases (receipts by `occurred_at`), transfers in/out, production in/out, waste (logged), theoretical
  (Σ depletion), closing (the closing count or the balance), **actual usage** = opening + purchases + transfers in +
  production in − transfers out − production out − closing, **variance** = actual − theoretical, waste explained, comps
  explained, **unexplained**, each in base units and at cost (weighted average over the period: Σ value ÷ Σ qty of the
  period's receipts and opening); and the same by category and site. One function behind the screen, the tool, the
  export and the GL's `cogs_summary`.
- `ri_stock_value(scope_id, as_of, by)`, `ri_cogs(scope_id, period)`, `ri_food_cost_pct(scope_id, period)` (needs food
  sales from `pos_sales`), `ri_days_on_hand(scope_id)`, `ri_price_movers(scope_id, days)`.
- Closing a period freezes `ending_value` and `cogs_value`; a receipt or credit dated inside a closed period lands in the
  next open period flagged `late` (the kernel's statement rule); reopening is an admin's, logged.

### 6.8 Projection and ordering

- `calendar_days(scope_id, on_date, dow, holiday_id NULL, closed bool, event text NULL)` — the site's trading calendar
  (closures excluded from training and error; holidays as a learned factor).
- `sales_daily(scope_id, menu_item_id, business_date, day_part_id NULL, qty, net)` — maintained from `pos_sale_lines` by
  the depletion run (the forecaster's input, indexed).
- `forecast_runs(id, scope_id, made_at, method baseline|holt_winters|manual, horizon_days, history_days, params jsonb)` +
  `item_forecasts(run_id, menu_item_id, on_date, qty, lo, hi)` + `forecast_overrides(scope_id, menu_item_id NULL (all),
  on_date, factor NULL, qty NULL, reason, set_by)` + `forecast_accuracy(scope_id, menu_item_id, horizon, window_days,
  mae, wape, bias, computed_at)`.
- `covers_expected(scope_id, on_date, day_part_id, covers, source reservations|manual)` — read from Reservations'
  `covers_by_service` through K7 (`reads[]`), degraded to none on `no_connection`; the forecast applies **sales per cover
  by day part** (from history) as a factor when booked covers differ from the same weekday's average by more than the
  setting (default 15 %).
- `ri_ingredient_demand(scope_id, from, to)` — the explosion: Σ forecast qty × recipe quantities through sub-recipes with
  line yields, plus modifiers' average attachment rates; `ri_projected_stock(scope_id, ingredient_id, days)` — on hand +
  receipts due (open POs by `expected_on`) − Σ demand − planned waste (the category's historical waste %), per day; the
  first day under zero is the **projected stock-out**.
- `ri_suggested_order(scope_id, supplier_id, as_of)` — the periodic-review rule in SQL, step by step on every line:
  the protection interval P from this delivery to the delivery after the next cut-off (`supplier_schedules`); need = Σ
  demand over P + safety stock (z for the ingredient's service level × σ of the forecast residuals × √P; or the
  category's safety-stock % when history is thin); for `par_mode = fixed` need = par; have = on hand + on order due before
  the delivery; cap = the newsvendor fractile over shelf life for perishables (empirical quantile of recent daily demand);
  raw = max(min(need, cap) − have, 0); rounded to the supplier item's purchase unit (up, unless `allow_split_case`),
  minimum order quantity applied, the supplier's minimum order value checked; every step kept on the drafted
  `purchase_order_lines` row. The Purchasing agent's duty and the Manager's "Suggested orders" screen call the same function.
- `buyer_proposals` — Inventory's shape with `scope_id` (the agent's morning proposals and their acceptance).

### 6.9 Tables against the estate — read, reuse, new (the shared-schema rule)

| Table(s) | Decision | Source and what differs |
|---|---|---|
| `members`, `departments`, `department_members`, `sso_nonces`, `member_sessions`, `directory_sync_state`, `os_scopes`, `member_scope_roles` | **reuse** | the kernel contract, scoped (txtSchedules' `sites` variant: `sites(scope_id PK, location_id, name, timezone, removed_at)`); `members` appends `is_agent`, `is_external` |
| `activity_log`, `activity_ingest_state`, `mcp_access_tokens`, `ri_rights`/`ri_roles`/`ri_role_rights`/`mcp_app_roles`, `ri_settings`, `site_settings` | **reuse** | the contract and Consultant Tracking's shapes; `activity_log` appends `scope_id`, `ingredient_id`, `recipe_id`, `purchase_order_id`, `count_id` |
| `document_sequences`, `tax_rates`, `attachments`, `notes`, `notifications`, `notification_prefs`, `notification_outbox`, `agent_dispatches` | **reuse** | GL canonical verbatim; `kind` lists ours; `record_type` widened |
| `reason_codes`, `price_history`, `sources`, `source_pulls`, `source_credentials`, `purchase_orders`, `purchase_order_lines`, `purchase_order_events`, `supplier_links_secure`, `buyer_proposals` | **reuse** | Inventory canonical; `scope_id` appended; the PO's drop-ship half omitted (columns kept nullable where the canonical has them, so a reader of one recognises the other); the suggested-order arithmetic columns appended to the line |
| `suppliers`, `supplier_items` | **reuse** | the cidery's shape (purchase unit + factor) with Inventory's `moq`, `order_method`, `lead_time_days`; appended `account_number`, `rep_*`, `match_tolerance_*`, `min_order_value`, `allow_split_case` |
| `units`, `ingredient_units` | **reuse** (shape) → **canonical for the kit family** | the cidery's / ProcessCore's `app.units`, `app.item_units` verbatim in `public`; `kind` appended |
| `storage_areas`, `inventory_balances`, `inventory_transactions`, `goods_receipts(+lines)`, `inventory_transfers(+lines)`, `inventory_adjustments(+lines)`, `inventory_counts(+lines)` | **reuse with ONE recorded deviation** | Inventory's canonical for unit-counted goods: `qty integer` keyed by `variant_id` → `qty_base numeric(18,4)` keyed by `ingredient_id` with the unit implied by the ingredient's base unit, `unit_cost numeric(18,6)` per base; `scope_id` on every document; `locations` renamed `storage_areas` with `kind` widened — **proposed back to the catalogue as "the canonical shape widened for measured goods"**, the cidery's lot-keyed shape the third member of the family |
| `ingredients`, `ingredient_categories`, `ingredient_site_settings`, `ingredient_site_costs`, `yield_tests`, `containers` | **new** (the `items` family under the kitchen's word) | the cidery's / ProcessCore's `items` columns kept where the concept matches (`code`, `base_unit_code`, `catch_weight`, `costing_method`, `shelf_life_days`); Inventory's `products` recorded as a name collision |
| `recipes`, `recipe_versions`, `recipe_lines`, `recipe_cost_cache`, `menu_items`, `menu_item_prices`, `modifiers`, `menu_item_modifiers` | **new** → canonical kitchen recipe | the cidery's `recipe_versions` columns kept (`version_no`, `status`, `expected_loss_pct`, `change_note`, `activated_at/by`, the immutability guard); sub-recipes, line yield, the menu link and effective dates are new; `consumption_mode = backflush` is the estate's word for depletion |
| `pos_sales`, `pos_sale_lines`, `depletion_runs`, `sales_daily`, `day_parts` | **new** → canonical POS sales | nothing in the estate holds a POS sale; `day_parts` is txtSchedules' shape verbatim |
| `waste_logs`, `production_batches` | **new** | the cidery's `loss_events` and `production_orders` column names kept where the concept matches |
| `count_sheets(+lines)`, `costing_calendars`, `periods` | **new** | nothing close (the GL's fiscal periods are the books', read never copied) |
| `supplier_schedules`, `order_guides(+lines)`, `supplier_invoices(+lines)`, `price_alerts` | **new** | nothing close; the GL's `bills` are the payable, fed through the share |
| `calendar_days`, `forecast_runs`, `item_forecasts`, `forecast_overrides`, `forecast_accuracy`, `covers_expected` | **new** → canonical forecast tables | the cidery's `demand_forecasts` (`method`, `history_weeks`) and txtSchedules' `forecast_covers` shapes crossed |
| HR's employment, the GL's vendors, chart and books, Reservations' reservations, txtSchedules' shifts, the kernel's directory and scopes | **read** | another application's data; never copied |

**Views and functions**: `mcp_*` over every table with `security_barrier`, the caller's rights and sites tested once per
statement, `ri_sees_cost()` nulling the cost columns; the reads that matter are SQL functions — `ri_recipe_cost`,
`ri_menu_engineering`, `ri_usage`, `ri_stock_value`, `ri_cogs`, `ri_days_on_hand`, `ri_price_movers`,
`ri_ingredient_demand`, `ri_projected_stock`, `ri_suggested_order`, `ri_count_variance(count)`, `ri_unmapped_plus(site)`,
`ri_pos_health(site)`, `ri_forecast_accuracy(site)`, `ri_purchase_orders_open(site)`, `ri_find(q)` (one search: an
ingredient, a recipe, a menu item, a supplier item by name, code, PLU, SKU) — so a screen, a tool, the export and a share
never disagree. The forecaster itself is Python in the worker (pure Python Holt-Winters; no NumPy needed at a site's
scale: a few hundred items × a year of days), writing `item_forecasts`; everything after it is SQL.

**Activity memory**: `activity_log` through one `log_activity()`, `entity.verb` events, shipped as `activity` episodes
tagged `restaurant_inventory`. No audit table: a record's history is the activity log by entity; a cost's history is
`price_history`; a quantity's history is the ledger. A credential and a POS check's raw payload are never in a log
payload. Events: `ingredient.create|update|archive|delete|unit_set|par_set|cost_override`, `recipe.create|update|
version_draft|version_activate|version_retire|delete`, `menu_item.map|unmap|price_set`, `modifier.map`,
`supplier.create|update|archive|schedule_set`, `supplier_item.create|update|price_change`, `order_guide.set`,
`purchase_order.draft|suggest|send|place|supplier_view|supplier_ack|supplier_decline|supplier_date|receive|close|cancel`,
`receipt.draft|post|correct`, `invoice.create|line_set|match|dispute|settle|credit`, `price_alert.raise|seen`,
`source.create|update|credential_set|schedule_set|pull_start|pull_done|pull_fail|delete`, `sale.import`,
`depletion.run|void`, `plu.map|queue`, `stock.transfer_send|transfer_receive|adjust|produce|reverse`, `waste.log|
review`, `count.start|line_set|review|recount|approve|discard`, `period.open|close|reopen`, `forecast.run|override|
accuracy`, `watch.set|fire|clear`, `buyer.propose|accept|dismiss|note`, `report.run`, `export.download`, `share.read`,
`settings.save`, `token.mint|revoke`, `member.sign_on`, `directory.sync`, `screen.view`.

## 7. The question inventory — what Restaurant Inventory exists to answer

Each is a records tool (Phase 1 names them); the screens answer the same functions.

**Stock and cost.** What do we have of X at this site, by area, in its count unit and its base unit? What does X cost
today at this site, on which basis, and what did it cost on a date? What is our stock worth as of a date, by category and
site? What are the days on hand of X? Which costs moved this week, by how much, on which invoice? Which supplier is
cheapest for X in a comparable unit? **Recipes.** What does this plate cost today and what was it last period? Which
recipes use X, and how much of each day's demand for X is each? What is the food-cost % of every menu item, and which
are stars, plowhorses, puzzles and dogs this period? What changes if romaine goes to $X a case? What are the allergens of
this plate? Which recipe version was in force on a date? **Sales and depletion.** What sold yesterday, by item and
day part? Which PLUs sold with no recipe? Did yesterday's depletion run? What did yesterday's sales use of X?
**Usage and variance.** For the period, per ingredient: opening, purchases, transfers, production, waste, theoretical,
actual, variance, explained, unexplained — in units and at cost; by category; by site. Where did we lose the most money
last period? What is the actual and the theoretical food-cost % of the period? What was the last count's variance over
tolerance? Which areas were skipped? **Purchasing.** What should we order from supplier S for its next delivery, with
the arithmetic? What is on order and due when? Which POs are unacknowledged? Which deliveries are due today with no
receipt? Which invoice lines do not match? **Projection.** What will we sell of item M next week, and how sure is the
forecast? What will we use of X, and when do we run out at the current rate and on order? Can we run the special on
Friday with what is projected? How accurate was last month's forecast, by item? **Counts and waste.** What is open on
the count? What was wasted this week, by stage, reason and station, at cost? **Suppliers.** What do we buy from S, at what
price, and how has it moved? **Health.** Which sources failed, which sites had no sales where open, which periods are
open past their end?

## 8. The kernel's part, and the application's doors

- **Sign-on, the directory, sites, roles**: the kernel's; `scopes.kind = "location"`; `app_roles` publishes §3; the
  switcher lists the sites.
- **Agents**: hired and run by the kernel; the chat endpoint answers the command bar as the expert; the run-facts gate
  decides the tools; `money_out`, `deletion` and the §5 `other` actions pause.
- **K7 shares** (`shares[]`, provider side, every one `scoped`, each a records-server tool under the kernel's token with a
  CSV/JSON export twin):
  `purchases_received` (receipts at cost by supplier, site and period → the ledger's bills, `source = 'import'`),
  `stock_valuation` (on hand at cost as of a date by site and category → the inventory asset),
  `cogs_summary` (actual and theoretical usage at cost by site and category for a closed period, waste separated → COGS),
  `waste_summary` (by stage and reason), `sales_by_day` (net sales and covers per site, day and day part — for the GL's
  daily sales journal and for txtSchedules' actual covers), `ingredients_index` (names, units, categories — for Spaces
  and Help Desk to link), paged, ≤ 500 rows, `timeout_seconds` 20.
- **K7 reads** (`reads[]`, each `why` stated): `reservations.covers_by_service` — booked covers per service for the usage
  projection; degraded to none on `no_connection`.
- **K6 texts**: a watch may text its person (a projected stock-out before a delivery).
- **The doors**: `/s/<token>` the supplier's; `/api/v1/health`; `/mcp/records`, `/mcp/activity`; no feed of our own in v1.

## 9. Screens (the nxl look; counts, waste and receiving are designed at 375 px first — a cook in the walk-in; the recipe editor and the reports at 1280)

- **Home** (per site): projected stock-outs before the next delivery; suggested orders due today; unmapped PLUs; the
  last count's variance; price movers; open POs and deliveries due; the forecast's thin items; the period's status.
- **Ingredients**: cards by category with on hand, cost, days on hand; the ingredient page with units, suppliers' items,
  pars per site, cost history chart, the recipes that use it, the ledger, yield tests; create/edit; units admin.
- **Recipes**: cards by kind and category with cost per portion and food-cost %; the recipe page — the version in force,
  lines with the cost share of each, the tree expanded for sub-recipes, the method, allergens, the versions' history; the
  editor (a line picks an ingredient or a recipe through the record picker, in any unit, the conversion shown); "what if"
  a price changes; the menu map (PLU → recipe; modifiers → modifier recipes) with the unmapped queue; menu engineering.
- **Stock**: on hand by area; receive (against a PO or a delivery note, on a phone, catch weight and temperature);
  transfer; adjust; produce a prep batch; the ledger with filters; stock value.
- **Counts**: sheets (areas in walk order, drag to reorder); start a count; the count sheet at 375 px (one area at a time,
  the unit on the shelf, case + loose, the scale's gross weight for a bottle, zero, skip, blank-row prompt, kept without a
  signal); review with flags over tolerance; recount; approve; history.
- **Waste**: log at 375 px (stage, reason, item or plate, quantity or weight with tare, photo); the week by stage, reason,
  station; review.
- **Purchasing**: suppliers (schedules, items, order guides, terms); suggested orders (one card per supplier with the next
  cut-off and delivery, the drafted lines with need / have / on order / cap / rounding); purchase orders (draft, send,
  place, events, the supplier's door); receiving; invoices (lines, the match with differences, settle, credits); price alerts.
- **Sales**: sources (file mappings, SFTP, email intake, Square key), pulls, days imported, depletion runs (re-run), the
  day's sales by item and day part.
- **Usage**: actual vs theoretical for a period, by ingredient with every column, by category, by site; the period list
  (open, close, reopen); COGS and food-cost % by period; days on hand; the waste bucket.
- **Projection**: the forecast per item (chart with the band and the actuals), overrides, closures and holidays, booked
  covers, accuracy by item; ingredient demand and projected stock per day with the stock-out marked.
- **Reports and exports**: every report with CSV/JSON; the three ledger documents.
- **Admin**: settings (cost basis, thresholds, blind counts, the costing calendar, the day parts), sites, sources'
  credentials, MCP tokens, the agents' settings. **Me**: my settings, notifications, tokens, my trail.

## 10. Build order, gates and size

| Phase | Deliverable | Gate |
|---|---|---|
| 0 | this document and the owner's answers (§13); the schema `db/001`–`0NN` proven on a scratch database (`db/proof/phase0_proof.sql`: every actor of §2 at two sites; units and an ingredient in three classes with a density and an each-weight; a case never one each; a supplier item in cases, a receipt in cases landing in grams at the right cost and advancing the average; catch weight recalculating the delivered cost; a recipe tree three deep with a prep recipe and a modifier, a cycle refused, a version activated with effective dates; `ri_recipe_cost` as of two dates; a POS day imported, a PLU unmapped and queued, the depletion run posting one row per ingredient, a void not depleting, a comp depleting; waste at the plate stage exploding; a transfer between sites; a prep batch posted; a count with case + loose, a bottle by weight, a skipped area, a line over tolerance, approval posting corrections; `ri_usage` tying for the period with every bucket; a period closed and a late receipt landing in the next; the forecast's SQL side; `ri_suggested_order` with every step on the line; cost nulled for a Viewer; a credential absent from every view); the kit (Inventory's/Data Lake's identity, roles, sign-on, MCP common files, HTTP helper, attachments, sync — with the mirror fix and the guard reading five refusal codes), proven without a kernel; `maludb-os.json` (ports in `env.required`, the six `shares[]`, the one `reads[]`); `os/{expert,buyer}.md`; twelve skills; `deploy/` with `ROOT_STEPS.sh`; the installer's `plan` clean | the owner's go on §13 |
| 1 | `docs/restaurant-inventory-mcp-tool-surface.md` (every question of §7 a tool; the share documents' shapes), `docs/restaurant-inventory-action-manifest.md`, `mcp/action_registry.json`, three build specs (`units-and-costing.md` — the conversion and roll-up rules; `pos-and-depletion.md` — the file mappings, the Square connector, the run; `projection-and-ordering.md` — the forecaster, the explosion, the periodic-review rule, step by step with worked numbers), and the slice specs in `docs/build-specs/` (`sso-shell` and nine slices; **slice 2 recipes and costing and slice 5 the POS and depletion are the exemplars; slice 8 projection is the third**) | **approved together, before any PHP** |
| 2 | `/sso`, the mirror and its timer, the sites and the switcher, `ri_has_right()`, `ri_sees_cost()`, the ingest bridge, the shell (sidebar at 1280, the tab bar Home · Stock · Count · Order · Me at 375, the command bar), the record picker, `/api/v1/health`, the vhost | hand-off replay refused; a revoked grant shuts every door within a minute; a site not held is 404; cost hidden from a Viewer; 375 and 1280 |
| 3 | the slices, in order: **(1) ingredients, units, categories, suppliers and their items, storage areas, yield tests** (the CRUD exemplar for workers) → **(2) recipes, versions, sub-recipes, the menu map, modifiers, costing, menu engineering — THE FIRST EXEMPLAR** → **(3) the ledger: receiving (with and without a PO), transfers, adjustments, production, waste; stock on hand and value** → **(4) purchasing: schedules, order guides, purchase orders, send and the supplier's door, receiving against, invoices and the match, price alerts** → **(5) POS sources: file mappings, upload, email intake, SFTP, Square; imports; the PLU queue; depletion runs; sales by day — THE SECOND EXEMPLAR** → **(6) counts: sheets, the count at 375 px kept without a signal, bottle weights, review, recount, approval** → **(7) periods, `ri_usage`, actual vs theoretical, COGS, valuation, the close, the ledger's shares and exports** → **(8) projection and ordering: the calendar, the forecaster, overrides, covers from Reservations, accuracy, ingredient demand, projected stock, the suggested orders, the Purchasing agent's data and the worker's passes — THE THIRD EXEMPLAR** → **(9) reports, home, admin, tokens, the morning note's assembly** | each slice: screens + handlers + logging + manifest entries + tools, proven at 375 and 1280 on a scratch database |
| 4 | the two MCP servers with the run-facts gate, `app_roles` and the six shares, the registry wrapper, the two agents with their grants and the duty; the kernel's Actions-MCP contract proved with a stubbed hook | the command bar answers "what does the short rib cost today" and "draft Tuesday's Sysco order"; an agent's `purchase_order_send` pauses; the duty drafts and never sends |
| 5 | the owner's `app_install.php apply` (ports pinned first), DNS and TLS, the MaluMail key, the hires, the sites, the first catalog (a CSV of the distributor's order guide), the first recipes, the first POS file; the end-to-end proof | **a cook receives Tuesday's delivery on a phone with one case short and a 12.4 lb brisket; the POS's export lands by email and depletes the day; a bartender counts the well by weight in the walk-in with no signal and the sheet posts upstairs; the chef approves the count and the period closes with every bucket shown; the morning note drafts Saturday's order with need, have and rounding on every line and flags a projected stock-out of limes before Friday; the Manager sends it and the supplier acknowledges through the link; the ledger reads `purchases_received`, `stock_valuation` and `cogs_summary` over an approved connection** |

Size: eleven specs (sso-shell + nine slices + three build specs), about 90 screens, 140 actions, 75 records tools, 7
activity tools — Inventory's size with the recipes and the forecaster in place of the connectors. **Five days is the
honest estimate**: units, recipes and costing a day; the ledger and purchasing a day; the POS, depletion and counts a day;
periods, projection and ordering a day and a half; the rest and Phase 5's proof with the owner the remainder.

## 11. Extended, later, and what other applications owe

**Extended** (after v1, each its own spec): invoice OCR — the expert reads an invoice image through the kernel's model
credential (K24) and proposes the lines; EDI 850/855/856/810 and the distributors' APIs on the `sources` frame; GS1
barcode scanning at receiving (GTIN-14, GS1-128 lot and best-before) with lot and expiry tracking and a recall query;
Toast, Clover, Lightspeed connectors; nutrition from USDA FoodData Central rolled up like cost; prime cost from
txtSchedules' labour cost; the commissary-as-vendor pattern; weather and events as forecast features and a
gradient-boosted method behind the same accuracy table; standard cost with the price/usage variance split; a requisition
document between sites; menu price suggestions from a target food-cost %.

**What this design owes other applications**: nothing by way of writes; the shares of §8 for the GL, txtSchedules,
Spaces and Help Desk to read when they choose.

## 12. What the kernel, the plugin and the siblings owe this application

- **K47** — the catalog row: `restaurant_inventory`, "Restaurant Inventory", area Operations, category `inventory` (admitted
  already — nothing widened), `kind ours`, icon `feather-coffee`; `db/182` in the kernel.
- **K48** (optional) — the kernel's hire script reads an agent's per-site duty time zone (today the duty's cron is UTC;
  a per-site 06:00 needs the site's time zone — until then the duty runs at one UTC time and the note names every site).
- **G5** (the GL) — reads `purchases_received`, `stock_valuation`, `cogs_summary` and `sales_by_day` over an approved
  connection and posts them (bills per supplier with `site_id`, the 1400 inventory adjustment at close, 5000 COGS by
  site, the daily sales journal); the GL declares no `reads[]` today.
- **T2** (txtSchedules) — reads `sales_by_day` for actual covers when it wants actuals beside Reservations' bookings;
  and shares its labour cost by day for prime cost (Extended here).
- **R1** (Reservations) — nothing; `covers_by_service` is consumed as it is.
- **The plugin** (`shared-schema.md`) — the catalogue gains `units`/`ingredient_units` as the kit family's units, the
  `items` family's kitchen member `ingredients`, the kitchen recipe (`recipes`/`recipe_versions`/`recipe_lines` with
  sub-recipes), `pos_sales`/`pos_sale_lines`, `waste_logs`, `count_sheets`, `periods`, the forecast tables, and the
  measured-goods deviation of the Inventory ledger as the third member of that family.
- **S5 / HD6** (Spaces, Help Desk) — optional cards from `ingredients_index`.

## 13. Decisions the owner is asked for (recommendation first)

1. **Name, key, label, repository** — "Restaurant Inventory", catalog key `restaurant_inventory`, repository
   `maludb-os-restaurant-inventory` (not yet created; **public**, as Inventory and MaluMail), local clone and install path
   `/srv/apps/restaurant_inventory`, DNS label **`kitchen`** (`kitchen.<domain>` — `inventory.<domain>` is the retail
   application's; alternatives `foodcost`, `stock`), database `<tenant>_restaurant_inventory`, roles
   `restaurant_inventory_rw` / `_records_ro` / `_activity_ro`, SQL prefix `ri_`. Confirm, or choose the label and visibility.
2. **Roles** — Viewer (`viewer`, never cost), Kitchen (`user`), Chef, Manager, Restaurant Inventory admin (is_admin);
   **cost is Chef's and above**, Kitchen's when `kitchen_sees_cost` is on (default off). Confirm the words.
3. **Scoped by site; not a default; granted per person** — `scopes.kind = "location"`, every restaurant a kernel site,
   a commissary a site; ingredients, units, recipes and suppliers shared across sites, pars, costs, stock and prices per
   site; `grant_standing_departments: false`. Confirm.
4. **Perpetual balance, periodic close** — the ledger keeps a running balance moved by every document; an approved count
   corrects it; a period closes on its closing count and freezes the ending value and COGS. Every screen says which number
   it shows ("on hand (book)" vs "counted"). Confirm, or choose periodic only (purchases to COGS at once; a day less, and
   no projected stock between counts — which defeats the first goal).
5. **Units and the ledger's deviation** — measure classes, a global units table, per-ingredient purchase, count and
   recipe units, a density or each-weight only when a recipe crosses classes; every stored quantity numeric in the base
   unit; Inventory's canonical ledger reused with that one recorded deviation and proposed back to the catalogue. Confirm.
6. **Recipes** — three kinds (menu item, prep, modifier), sub-recipes to any depth (cycles refused), versioned with
   effective dates, yield on the ingredient (cost) and on the line (depletion), **cost computed as of a date and never
   stored on the version**, weighted average the basis for valuation and variance, last price on the card by a toggle.
   Confirm, or add standard cost in v1 (half a day; the price/usage split).
7. **The POS door of v1** — a `file` source (upload, email intake at MaluMail, SFTP) with mapping templates for Square,
   Toast, Clover, Lightspeed and a generic shape, plus the **Square** API connector (an open key); Toast, Clover and
   Lightspeed API connectors Extended on the same interface. Depletion **one run per site per business date, one ledger
   row per ingredient per area per date**, re-runnable; voids never deplete, comps always do. Confirm, or drop Square to
   Extended (file only — saves half a day).
8. **The forecast of v1** — per menu item per site per day: the same-weekday weighted four-week average as the baseline
   and the floor, Holt-Winters additive with a weekly cycle when twelve weeks exist, closures and holidays on the site's
   calendar, overrides by a person, booked covers from Reservations as a factor when they differ from the weekday's
   norm by more than 15 %; accuracy kept as MAE, WAPE and bias; pure Python in the worker, no new dependency. Confirm.
9. **The reorder rule of v1** — per ingredient per site `par_mode` **fixed** (Craftable's rule: par − on hand − on order,
   rounded up to the pack by at most 20 %) or **dynamic** (the periodic-review rule: Σ demand over the protection interval
   + safety stock at the ingredient's service level, perishables capped by the newsvendor fractile over shelf life);
   **default dynamic once four weeks of history exist, fixed before**; the suggested order one drafted PO per supplier
   with every step on the line; never placed automatically. Confirm the default.
10. **Receiving and invoices** — receive on a phone against a PO or a delivery note in the purchase unit, catch weight
    declared, the temperature check optional per category; invoice lines typed by a person with the attachment; the
    three-way match with per-supplier tolerances; a settled difference writes price history; price alerts at a threshold
    per category (default 10 %); OCR Extended. Confirm.
11. **Counts** — sheets per storage area in shelf order; the count at 375 px kept in the browser until posted; case +
    loose and decimals; bottle and keg weights against the container's full and empty weights; blind by a setting
    (default on); tolerance per ingredient or category → recount → a Chef's approval posting the corrections. Confirm.
12. **Waste, production, transfers, the commissary** — the waste log in Leanpath's shape (stage × reason, station, day
    part, weight with tare, photo), a prep batch posted as production, transfers between areas and sites at the sender's
    average cost, a commissary as a site; the transfer-becomes-a-bill pattern Extended. Confirm.
13. **Periods and the ledger** — a costing calendar per business (month, 4-4-5 variants, 13 × 4, or week; default
    **week** with a monthly roll-up), a late receipt into the next open period flagged; the six shares of §8; the GL's
    G5 owed; no write to the books. Confirm the default calendar.
14. **Two shipped agents, hired on install** — the expert and the **Purchasing agent** (`buyer`; the seven-heading
    morning note per site at 06:00, to the Manager you name — the first super-admin until then — and `#kitchen` in Spaces
    when installed). Confirm, and name the Manager.
15. **What pauses for an agent** (§5): sending or placing a PO (`money_out`); a message to a supplier (`external_send`);
    approving a count, closing or reopening a period, settling a match, activating a recipe version (`other`, pause by
    default); deleting, cancelling, adjusting without a document, voiding a depletion run (`deletion`); cost overrides,
    pars, thresholds, sources and credentials, settings (`other`). Everything else an agent does alone. Confirm.
16. **Ports and the division of work** — `APP_INTERNAL_PORT=8200`, `MCP_RECORDS_PORT=8856`, `MCP_ACTIVITY_PORT=8857`
    pinned before `apply`, scratch 9301–9307; the Spaces division — a planning-class model builds K47, the rest of
    Phase 0, Phase 1 for your approval, Phase 2, slices 1, 2 (the first exemplar), 3 and 5 (the second exemplar), and
    writes slice 8's spec with worked numbers; a worker model (Sonnet 5.5) builds slices 4, 6, 7, 8, 9, Phase 4 and
    Phase 5 from the specs, each verified here. Confirm, or have the planning model build slice 8 too (the third
    exemplar — half a day more).

## 14. Ports, names and files

- Repository `/srv/apps/restaurant_inventory` (origin `github.com/maludb/maludb-os-restaurant-inventory` once created);
  database `<tenant>_restaurant_inventory`; roles `restaurant_inventory_rw`, `_records_ro`, `_activity_ro`; MaluDB: the
  tenant's one memory, episodes tagged `restaurant_inventory`.
- `kitchen.<domain>` (`vhost.label = "kitchen"`, D1); `APP_INTERNAL_PORT`, `MCP_RECORDS_PORT`, `MCP_ACTIVITY_PORT` in
  `env.required`, written to `config/.env` before `apply`; `deploy/` files are templates.
- Units: `restaurant-inventory-records-mcp`, `-activity-mcp`, `-activity-ingest` (+ timer), `-directory-sync` (+ timer),
  `-worker` (+ timer, every minute: due POS pulls and the email intake, depletion runs for days with new sales, the
  forecast pass nightly per site, suggested orders ahead of each cut-off, price alerts, watches, the outbox, dispatches,
  link expiry, the morning note's data at the duty's hour).
- `maludb-os.json`: `catalog_key restaurant_inventory`, `name "Restaurant Inventory"`, `business_area "Operations"`,
  `category inventory`, `icon feather-coffee`, `criticality medium`, `vhost.label kitchen`, `scopes {kind: location}`,
  `sso`, `directory {reads: true, writes: false}`, `assistant {command_bar: true, agent: expert}`, `agents [expert, buyer]`
  each `hired_on_install: true` with the duty, `grant_standing_departments: false`, `approvals` (§5), endpoints (web,
  health, the supplier door, records, activity), services, `shares` (six, scoped, `timeout_seconds: 20`), `reads`
  (`reservations.covers_by_service`).
- Env: the contract's required keys plus `RI_SECRETS_KEY` (source credentials sealed under it), `MALUMAIL_API_KEY`,
  `MAIL_FROM`, `MAIL_FROM_NAME` (the installer's `mail` step), `RI_INTAKE_MAILBOX` (the POS email intake, optional),
  `ATTACHMENT_MAX_BYTES`, `RI_PUBLIC_BASE_URL`, `RI_MANAGER_EMAIL` (seeds the per-site Manager — D14).
- Vhost allow-list (public): `/s/`, `/api/v1/health`, `/mcp/records`, `/mcp/activity`.
- Kernel files: `mcp/registries/restaurant_inventory.json` (made by the installer), `db/182` (K47).
- Proof scratch ports: 9301–9307. Fixtures: `tests/fixtures/pos/` — one trimmed export per POS shape, synthetic.

## 15. The owner's answers

**2026-10-11 — the owner accepted every recommendation, D1–D16 as written**, and asked for the repository to be created:
`github.com/maludb/maludb-os-restaurant-inventory`, public, created the same day. So: "Restaurant Inventory", key
`restaurant_inventory`, label `kitchen`; Viewer / Kitchen / Chef / Manager / admin with cost Chef's and above; scoped by
site, not a default; perpetual balance with a periodic close; measure classes and the ledger's measured-goods deviation;
three recipe kinds with sub-recipes, effective-dated versions, cost as of a date, weighted average; the file source with
mapping templates plus Square, one depletion run per site per day; the baseline and Holt-Winters forecast with covers as a
factor; dynamic par once four weeks of history exist; receiving and typed invoices with the match; counts kept in the
browser, blind by default; Leanpath's waste; weekly periods with a monthly roll-up; two agents hired on install (the
Manager named at install through `RI_MANAGER_EMAIL`, the first super-admin until then); the pauses of §5; ports
8200/8856/8857; the Spaces division of §13.16.

## 16. State

- 2026-10-11 — the research written (three reports and the synthesis, commit 116ff7b); this design written the same day;
  **approved by the owner the same day, every recommendation (§15)**; the repository created public and pushed.
- 2026-10-11 — **K47 built and applied in the kernel** (db/182: the catalog row under Operations, category `inventory`, nothing widened).
- 2026-10-11 — **PHASE 0 COMPLETE.** The kit (db/001–006, app/, bin/, html/, mcp/, tests/) — MaluMail's kit made site-scoped the way
  txtSchedules is, the five roles with rights per site and `ri_sees_cost()`, proven without a kernel by `tests/phase0/run.sh`
  (123 checks + the schema proof). The schema db/007–017 (90 tables, 71 `mcp_*` views) proven by `db/proof/phase0_proof.sql`
  (164 checks, `tests/phase0/schema.sh`): every actor at two sites; three measure classes with a density and an each-weight;
  a case never one each; a receipt in bags landing in grams and advancing the average; catch weight at the declared pounds;
  a recipe tree with a prep sub-recipe and a modifier, a cycle refused, versions with effective dates (today v1, tomorrow
  v2), the plate cost with the prep at its batch cost net of loss; production making stock at the rolled-up cost; a POS day
  with a void (not depleted), a comp (depleted), an unmapped PLU queued, one depletion row per ingredient, a re-run reversing
  the first; waste at the plate stage exploding; a two-line transfer between sites at the sender's average; a PO sent,
  acknowledged and a line declined through the door, received short, closed short; an invoice over tolerance settled into
  price history; a blind count with case + loose, a bottle by weight, a skipped area, two lines over tolerance recounted by
  another person, approval posting four corrections; `ri_usage()` tying with every bucket; weekly periods, a close, a late
  receipt into the next open period, a reopen; the baseline forecast, a closure, an override, demand exploded, a projected
  stock-out, the suggested order with fixed and dynamic pars and the arithmetic on the drafted line; a watch firing once;
  cost and site as walls in the views; the read roles writing nothing. `maludb-os.json` (six scoped shares, one read, 25
  approvals, two agents), `os/`, twelve skills, `deploy/` with `ROOT_STEPS.sh`; the installer's `plan` reads the repository
  clean (37 steps, 8 notes). **Found and fixed along the way:** the ledger's after-trigger cleared the balance-writer flag a
  posting function held across several lines (it restores the previous value now); a sent PO's lines must still take the
  supplier's and the receipt's updates; a count taken at the end of the day must be as of the sales cut, or the day's
  depletion (dated at the cut) is outside its book figure — the proof does so and the count screen should default to it.
- 2026-10-11 — **PHASE 1 WRITTEN, for the owner's approval.** `docs/restaurant-inventory-action-manifest.md` (119 screens, 146
  actions in ten sections, 25 agent-approval categories = `maludb-os.json` `approvals[]`, synced; the registry built clean),
  `docs/restaurant-inventory-mcp-tool-surface.md` (about 115 records tools — the expert's 83 by name, `app_roles`, the six
  shares under the kernel's token, the resolvers —, 4 activity tools; cost as the fn/view/none wall per site; no bridge to PHP),
  three build specs (`units-and-costing`, `pos-and-depletion`, `projection-and-ordering`) and ten slice specs (`sso-shell` and
  slices 1–9: `catalog`, `recipes`, `ledger`, `purchasing`, `pos`, `counts`, `periods`, `projection`, `reports-admin`) — every
  manifest row claimed by exactly one spec (`tests/phase1/check_specs.sh`, 52 checks green). **db/018** carries what the documents
  found the schema owed, in one additive migration: the read functions SECURITY DEFINER with `ri_require_reader(scope)` so Phase 4's
  records role can call them (the first finding — db/017 had granted it the views only); the missing reads (`ri_recipe_what_if`,
  `ri_recipes_using`, `ri_sales_by_item`, `ri_variance_top`, `ri_invoice_mismatches`, `ri_watch_events` + `watch_events`,
  `ri_morning_note` as one gatherer, `ri_share_ingredients_index`, `ri_count_line_add`, `ri_find` with kinds); `menu_items.no_depletion`
  (the queue and the run skip it); the preferences' display units and default area; `agent_site_settings` and
  `ri_settings.forecast_method`; the four vendor mapping templates (columns to confirm against real exports in Phase 5);
  `ri_po_supplier_event(…, p_source, p_member_id)`. The schema proof is 179 checks, the kit's 124; the installer's plan clean
  (49 steps). **Decisions the specs took, for the owner with Phase 1:** POS connectors live in PHP under `app/pos/`; the forecaster
  is `bin/forecast.py` (pure Python) shelled from the worker's pass; a no-depletion PLU is a flag, never a lineless recipe;
  `recipe_create` makes the recipe and its first draft in one POST; the morning note to `#kitchen` in Spaces stays Extended
  (the text goes in the notification); a count after the day's count is as of a moment after the sales cut (its corrections
  are dated at the cut — the proof's second lesson on counts); `watch.fire` is logged by the worker's pass. NEXT: the owner's
  approval of Phase 1 → Phase 2 the shell.
