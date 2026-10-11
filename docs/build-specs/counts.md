# Build spec: counts — sheets, the count at 375 px, review, recount, approval (slice 6)

Built by the worker model on slice 1's CRUD pattern and slice 3's document pattern (`docs/build-specs/ledger.md`), replicating slice 2's
files, gates and proof style (`docs/build-specs/recipes.md`, the first exemplar). What exists at the end: a Chef keeps **count sheets** per
site — every storage area in walk order, every ingredient in shelf order with the unit it is counted in; the bar's bottles and kegs have
**containers** with full and empty weights; a cook starts a **count** (the whole site or a sheet, as of the day's sales cut) and walks the
walk-in with a phone — one area at a time, a case + loose pair per line, the scale's gross weight for a bottle, zero, skip — and **the
entries survive a dead signal** (kept in the browser, resent when the network returns); the book figure stays hidden on a blind count; a
Chef **reviews** every column, a second person **recounts** what is over tolerance, the Chef **approves** and the corrections post dated
at the count's moment; an approved count's history shows who counted what and what it corrected. **Every quantity here is a count in a
unit the database converts**, never a balance written by hand; approval is a person's (an agent pauses — `other`), discarding is a
`deletion`.
Schema: `containers`, `ri_container_remaining_base()`, `count_sheets`, `count_sheet_lines`, `ri_count_sheet_fill()`, `inventory_counts`,
`inventory_count_lines`, `ri_count_lines_guard()`, `ri_count_start()`, `ri_count_line_set()`, `ri_count_review()`, `ri_count_approve()`,
`ri_count_discard()`, `ri_count_areas_skipped()` (db/012); `ri_count_variance()` (db/016); `ri_site_moment()` (db/013); `ri_to_base()`,
`ri_from_base()`, `ingredient_units`, `storage_areas`, `ri_depletion_area()` (db/007); `ri_post_txn()` and the `count_correction` rows
(db/009); `reason_codes` (`count_correction`); `ri_sees_cost()`, `ri_has_right()` (db/004); the views `mcp_count_sheets`,
`mcp_containers`, `mcp_inventory_counts`, `mcp_inventory_count_lines` (the blind rule lives HERE: `system_qty_base`, `variance_base`
and `variance_value` are null for a counter while a blind count is `open`; an approver — `counts.approve` at the site — and anyone once
the count leaves `open` see them; `variance_value` also behind `ri_sees_cost`), `mcp_storage_areas`, `mcp_ingredients`,
`mcp_ingredient_units`, `mcp_inventory_balances`, `mcp_inventory_transactions`, `mcp_reason_codes`, `mcp_activity_log` (db/017). Never
modify them. **The database is the referee**: one full count open per site (the partial unique index), a count's lines change only while
it is open, in review or in recount (`ri_count_lines_guard()`), the book figure is frozen as of the count's moment at start, a counted
line carries entries or a weight ("A counted line carries entries or a weight"), a recount is by another person, approval waits for
every recount unless forced, an approved count is never discarded, a skipped line posts nothing; the handlers call the verb inside
`ri_guard()` and show its sentence as a 422; PHP sums no quantity and computes no variance.

## Screens (the count sheet is designed at 375 px first — a cook in the walk-in; the rest usable at 375, designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `count-sheet-list` | `/counts/sheets/` | cards (`sheet-card-{id}`): name, kind chip, areas covered, lines, the last count made from it; **New sheet** (`ingredients.write`) opens a small form in the page header (name, kind, "fill from the shelves" checkbox → `count_sheet_save` with `fill`); an archived sheet `dark` |
| `count-sheet-edit` | `/counts/sheets/{sheet}` | the sheet by storage area in walk order (`sheet-area-{area}`): each area a card with its lines (`sheet-line-{id}`: ingredient, count unit select (the ingredient's units of kind count or purchase + its base), a drag handle — SortableJS on `.sheet-lines` posts `count_sheet_reorder` with `lines[]`; without JavaScript ▲/▼ buttons post the same with the moved order), **Remove**; under each area the add row (`sheet-add-form-{area}`: the record picker `/ingredients/pick`, the unit) → `count_sheet_line_set`; **Fill from the shelves** adds every ingredient with a balance not yet on the sheet (`ri_count_sheet_fill()`); **Start a count from this sheet** → `count-add?sheet=`; `data-entity="count_sheet"` |
| `container-list` | `/counts/containers/?ingredient=` | the table `containers-table` (`container-row-{id}`): ingredient, name, full g, empty g, pourer tare g, volume ml, default; the inline form at the top (`container-form`, `container-form-field-{name}`: ingredient picker (liquor, beer, wine — any volume ingredient), name, full_weight_g, empty_weight_g, pourer_tare_g, volume_ml, is_default) → `container_save`; **Edit** opens the row's values in the form (Pattern C) |
| `count-list` | `/counts/?status=&kind=&from=&to=&page=` | the table `counts-table` (`count-row-{id}`): number (a link), kind chip, as of (site time), status chip, started by/at, lines counted / total, over tolerance, total variance value (walled — "—"), approved by/at; **Start a count** (`stock.count`) → `count-add`; 50 a page |
| `count-add` | `/counts/new?sheet=&kind=` | the form (`count-form`, `count-form-field-{name}`): sheet (select of active sheets + "the whole site"), kind (full · cycle · spot), as of (datetime-local in the site's zone — **the day's sales cut by default**: `ri_site_moment(site, today, true)` shown as "tonight at 04:00"; a count taken this morning for last night picks yesterday's cut), blind (checkbox from `ri_settings.blind_counts`; a Chef may untick), notes → **Start** (`count_start`) lands on `count-view` |
| `count-view` | `/counts/{count}?area=` | **the count sheet**: the header (number, kind, as of, the area pager `count-area-pager`: one storage area at a time in walk order, prev/next, a progress bar `count-progress` "14 of 31 lines"); the lines of the area (`count-line-{id}`): the ingredient's name and the unit on the shelf; for a counted-by-unit line two inputs **cases** (`count-line-{id}-whole`, the default count unit) and **loose** (`count-line-{id}-loose`, the base unit or the next unit down) — any ingredient's other count units reachable under "other unit"; for an ingredient with a container the **scale** input (`count-line-{id}-gross`, grams; the container select when several) beside the full-bottles input; **0** and **Skip** buttons; the line's state chip (pending · counted · zero · skipped); the book figure hidden when the count is blind (the view nulls it — the page never receives it); saved per line on change → `count_line_set` (Pattern C: the row re-renders); **Found on the shelf** (`count-add-line-form`: picker + entries) → `count_line_add`; **Review** (`count_review`, enabled when no line is pending — the handler's sentence otherwise); `count-sheet.js` keeps every unsent entry in `localStorage` (below); `data-entity="inventory_count"` |
| `count-review` | `/counts/{count}/review?over_only=` | the review table `review-table` (`review-row-{id}`): area, ingredient, book (base and count unit), counted, variance in units (coloured) and at cost (walled), the tolerance (the ingredient's % and value), **over** flag, state, counted by; the areas skipped listed above the table (`review-skipped`: `ri_count_areas_skipped()`); on a `recount` count the over-tolerance rows show a recount input pair for **another person** (`count_recount`) and the counter's own name greyed; **Approve** (`count_approve`, `hx-confirm` "Approve CNT-00007: 4 corrections at Walk-in and Dry store?"; a count in `recount` offers **Approve anyway** with `force`), **Discard** (`count_discard`, `hx-confirm`); the totals row: lines, over tolerance, Σ variance value (walled) |
| `count-history` | `/counts/{count}/history` | an approved count: the corrections it posted (`mcp_inventory_transactions` where `reference_kind = 'count'`: ingredient, area, delta, cost, value — walled), the variance by category (a table from the lines joined to `mcp_ingredients`), who counted what (lines by `counted_by`, `recount_by`), the review's skipped areas, the trail |

## The count form (`count-form`, ids `count-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| sheet | select (active sheets of the site, `count_sheets()`) + "the whole site" | no | the whole site = every ingredient with a balance in every active area, in walk order |
| kind | select full · cycle · spot | yes | full by default; one full count open at a time (the index) |
| as_of | datetime-local in the site's zone | yes | `default_count_as_of()` — tonight's sales cut; before 04:00 site time, yesterday's; never in the future by more than a day; the handler converts to UTC with `site_timezone()` |
| blind | checkbox | no | from `ri_settings.blind_counts`; a Chef may untick; a Kitchen member sees it disabled at the setting's value (DECISION 6) |
| notes | textarea ≤ 2,000 | no | |

## The sheet and container forms
| Form | Field | Input | Required | Rule |
|---|---|---|---|---|
| `sheet-form` | name | text ≤ 80 | yes | unique per site (the UNIQUE — "A sheet named X exists at this site") |
| | kind | select full · cycle · spot | no | full by default |
| | fill | checkbox | no | `ri_count_sheet_fill()` after the insert — every ingredient with a balance |
| `sheet-add-form-{area}` | ingredient | the record picker `/ingredients/pick` | yes | active ingredients; one line per ingredient per area (the UNIQUE) |
| | count_unit | select (`sheet_unit_options()`) | no | the ingredient's default count unit by default |
| `container-form` | ingredient | the record picker, volume ingredients | yes | |
| | name | text ≤ 60 | yes | unique per ingredient |
| | full_weight_g, empty_weight_g | number, 1 decimal | yes | empty < full (the CHECK, mapped) |
| | pourer_tare_g | number | no | 0 by default |
| | volume_ml | number | yes | > 0 |
| | is_default | checkbox | no | one default per ingredient — the handler clears the previous |

## The count line entry (`count_line_set`), exactly
A line's POST carries `count_line` and ONE of: `entries` (JSON `[{unit_code, qty}]` — the two inputs are sent as `whole` + `whole_unit`
and `loose` + `loose_unit` by the form and folded into `entries` by the handler; an agent sends `entries` itself), `gross_weight_g`
(+ `container`), `state = zero`, `state = skipped`. The handler validates: a known count line at a held site (`require_visible` on
`mcp_inventory_count_lines`); `qty` ≥ 0 and finite; a unit the ingredient converts (the database's "No unit X for Y" sentence is the
422); `gross_weight_g` > 0 only for an ingredient with a container ("No container is known for this ingredient — a weight cannot be
read" from the function); then `SELECT * FROM ri_count_line_set(:line, :me, :entries::jsonb, :gross, :container, :state, :note)`. The
reply (JSON and the Pattern C row) carries the line's `counted_qty_base`, its display in the count unit (`ri_from_base`), the state and
— never on a blind open count — the book figure. **Idempotent**: the same entries twice leave the same line.

## Posting, step by step (what each handler calls)
- **Start** `count_start` → `SELECT * FROM ri_count_start(:site, :me, :sheet, :as_of, :kind, :blind)`: the lines from the sheet (or every
  balance at the site in walk order), `system_qty_base` = the balance now minus what moved after `as_of` (the function's); a second full
  count open at the site → the unique index's sentence mapped ("A full count is already open at this site — finish or discard it").
  Log `count.start` (`number`, `kind`, `sheet_id`, `as_of`, `blind`, `lines`) with `count_id` and `scope_id` as audit keys.
- **A line** `count_line_set` → `ri_count_line_set()` (above). Log `count.line_set` (`count_line_id`, `ingredient_id`, `state`,
  `counted_qty_base`, `entries` as sent, `gross_weight_g`) with `count_id`, `ingredient_id`; a Pattern C answer. **A line found on the shelf**
  `count_line_add` → INSERT `inventory_count_lines` (the area, the ingredient, `shelf_order` = max + 1, `count_unit_code` the default count
  unit, `system_qty_base` = the balance in that area now minus movements after `as_of`, computed by one SQL statement in `write.php`
  exactly as `ri_count_start()` does) then `ri_count_line_set()` with the entries when given. Log `count.line_add`.
- **Review** `count_review` → `ri_count_review(:count, :me)`: variances, tolerances (the ingredient's `variance_tolerance_pct` / `_value`,
  else 5 % and $10), the status `review` or `recount` when any line is over tolerance and not yet recounted; pending lines → the function's
  "N lines are still pending" (the screen disables the button and says which). Log `count.review` (`number`, `lines`, `over_tolerance`,
  `total_variance_value`).
- **Recount** `count_recount` → `ri_count_line_set()` again with the second person as `p_by` on a count in `recount` whose line is
  `over_tolerance` — the function writes `recount_qty_base` and refuses the same person ("A recount is by another person"); then the
  handler calls `ri_count_review()` again so the flags and the status refresh. Log `count.recount` (`count_line_id`, `recount_qty_base`,
  `recount_by`).
- **Approve** `count_approve` → `ri_count_approve(:count, :me, :force)`: a `count_correction` per counted or zeroed line whose counted (or
  recounted) figure differs from the frozen book figure, dated `as_of`, reason `count_correction`, one group; skipped lines nothing; a
  count with lines awaiting a recount refused unless `force`. **other** for an agent (pauses). Log `count.approve` (`number`,
  `corrections`, `units_delta` by ingredient (≤ 50 rows), `total_variance_value`, `forced`, `areas_skipped`) with `count_id`, `scope_id`.
- **Discard** `count_discard` → `ri_count_discard(:count, :me)`; an approved one → the function's sentence. **deletion** for an agent.
  Log `count.discard` (`number`, `reason`).
- **Sheets**: `count_sheet_save` INSERT/UPDATE `count_sheets` (+ `ri_count_sheet_fill()` when `fill`); `count_sheet_line_set` INSERT/UPDATE
  `count_sheet_lines` (the unit must be one the ingredient converts — `ri_to_base(ingredient, 1, unit)` in `ri_guard()`);
  `count_sheet_line_remove` DELETE; `count_sheet_reorder` renumbers `shelf_order` 1..n in the order given, one statement.
  Log `count_sheet.save|line_set|line_remove|reorder` (`sheet_id`, `area_id`, `lines`).
- **Containers**: `container_save` INSERT/UPDATE `containers` (empty < full by the CHECK — "The empty weight must be less than the full
  weight" mapped in `DB_CHECK_SENTENCES`); one default per ingredient (the partial unique index — the handler clears the old default in the
  same transaction when `is_default`). Log `container.save` (`container_id`, `ingredient_id`, `full_weight_g`, `empty_weight_g`, `volume_ml`).

## `count-sheet.js` — the entries that survive a dead signal (exactly)
- Bound on `count-view`'s `#count-lines` (and re-bound on `htmx:afterSwap`). Each line's inputs carry `data-line`, `data-field`
  (`whole`, `loose`, `gross`, `container`). On `change` (and on a 600 ms debounce of `input`), the script builds the line's payload
  `{count_line, whole, whole_unit, loose, loose_unit, gross_weight_g, container}` (or `{state}` for the 0 / Skip buttons), writes it to
  `localStorage` under `ri.count.<count_id>.<line_id>` with `{payload, at}`, and POSTs it to `/counts/line.php` with `fetch` (the CSRF token
  from `meta[name=csrf]`, `X-Requested-With`). On `200` it removes the key and swaps the row with the response's HTML (the Pattern C
  fragment); on a 422 it shows the sentence under the line and removes the key (the person must fix it); on a network error or 5xx it keeps
  the key, marks the row `line-pending` (an amber dot, the title "saved on this phone — not yet sent"), and shows the sticky banner
  `#count-offline` "N entries saved on this phone. They are sent when the signal returns."
- A resend runs on `online`, on `visibilitychange` to visible, and every 20 s while keys exist: oldest first, one at a time, the same
  rules. On page load the script replays any keys for this count before anything else (the person may have reopened the page).
- Without JavaScript every input pair sits in its own `<form>` posting the same fields with a **Save** button; the 0 and Skip buttons are
  forms too; the banner never appears. Nothing is lost either way; the server is the truth and the keys are a queue, not a store.
- The script never computes a quantity in base units or a variance — the server answers both.

## Files (exactly these)
- `html/counts/index.php` (`count-list`) · `form.php` (`count-add`) · `view.php` (`count-view`) · `review.php` (`count-review`) · `history.php` (`count-history`) · `start.php` (`count_start`) · `line.php` (`count_line_set`) · `line-add.php` (`count_line_add`) · `review-post.php`? — **no**: `review.php` answers GET (the screen) and POST (`count_review`) by method (DECISION 1) · `recount.php` (`count_recount`) · `approve.php` (`count_approve`) · `discard.php` (`count_discard`)
- `html/counts/sheets/index.php` (`count-sheet-list`) · `view.php` (`count-sheet-edit`) · `save.php` (`count_sheet_save`) · `line.php` (`count_sheet_line_set`) · `line-remove.php` · `reorder.php`
- `html/counts/containers/index.php` (`container-list`) · `save.php` (`container_save`)
- `app/features/counts/{queries,present,write,handler}.php` (`handler.php`: `count_from_request()`, `require_count_open()`, `entries_from_request()` — the whole/loose pair → `entries` JSON, `count_log()` — `count_id` and `scope_id` on every row, `ingredient_id` on a line's)
- `app/views/counts/{index,form,view,review,history,partials/count-row,partials/area-pager,partials/count-line,partials/review-row,partials/skipped,partials/add-line-form}.php` · `app/views/counts/sheets/{index,view,partials/sheet-card,partials/sheet-area,partials/sheet-line}.php` · `app/views/counts/containers/{index,partials/container-row}.php`
- `html/assets/js/count-sheet.js` (above) — `html/assets/vendors/sortablejs/Sortable.min.js` is the kit's
- `app/partial_update.php`: `PARTIAL_UPDATE_TARGETS` gains `'/counts/sheets/save.php' => ['count_sheets', 'sheet', 'mcp_count_sheets', 'count_sheet_id']`, `'/counts/containers/save.php' => ['containers', 'container', 'mcp_containers', 'container_id']`
- `app/features/shell/nav.php`: `back_link()` learns `/counts/{id}` "the count", `/counts/sheets/{id}` "the sheet"; `record_url()` learns `inventory_count`, `count_sheet`, `container`
- `deploy/apache-restaurant_inventory.conf` and `tests/dev_router.php`: `/counts/{id}/review` → `review.php?id=`, `/counts/{id}/history` → `history.php?id=`, `/counts/sheets/{id}` → `sheets/view.php?id=` (the generic `/{x}/{id}/{page}` rule covers the first two; one line for the sheet)

## Query functions (signatures fixed; PDO first; the site from `current_site_id()` unless given — the tools of Phase 4 call these)
- `counts(PDO, int $scopeId, array $filters, int $limit = 50, int $offset = 0): array` (`mcp_inventory_counts`; `status[]`, `kind`, `from`, `to`) — tool `counts` · `find_count(PDO, int $id): ?array` — tool `get_count`
- `count_lines(PDO, int $countId, ?int $areaId = null, bool $overOnly = false): array` (`mcp_inventory_count_lines` ⨝ `mcp_ingredients`, `mcp_storage_areas`, the display quantities through `ri_from_base`; the view nulls the book figure on a blind open count — the function passes the nulls through) — tool `count_lines` · `count_areas(PDO, int $countId): array` (areas in walk order with counted / total — the pager) · `count_progress(PDO, int $countId): array` (`{lines, pending, counted, zero, skipped}`)
- `count_variance(PDO, int $countId, bool $overOnly = false): array` (`ri_count_variance(count)` + names and units) — tool `count_variance` · `count_areas_skipped(PDO, int $countId): array` (`ri_count_areas_skipped`) — tool `areas_skipped` · `count_corrections(PDO, int $countId): array` (`mcp_inventory_transactions` where `reference_kind = 'count'`) · `count_variance_by_category(PDO, int $countId): array`
- `count_sheets(PDO, int $scopeId, bool $includeInactive = false): array` (`mcp_count_sheets`) — tool `count_sheets` · `find_count_sheet(PDO, int $id): ?array` · `count_sheet_areas(PDO, int $sheetId): array` (the lines grouped by area in walk order with names and the unit options) · `sheet_unit_options(PDO, int $ingredientId): array` (`mcp_ingredient_units` kind count or purchase + the base)
- `containers(PDO, ?int $ingredientId = null): array` (`mcp_containers` ⨝ `mcp_ingredients`) · `find_container(PDO, int $id): ?array` · `container_for(PDO, int $ingredientId): ?array` (the default)
- `default_count_as_of(int $scopeId): string` (`ri_site_moment(site, today, true)` as an ISO string in the site's zone — the form's default; before 04:00 site time it answers yesterday's cut)
- Writes (`write.php`): `start_count(PDO, int $scopeId, int $by, ?int $sheetId, ?string $asOf, string $kind, ?bool $blind, ?string $notes): array` · `set_count_line(PDO, int $lineId, int $by, ?array $entries, ?float $grossG, ?int $containerId, string $state, ?string $note): array` (the row re-read through the view) · `add_count_line(PDO, int $countId, int $areaId, int $ingredientId, ?array $entries, int $by): array` · `review_count(PDO, int $countId, int $by): array` · `recount_line(PDO, int $lineId, int $by, ?array $entries, ?float $grossG, ?string $note): array` · `approve_count(PDO, int $countId, int $by, bool $force): array` (the row + corrections, units_delta by ingredient) · `discard_count(PDO, int $countId, int $by, ?string $reason): array` · `save_count_sheet(PDO, int $scopeId, ?int $id, array $fields, bool $fill, int $by): int` · `set_sheet_line(PDO, int $sheetId, ?int $lineId, int $areaId, int $ingredientId, ?int $shelfOrder, ?string $unit): int` · `remove_sheet_line(PDO, int $lineId): void` · `reorder_sheet_lines(PDO, int $sheetId, int $areaId, array $lineIds): void` · `save_container(PDO, ?int $id, array $fields): int`

## Handlers (every one: `ri_handler_begin()`; the right at the site (`require_right(right, site)`); `require_site()` on the record's site; `ri_guard()`; `log_activity` with `count_id`, `scope_id` (and `ingredient_id` on a line); `emit_action_status()`; `ri_done()`; `HX-Trigger: countChanged` — `countSheetChanged` for a sheet, `containerChanged` for a container)
- `counts/start.php` (`count_start`): `stock.count`; `count.start`; location `/counts/{id}`. `counts/line.php` (`count_line_set`): `stock.count`; the entry rules above; `count.line_set`; location `/counts/{count}?area={area}#count-line-{id}`; a Pattern C request answers the line partial. `counts/line-add.php` (`count_line_add`): `stock.count`; `count.line_add`. `counts/review.php` POST (`count_review`): `stock.count`; `count.review`; location `/counts/{id}/review`. `counts/recount.php` (`count_recount`): `stock.count`; `count.recount`; a Pattern C row. `counts/approve.php` (`count_approve`): `counts.approve`; `count.approve`; **other**; location `/counts/{id}/history`. `counts/discard.php` (`count_discard`): `counts.approve`; `count.discard`; **deletion**; location `/counts/`.
- `counts/sheets/save.php` (`count_sheet_save`): `ingredients.write`; `count_sheet.save` (`after`: name, kind, fill, lines); location `/counts/sheets/{id}`. `sheets/line.php` (`count_sheet_line_set`): `count_sheet.line_set`; a Pattern C row. `sheets/line-remove.php`: `count_sheet.line_remove`. `sheets/reorder.php` (`count_sheet_reorder`): `count_sheet.reorder` (`after.lines` the ids in order); no location (204 for HTMX, JSON `ok`).
- `counts/containers/save.php` (`container_save`): `ingredients.write`; `container.save`; location `/counts/containers/?ingredient={ingredient}#container-row-{id}`.
- The screens: `count-list`, `count-view`, `count-add`: `stock.count` (a Viewer reads the list and a count's history — `ri.read` — but not the sheet to fill; DECISION 2); `count-review`: `stock.count` to read, the Approve and Discard buttons for `counts.approve`; `count-history`: `ri.read`; the sheets and containers: `ri.read` to see, `ingredients.write` to change; `log_screen_view()` on each with `count_id` where there is one.

## Manifest rows claimed
Screens (8): `count-sheet-list`, `count-sheet-edit`, `container-list`, `count-list`, `count-add`, `count-view`, `count-review`, `count-history`
Actions (12): `count_sheet_save`, `count_sheet_line_set`, `count_sheet_line_remove`, `count_sheet_reorder`, `container_save`, `count_start`, `count_line_set`, `count_line_add`, `count_review`, `count_recount`, `count_approve`, `count_discard`
Agent approvals: `count_approve` (`other`), `count_discard` (`deletion`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `count_sheet_save` | `/counts/sheets/save.php` | `count_sheet.save` | ingredients.write | |
| `count_sheet_line_set` | `/counts/sheets/line.php` | `count_sheet.line_set` | ingredients.write | |
| `count_sheet_line_remove` | `/counts/sheets/line-remove.php` | `count_sheet.line_remove` | ingredients.write | |
| `count_sheet_reorder` | `/counts/sheets/reorder.php` | `count_sheet.reorder` | ingredients.write | |
| `container_save` | `/counts/containers/save.php` | `container.save` | ingredients.write | |
| `count_start` | `/counts/start.php` | `count.start` | stock.count | |
| `count_line_set` | `/counts/line.php` | `count.line_set` | stock.count | |
| `count_line_add` | `/counts/line-add.php` | `count.line_add` | stock.count | |
| `count_review` | `/counts/review.php` | `count.review` | stock.count | |
| `count_recount` | `/counts/recount.php` | `count.recount` | stock.count | |
| `count_approve` | `/counts/approve.php` | `count.approve` | counts.approve | other |
| `count_discard` | `/counts/discard.php` | `count.discard` | counts.approve | deletion |

Every row of the manifest's counts section is claimed; none is left to another slice. (The manifest's "Size" line says 7 screens for this
section; the section lists 8 — `count-history` is the eighth. The registry is the truth; the Size line should read 8 / 118.)

## Activity log events (every row: `count_id`, `scope_id`; a line's row: `ingredient_id`)
`count.start|line_set|line_add|review|recount|approve|discard`, `count_sheet.save|line_set|line_remove|reorder`, `container.save`,
`screen.view` (the count views with `after.number`, the review with `after.over_only`). Quantities in `after` are base quantities with
the entries as sent; variance values are expected in `count.approve`'s `after` (the log is the record; the views wall the reader).

## Notifications this slice queues
- `count_review` → `ri_notify()` kind `count_review` to every member holding `counts.approve` at the site ("CNT-00007 is ready to review: 31
  lines, 2 over tolerance", dedupe `count:<id>:review`); when the status is `recount`, kind `count_recount` to the members holding
  `stock.count` at the site other than the counter ("2 lines of CNT-00007 need a second count", dedupe `count:<id>:recount`).
- `count_approve` → kind `variance` to the site's Manager (`site_settings.manager_member_id`, else none) when `total_variance_value`
  exceeds 1 % of the last closed period's `cogs_value` or $100 when none (DECISION 3), dedupe `count:<id>:variance`.

## Status vocabulary
Count status chips: open `warning`, review `info`, recount `danger`, approved `success`, discarded `dark`. Line state chips: pending
`secondary`, counted `success`, zero `secondary` ("0"), skipped `dark`; an over-tolerance line's variance cell `text-danger` with the title
"over tolerance: ±N % or $N". Sheet kind chips: full `primary`, cycle `info`, spot `secondary`. Ids: `counts-table`, `count-row-{id}`,
`count-form`, `count-form-field-{name}`, `count-area-pager`, `count-progress`, `count-lines`, `count-line-{id}`,
`count-line-{id}-whole`, `count-line-{id}-loose`, `count-line-{id}-gross`, `count-line-{id}-container`, `count-line-{id}-zero`,
`count-line-{id}-skip`, `count-line-{id}-state`, `count-add-line-form`, `count-offline`, `count-review-btn`, `review-table`,
`review-row-{id}`, `review-row-{id}-recount`, `review-skipped`, `review-totals`, `count-approve`, `count-approve-force`, `count-discard`,
`sheet-card-{id}`, `sheet-area-{area}`, `sheet-line-{id}`, `sheet-add-form-{area}`, `sheet-fill`, `containers-table`, `container-row-{id}`,
`container-form`, `container-form-field-{name}`.

## Mobile rule (375 × 740)
The count sheet is one column: the area pager and the progress bar sticky under the header (`.scan-sticky`, lifted from `.main-content`'s
clip as slice 3 does), one line per card-row with the ingredient's name, the two inputs side by side (`inputmode="decimal"`, 44 px tall),
the 0 and Skip buttons under them, the state chip at the right; the scale input shown only for a container ingredient; the offline banner
fixed at the bottom; **Review** in the page header. The review table scrolls inside `.table-responsive` with ingredient · book · counted ·
variance visible and the rest under a tap. Every control ≥ 44 px; `scrollWidth` = viewport.

## Vocabulary
*sheet* (the template: areas in walk order, ingredients in shelf order, each with its count unit), *count* (one walk of the sheet as of a
moment), *as of* (the moment the book figure is frozen at — the day's sales cut by default), *book figure* (`system_qty_base`: the balance
as of the moment), *entries* (what was counted, in the units on the shelf: a case + loose), *gross weight* (the scale's reading of a bottle
or keg with its pourer), *container* (the bottle's full and empty weights), *blind* (the book figure hidden until review), *tolerance* (the
ingredient's % and value — both must be exceeded), *recount* (a second count of an over-tolerance line by another person), *approval*
(the Chef's word; the corrections post), *skipped* (a line not counted — posts nothing, listed on the review and the close).

## Out of scope for this slice
The period close that names a count as its closing count (slice 7 — `period_close` takes `closing_count`); the usage report's reading of
the corrections (slice 7); the variance's explained buckets (slice 7's `ri_usage`); the morning note's "last count" heading (slice 8 reads
`count_variance`); a scale connected by Bluetooth (Extended — the gross weight is typed); cycle-count scheduling (Extended).

## Proof (`tests/phase3/slice6/run.sh`: the scratch database `ri_dev6`; the application on 9301; the fake kernel 9302; the fake MaluDB 9303;
members through `bin/dev_handoff.php <member> <scope>`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800 with
`context.setOffline(true)` for the offline checks; `bin/build_action_registry.php --check`; `bin/sync_approvals.php --check`)
Target: **≥ 200 checks** (sheets 25, containers 12, start 15, lines 40, offline 15, review 25, recount 15, approve 20, history 10, visibility 15,
json 15, browser 25).
The world (`tests/phase3/slice6/lib.php` `count_world()`): slices 1–3's worlds (the Downtown site 101 with Walk-in, Dry store, Bar; flour, chicken,
eggs, limes, gin, oil, the stock prep; the receipts of slice 3 so balances exist); Bea (Kitchen at Downtown), Dana (Chef), Marco (Manager),
Ann (Viewer), the expert (an agent Chef), the Purchasing agent.
- [ ] **Sheets**: Dana makes "Full house" with fill → a line per ingredient with a balance in each area in walk order; adds limes to the Bar with
  unit `each`; reorders the Walk-in's lines by drag (SortableJS posts `lines[]`) and by ▲/▼ without JavaScript; a unit the ingredient cannot
  convert → the database's sentence; removes a line; Bea (no `ingredients.write`) → 403 in words; `count_sheet.save|reorder` logged.
- [ ] **Containers**: a 750 ml gin bottle (1 240 / 500 / 30 / 750); empty ≥ full refused in words; a second container not default; `container.save`.
- [ ] **Start**: Bea starts a full count from the sheet; the form's default as-of is tonight's cut (`ri_site_moment`) and before 04:00 site time
  yesterday's; the lines carry the book figure frozen as of then (a depletion run dated at the cut IS inside it — the proof's lesson); a second full
  count → "A full count is already open at this site"; a spot count may open beside it; `blind` follows the setting; `count.start` logged with `lines`.
- [ ] **Lines**: Bea enters 6 bags + 5 lb of flour → `counted_qty_base` 6 × 22 679.6185 + 5 × 453.59237; 10 flats + 7 eggs → 307; 11 bottles + a
  900 g weighed bottle of gin → 8 625 ml; a line with no entries and no weight → "A counted line carries entries or a weight"; a weight on limes →
  "No container is known"; a unit the ingredient cannot convert → the sentence; 0 and Skip set the state; a line found on the shelf (olive oil in the
  Dry store) added with 2 cases; the same entries sent twice leave the line unchanged; the Pattern C answer is the line partial; the book figure is
  absent from Bea's page and JSON on the blind count and present for Dana (the view's rule); `count.line_set` logged with `ingredient_id`.
- [ ] **Offline**: in headless Chromium at 375 × 740 with the network cut after the page loads, three entries typed → three `localStorage` keys, the
  amber dots and the banner "3 entries saved on this phone"; the network restored → the keys drain oldest first, the rows re-render from the server,
  the banner clears; a page reload while offline shows the pending marks from the keys; a 422 (a bad unit) clears its key and shows the sentence;
  JavaScript off: every input pair posts as a form and saves.
- [ ] **Review**: with a line pending, Review is refused in the function's words ("1 lines are still pending" — mapped to "1 line is still pending");
  with limes zeroed (300 on the book) and eggs at 307 (266 on the book) the review flags both over tolerance → status `recount`; flour five pounds
  over on six bags is within tolerance; the skipped area (the Bar's chicken? — the chicken and the stock skipped) listed; the totals row; the
  review notification to Dana and the recount notification to the other counters.
- [ ] **Recount**: Bea's recount of her own line → "A recount is by another person"; Dana recounts limes at 298 and eggs at 307; the second review →
  status `review`, limes −2, the flags cleared; `count.recount` logged with `recount_by`.
- [ ] **Approve**: approve before the recount → "2 lines over tolerance await a recount"; `force` by Dana approves anyway on a copy of the world;
  the real path: after the recount Dana approves → `count_correction` rows for flour, eggs, gin, limes dated at `as_of`, reason `count_correction`,
  one group; the skipped chicken untouched; the balances now equal the counted figures (eggs 307, limes 298); the expert's `count_approve` through
  the Actions MCP path **pauses** (`other`); the variance notification to Marco when over the threshold; `count.approve` logged with `corrections`.
- [ ] **History and discard**: the history lists the four corrections with cost (walled for Ann), the variance by category, who counted what; a
  discard of the approved count → "An approved count is never discarded"; a spot count discarded → `discarded`, nothing posted; **deletion** for the agent.
- [ ] **Who sees**: Ann reads the count list and a history with "—" for every value; Ann cannot open the count sheet to fill (403 in words); Marco
  at Airport sees no Downtown count (404); the Chef sees the book figure on an open blind count, the counter does not.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `count_start` with `as_of` and
  `sheet`; `count_line_set` with `entries` JSON; `count_approve` with `force`; 422 `{error: {code: invalid, fields}}`; the expert starts a spot count
  as `source agent`.
- [ ] **375 × 740 and 1280 × 800**: the count sheet one area at a time with the pager sticky, the inputs 44 px, the progress bar advancing, the banner
  at the bottom when offline; the review table at 1280 with every column; the sheet editor's drag; no console errors; `scrollWidth` = viewport.

**Decisions taken in this spec (not questions):**
1. `review.php` answers GET (the review screen) and POST (`count_review`) by method — one file, as the kernel's and Inventory's screens do for a
   screen whose button only recomputes.
2. A Viewer reads counts and their history (`ri.read`) and never the sheet to fill; a Kitchen member fills; a Chef reviews and approves; a
   Manager holds the Chef's rights.
3. The variance notification to the site's Manager fires when the approved count's total variance value exceeds 1 % of the last closed
   period's COGS, or $100 when no period is closed yet.
4. A line found on the shelf takes the area's book figure as of the count's moment, computed in `write.php` by the same statement
   `ri_count_start()` uses — the schema has no verb for one line; Phase 1 reconciles if the schema gains `ri_count_line_add()`.
5. The whole/loose pair is folded into `entries` by the handler; an agent sends `entries` directly; the server converts and sums — the script
   never does.
6. The blind checkbox is a Chef's to untick on the start form; a Kitchen member starts a count at the setting's value.
7. The review's "N lines are still pending" sentence is mapped to singular/plural words by `db_message()` like the ledger's CHECK sentences.

## Open questions
(none)
