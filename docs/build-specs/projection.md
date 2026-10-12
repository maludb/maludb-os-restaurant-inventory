# Build spec: projection, ordering, the Purchasing agent's data and the worker — THE THIRD EXEMPLAR (slice 8)

Built by the worker model from this spec and its companion build spec `docs/build-specs/projection-and-ordering.md` (the forecaster,
the explosion and the periodic-review rule with worked numbers — read it first; nothing of that arithmetic is repeated here), replicating
slice 2's and slice 5's files, gates and proof style. What exists at the end: the **forecast** per menu item for the next three weeks —
the baseline from SQL on day one, Holt-Winters from the worker's nightly pass once twelve weeks exist — with its band, its accuracy and
the overrides a person sets; the site's **trading calendar** (closures, holidays, events) and the **booked covers** read from Reservations
through K7; **ingredient demand** exploded through the recipes; **projected stock** per ingredient per day with the stock-out marked; the
**suggested orders** — one card per supplier with its next cut-off and delivery and every line's need, have, on order, cap and rounding —
and **Draft the PO**; the **special check** ("can we run 60 portions of the braise on Friday?"); the Purchasing agent's **proposals** with
Accept and Dismiss; **watches**; and the worker's passes that make the morning note possible. The application proposes and drafts; a
person sends (slice 4's `purchase_order_send`, `money_out`).
Schema: `calendar_days`, `forecast_runs`, `item_forecasts`, `forecast_overrides`, `forecast_accuracy`, `covers_expected`,
`ri_forecast_baseline()`, `ri_covers_factor()`, `ri_item_forecast()`, `ri_ingredient_demand()`, `ri_projected_stock()`, `ri_stockout_on()`,
`ri_z()`, `ri_suggested_order()`, `ri_suggested_order_draft()`, `ri_suppliers_due()`, `ri_forecast_accuracy_compute()` (db/014);
`ri_next_order_windows()`, `supplier_schedules`, `order_guides`, `ingredient_site_settings` (db/007); `ri_on_order()` and the PO drafted
(db/010); `sales_daily`, `ri_depletion_due()`, `ri_depletion_run()` (db/011); `watches`, `ri_watch_state()`, `ri_watches_fire()`,
`buyer_proposals`, `agent_dispatches`, `ri_notify()`, `notification_outbox` (db/015); `ri_stockouts_projected()`, `ri_price_movers()`,
`ri_pos_health()`, `ri_unmapped_plus()`, `ri_count_variance()`, `ri_purchase_orders_open()`, `ri_deliveries_due()` (db/016 — the morning
note's gatherers); `ri_explode()`, `ri_recipe_version_on()` (db/008); the views `mcp_calendar_days`, `mcp_forecast_runs`,
`mcp_item_forecasts`, `mcp_forecast_overrides`, `mcp_forecast_accuracy`, `mcp_covers_expected`, `mcp_watches`, `mcp_buyer_proposals`,
`mcp_agent_dispatches`, `mcp_menu_items`, `mcp_sales_daily`, `mcp_suppliers`, `mcp_supplier_schedules`, `mcp_supplier_items`,
`mcp_purchase_orders`, `mcp_purchase_order_lines`, `mcp_ingredients`, `mcp_ingredient_site_settings`, `mcp_ingredient_site_costs`,
`mcp_activity_log` (db/017). Never modify them. **The database is the referee**: the forecast of an item on a day is `ri_item_forecast()`
(the run's row, else the baseline; the override; the covers factor) — PHP never averages a history; demand is `ri_ingredient_demand()`;
the suggested order is `ri_suggested_order()` row by row with every step on the row, and `ri_suggested_order_draft()` writes the PO — a
draft, never sent; a watch fires once per state change (`ri_watches_fire()`); a closed day forecasts zero; an override's factor and
quantity are one or the other (the CHECK).

## Divergences from the design and the manifest
1. **The forecaster is Python in the worker, the rest SQL** (design §6.9): `bin/forecast.py` (the companion spec's) writes `forecast_runs` +
   `item_forecasts`; `bin/worker.php`'s `forecast_pass` calls it per site through `proc_open` with the site's DSN from the environment
   (`mcp/venv`'s python, no new dependency — pure Python Holt-Winters). Until a run exists, every screen and tool reads the baseline through
   `ri_item_forecast()` and says "baseline" (DECISION 1).
2. `suggested_order_run` with `draft = no` only computes (the screen's refresh); with `draft = yes` it drafts one PO per supplier due — the
   screen's **Draft the PO** per card posts `supplier` + `draft = yes`; **Draft all due** posts `draft = yes` alone. The manifest's row stands;
   the two buttons are one action (DECISION 2).
3. `morning_note_send` assembles the note from the day's `buyer_proposals` (made by the agent's duty through `buyer_propose`) and from the
   gatherers when the agent made none — so the note exists even when the kernel's agent did not run (DECISION 3, the design's "the morning
   note's data" is the worker's `morning_note_inputs` pass, slice 9's — reconciled: that pass only cuts the inputs; this slice's action sends).

## Screens (375 px usable; the forecast item, projected stock and suggested orders designed at 1280 px; the proposals and watches at 375 px first — a Manager on a phone at 06:30)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `forecast-list` | `/projection/?category=&horizon=&sort=` | the table `forecast-table` (`forecast-row-{menu_item}`): menu item (a link), category, the next 7 days as small numbers (`ri_item_forecast` per day — the source chip: run · baseline · override), the 21-day total, the band (lo–hi when a run exists), **WAPE** and **bias** from `mcp_forecast_accuracy` (horizon 7, window 28), a `thin` chip when fewer than `dynamic_par_after_weeks` weeks of history; the header: the current run (method, made at, items) or "baseline — no run yet", **Run the forecast now** (`forecast_run`, `forecast.adjust`); sort by total, WAPE, name; `horizon` 7 · 14 · 21 |
| `forecast-item` | `/projection/items/{menu_item}` | the chart `forecast-chart` (an inline SVG: the last 8 weeks' actuals as bars, the next 3 weeks' forecast as a line with the band shaded, closures hatched, overrides marked — `forecast_chart_svg()`), the same-weekday table (the four weeks the baseline weighs), the overrides on this item (`override-row-{id}`, **Remove**), the proposed overrides awaiting a decision (**Set** / **Dismiss**), the covers factor per day when it differs from 1, the accuracy row; the **Override** form (`override-form`, `override-form-field-{name}`: from, to, factor or qty, reason) → `forecast_override_set`; `data-entity="menu_item"` |
| `calendar-days` | `/projection/calendar?month=` | a month grid (`calendar-day-{date}`): each day its closed flag, holiday, event, the booked covers (`mcp_covers_expected` summed), the forecast's total; a tap opens the day's form (`calendar-day-form`: closed, holiday, event, note → `calendar_day_set`); **Sync covers** (`covers_sync`, `forecast.adjust`) with the last sync's time; a legend |
| `demand-report` | `/projection/demand?from=&to=&category=&ingredient=` | the table `demand-table` (`demand-row-{ingredient}`): ingredient, category, the window's demand per day in the count unit (the base in a title) and the total; the drivers under a tap (`demand_drivers()`: the menu items × portions that make it, share %); the window a week by default, ≤ 28 days |
| `projected-stock` | `/projection/stock?category=&days=&stockouts_only=` | the table `projected-table` (`projected-row-{ingredient}`): ingredient, on hand (count unit), 14 day cells of projected on hand (`ri_projected_stock`), the receipts due marked `+`, the first negative cell `bg-danger-subtle` with the date, days until stock-out; `stockouts_only` filters to `ri_stockouts_projected`; the par shown when set |
| `projected-ingredient` | `/projection/stock/{ingredient}` | one ingredient: the chart `projected-chart` (SVG: projected on hand by day, receipts as steps, zero line), the open PO lines due (`mcp_purchase_order_lines`), the demand's drivers, the par and its mode, the suggested order line that covers it (from `ri_suggested_order` for its preferred supplier), **Watch this** (`watch_set` prefilled) |
| `suggested-order-list` | `/purchasing/suggested?supplier=&due_hours=` | **the suggested orders**: one card per supplier due within `due_hours` (36 by default; a select 24 · 36 · 72 · 168) — `supplier-card-{id}`: the supplier, the next cut-off (site time, "in 5 h"), the delivery date, the protection interval, the minimum order value and whether the draft reaches it; the lines table (`sugg-line-{supplier_item}`): ingredient, par mode chip (fixed · dynamic), **need** (demand + safety, or the par), **have** (on hand + on order, each in a title), **cap** (the perishable cap when any), raw, the units in the purchase unit, unit cost (walled), line value (walled), the rounding note, a `thin` chip; the card's total (walled); **Draft the PO** (`suggested_order_run` with `supplier`, `draft = yes`) → lands on the PO; **Draft all due** in the header; a supplier with a PO already drafted today shows the link instead ("drafted 06:02 — PO-00044"); **Recompute** (`draft = no`) |
| `special-check` | `/projection/special?recipe=&date=&portions=` | the form (`special-form`: recipe through the picker, date, portions) → the table `special-table` (`special-row-{ingredient}`): ingredient, needed (the explosion × portions, count unit), projected on hand that day, the shortfall, the supplier that can bring it by then (`ri_next_order_windows` per supplier item: the first whose delivery ≤ the date) and its cut-off; the verdict line "Yes — everything covered" / "Short 4.2 kg of short rib; Sysco by Thursday if ordered by Wednesday 13:00"; **Draft what is short** (`purchase_order_draft` with the short lines — slice 4's action; DECISION 4) |
| `proposal-list` | `/projection/proposals?date=&kind=&status=` | the Purchasing agent's day (`proposal-card-{id}`), grouped by the note's seven headings in order (orders · stock-outs · prices · the POS · the count · the forecast · overdue POs and deliveries): title, the detail rendered by kind (`proposal_detail_html()`), the drafted record's link, **Accept** / **Dismiss** (`buyer_proposal_decide`); the day picker; **Send the morning note** (`morning_note_send`, `purchasing.draft`) with the last send's time; the acceptance rate for the last 30 days |
| `watch-list` | `/projection/watches` | the table (`watch-row-{id}`): ingredient, kind chip, threshold, agent, text me, last fired, fired count, active; mine for everyone, every member's for an admin; the form (`watch-form`, `watch-form-field-{name}`: ingredient picker, kind, threshold (shown for cost kinds), agent (select of agents here), text me, note) → `watch_set`; **Clear** (`watch_clear`) |

## The forms
| Form | Field | Input | Required | Rule |
|---|---|---|---|---|
| `override-form` | menu_item | hidden (the item's page) or the picker (every item when blank) | no | |
| | from_date, to_date | dates | yes | to ≥ from; within 92 days |
| | factor | number, 2 decimals | one of | ≥ 0 (0 = none); 1.5 = +50 % |
| | qty | number | the two | ≥ 0 — a fixed quantity per day |
| | reason | text ≤ 200 | yes | |
| `calendar-day-form` | on_date | hidden | yes | |
| | closed | checkbox | no | a closed day forecasts 0 and is excluded from training |
| | holiday, event | text ≤ 80 | no | |
| | note | text ≤ 200 | no | |
| `special-form` | recipe | the picker (menu-item or prep recipes) | yes | |
| | date | date | yes | today .. +28 days |
| | portions | number | yes | 1..10,000 |
| `watch-form` | ingredient | the picker | yes | |
| | kind | select below_par · stockout_projected · cost_above · cost_moved | yes | |
| | threshold | number | for cost kinds | `cost_above`: a cost per recipe unit (converted to per base by the handler); `cost_moved`: a percentage |
| | agent | select (agents admitted here) + none | no | |
| | text_me | checkbox | no | K6 — the kernel keeps the opt-out |
| | note | text ≤ 200 | no | |
| `suggested-form` | supplier | hidden per card | no | |
| | due_hours | select | no | 36 by default |
| | draft | hidden yes / no | no | |

## Posting, step by step (what each handler calls)
- **Run** `forecast_run` → `run_forecast(site, method, horizon, history)`: `method = baseline` writes a run whose rows are
  `ri_forecast_baseline()` per item per day (SQL, one INSERT … SELECT; items with sales in the last 84 days); `holt_winters` calls
  `bin/forecast.py --site --horizon --history` (the companion spec) which writes the run and its rows and marks it current (the previous
  run's `is_current` false in the same transaction); then `ri_forecast_accuracy_compute(site, 28)`. Log `forecast.run` (`run_id`, `method`,
  `horizon_days`, `history_days`, `items`, `seconds`) with `scope_id`.
- **Override** `forecast_override_set` → INSERT `forecast_overrides` status `set`; the CHECK "factor or qty, one of the two" mapped to words;
  `forecast_override_propose` (an agent's) → status `proposed`, and a notification kind `agent_drafted` to the site's Manager
  ("the Purchasing agent proposes +50 % on the Chicken Sandwich for Oct 18 — the festival", dedupe `override:<id>`);
  `forecast_override_decide` → status `set` or `dismissed` (a person's); `forecast_override_remove` → DELETE a `set` one. Log
  `forecast.override|override_propose|override_decide|override_remove` (`override_id`, `menu_item_id`, `from_date`, `to_date`, `factor`,
  `qty`, `reason`, `decision`).
- **The calendar** `calendar_day_set` → INSERT … ON CONFLICT UPDATE `calendar_days`. Log `calendar.day_set` (`on_date`, `closed`,
  `holiday`, `event`).
- **Covers** `covers_sync` → `kernel_read('reservations', 'covers_by_service', {location_id: the site's kernel location, from, to})` through
  `app/api/kernel.php` (K7; the connection a super-admin approved); the answer's rows `{date, service, reservations, covers}` mapped to
  `day_parts` by `service_name` (unmatched services summed into the day's `day_part_id NULL` row); `covers_expected` rows written with
  `source = reservations` (ON CONFLICT UPDATE); `no_connection` / an error → a 200 with `did: "Reservations is not connected — the forecast
  runs without covers"` and the notice on the calendar (DECISION 5); ≤ 62 days a call (the share's cap), two calls for a longer window.
  Log `forecast.covers_sync` (`from`, `to`, `days`, `covers`, `connected: bool`).
- **Suggested orders** `suggested_order_run` → for `supplier` given: `ri_suggested_order(site, supplier, now())` (the screen's rows) and,
  with `draft`, `ri_suggested_order_draft(site, supplier, me, 'suggested')` → the PO id (NULL when every line is covered — the reply says
  "Nothing to order from Sysco before Tuesday"); for no `supplier`: every supplier of `ri_suppliers_due(site, due_hours)`, one draft each;
  **a supplier with a draft PO of origin `suggested` or `agent` made today is skipped** (`suggested_po_today()`; DECISION 6) so a re-run never
  doubles an order; the person's draft from the screen has origin `suggested`, the agent's duty `agent`. Log `purchase_order.suggest`
  (`supplier_id`, `cutoff_at`, `delivery_on`, `lines`, `drafted_po_id`, `skipped: bool`, `due_hours`) with `scope_id`, `purchase_order_id`.
- **Watches** `watch_set` → INSERT/UPDATE `watches` (`member_id` = the caller; `threshold` for `cost_above` converted from the recipe unit to
  per base through `ri_to_base`), `last_state` = `ri_watch_state()` at once so a condition already true fires on the next change, not now
  (DECISION 7); `watch_clear` → `active = false`. Log `watch.set|clear` (`watch_id`, `ingredient_id`, `kind`, `threshold`, `agent_member_id`,
  `text_me`).
- **Proposals** `buyer_propose` → INSERT `buyer_proposals` (`proposed_by` = the caller — the agent; `note_date` today in site time; the kernel's
  `agent_run_request_id()` kept in `detail.request_id`); `buyer_proposal_decide` → `status`, `decided_by`, `decided_at`; accepting an `order`
  proposal whose drafted PO exists lands on the PO (the person sends it there — slice 4); accepting a `forecast` proposal calls
  `forecast_override_decide(set)` on its override. Log `buyer.propose|decide` (`proposal_id`, `kind`, `subject_type`, `subject_id`,
  `drafted_record_type`, `drafted_record_id`, `decision`).
- **The morning note** `morning_note_send` → `morning_note_assemble(site, date)` (below) → `ri_notify()` kind `morning_note` to the site's
  Manager (`site_settings.manager_member_id`, else every member holding `purchasing.send` at the site, else the first super-admin — the
  design's chain), dedupe `note:<site>:<date>` (one send a day; a second send answers "already sent at 06:02" unless `force` — not a manifest
  param: the second send is a 200 with the earlier time, DECISION 8); to `#kitchen` in Spaces when installed (`kernel_read('spaces',
  'channel_post', …)` — **Extended until Spaces publishes a post share; v1 writes the Spaces line as a notification body only**, DECISION 9).
  Log `buyer.note` (`date`, `headings` the counts per heading, `recipients`, `proposals`).

## The morning note, assembled (`morning_note_assemble(PDO, int $scopeId, string $date): array`)
Seven headings in the design's order, each `{heading, count, lines[], link}`; a heading reads the day's `buyer_proposals` of its kind when
any exist (the agent's words) and the gatherer otherwise:
1. **Suggested orders** — `ri_suppliers_due(site, 36)` × `ri_suggested_order()` summarised per supplier (lines to order, the total walled
   in the note? — **the note carries values**: it goes to the Manager, who sees cost; a recipient without cost gets "—", DECISION 10),
   the drafted PO's number; flagged `thin` when any line has thin history.
2. **Stock-outs projected** — `ri_stockouts_projected(site, 7)` with the day.
3. **Prices** — `ri_price_movers(site, 1)` over the threshold and the open `price_alerts`; contract vs invoice drift from
   `invoice_mismatches()` (slice 4's query) when any.
4. **The POS** — `ri_pos_health(site)`: pulls failed, days missing where the site was open, unmapped PLUs (`ri_unmapped_plus`), unmapped
   modifiers, pending runs.
5. **The last count** — the latest approved count's `ri_count_variance()` rows over tolerance, by value; the areas skipped.
6. **The forecast** — items whose WAPE worsened by more than 0.1 against the previous accuracy row (kept in `forecast_accuracy` history?
   — **no history table**: the pass compares with the row before it overwrites and writes `detail` into a `forecast` proposal; DECISION 11),
   closures and holidays in the next 14 days with no override.
7. **Purchase orders** — `ri_purchase_orders_open(site)` unacknowledged past 2 days, `ri_deliveries_due(site, today)` with no receipt.
The note's text is one Markdown document (`morning_note_markdown()`), ≤ 4,000 characters, headings with counts, each line a sentence with a
link to the screen; the email through the outbox; the in-app notification carries the Markdown.

## The worker's passes (`bin/worker.php`, one pass a minute from `restaurant-inventory-worker.timer`; the advisory lock; `$GLOBALS['__public_door'] = 'cron'`; `--passes=` runs the named ones whether due or not; `--limit`; `RI_WORKER_NOW` outside production)
| Pass | Every | Does (`worker_pass_<name>(PDO, int $limit, DateTimeImmutable $now): array`) |
|---|---|---|
| `pos_pulls`, `email_intake`, `depletion_runs` | minute | **slice 5's** — untouched here; `depletion_runs` runs `ri_depletion_run()` for every row of `ri_depletion_due()` (≤ `$limit`), which this slice's passes rely on |
| `price_alerts`, `link_expiry` | — | **slice 4's** — untouched |
| `forecast_pass` | once a night per site, after 02:00 site time (`forecast_pass_due()`: no run made today in site time and the clock past 02:00) | per site with sales: `run_forecast(site, setting forecast_method, 21, 364)`; then `ri_forecast_accuracy_compute(site, 28)`; the forecast-worsened proposals (heading 6) written as `buyer_proposals` kind `forecast` with `proposed_by` the Purchasing agent's member id when hired (else NULL); answers `['sites' => n, 'items' => n, 'seconds' => s]` |
| `suggested_orders` | every 15 minutes (`now.minute % 15 = 0` or forced) | per site: for each supplier of `ri_suppliers_due(site, 36)` with no draft PO of origin `agent`/`suggested` today (`suggested_po_today()`): `ri_suggested_order_draft(site, supplier, agent member id or NULL, 'agent')` and a `buyer_proposals` row kind `order` (`subject_type supplier`, `drafted_record_type purchase_order`); a stock-out proposal per `ri_stockouts_projected(site, 7)` row not yet proposed today (`subject_type ingredient`, dedupe by day); answers `['drafted' => n, 'stockouts' => n]`. **The pass drafts; it never sends.** (DECISION 12: the pass does the agent's drafting so the note is ready at 06:00 whether or not the kernel's duty ran; the duty's turn reads the proposals and adds its words.) |
| `watches` | minute | `SELECT ri_watches_fire()` → `['fired' => n]`; the dispatches it inserted are slice 9's `dispatches` pass's |
| `notify_outbox`, `dispatches`, `morning_note_inputs` | — | **slice 9's** — untouched (the note's SEND is this slice's action, called by the agent's duty or by the `morning_note_inputs` pass at the site's duty hour when the agent is not hired — reconciled: slice 9's pass calls `morning_note_assemble()` + the send at `duty_hour` when `agents_here()` finds no Purchasing agent; DECISION 13) |
| `key_usage_prune` | minute | the kit's |
A pass's error is caught by the driver (`errors[]` with the sentence, never a body); the report is one JSON line and one `worker.pass` row.
**Idempotency**: `forecast_pass` once a night (the current run's `made_at` in site time), `suggested_orders` one draft per supplier per day
and one stock-out proposal per ingredient per day (`buyer_proposals` queried by `note_date` and subject), `watches` fires once per state
change (the function's `last_state`). `RI_WORKER_NOW` moves the clock for the proofs.

## Files (exactly these)
- `html/projection/index.php` (`forecast-list`) · `items/view.php` (`forecast-item`) · `calendar.php` (`calendar-days`) · `demand.php` (`demand-report`) · `stock.php` (`projected-stock`) · `stock/view.php` (`projected-ingredient`) · `special.php` (`special-check`) · `proposals.php` (`proposal-list`) · `watches.php` (`watch-list`)
- `html/projection/run.php` (`forecast_run`) · `override.php` · `override-propose.php` · `override-decide.php` · `override-remove.php` · `calendar/save.php` (`calendar_day_set`) · `calendar/covers.php` (`covers_sync`) · `watches/save.php` (`watch_set`) · `watches/clear.php` · `proposals/save.php` (`buyer_propose`) · `proposals/decide.php` · `proposals/note.php` (`morning_note_send`)
- `html/purchasing/suggested/index.php` (`suggested-order-list`) · `run.php` (`suggested_order_run`)
- `app/features/projection/{queries,present,write,handler,charts}.php` (`charts.php`: `forecast_chart_svg()`, `projected_chart_svg()` — inline SVG, the dataviz neutrals, no library) · `app/features/ordering/{queries,present,write}.php` (the suggested orders, the special check) · `app/features/agents/{queries,note,write}.php` (`note.php`: `morning_note_assemble()`, `morning_note_markdown()`; `queries.php`: `proposals()`, `agents_here()` is slice 9's — this slice adds `purchasing_agent_member_id(PDO): ?int`) · `app/features/watches/{queries,write}.php`
- `app/views/projection/{index,item,calendar,demand,stock,stock-item,special,proposals,watches,partials/forecast-row,partials/forecast-chart,partials/override-form,partials/override-row,partials/calendar-day,partials/calendar-day-form,partials/demand-row,partials/projected-row,partials/projected-chart,partials/special-row,partials/proposal-card,partials/watch-row,partials/watch-form}.php` · `app/views/purchasing/suggested/{index,partials/supplier-card,partials/sugg-line}.php`
- `bin/forecast.py` (the companion spec's — pure Python Holt-Winters additive m = 7, the baseline fallback, writes the run) · `bin/worker.php`: `worker_pass_forecast_pass()`, `worker_pass_suggested_orders()`, `worker_pass_watches()` made real (the stubs replaced; the other stubs untouched)
- `app/api/kernel.php` gains `kernel_read(string $app, string $tool, array $args): array` (K7's `POST /api/v1/apps/read.php` with the application token; `{ok, rows|answer, error}`; 20 s timeout) if Phase 2 did not add it — reconciled: Phase 2's shell adds `kernel_read()` for the switcher's sibling reads; this slice uses it (DECISION 14)
- `app/features/shell/nav.php`: `back_link()` learns `/projection/items/{id}` "the item's forecast", `/projection/stock/{id}` "the projection", `/purchasing/suggested` "the suggested orders"; `record_url()` learns `forecast_override`, `watch`, `buyer_proposal`
- `deploy/apache-restaurant_inventory.conf` and `tests/dev_router.php`: `/projection/items/{id}` → `items/view.php?id=`, `/projection/stock/{id}` → `stock/view.php?id=`, `/purchasing/suggested` → `suggested/index.php` before `/purchasing/{id}` (three lines)
- `tests/phase3/slice8/{run.sh,lib.php,forecast.php,calendar.php,demand.php,projected.php,suggested.php,special.php,proposals.php,watches.php,worker.php,json.php,browser.mjs}`

## Query functions (signatures fixed; PDO first; the site from `current_site_id()` unless given — the tools of Phase 4 call these)
- `forecast_list(PDO, int $scopeId, array $filters, int $horizon = 21): array` (items with sales in 84 days; per item the days' `ri_item_forecast`, the total, accuracy, thin) — tool `item_forecast` (one item) reads `item_forecast(PDO, int $scopeId, int $menuItemId, int $days = 21): array` (`{menu_item, days: [{on_date, qty, source, lo, hi}], accuracy, overrides, covers_factors}`) · `current_run(PDO, int $scopeId): ?array` · `same_weekdays(PDO, int $scopeId, int $menuItemId, string $onDate): array`
- `calendar_month(PDO, int $scopeId, string $month): array` (days with closed, holiday, event, covers, forecast total) — tool `calendar_days` · `covers_for(PDO, int $scopeId, string $from, string $to): array`
- `ingredient_demand(PDO, int $scopeId, string $from, string $to, array $filters): array` (`ri_ingredient_demand` pivoted by day + totals) — tool `ingredient_demand` · `demand_drivers(PDO, int $scopeId, int $ingredientId, string $from, string $to): array` (per menu item: forecast × explosion share)
- `projected_stock(PDO, int $scopeId, array $filters, int $days = 14): array` (`ri_projected_stock` per ingredient with a balance or a par; `stockouts_only`) — tool `projected_stock` · `projected_ingredient(PDO, int $scopeId, int $ingredientId, int $days = 14): array` (+ open PO lines, par, drivers, the covering suggested line) · `stockouts(PDO, int $scopeId, int $days = 7): array` (`ri_stockouts_projected`)
- `suggested_orders(PDO, int $scopeId, ?int $supplierId = null, int $dueHours = 36): array` (per supplier due: `ri_next_order_windows` + `ri_suggested_order` rows + totals + `drafted_today`) — tool `suggested_orders` · `suggested_order(PDO, int $scopeId, int $supplierId): array` — tool `get_suggested_order` · `suggested_po_today(PDO, int $scopeId, int $supplierId): ?int`
- `special_check(PDO, int $scopeId, int $recipeId, string $date, float $portions): array` (`{needs: [{ingredient, needed_base, projected_base, short_base, supplier, cutoff_at, delivery_on}], verdict}`) — tool `can_run_special`
- `proposals(PDO, int $scopeId, string $date, array $filters): array` (`mcp_buyer_proposals` grouped by heading) — tool `buyer_proposals` · `proposal_detail_html(array $p): string` · `acceptance_rate(PDO, int $scopeId, int $days = 30): array` · `morning_note(PDO, int $scopeId, string $date): array` (the assembled note) — tool `morning_note` · `purchasing_agent_member_id(PDO): ?int` (the member whose kernel agent key is `buyer` — from `mcp_members` by the mirror's `job_title`/roles, else NULL; DECISION 15)
- `watches(PDO, int $scopeId, ?int $memberId): array` (`mcp_watches`) — tool `my_watches` · `watch_events(PDO, int $scopeId, int $limit = 50): array` (`mcp_activity_log` where action `watch.fire` — the function logs through `log_activity`? — **no**: `ri_watches_fire()` is SQL and writes no activity row; the worker's pass logs `watch.fire` per fired watch from the function's return — DECISION 16; the tool reads notifications kind `watch` instead) — tool `watch_events`
- Writes (`write.php`): `run_forecast(PDO, int $scopeId, string $method, int $horizon, int $history, ?int $by): array` · `set_override(PDO, int $scopeId, array $fields, int $by, string $status): int` · `decide_override(PDO, int $id, string $decision, int $by): array` · `remove_override(PDO, int $id): void` · `set_calendar_day(PDO, int $scopeId, array $fields): void` · `sync_covers(PDO, int $scopeId, string $from, string $to): array` · `run_suggested(PDO, int $scopeId, ?int $supplierId, int $dueHours, bool $draft, ?int $by, string $origin): array` (`{suppliers: [{supplier_id, rows, drafted_po_id, skipped}]}`) · `set_watch(PDO, int $scopeId, ?int $id, array $fields, int $by): int` · `clear_watch(PDO, int $id): void` · `propose(PDO, int $scopeId, array $fields, ?int $by): int` · `decide_proposal(PDO, int $id, string $decision, int $by): array` · `send_morning_note(PDO, int $scopeId, string $date, ?int $by): array` (`{sent: bool, recipients, at}`)

## Handlers (every one: `ri_handler_begin()`; the right at the site; `ri_guard()`; `log_activity` with `scope_id` (and `ingredient_id`, `purchase_order_id` where the record has one); `emit_action_status()`; `ri_done()`; `HX-Trigger: forecastChanged` — `orderChanged` for a suggested draft, `watchChanged`, `proposalChanged`, `calendarChanged`)
- `projection/run.php` (`forecast_run`): `forecast.adjust`; `forecast.run`; location `/projection/`.
- `projection/override.php` (`forecast_override_set`): `forecast.adjust`; `forecast.override`; location `/projection/items/{menu_item}` (or `/projection/` for every item). `override-propose.php` (`forecast_override_propose`): `purchasing.draft`; `forecast.override_propose`; the notification. `override-decide.php`: `forecast.adjust`; `forecast.override_decide`. `override-remove.php`: `forecast.adjust`; `forecast.override_remove`.
- `projection/calendar/save.php` (`calendar_day_set`): `forecast.adjust`; `calendar.day_set`; location `/projection/calendar?month=`. `calendar/covers.php` (`covers_sync`): `forecast.adjust`; `forecast.covers_sync`; the not-connected notice is a 200.
- `purchasing/suggested/run.php` (`suggested_order_run`): `purchasing.draft`; `purchase_order.suggest`; with `draft` and one supplier → location `/purchasing/{po}` (or the card with "nothing to order"); with every supplier → `/purchasing/suggested` with the notice "3 drafted, 1 covered, 1 already drafted today".
- `projection/watches/save.php` (`watch_set`): `ri.read` (one's own); `watch.set`; location `/projection/watches#watch-row-{id}`. `watches/clear.php` (`watch_clear`): own or admin; `watch.clear`.
- `projection/proposals/save.php` (`buyer_propose`): `purchasing.draft`; `buyer.propose`; location `/projection/proposals?date=`. `proposals/decide.php` (`buyer_proposal_decide`): `purchasing.draft`; `buyer.decide`; accepting an order proposal → location the PO. `proposals/note.php` (`morning_note_send`): `purchasing.draft`; `buyer.note`; location `/projection/proposals?date=`.
- The screens: every projection screen `reports.read` (a Kitchen member is told "Projection is the Chef's and the Manager's"); the suggested orders `purchasing.draft`; the proposals `purchasing.draft`; the watches `ri.read`; values walled by `sees_cost()`; `log_screen_view()` on each.

## Manifest rows claimed
Screens (10): `forecast-list`, `forecast-item`, `calendar-days`, `demand-report`, `projected-stock`, `projected-ingredient`, `suggested-order-list`, `special-check`, `proposal-list`, `watch-list`
Actions (13): `forecast_run`, `forecast_override_set`, `forecast_override_propose`, `forecast_override_decide`, `forecast_override_remove`, `calendar_day_set`, `covers_sync`, `suggested_order_run`, `watch_set`, `watch_clear`, `buyer_propose`, `buyer_proposal_decide`, `morning_note_send`
Agent approvals: none (the slice drafts and proposes; sending a PO is slice 4's `purchase_order_send`, `money_out`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `forecast_run` | `/projection/run.php` | `forecast.run` | forecast.adjust | |
| `forecast_override_set` | `/projection/override.php` | `forecast.override` | forecast.adjust | |
| `forecast_override_propose` | `/projection/override-propose.php` | `forecast.override_propose` | purchasing.draft | |
| `forecast_override_decide` | `/projection/override-decide.php` | `forecast.override_decide` | forecast.adjust | |
| `forecast_override_remove` | `/projection/override-remove.php` | `forecast.override_remove` | forecast.adjust | |
| `calendar_day_set` | `/projection/calendar/save.php` | `calendar.day_set` | forecast.adjust | |
| `covers_sync` | `/projection/calendar/covers.php` | `forecast.covers_sync` | forecast.adjust | |
| `suggested_order_run` | `/purchasing/suggested/run.php` | `purchase_order.suggest` | purchasing.draft | |
| `watch_set` | `/projection/watches/save.php` | `watch.set` | ri.read | |
| `watch_clear` | `/projection/watches/clear.php` | `watch.clear` | own | |
| `buyer_propose` | `/projection/proposals/save.php` | `buyer.propose` | purchasing.draft | |
| `buyer_proposal_decide` | `/projection/proposals/decide.php` | `buyer.decide` | purchasing.draft | |
| `morning_note_send` | `/projection/proposals/note.php` | `buyer.note` | purchasing.draft | |

Every row of the manifest's projection section is claimed; none is left to another slice.

## Activity log events (every row: `scope_id`; `ingredient_id` on a watch's and a stock-out proposal's; `purchase_order_id` on a drafted order's)
`forecast.run|override|override_propose|override_decide|override_remove|covers_sync`, `calendar.day_set`, `purchase_order.suggest`,
`watch.set|clear|fire` (the worker's), `buyer.propose|decide|note`, `worker.pass`, `screen.view` (the suggested orders with
`after.due_hours`, the special check with `after.recipe_id|date|portions`). Values (the drafted lines' costs) are expected in
`purchase_order.suggest`'s `after`.

## Notifications this slice queues (and sends through slice 9's outbox)
`morning_note` (the Manager, daily, dedupe `note:<site>:<date>`); `watch` (the watch's member; a text when `text_me` — K6; by
`ri_watches_fire()`); `agent_drafted` (a proposed override, a drafted order when the agent made it — "the Purchasing agent drafted
PO-00044 for Sysco: 6 lines, $412"); `stockout` (the site's Manager when a stock-out is projected within 2 days and no PO covers it —
dedupe `stockout:<site>:<ingredient>:<date>`, raised by the `suggested_orders` pass).

## Status vocabulary
Source chips on a forecast cell: run `primary`, baseline `secondary`, override `warning`, closed `dark` (0); par mode chips: fixed
`secondary`, dynamic `info`; `thin` `warning` ("fewer than 4 weeks of history — the par rule is fixed until then"); a projected cell
negative `bg-danger-subtle`; a proposal's status: proposed `warning`, accepted `success`, dismissed `dark`; a watch kind: below_par
`warning`, stockout_projected `danger`, cost_above / cost_moved `info`. Ids: `forecast-table`, `forecast-row-{menu_item}`,
`forecast-run-btn`, `forecast-chart`, `override-form`, `override-form-field-{name}`, `override-row-{id}`, `calendar-grid`,
`calendar-day-{date}`, `calendar-day-form`, `covers-sync-btn`, `demand-table`, `demand-row-{ingredient}`, `projected-table`,
`projected-row-{ingredient}`, `projected-chart`, `supplier-card-{id}`, `sugg-line-{supplier_item}`, `sugg-draft-btn-{supplier}`,
`sugg-draft-all`, `sugg-recompute`, `special-form`, `special-table`, `special-row-{ingredient}`, `special-verdict`, `special-draft-btn`,
`proposal-card-{id}`, `proposal-accept-{id}`, `proposal-dismiss-{id}`, `note-send-btn`, `watch-form`, `watch-form-field-{name}`,
`watch-row-{id}`.

## Mobile rule (375 × 740)
The proposals are cards stacked under their headings with Accept / Dismiss as two 44 px buttons; the suggested-order card shows the
supplier, the cut-off and the delivery with the lines as a scrolling table (ingredient · need · have · units pinned) and Draft the PO in
the card's header; the forecast table pins item · 7-day · total; the charts scale to the width (the SVG's `viewBox`); the calendar grid
becomes a list of days. Every control ≥ 44 px; `scrollWidth` = viewport.

## Vocabulary
*forecast* (the expected sales of a menu item on a day), *baseline* (the same-weekday weighted four-week average), *run* (one pass of
the forecaster over every item), *band* (lo–hi), *override* (a person's factor or quantity for a window, with a reason), *covers factor*
(booked covers against the weekday's norm, applied when far enough off), *demand* (the forecast exploded through the recipes), *projected
on hand* (on hand + receipts due − demand − planned waste, by day), *stock-out* (the first day under zero), *protection interval* (this
delivery to the delivery after the next cut-off), *need / have / cap / raw / units* (the suggested order's steps), *par mode* (fixed par or
the periodic-review rule), *thin history* (fewer weeks than the setting), *proposal* (the Purchasing agent's finding, accepted or dismissed
by a person), *the morning note* (the seven headings, sent once a day), *watch* (a condition that fires once per change).

## Out of scope for this slice
Sending or placing the PO (slice 4); the price alerts' raising (slice 4's pass) and the POS pulls and depletion runs (slice 5's passes) the
projection relies on; the outbox, the dispatch loop and the `morning_note_inputs` pass (slice 9); the Spaces channel post (Extended —
a notification body carries the text until Spaces publishes a post share); weather and events as forecast features, gradient boosting
(Extended); a requisition between sites (Extended); Holt-Winters' internals (the companion spec).

## Proof (`tests/phase3/slice8/run.sh`: the scratch database `ri_dev8`; the application on 9301; the fake kernel 9302 (its K7 `read` answering
`covers_by_service` from a fixture and `no_connection` on demand); the fake MaluDB 9303; `RI_WORKER_NOW` where a pass selects by the clock;
members through `bin/dev_handoff.php <member> <scope>`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800;
`bin/build_action_registry.php --check`; `bin/sync_approvals.php --check`)
Target: **≥ 260 checks** (forecast 35, overrides 20, calendar and covers 20, demand 15, projected 20, suggested 45, special 15, proposals 25,
note 15, watches 15, worker 25, visibility 10, json 15, browser 25).
The world (`tests/phase3/slice8/lib.php` `projection_world()`): slices 1–7's worlds plus twelve weeks of `sales_daily` for four menu items with a
weekend swing and one holiday closure; Sysco's two schedules; pars on flour (fixed 250 kg) and eggs (dynamic); limes with a 10-day shelf life;
Dana (Chef), Marco (Manager), Bea (Kitchen), Ann (Viewer), the expert, the Purchasing agent (member 42).
- [ ] **Forecast**: with no run, every cell says baseline and equals `ri_forecast_baseline()`; `forecast_run` baseline writes a run and the cells say
  run with the same numbers; `holt_winters` through `bin/forecast.py` writes a run with bands (the companion spec's checks: the weekly cycle
  recovered on the synthetic series within 10 % WAPE; a series under twelve weeks falls back to the baseline and says so); a second run marks the
  first not current; the accuracy row per item (MAE, WAPE, bias) after the run; the `thin` chip on an item with three weeks of sales; the chart
  SVG has eight weeks of bars and three of line, no external script.
- [ ] **Overrides**: Marco sets +50 % on an item for a day → the cell says override and equals baseline × 1.5; a fixed quantity override; factor and
  qty together refused in words; the agent's proposal → status proposed, the Manager notified; Marco sets it → set; dismiss; remove; Dana (no
  `forecast.adjust`) → 403 in words; `forecast.override_propose` logged with the agent as actor.
- [ ] **Calendar and covers**: a closure → the cell 0 and excluded from the baseline's weeks; a holiday named; `covers_sync` against the fake kernel's
  fixture → `covers_expected` rows mapped to Lunch and Dinner by `service_name`, an unknown service into the day row; the factor 1 when within 15 %
  and the ratio when 300 booked against a norm of 150; `no_connection` → the 200 with the notice and no rows changed; two calls for a 90-day window.
- [ ] **Demand**: the egg demand on a day equals the sandwich forecast (one egg each) plus the omelette's; the drivers sum to 100 %; a 29-day window
  refused in words; the count unit shown with the base in the title.
- [ ] **Projected stock**: fourteen cells per ingredient falling from on hand; a receipt due marked and lifting the line; the first negative cell marked
  with the date; `stockouts_only` lists the eggs; the ingredient page's chart and open PO lines; the par shown.
- [ ] **Suggested orders**: the Sysco card shows the next cut-off and delivery (the schedule's), the protection interval; flour: fixed par 250 kg − have,
  rounded up to bags with the note; eggs: dynamic — demand over the interval + z·σ·√P, not thin; limes: the perishable cap at the 0.7 quantile ×
  shelf life when it binds; chicken's catch-weight line in pounds; the card's total under the minimum order value flagged; **Draft the PO** → a
  draft PO with origin `suggested`, `cutoff_at`, `expected_on`, every line carrying need/have/on_order/cap/raw/rounding; a second Draft the same
  day → "already drafted today — PO-00044" and no second PO; Recompute changes nothing; **Draft all due** drafts one per supplier due and skips
  the covered; a supplier with no schedule drafts for tomorrow; Bea (no `purchasing.draft`) → 403.
- [ ] **Special check**: 60 portions of the braise on Friday → the table's needs, the projected on hand, a shortfall on short rib, Sysco by Thursday if
  ordered by Wednesday 13:00; a recipe with no version in force → the sentence; "Draft what is short" lands on a draft PO with the short lines.
- [ ] **Proposals and the note**: the agent (a run token) proposes an order with its drafted PO and a price finding; Marco accepts the order → lands on
  the PO; dismisses the price; the acceptance rate; `morning_note_send` → one notification to Marco with seven headings, the Markdown ≤ 4,000
  characters, each heading's count matching the gatherer; a second send the same day → 200 "already sent at …" and no second notification; a
  recipient without cost (a copy of the world where the Manager is a Kitchen member) sees "—" for values.
- [ ] **Watches**: Marco sets a stock-out watch on eggs with text me and the agent → `last_state` true at once, no fire now; the eggs covered by a
  receipt → state false; the receipt reversed → the pass fires once: a notification, a text in the outbox, an `agent_dispatches` row, `watch.fire`
  logged; not again next minute; `cost_above` with a threshold per recipe unit converted to per base; clear.
- [ ] **The worker**: `--passes=forecast_pass` with `RI_WORKER_NOW` at 01:30 site time does nothing, at 02:10 runs once per site and not again the same
  night; `suggested_orders` at :15 drafts Sysco's PO with origin `agent` and the order proposal, the stock-out proposal for eggs, and at :30 drafts
  nothing more; `watches` fires as above; a pass's exception is caught and reported in `errors[]`; one `worker.pass` row per run.
- [ ] **Who sees**: Ann reads nothing of projection (403 in words — `reports.read`); Dana sees forecasts, demand, projected stock and the suggested
  orders with costs; Bea reads none; Marco at Airport sees Airport's own (empty) projection.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `suggested_order_run` with
  `supplier` + `draft` answers `record_id` = the PO; `covers_sync` answers `connected`; 422 for the override's CHECK in words; the Purchasing
  agent's `buyer_propose` and `morning_note_send` as `source agent`; the expert's `suggested_order_run` drafts and nothing pauses.
- [ ] **375 × 740 and 1280 × 800**: the proposals as cards with 44 px buttons; the suggested-order card's table pinned columns; the forecast chart
  scaling; the calendar as a list at 375 px and a grid at 1280; no console errors; `scrollWidth` = viewport.

**Decisions taken in this spec (not questions):**
1. The forecaster is `bin/forecast.py` (pure Python, the companion spec); the baseline is SQL; every reader goes through `ri_item_forecast()`.
2. One action `suggested_order_run` serves Draft the PO (one supplier), Draft all due (every supplier due) and Recompute (`draft = no`).
3. The morning note is assembled from the day's proposals when the agent made them, from the gatherers otherwise — the note always exists.
4. The special check's "Draft what is short" posts slice 4's `purchase_order_draft` with `lines` (no new action).
5. A K7 `no_connection` on `covers_sync` is a 200 with a notice; the forecast runs without covers.
6. A supplier with a `suggested` or `agent` draft made today is skipped by every later run that day — no doubled orders.
7. A new watch takes the current state as its `last_state`, so it fires on the next change, not at creation.
8. A second `morning_note_send` the same day is a 200 naming the earlier send; there is no `force`.
9. The Spaces `#kitchen` post is Extended until Spaces publishes a post share; v1 keeps the text in the notification.
10. The note carries values; a recipient without cost reads "—".
11. "The forecast worsened" compares the new accuracy with the row it overwrites; no accuracy history table is added.
12. The `suggested_orders` pass does the agent's drafting at :15 past each hour so the note is ready whether or not the kernel's duty ran; the
    duty's turn reads the proposals and writes its words.
13. When no Purchasing agent is hired, slice 9's `morning_note_inputs` pass sends the note at the site's duty hour by calling this slice's
    `send_morning_note()`.
14. `kernel_read()` lives in `app/api/kernel.php` (Phase 2 adds it for the switcher; this slice relies on it).
15. The Purchasing agent's member id is found by its kernel agent key through the mirror (`members.roles`/`job_title` — the feed carries the
    agent's declared key as its job title, `registration.md`); NULL when not hired.
16. `watch.fire` is logged by the worker's pass per fired watch (the SQL function writes no activity row); the `watch_events` tool reads the
    notifications of kind `watch`.

## Open questions
(none)

## Built and proven

Built 2026-10-12 by a Sonnet 5.5 worker from `worker-brief.md`, on `/srv/apps/restaurant_inventory`; to be reviewed and committed by the planning model; the application's commits are not pushed.

**Proof:** `tests/phase3/slice8/run.sh` — the schema proof 198 (db/028's checks with it), then forecast 37, overrides 30, calendar and covers 31, demand 19, projected stock 24, suggested orders 55, special check 24,
proposals and the note 39, watches 33, the worker 26, who sees what 29, JSON mode 51 (the log vocabulary and the registry counts with it), the browser 34 (headless Chromium at 375 × 740 and 1280 × 800 and with JavaScript off),
`bin/forecast.py` compiling, the server log, the registry, the approvals and the Phase 1 claim check: **437 checks under php -S and 437 under a user-level Apache rendering `deploy/apache-restaurant_inventory.conf`**
(target ≥ 260). The scratch database is `ri_dev_s8` and the port block 9337–9340. The registry this commit carries reads 107 of 119 screens and 136 of 146 actions built (10 and 13 of them this slice's; none pauses
for an approval). Screenshots in `/tmp/ri-shots/slice8`. The world is `projection_world()` in `tests/phase3/slice8/lib.php`: the ledger world plus short rib, honey, an omelette, a lime soda, a braise and a daily
special; Sysco's two schedules (Saturday 13:00 → Tuesday, Wednesday 13:00 → Friday), Produce Co's and a split-case supplier with none; 56 days of POS history through the real path (a sale a day, `ri_depletion_run()`)
and 56 more of `sales_daily` (the sandwich 20 and 40 on Fridays and Saturdays, the omelette 12, the soda 25, the special for three weeks only), 200 covers a check; stock set so each rounding case occurs (flour 161.025 kg
against a fixed 250 kg par, eggs dynamic, oil 4.1 cases short, gin 1.2 cases with a minimum of 3, limes capped by a 5-day shelf life, chicken covered, honey at a split-case supplier). Every dated figure counts from
the DATABASE's `current_date` (what db/014's functions count from), so the proofs run on any day. The suggested order is re-derived in PHP from the raw tables (demand, σ of 28 days of depletion, z, √P, the 0.7 quantile,
Craftable's 20 % rule) and compared with `ri_suggested_order()` line by line; flour's worked numbers (88 975 g = 3.92 bags → "rounded up to the bag50" = 4) are the spec's own.

**Files:** `html/projection/{index,calendar,demand,stock,special,proposals,watches}.php`, `html/projection/{items,stock}/view.php`, `html/projection/{run,override,override-propose,override-decide,override-remove}.php`,
`html/projection/calendar/{save,covers}.php`, `html/projection/watches/{save,clear}.php`, `html/projection/proposals/{save,decide,note}.php`, `html/purchasing/suggested/{index,run}.php` (the placeholder `html/purchasing/suggested.php`
removed for the directory); `app/features/projection/{queries,present,write,handler,charts,overrides,worker}.php`, `app/features/ordering/{queries,write}.php`, `app/features/agents/{queries,note,write}.php`,
`app/features/watches/{queries,write}.php`; the views under `app/views/projection/` and `app/views/purchasing/suggested/`; `html/assets/css/projection.css`; `bin/forecast.py`; `bin/worker.php` (the three passes real);
`app/features/shell/nav.php` (`back_link()`, the two rights below); `ri_qty_text()` appended to `app/features/units/queries.php`; **`db/028_slice8_projection.sql`**; the schema proof's slice 8 block; `tests/phase3/slice8/*`; the Phase 2 suite follows
the screens now built (`tests/phase2/gates.php`) and slices 4, 6 and 7's registry totals follow (107 and 136). `ordering/present.php` and `watches`' presenters are folded into `projection/present.php`.

**What the spec or the schema got wrong (fixed or reported):**
- **db/014's periodic-review rule could not run its own branch.** `ri_suggested_order()` failed with "function round(double precision, integer) does not exist" as soon as a dynamic ingredient had seven days of depletion
  (`round(z * sig * sqrt(p_days), 4)`; the perishable cap's `round(dq * shelf_life, 4)` the same — `percentile_cont` is double precision). The Phase 0 proof's eggs had fewer than seven days, so the safety-percentage branch
  answered and the rule never ran. Fixed in db/028 (the body is now `ri_suggested_order_core()` with both cast to numeric); a minimum order also reads "minimum 3", not "minimum 3.0000"; the schema proof gains a check with nine days of depletion.
- **A closed day did not forecast zero through a RUN.** `ri_item_forecast()` zeroed a closure only inside the baseline, so a closure set after the night's run kept the run's quantity and the demand, the projection and the
  suggested order ordered for a shut day. Now zero with source `closed`, ahead of the run, the baseline and an override (db/028).
- **`ri_projected_stock()` and `ri_stockouts_projected()` exploded the whole menu once per ingredient (twice, for the stock-outs).** The projected-stock table would have cost 14 days × every item × every ingredient. db/028 adds
  `ri_projected_stock_all(site, days, ingredients[])` (one explosion; the schema proof asserts it equals `ri_projected_stock()` day for day) and `ri_stockouts_projected()` reads it; `sales_daily` gains the `(scope_id, business_date)` index
  the baseline's "did the site record any sales that day" asked four times a call.
- **`ri_suggested_order()` returned `unit_cost` unwalled** (db/027's lesson, for the tool and `ri_morning_note()`): it is now the walled front of `ri_suggested_order_core()` (a member must hold the site; cost only for who sees it; the
  worker, no member, sees all; the read roles with no member are refused), and `ri_suggested_order_draft()` reads the core so a drafted line always carries its cost. The writing definers (`ri_suggested_order_draft`,
  `ri_forecast_accuracy_compute`, `ri_watches_fire`) were executable by PUBLIC and no longer are. The schema proof asserts all of it (198).
- **`ri_notify()` dedupes a watch per hour** (`watch:<id>:<YYYYMMDDHH24>` through the email outbox), so a watch that fires twice in one hour notifies once; the fire count and the event still count twice. Reported, not changed.
- A watch's threshold is `numeric(14,4)` per base unit: a cost per gram below $0.0001 is stored coarsely (views depend on the column, so the type was left). The screen reads it back in the recipe unit.
- The spec's special-check "Sysco by Thursday if ordered by Wednesday 13:00" depends on the weekday; the proof asks for the date of Sysco's first delivery and compares weekday and cut-off from the window itself.

**Decisions taken here (within the spec):**
- **`bin/forecast.py` holds no database connection**: PHP reads the series (one daily list per item from its first sale, null for a closed day or a day the site recorded no sales), shells to it with the document on stdin and writes the run from its one JSON line (`run_forecast()`, its own transaction, so the current run
  stands when the forecaster fails). The spec's `--site` DSN reading would have needed a PostgreSQL driver the application's venv does not carry (asyncpg only) and nothing is gained: the script is a pure function. `RI_PYTHON` names the interpreter, else `mcp/venv/bin/python`, else `python3`.
  It also reports `mae28` for the chosen parameters and for α = β = γ = 0.3, which the proof compares.
- **Who opens what:** every projection screen is `reports.read`'s (the spec's blanket rule: "Projection is the Chef's and the Manager's" — a Viewer and a Kitchen member are told so), the suggested orders and the proposals `purchasing.draft`'s, the watches `ri.read`'s. The menu's Projected stock and Can we run it? follow (they were `ri.read` in the shell);
  the Trading calendar stays in the menu for `forecast.adjust` but any `reports.read` holder may open and read it; its form and Sync covers are `forecast.adjust`'s. Every write opens to the right that writes it.
- `suggested_order_run`'s `draft` defaults to **no** (read only) — an agent must say `draft = yes` to draft; a person's origin is `suggested`, a run token's `agent`; a supplier with no schedule drafts for the supplier's lead time ("tomorrow"). "Already drafted today" is a PO of origin suggested/agent not cancelled, made on the site's date.
- A factor of 0 is allowed (it forecasts zero for those days); an empty field is "not given". An override on a closed day does not apply (the closure wins; open the day to override it). The all-items override loses to an item's own.
- Heading 7 of the note counts a sent order unacknowledged for MORE than two days (projection.md's wording; the companion spec's `ack_days` of 3 was not built). A dismissed proposal is left out of its heading and the gatherer is not consulted for a heading that has proposals. The recipients are the site's Manager, else the business's, else the holders of `purchasing.send`, else the first super-admin.
- `purchasing_agent_member_id()` finds the agent by its job title (`buyer`, `purchasing agent`) in the mirror — the proof sets the title on member 42 as the kernel's feed will.
- The special check's portions are the recipe's yield units (a prep's batch unit); the supplier is the preferred supplier item's, else the earliest delivery on or before the day; units are whole purchase units, at least the minimum (SQL).
- Everything the worker's passes and the note read comes from the base tables and the SQL functions (the worker has no member and the `mcp_*` views answer nothing without one); the screens read the views.
- The forecast pass stamps `made_at` with the worker's clock (`RI_WORKER_NOW` in the proofs) so "a run made today in the site's zone" is the clock's own; the suggested-orders pass is forced by `--passes=` and otherwise runs on the quarter hour.

**Reconciled by the planning model in review (2026-10-12):**
- **The gate.** The seven read screens (the forecast list and item, the calendar, demand, projected stock and an ingredient's projection, the
  special check) had kept the spec's `reports.read`; the worker brief's rule (design §3 — a read-only screen opens to every reader at the site)
  governs, and these screens carry quantities only, so `projection_screen_gate()` asks `ri.read`. The suggested orders and the proposals, which
  carry cost and draft orders, keep `purchasing.draft`; the menu still shows the projection to who works with it. `visibility.php` and
  `tests/phase2/gates.php` follow.
- **The clock.** The worker found slices 3 and 7 failing from 00:00 to 04:00 UTC. The application's PHP was right (it uses the site's zone); the
  database was not — the connection was set to UTC, so the schema's `current_date` was tomorrow from 20:00 in New York (projected stock's first
  day, a waste log's day, deliveries due). `app/db.php` `db_use_site_zone()` now sets the session's zone to the site's in every request, the
  switcher and each worker loop; PHP's default zone follows in the same function — slices 1 and 2 dated a menu price, a version's activation and retirement, and the day's
  plate cost with `date('Y-m-d')` in UTC, so an evening's new price started tomorrow (slice 2's proof caught it inside the window); the four
  proof libraries read the Downtown site's day; Phase 4's servers owe the same per call.
- **db/029** finishes it in the schema: `ri_site_date()` / `ri_site_day_end()`, and the five UTC day conversions (`ri_recipe_cost`,
  `ri_portion_cost_cached`, the price trigger's cache invalidation, `ri_post_production` twice) use the site's day.

