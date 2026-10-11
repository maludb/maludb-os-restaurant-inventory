# Build spec: periods, usage, variance and the close (slice 7)

Built by the worker model on slice 1's CRUD pattern and slice 3's document pattern, replicating slice 2's files, gates and proof style
(`docs/build-specs/recipes.md`, the first exemplar). What exists at the end: an admin names a **costing calendar** (weeks by default, with
a monthly roll-up; months; 4-4-5 families; 13 × 4) and makes the year's **periods** at a site; the **usage report** answers the
application's defining question for any window — per ingredient: opening, purchases, transfers in and out, production in and out,
adjustments, closing, **actual** usage, **theoretical** usage, variance, waste, comps, **unexplained**, in base units and at cost — with
every column shown and a line per ingredient that opens its week; **COGS** and food-cost % by period, actual and theoretical; a Manager
**closes** the first open period past its end once every day's sales are depleted, naming the approved count that closes it; the figures
freeze; a late receipt lands in the next open period flagged; an admin **reopens** the last closed one; an export of the usage rows.
Nothing here posts a movement; the close freezes numbers the ledger already holds. Closing and reopening are a person's (an agent pauses —
`other`).
Schema: `costing_calendars`, `periods`, `ri_calendar_periods()`, `ri_periods_ensure()`, `ri_period_on()`, `ri_site_moment()`, `ri_usage()`,
`ri_usage_by_category()`, `ri_food_sales()`, `ri_stock_value()`, `ri_period_close()`, `ri_period_reopen()`, `ri_transactions_late()` (db/013);
`ri_depletion_due()` (db/011 — the close's guard); `ri_cost_as_of()`, `ri_on_hand_as_of()` (db/007, db/009); `ri_share_cogs_summary()`
(db/016 — the export's twin); `inventory_counts` (the closing count, db/012); the views `mcp_costing_calendars`, `mcp_periods` (every value
walled by `ri_sees_cost`), `mcp_inventory_counts`, `mcp_inventory_transactions`, `mcp_ingredients`, `mcp_ingredient_categories`,
`mcp_recipes`, `mcp_recipe_lines`, `mcp_waste_logs`, `mcp_sales_daily`, `mcp_activity_log` (db/017). Never modify them. **The database is
the referee**: a period closes only after its last day, only when no earlier period is open, only when every day with sales in it has a
live depletion run, only with an approved closing count; a closed period's figures are frozen and the next period takes the ending value
as its opening; a transaction dated inside a closed period is moved to the first open period and flagged in its note; only the last
closed period reopens; the usage identity is the function's, never PHP's.

## Screens (the usage report and COGS at 1280 px, usable at 375 px; the period list usable at 375 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `period-list` | `/periods/?year=&status=` | the table `periods-table` (`period-row-{id}`) for the fiscal year (the current one by default; a year select): no, dates, status chip, opening value, purchases, ending value, COGS, food sales, food-cost % — the last five live for an open period (computed by `period_figures()` from `ri_usage()`, cached per request) and frozen for a closed one, every value walled ("—"); **Close** on the first open period whose `ends_on < today` (`periods.close`; `hx-confirm` "Close week 40 (Sep 29 – Oct 5)? Its figures freeze."), with a select of approved counts whose `as_of` falls on the period's last day (`closing_count`, optional); **Reopen** on the last closed one (`settings.manage`, `hx-confirm` with a reason field); **Make the year's periods** when the year has none (`periods_ensure`); a banner when `ri_depletion_due()` names a day inside the period to close ("Oct 3 and Oct 4 are not depleted yet — run them first", a link to `sales-day-list`) |
| `period-view` | `/periods/{period}?tab=` | the header: no, dates, status, the calendar, closed by/at, reopened by/at; the figure cards (`period-figure-{name}`: opening, purchases, ending, COGS, theoretical, waste, food sales, food-cost % actual and theoretical, walled); the tabs — **Usage** (the usage table filtered to the period, by category collapsed, each category opening its ingredients), **Counts** (the opening and closing counts with links; the approved counts inside the period), **Late rows** (`mcp_inventory_transactions` whose `note` carries "late:" and `business_date` inside this period — what landed here from a closed one), **Trail**; **Close**, **Reopen** as the list; **Export** (CSV / JSON of the usage rows → `usage_export`); `data-entity="period"` |
| `usage-report` | `/usage/?from=&to=&category=&basis=&sort=&format=` | **actual vs theoretical**: the window (two dates; the current period by default; quick picks: this period, last period, last 4 weeks, a month), the basis (the setting's `avg`, or `last`), the category filter; the table `usage-table` (`usage-row-{ingredient}`): ingredient (a link to `usage-ingredient` with the window), category, then the buckets **in the count unit** with the base figure in a title — opening, purchases, transfers in, transfers out, production in, production out, adjustments, closing, **actual**, **theoretical**, **variance**, waste, comps, **unexplained** — and the value columns (unit cost, opening value, purchases value, closing value, actual value, theoretical value, variance value, waste value, unexplained value — all walled); sorted by |unexplained value| desc by default (`sort`: unexplained, variance, actual, name); the category subtotal rows (`usage-cat-{category}`) and the grand total; a legend row under the table reading the identity in words ("actual = opening + purchases + transfers in − transfers out + production in − production out + adjustments − closing; variance = actual − theoretical; unexplained = variance − waste"); `?format=csv` answers the same rows as `text/csv` (logged `screen.view` with `after.format`); a negative variance is marked "check the data" (a receipt missing, a count wrong) |
| `usage-ingredient` | `/usage/{ingredient}?from=&to=` | one ingredient's window: the bucket cards; **the recipes that consumed it** (`consumers()`: from `mcp_sales_daily` × the versions in force × `ri_explode` — qty depleted by recipe and share %), the waste logged (`mcp_waste_logs`), the count lines inside the window (`mcp_inventory_count_lines` of approved counts), the movements (`mcp_inventory_transactions` of the window, 200 a page), the cost history in the window; "this ingredient's variance is at the ingredient — the recipes beside it are where it went" |
| `cogs-report` | `/usage/cogs?year=` | COGS by period for the fiscal year (`cogs-table`, `cogs-row-{period}`): period, dates, food sales, COGS actual, food-cost % actual, theoretical COGS, theoretical %, variance (value and points), waste value, status; the open period live; a bar per period (`cogs-chart`: a server-rendered inline SVG — actual vs theoretical as two bars per period, the dataviz palette's two neutrals, no library); the year's totals; walled |
| `calendar-list` | `/periods/calendars` | the table (`calendar-row-{id}`): name, kind, year start, week starts, default; the form (`calendar-form`, `calendar-form-field-{name}`: name, kind select, year_start_on date, week_starts select, is_default) → `calendar_set`; **Make the year's periods** per site (`periods_ensure`: fiscal_year, calendar) with the periods it would make previewed (`ri_calendar_periods()`) before the button |

## The forms
| Form | Field | Input | Required | Rule |
|---|---|---|---|---|
| `calendar-form` | name | text ≤ 60 | yes | |
| | kind | select week · month · 4-4-5 · 4-5-4 · 5-4-4 · 13x4 | yes | |
| | year_start_on | date | yes | week kinds align to the week boundary (the function's) |
| | week_starts | select Mon..Sun (1..0) | no | Monday by default |
| | is_default | checkbox | no | one default (the partial unique index — the handler clears the previous in the same transaction) |
| `periods-ensure-form` | fiscal_year | number | yes | the current year by default; 2000..2100 |
| | calendar | select | no | the default calendar |
| `period-close-form` | period | hidden | yes | the first open past its end |
| | closing_count | select of approved counts with `as_of::date` = `ends_on` (site time) + none | no | the function refuses one not approved |
| `period-reopen-form` | reason | text ≤ 200 | yes (the handler's) | logged |
| `usage-form` | from, to | dates | yes | to ≥ from; ≤ 400 days |
| | category | select + all | no | |
| | basis | select avg · last | no | the setting's by default |
| | sort | select | no | unexplained by default |

## The usage report, column by column (what each cell is — the function's column, the unit it shows in, the title)
| Column | `ri_usage()` column | Shown in | Title (hover) | Note |
|---|---|---|---|---|
| Ingredient | `ingredient_id` | name, code | the category | a link to `usage-ingredient` with the window |
| Opening | `opening_base` | the default count unit (`ri_from_base`) | the base figure and the unit | the approved count's figure when one closed the previous period, else the ledger as of the window's start (the site's sales cut) |
| Purchases | `purchases_base` | count unit | the receipts in the window by `occurred_at` | reversals net out |
| Transfers in / out | `transfers_in_base`, `transfers_out_base` | count unit | the other site or area | shown as two columns, out as a positive number with a "−" prefix |
| Production in / out | `production_in_base`, `production_out_base` | count unit | the batches | a prep's output in, its inputs out |
| Adjustments | `adjustments_base` | count unit | the reasons | signed |
| Closing | `closing_base` | count unit | as of the window's end | the approved count's figure when it closed the period |
| **Actual** | `actual_base` | count unit | the identity in words | bold |
| **Theoretical** | `theoretical_base` | count unit | Σ depletion of the window's sales | bold |
| **Variance** | `variance_base` | count unit | actual − theoretical | coloured: negative `text-danger` ("check the data"), > 5 % of theoretical `text-warning` |
| Waste | `waste_base` | count unit | the waste logs | explained |
| Comps | `comps_base` | count unit | the comped sales' explosion | explained (inside theoretical already — shown so the reader sees it) |
| **Unexplained** | `unexplained_base` | count unit | variance − waste | bold; the default sort |
| Unit cost | `unit_cost` | per base, shown per count unit | the basis | walled |
| Opening / purchases / closing value | `*_value` | money | | walled |
| Actual / theoretical / variance / waste / unexplained value | `*_value` | money | | walled; the totals row sums them |

The category subtotal row sums the value columns and shows "—" for units (units across ingredients do not add); the grand total likewise.
The quick picks: **this period** (`ri_period_on(site, today)`), **last period**, **last 4 weeks** (today − 27 .. today), **last month**
(the calendar month), **custom**. The weekly calendar's **monthly roll-up** is the COGS report's year table grouped by `date_trunc('month',
starts_on)` with a subtotal row per month (`cogs_by_period()` returns `month_subtotals` beside the rows; DECISION 6) — no second calendar
is needed for the roll-up the design names.

## Posting, step by step (what each handler calls)
- **Calendar** `calendar_set` → INSERT/UPDATE `costing_calendars`; `is_default` clears the previous default first. **other** for an agent.
  Log `calendar.set` (`calendar_id`, `kind`, `year_start_on`, `is_default`).
- **The year's periods** `periods_ensure` → `SELECT ri_periods_ensure(:site, :year, :calendar)` → the count made (0 when they exist). Log
  `period.ensure` (`fiscal_year`, `calendar_id`, `made`).
- **Close** `period_close` → `SELECT * FROM ri_period_close(:period, :me, :closing_count)`: the function's refusals, each a 422 in its words
  — "Period N ends … — it closes after its last day", "An earlier period is still open — close it first", "The closing count is not
  approved", "Sales in the period are not yet depleted — run the depletion first"; the figures frozen from `ri_usage()` over the period
  and `ri_food_sales()`; the next period's opening set. **other** for an agent (pauses). Log `period.close` (`period_no`, `fiscal_year`,
  `starts_on`, `ends_on`, `closing_count_id`, `opening_value`, `purchases_value`, `ending_value`, `cogs_value`, `theoretical_value`,
  `waste_value`, `food_sales`) with `scope_id`, `count_id` = the closing count.
- **Reopen** `period_reopen` → `ri_period_reopen(:period, :me)`; "A later period is closed — reopen it first". **other**. Log
  `period.reopen` (`period_no`, `reason`).
- **Export** `usage_export` → `ri_usage(site, from, to, basis)` (or `ri_usage_by_category`) rendered as CSV or JSON by `usage_csv()`; the
  download named `usage-<site>-<from>-<to>.csv`. Log `export.download` (`document: usage`, `from`, `to`, `by`, `format`, `rows`).
- **The late rule** is the schema's trigger (`ri_transactions_late()`): nothing here posts; the period view's Late tab and the usage
  report's "late" badge on a row (`late_rows()` by note) are reads. A receipt posted into a closed period says so in its `ri_done()`
  sentence: "Posted GR-00031 — dated Oct 2 inside closed week 40; landed in week 41" (slice 3's handler reads the posted rows' notes;
  reconciled: slice 3 adds that sentence from `late_rows()` here — DECISION 1).

## Files (exactly these)
- `html/periods/index.php` (`period-list`) · `view.php` (`period-view`) · `ensure.php` (`periods_ensure`) · `close.php` (`period_close`) · `reopen.php` (`period_reopen`) · `calendars/index.php` (`calendar-list`) · `calendars/save.php` (`calendar_set`)
- `html/usage/index.php` (`usage-report`) · `ingredient.php` (`usage-ingredient`) · `cogs.php` (`cogs-report`) · `export.php` (`usage_export`)
- `app/features/periods/{queries,present,write,handler}.php` (`handler.php`: `period_from_request()`, `require_period_site()`, `period_log()` — `scope_id` on every row, `count_id` on a close with a closing count) · `app/features/usage/{queries,present,csv}.php` (`usage_csv()`, `window_from_request()` with the quick picks, `display_qty()` — a base quantity in the ingredient's default count unit through `ri_from_base`)
- `app/views/periods/{index,view,partials/period-row,partials/figures,partials/close-form,partials/reopen-form,partials/late-rows,partials/counts}.php` · `app/views/periods/calendars/{index,partials/calendar-row,partials/preview}.php` · `app/views/usage/{index,ingredient,cogs,partials/usage-table,partials/usage-row,partials/category-row,partials/legend,partials/consumers,partials/cogs-chart}.php`
- `app/features/shell/nav.php`: `back_link()` learns `/periods/{id}` "the period", `/usage/{id}` "the ingredient's usage"; `record_url()` learns `period`, `costing_calendar`
- `deploy/apache-restaurant_inventory.conf` and `tests/dev_router.php`: `/usage/cogs` → `cogs.php` before the `/usage/{ingredient}` rule; `/periods/calendars` → `calendars/index.php` before `/periods/{period}` (two lines each)

## Query functions (signatures fixed; PDO first; the site from `current_site_id()` unless given — the tools of Phase 4 call these)
- `periods(PDO, int $scopeId, ?int $fiscalYear = null, ?string $status = null): array` (`mcp_periods`; the current year by default) — tool `periods` · `find_period(PDO, int $id): ?array` — tool `get_period` · `period_figures(PDO, array $period): array` (frozen when closed; live from `ri_usage()` + `ri_food_sales()` when open: `{opening_value, purchases_value, ending_value, cogs_value, theoretical_value, waste_value, food_sales, food_cost_pct, theoretical_pct, live: bool}`) · `period_to_close(PDO, int $scopeId): ?array` (the first open period with `ends_on < today`) · `period_closing_count_options(PDO, array $period): array` (approved counts as of its last day) · `period_undepleted_days(PDO, array $period): array` (`ri_depletion_due()` inside it) · `period_late_rows(PDO, int $periodId, int $limit = 100): array` · `period_counts(PDO, array $period): array`
- `usage(PDO, int $scopeId, string $from, string $to, array $filters): array` (`ri_usage(site, from, to, basis)` ⨝ `mcp_ingredients`, `mcp_ingredient_categories`, the display quantities; `category`, `sort`; rows + category subtotals + totals) — tool `usage_report` · `usage_by_category(PDO, int $scopeId, string $from, string $to, ?string $basis = null): array` (`ri_usage_by_category`) — tool `usage_by_category` · `variance_top(PDO, int $scopeId, string $from, string $to, int $limit = 10): array` (the rows with the largest |unexplained value|) — tool `variance_top` · `food_cost_pct(PDO, int $scopeId, string $from, string $to): array` (`{food_sales, cogs_actual, pct_actual, cogs_theoretical, pct_theoretical, waste_value}`) — tool `food_cost_pct`
- `usage_ingredient(PDO, int $scopeId, int $ingredientId, string $from, string $to): array` (the buckets for one ingredient + `consumers()` + waste + count lines + movements) · `consumers(PDO, int $scopeId, int $ingredientId, string $from, string $to): array` (per recipe: qty depleted, share %) — the tool `recipes_using` is slice 2's; this is the window's actual consumption
- `cogs_by_period(PDO, int $scopeId, int $fiscalYear): array` (`period_figures()` per period + totals) — the `cogs-report`; the tool `food_cost_pct` per period reads it · `cogs_chart_svg(array $rows): string` (the inline SVG, two bars per period, the palette's two neutrals, the axis labelled; ≤ 600 px wide, scales to the container)
- `calendars(PDO): array` (`mcp_costing_calendars`) · `find_calendar(PDO, int $id): ?array` · `calendar_preview(PDO, int $calendarId, int $fiscalYear): array` (`ri_calendar_periods`)
- `usage_csv(array $usage, string $by): string` · `usage_json(array $usage, string $by): string`
- Writes (`write.php`): `save_calendar(PDO, ?int $id, array $fields): int` · `ensure_periods(PDO, int $scopeId, int $fiscalYear, ?int $calendarId): int` · `close_period(PDO, int $periodId, int $by, ?int $closingCountId): array` · `reopen_period(PDO, int $periodId, int $by, string $reason): array`

## Handlers (every one: `ri_handler_begin()`; the right at the site; `require_site()` on the record's site; `ri_guard()`; `log_activity` with `scope_id`; `emit_action_status()`; `ri_done()`; `HX-Trigger: periodChanged` — `calendarChanged` for a calendar)
- `periods/calendars/save.php` (`calendar_set`): `settings.manage`; `calendar.set`; **other**; location `/periods/calendars#calendar-row-{id}`.
- `periods/ensure.php` (`periods_ensure`): `periods.close`; `period.ensure`; location `/periods/?year={year}`.
- `periods/close.php` (`period_close`): `periods.close`; the function's refusals as 422 in words; `period.close`; **other**; location `/periods/{id}`.
- `periods/reopen.php` (`period_reopen`): `settings.manage`; `reason` required by the handler; `period.reopen`; **other**; location `/periods/{id}`.
- `usage/export.php` (`usage_export`): `exports.all`; `export.download`; the download (no `HX-Trigger`); JSON mode answers `{ok, rows, count}` for `format=json` (≤ 2,000 rows — larger windows are told to narrow).
- The screens: `period-list`, `period-view`, `calendar-list`: `ri.read` (values walled by the view); `usage-report`, `usage-ingredient`, `cogs-report`: `reports.read` — a Kitchen member sees units and never values; `log_screen_view()` on each (`usage-report` with `after.from|to|basis|format`).

## Manifest rows claimed
Screens (6): `period-list`, `period-view`, `usage-report`, `usage-ingredient`, `cogs-report`, `calendar-list`
Actions (5): `calendar_set`, `periods_ensure`, `period_close`, `period_reopen`, `usage_export`
Agent approvals: `calendar_set`, `period_close`, `period_reopen` (`other`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `calendar_set` | `/periods/calendars/save.php` | `calendar.set` | settings.manage | other |
| `periods_ensure` | `/periods/ensure.php` | `period.ensure` | periods.close | |
| `period_close` | `/periods/close.php` | `period.close` | periods.close | other |
| `period_reopen` | `/periods/reopen.php` | `period.reopen` | settings.manage | other |
| `usage_export` | `/usage/export.php` | `export.download` | exports.all | |

Every row of the manifest's periods section is claimed; none is left to another slice. (The ledger's `cogs_summary` share is Phase 4's tool
over `ri_share_cogs_summary()`; the formal `export_download` of the five documents is slice 9's — this slice's `usage_export` is the usage
rows alone.)

## Activity log events (every row: `scope_id`; a close with a count: `count_id`)
`calendar.set`, `period.ensure|close|reopen`, `export.download` (document `usage`), `screen.view` (the usage report with `after.from`,
`after.to`, `after.basis`, `after.format`; the period view with `after.period_no`). Values are expected in `period.close`'s `after` (the log
is the record; the views wall the reader).

## Notifications this slice queues
- The worker's `morning_note_inputs` pass (slice 9's) reads `period_to_close()`; this slice queues one notification itself: when a period's
  `ends_on + 3 days < today` and it is still open, `ri_notify()` kind `period_due` to the site's Manager once per period (dedupe
  `period:<id>:due`) — raised by `period_due_check(PDO): int` in `app/features/periods/write.php`, called from the worker's
  `morning_note_inputs` pass (reconciled with slice 9: that pass calls it; DECISION 2).

## Status vocabulary
Period status chips: open `warning`, closed `success`; the period to close marked `primary` ("closes next"); a late row's badge `info`
"late: dated Oct 2 in closed week 40". Usage cells: variance negative `text-danger` with the title "actual below theoretical — check the
data: a receipt missing or a count wrong", unexplained over 2 % of actual `fw-bold`; a walled value "—" with the title "cost withheld".
Ids: `periods-table`, `period-row-{id}`, `period-close-btn`, `period-close-form`, `period-close-form-field-closing_count`,
`period-reopen-form`, `period-reopen-form-field-reason`, `periods-ensure-form`, `period-figure-{name}`, `period-tabs`, `period-late-rows`,
`period-counts`, `usage-form`, `usage-form-field-{name}`, `usage-table`, `usage-row-{ingredient}`, `usage-cat-{category}`, `usage-totals`,
`usage-legend`, `usage-ingredient-buckets`, `usage-consumers`, `cogs-table`, `cogs-row-{period}`, `cogs-chart`, `calendar-form`,
`calendar-form-field-{name}`, `calendar-row-{id}`, `calendar-preview`.

## Mobile rule (375 × 740)
The usage table scrolls inside `.table-responsive` with ingredient · actual · theoretical · unexplained pinned as the first columns (the
rest reachable by scrolling right); the period list stacks each period as a card at 375 px with the five figures in two rows; the close
form's select and button ≥ 44 px; the COGS chart scales to the width. `scrollWidth` = viewport.

## Vocabulary
*costing calendar* (how the year is cut: weeks, months, 4-4-5), *period* (one cut at one site), *opening / closing* (the book figure at the
period's boundaries — the approved count's when one closes it, else the ledger's), *actual usage* (what left the shelves: opening +
purchases ± transfers ± production + adjustments − closing — waste is inside it), *theoretical usage* (what the sales should have used: Σ
depletion), *variance* (actual − theoretical), *explained* (waste logged, comps), *unexplained* (the rest — where the money went
unaccounted), *basis* (the cost each bucket is valued at: the period's weighted average, or last price), *close* (the figures freeze; COGS
is what the ledger reads), *late* (dated in a closed period, landed in the next open one).

## Out of scope for this slice
The approved count itself (slice 6); the depletion runs the close requires (slice 5); the ledger's `cogs_summary` share and the formal
exports (Phase 4 and slice 9); prime cost (labour from txtSchedules — Extended, T2); standard cost and the price/usage split (Extended);
a 53rd week's own period (the calendar function joins it to the last period).

## Proof (`tests/phase3/slice7/run.sh`: the scratch database `ri_dev7`; the application on 9301; the fake kernel 9302; the fake MaluDB 9303;
members through `bin/dev_handoff.php <member> <scope>`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800;
`bin/build_action_registry.php --check`; `bin/sync_approvals.php --check`)
Target: **≥ 170 checks** (calendars 15, periods 20, usage 40, ingredient 15, cogs 15, close 25, reopen 10, late 10, visibility 10, json 10, browser 20).
The world (`tests/phase3/slice7/lib.php` `period_world()`): slices 1–6's worlds (receipts a week and three days back, the prep batch, the POS day
depleted, the waste, the transfer to Airport, the approved count) and `sales_daily` rows for five weeks; Dana (Chef), Marco (Manager), Ann
(Viewer), the owner (admin), the expert.
- [ ] **Calendars**: the owner makes "Weeks" (week, the year start, Monday, default); a second default clears the first; the preview shows 52 or 53
  seven-day periods; a month calendar previews twelve; a 4-4-5 previews twelve of 28/28/35 days; `calendar.set` logged; the expert's `calendar_set`
  **pauses** (`other`).
- [ ] **Periods**: `periods_ensure` makes the year at Downtown (52–53) and 0 the second time; the list shows the current year, the period to close
  marked, the open periods' figures live and equal to `ri_usage()` over their dates; Airport has none until ensured there (a scope's own).
- [ ] **Usage**: the report over the last 7 days at Downtown: for eggs the row reads opening + purchases + transfers in − transfers out + production
  in − production out + adjustments − closing = actual (the identity, every column present), theoretical 3, waste 31, comps 1, unexplained −41 (the
  count surplus) — the same numbers the Phase 0 proof ties; the legend row; the category subtotals sum the rows; the sort by unexplained puts eggs
  first; `basis=last` changes the unit cost and not the units; the CSV answers the same rows; a 400-day window refused in words; a negative
  variance row carries the "check the data" mark.
- [ ] **The ingredient's week**: chicken's buckets; the consumers table names the sandwich (and the stock prep) with shares summing to 100 %; the
  waste logged; the count line; the movements.
- [ ] **COGS**: the year's table with the open period live; the SVG chart has one pair of bars per period and no external script; food-cost % =
  COGS ÷ food sales; a period with no sales shows "—" for the %.
- [ ] **Close**: the current period → "closes after its last day"; the period three weeks back → "An earlier period is still open"; the earlier
  ones closed in order (each freezing figures; the next's opening = the previous ending); a period with an undepleted day → "Sales in the period
  are not yet depleted" and the banner names the day; a closing count not approved → the sentence; the period of the approved count closed with it
  (`closing_count_id` set, `count_id` on the log row); the figures no longer move when a new receipt is posted (frozen); Marco closes (periods.close),
  Dana cannot (403 in words); the expert's `period_close` **pauses** (`other`); `period.close` logged with the values.
- [ ] **Late**: a receipt dated inside the closed period posts (slice 3's handler) → its rows carry `business_date` in the next open period and
  "late:" in the note; the period view's Late tab lists them; the usage report of the closed period does not count them, the next period's does.
- [ ] **Reopen**: the owner reopens the last closed period with a reason; an earlier one → "A later period is closed"; Marco cannot (settings.manage);
  **other** for an agent; `period.reopen` logged with `reason`; the figures live again.
- [ ] **Who sees**: Ann and Bea read the period list and the usage table with every value "—" and the units shown; Dana and Marco see values; a
  period at Airport is 404 for Dana (Downtown only).
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `usage_export` with `format=json`
  answers the rows; 422 `{error: {code: invalid, fields}}` for the close's refusals with the function's words.
- [ ] **375 × 740 and 1280 × 800**: the usage table pins the four key columns at 375 px and shows every column at 1280; the period cards stack; the
  close dialog; the chart scales; no console errors; `scrollWidth` = viewport.

**Decisions taken in this spec (not questions):**
1. Slice 3's receipt handler reports a late landing in its `did` sentence by reading the posted rows' notes (`late_rows()` from this slice's
   `app/features/usage/queries.php`); this slice adds the function, slice 3 the sentence — reconciled by name here.
2. The `period_due` notification is raised by `period_due_check()` in this slice and called from slice 9's `morning_note_inputs` worker pass.
3. The usage window is capped at 400 days (the function is per-row `ri_on_hand_as_of`, linear in movements; a year is the honest limit for the
   screen — the tools cap at 400 too).
4. The COGS chart is a server-rendered inline SVG (two bars per period, the design system's dataviz neutrals) — no chart library.
5. An open period's figures are computed live on the list (one `ri_usage()` call per open period shown — at most a handful) and never stored
   until the close.
6. The monthly roll-up of a weekly calendar is a grouping on the COGS report (subtotals per calendar month), not a second calendar; a business
   that closes books monthly on a weekly calendar reads the month's subtotal; one that wants month-end closes picks the `month` kind.
7. The late tab reads the note's "late:" prefix (the trigger's); no column is added to the ledger for it.

## Open questions
(none)
