# Build spec: POS sources, sales and depletion — THE SECOND EXEMPLAR (slice 5)

Built by the planning-class model, after slices 1–3 (the ingredients, the recipes and the menu map, the ledger) and before slice 6 (counts need the
day's depletion in the book figure). What exists at the end: the admin adds the restaurant's POS as a **source** — a file source fed by an upload, by
the POS emailing its nightly export to an address at MaluMail, or by an SFTP pull — or the Square connector with a key sealed as a credential; a person
drops yesterday's export and sees the first rows, the dates it holds and what will be imported before pressing Import; the checks and their lines land,
every PLU resolved to a menu item (an unknown one created unmapped and queued — never dropped), the day part set from the close time; **one depletion run
per site per business date** explodes every mapped line and modifier through the recipe version in force that day and posts one ledger row per
ingredient per area, with a void never depleting and a comp always; the run's card says how many lines, how many unmapped, how many ingredients, at
what value; a day is re-run after a PLU is mapped and the first run is reversed; the worker pulls on schedule, reads the intake mailbox, and runs the
days that are due. This is the second exemplar: the connector interface, the mapping templates, the import's preview-then-commit, the run as a
re-runnable document, the unmapped queue linking to slice 2's map, and the worker's passes are the pattern slices 8 and 9 copy.
Schema: `sources` (+ `ri_connectors()`), `source_credentials`, `source_pulls`, `sales_file_mappings`, `pos_sales` (+ `ri_pos_sales_before()`),
`pos_sale_lines` (+ `ri_pos_line_resolve()`), `sales_daily`, `depletion_runs`, `ri_day_depletion()`, `ri_depletion_run()`, `ri_depletion_void()`,
`ri_depletion_due()` (db/011); `menu_items`, `modifiers`, `menu_item_modifiers`, `ri_recipe_version_on()`, `ri_explode()` (db/008); `day_parts`
(db/005); `inventory_transactions`, `ri_post_txn()`, `ri_reverse_transaction()`, `ri_depletion_area()` (db/009, db/007); `site_settings.sales_cut_time`
(db/005); `ri_pos_health()`, `ri_unmapped_plus()`, `ri_share_sales_by_day()` (db/016); the views `mcp_sources`, `mcp_source_credentials`,
`mcp_source_pulls`, `mcp_sales_file_mappings`, `mcp_pos_sales`, `mcp_pos_sale_lines`, `mcp_sales_daily`, `mcp_depletion_runs`, `mcp_menu_items`,
`mcp_modifiers`, `mcp_day_parts`, `mcp_inventory_transactions`, `mcp_sites`, `mcp_activity_log` (db/017). Never modify them. **The database is the
referee**: a connector is one of `ri_connectors()`; a credential's ciphertext is in no view (the screen shows the label and the last four; a person
sets or rotates, never reads); a check is unique per source and external id (a re-import of the same file changes nothing); an unknown PLU becomes an
unmapped menu item on insert; the day part follows the close time in the site's zone; a run is one live row per site and date (the unique index) —
a re-run voids the old and reverses its rows; a void never depletes, a comp always does; the run's rows are dated at the day's sales cut; a day's run
is due when sales arrived after the last run; the handlers call the verb inside `ri_guard()` and show its sentence.

## The connector interface (the companion build spec `docs/build-specs/pos-and-depletion.md` is the authority — read it first; this slice builds what it names)
`app/features/pos/connectors/{Connector}.php`: `file` (an export read from an upload, the intake mailbox or SFTP, through a `sales_file_mappings` row),
`square` (Orders by location with a key), `manual` (nothing to pull — a person uploads). Each answers `probe(source)`, `pull(source, pull)` → the normalized
checks `[{external_id, business_date, closed_at, covers, subtotal, tax, total, lines: [{external_line_id, plu, name, qty, unit_price, line_total, voided,
comp, modifiers: [{code, name, qty}]}]}]`, and `read_file(source, path)` for the file connector. The importer (`app/features/pos/import.php`,
`pos_import(pdo, source, pull, checks)`) upserts `pos_sales` + `pos_sale_lines` (ON CONFLICT on `(source_id, external_id)` → skipped; counted `sales_seen`
vs `sales_new`), records `dates_seen`, and queues the days through `ri_depletion_due()`. The mapping templates seeded in `db/018_phase1_fixes.sql`
(additive — the worker of this slice writes it from `pos-and-depletion.md` §3): `generic`, `square_items_csv`, `toast_item_selection`, `clover_orders`,
`lightspeed_sales`, each `{delimiter, encoding, date_format, columns: {external_id, business_date, closed_at, plu, item_name, qty, unit_price, line_total,
voided, comp, modifiers, covers}, void_values, comp_values, modifier_split}`; the fixtures `tests/fixtures/pos/<key>.csv` (synthetic, two days, a void,
a comp, a modifier, an unknown PLU) are what the proof and `tests/phase0`-style connector checks read.

## Screens (the upload and the day's view usable at 375 — a manager on a phone; designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `source-list` | `/sales/sources/?status=` | cards (`source-card-{id}`): name, connector chip, intake (upload / email / sftp / api), schedule ("on arrival", "every 60 min"), last ok, failures, a `paused` chip with the reason, the days missing this week (`ri_pos_health()`), unmapped PLUs count (a link to `menu-item-list?unmapped=1`); **New source** (`settings.manage`); **Upload a file** (the first file source) |
| `source-add` | `/sales/sources/new?connector=` | the form (below): name, connector (file / square — `toast`, `clover`, `lightspeed` listed disabled "Extended"), the connector's sub-form (file: the mapping template, the intake — upload / email with the intake address shown from `RI_INTAKE_MAILBOX` / SFTP host, path, port, the file pattern —, the delimiter; square: the location id), the schedule; **Create** lands on `source-view`; the credential is set apart on `source-credential` |
| `source-view` | `/sales/sources/{source}?tab=` | the header (name, connector, intake, schedule, state); the settings as a definition list (the admin sees the JSON; others the summary); the credential's kind, label, last four, rotated at; **Pull now** (`purchasing.draft`; Square and SFTP — a file source by upload says "upload a file"), **Upload a file** (→ `sales-upload?source=`), **Pause** / **Resume**, **Probe** (`hx-post` → the connector's `probe()` answer inline, Pattern A); the tabs **Pulls** (the last 20 — kind, when, status chip, file, dates seen, sales new, PLUs new, error), **Days** (the days imported with the run's status), **Unmapped** (`ri_unmapped_plus()` for this source — PLU, name, sold, net, last sold, **Map** → `menu-item-map`); `data-entity="source"` |
| `source-edit` | `/sales/sources/{source}/edit` | the same form; the connector fixed once a pull exists |
| `source-credential` | `/sales/sources/{source}/credential` | kind (the connector's: `api_key`/`bearer` for Square; `sftp_password`/`sftp_key` for SFTP), label, the secret (a password field, never pre-filled, never shown again); **Set** / **Rotate**; the current one's label and last four above |
| `mapping-list` | `/sales/mappings/` | the table (`mapping-row-{key}`): key, name, built-in / ours, the columns it expects as chips; **New mapping** (copies `generic`); a built-in is read-only (copy to change) |
| `mapping-edit` | `/sales/mappings/{mapping}` | the form: name, delimiter, encoding, date format, the column names (one input per field of the `columns` map, the required ones marked: external_id, business_date, plu, qty), the void and comp values, the modifier split; **a sample file** dropped → the first five rows parsed through the mapping as a preview table with the missing required columns in red (`hx-post` `/sales/mappings/preview`, keeps no file) |
| `sales-upload` | `/sales/upload?source=` | **the upload**: source (a file source of this site; the only one preselected), the file (CSV or XLSX), the mapping (the source's by default); **Preview** (`hx-post` `/sales/upload.php?preview=1`): the first ten rows as the mapping reads them (date, PLU, item, qty, price, void/comp flags, modifiers), the **dates seen** with a count of lines each and whether a run already exists for the day, the unknown PLUs it will create, the rows it cannot read (with the reason); **Import** (with "deplete the days when imported" checked) → `sales_upload` → lands on `sales-day-list` with the days highlighted and a notice "Imported 2 days: 412 lines, 3 new PLUs; 2 runs posted" |
| `sales-day-list` | `/sales/?from=&to=&page=` | the table (`day-row-{date}`): business date (a link), checks, covers, net, comps, voids, the run's status chip (none / pending / done / partial / void) with "unmapped N" when partial, the value depleted (walled); **Deplete** on a day with no live run, **Re-run** on a day whose run is older than its sales (`ri_depletion_due()`); the last 30 days by default; **Deplete all due** (`depletion_run` per due day) |
| `sales-day-view` | `/sales/{date}?day_part=` | one day: three tiles (checks, covers, net) and the day parts; **sales by item** (`mcp_sales_daily` for the day — item, PLU, qty, net, comps, mapped?) with **Map** on an unmapped one; the checks (`mcp_pos_sales`, with their lines on expand — Pattern A); the modifiers seen with their mapping state; **what the day depleted** (the run's transactions by ingredient in the count unit, cost walled) and the run's card (lines total / depleted / unmapped / voided / modifiers unmapped / ingredients / value / run no / when); **Re-run** (`hx-confirm`), **Void the run** (`counts.approve`, `hx-confirm`); `data-entity="pos_day"`, `data-record-id` the date |
| `depletion-run-list` | `/sales/runs/?status=&from=&to=&page=` | the table (`run-row-{id}`): date, status chip, run no, lines total, depleted, unmapped, voided, modifiers unmapped, ingredients, value (walled), started, by (a person or "worker"); filters |
| `depletion-run-view` | `/sales/runs/{run}` | the card and the movements it posted (`mcp_inventory_transactions` where `reference_kind = 'depletion_run'`, or the reversals of a void run); **Re-run**, **Void**; `data-entity="depletion_run"` |
| `pull-list` | `/sales/sources/{source}/pulls?page=` | the source's pulls in full (the view's tab shows 20): kind chip (scheduled / manual / upload / email / probe), started, finished, status chip, file name (a link to the attachment when kept), dates seen, sales seen / new, lines, PLUs new, error (200 chars), by |

## The source form (`source-form`, ids `source-form-field-{name}`; the connector's sub-form `source-form-{connector}` rendered server-side and switched by a small script — JavaScript off shows every sub-form with the chosen one marked)
| Field | Input | Required | Rule |
|---|---|---|---|
| name | text ≤ 80 | yes | unique per site (`UNIQUE (scope_id, name)` → "This site already has a source called Toast export") |
| connector | select file / square (+ toast, clover, lightspeed disabled) | yes | one of `ri_connectors()`; `manual` is made by the system when a person uploads with no source (DECISION: the upload screen offers "a new manual source" — a `file` source with intake upload) |
| settings.mapping_key | select of `mcp_sales_file_mappings` | file: yes | |
| settings.intake | select upload / email / sftp | file: yes | `email` shows the intake address and the subject rule (the POS's mail must come from the sender named in `settings.email_from`); `sftp` needs host, path, a credential |
| settings.sftp.host / port / path / pattern | text / number / text / text | sftp: host, path | the pattern a glob (`sales_*.csv`); the newest files not yet pulled (by name, kept in `settings.seen_files[]` ≤ 200) |
| settings.email_from | text (an address or a domain) | email: yes | |
| settings.location_id | text | square: yes | the Square location |
| base_url | url | no | Square's by default (`https://connect.squareup.com`) |
| schedule_minutes | number ≥ 0 | no | 0 = on arrival (upload/email); 60 by default for sftp and square |

## The handlers' rules beyond the schema
- `source_create` (`other` for an agent) → the row; `settings` validated per connector (a missing required key → 422 naming it); a credential is never part of
  this form. `source_update` keeps the untouched settings under `_partial=1`; the connector is fixed once a pull exists.
- `source_credential_set` (`other`) → `source_credentials` row: the secret sealed with libsodium under `RI_SECRETS_KEY` (`app/features/pos/credentials.php`
  `ri_seal()` / `ri_open()`; `ri_open()` is called by the connectors alone); the previous row `rotated_at`; `credential_id` on the source; the reply
  carries `last4` only; the log `source.credential_set` with `rotated: true|false`, never the secret.
- `source_pull` → a `source_pulls` row `manual`, `running`; the connector's `pull()` runs **inline** for Square and SFTP with a 20-second budget
  (DECISION: no queue — the worker takes the scheduled ones; a slow pull answers `partial` with what it got and the worker finishes it); a `file` source
  by upload or email → 422 "This source is fed by upload — drop a file"; a paused source → 422.
- `sales_upload` → a `source_pulls` row `upload` with the file name (the file kept as an attachment on the pull when `settings.keep_files`, else read and
  discarded — DECISION: kept by default, ≤ `ATTACHMENT_MAX_BYTES`); `read_file()` through the mapping → `pos_import()`; `?preview=1` parses and answers the
  preview without writing (no pull row); `deplete=yes` → `ri_depletion_run()` per date seen after the import; the reply carries `dates_seen`, `sales_new`,
  `lines_seen`, `plus_new`, `runs[]`.
- `mapping_save` → `sales_file_mappings` (a built-in `is_builtin` row refused: "Copy it to change it"); the required columns present or 422.
- `depletion_run` → `ri_depletion_run(scope, date, by)`: a date with no sales → 422 "No sales on 2026-10-11"; the reply the run's card. `depletion_run_void`
  (`deletion` for an agent) → `ri_depletion_void()`.
- `source_pause` → `paused_at`/`paused_reason` or cleared; `source_schedule_set` (`other`); `source_delete` (`deletion`) → a source with sales → 422
  "Pause it — its sales are history".

## The worker glue — `app/features/pos/worker.php` (the passes `bin/worker.php --passes=pos_pulls,pos_intake,depletion`)
- `pos_pulls`: every active, unpaused source with `schedule_minutes > 0` whose `last_ok_at + schedule` has passed and `backoff_until` has → a `scheduled`
  pull through its connector; a failure → `consecutive_failures + 1`, `backoff_until` 15 min × failures (≤ 6 h), the error on the pull (≤ 200 chars);
  three failures → `pull_failed` to the site's Manager once (dedupe by source and day); a success resets them.
- `pos_intake`: when `RI_INTAKE_MAILBOX` is set, the unread mail of the intake mailbox through MaluMail's API (`app/mail.php` `mail_read_unread()`;
  `\Seen` marks it handled — the estate rule): a message from a source's `settings.email_from` with a CSV/XLSX attachment → an `email` pull through the
  file connector for that source; no source matches → the mail left unread and one `pull_failed`-style notice to the admin naming the sender (once a day);
  the attachment kept on the pull.
- `depletion`: `ri_depletion_due()` → `ri_depletion_run(scope, date, NULL)` per row (the worker as actor — `started_by NULL`); a run that fails (no
  storage area at the site) → the error logged `depletion.run` with `after.error`, the day left due, a notice to the site's Manager once.
- `ri_watches_fire()` after a depletion pass (slice 8 owns the watches; the call is one line here).

## Files (exactly these)
- `app/features/pos/connectors/Connector.php` (the interface), `FileConnector.php`, `SquareConnector.php`, `ManualConnector.php`, `Normalizer.php` (the
  mapping → the normalized check), `Csv.php` (delimiter sniff, BOM, encodings), `Xlsx.php` (a minimal shared-strings reader — no library)
- `app/features/pos/import.php` — `pos_import()`, `pos_preview()`
- `app/features/pos/credentials.php` — `ri_seal()`, `ri_open()`, `credential_set()`
- `app/features/pos/worker.php` — the three passes
- `app/features/pos/queries.php` — `source_list()`, `source_for()`, `source_pulls()`, `source_days()`, `source_unmapped()`, `mapping_list()`, `mapping_for()`,
  `days_list()`, `day_for()`, `day_items()`, `day_checks()`, `check_lines()`, `day_modifiers()`, `day_depletion()`, `run_list()`, `run_for()`, `run_movements()`,
  `pos_health()`, `file_sources_for_site()`
- `app/features/pos/present.php` — `present_source()` (settings for the admin only; never the credential), `present_pull()`, `present_day()`, `present_run()`,
  `present_check()`
- `app/views/pos/sources/{list,form,view,credential,pulls}.php`, `_sub-form-file.php`, `_sub-form-square.php`, `_probe-result.php`, `_unmapped.php`
- `app/views/pos/mappings/{list,edit}.php`, `_preview-table.php`
- `app/views/pos/{upload,days,day}.php`, `_upload-preview.php`, `_run-card.php`, `_items-table.php`, `_checks.php`, `_check-lines.php`, `_depleted.php`
- `app/views/pos/runs/{list,view}.php`
- `html/sales/sources/index.php`, `form.php`, `view.php`, `credential.php` (GET the form, POST the set), `pulls.php`, `save.php`, `schedule.php`, `pause.php`, `pull.php`,
  `delete.php`, `probe.php`
- `html/sales/mappings/index.php`, `edit.php`, `save.php`, `preview.php`
- `html/sales/index.php`, `upload.php` (GET the screen, POST import or preview), `day.php`, `runs/index.php`, `runs/view.php`, `runs/run.php`, `runs/void.php`
- `html/assets/js/pos-forms.js` — the connector sub-form switch; the upload's preview without a page reload (the form works without it)
- `db/018_phase1_fixes.sql` — the four vendor mapping rows (additive)
- `tests/fixtures/pos/{generic,square_items_csv,toast_item_selection,clover_orders,lightspeed_sales}.csv`, `tests/fixtures/pos/square/orders-page1.json`,
  `tests/fixtures/pos/router.php` (the fixture server: Square's `/v2/orders/search` by location, an SFTP stand-in over HTTP for the proof's `sftp` connector
  seam — DECISION: the SFTP connector talks `ssh2`/`phpseclib`-free through `sftp://` via `curl` when the host has it, and the proof exercises the file path
  through a local directory `settings.sftp.local_dir` the fixture provides)
- `tests/phase3/slice5/run.sh`, `lib.php` (`pos_world()`), `browser.mjs`

## Query functions (signatures fixed; the tools of Phase 4 call these — `find_sources`, `get_source`, `source_pulls`, `sales_by_day`, `sales_by_item`, `depletion_runs`, `get_depletion_run`, `pos_health`, `unmapped_plus`)
- `source_list(PDO, int $scope, array $f): array` — `mcp_sources` + `ri_pos_health()` per source
- `source_for(PDO, int $id): ?array`, `source_pulls(PDO, int $id, int $page): array`, `source_unmapped(PDO, int $id): array`
- `days_list(PDO, int $scope, string $from, string $to, int $page): array` — `ri_share_sales_by_day()` folded to one row a day + the live run from `mcp_depletion_runs`
- `day_for(PDO, int $scope, string $date): ?array`, `day_items(PDO, int $scope, string $date, ?int $dayPart): array`, `day_checks(...)`, `check_lines(PDO, int $sale): array`,
  `day_modifiers(...)`, `day_depletion(PDO, int $run): array` (the count unit through `ri_from_base()`; cost walled)
- `run_for(PDO, int $id): ?array`, `run_movements(PDO, int $id): array`
- `pos_health(PDO, int $scope): array` — `ri_pos_health()`

## Handlers (every one: `ri_handler_begin()`; `require_site()`; the right; `ri_guard()`; `log_activity` with `scope_id` (a source's rows: `entity_type source`); `ri_done()`; `HX-Trigger: sourceChanged`, `salesChanged`, `depletionChanged`)
- as in "The handlers' rules"; `sales_upload` logs `sale.import` with `after = {source_id, pull_id, dates_seen, sales_new, lines_seen, plus_new}` — never a row of the file;
  `depletion_run` logs `depletion.run` with the card's counts; `depletion_run_void` logs `depletion.void`.

## Manifest rows claimed
Screens (13): `source-list`, `source-add`, `source-view`, `source-edit`, `source-credential`, `mapping-list`, `mapping-edit`, `sales-upload`, `sales-day-list`, `sales-day-view`, `depletion-run-list`, `depletion-run-view`, `pull-list`
Actions (11): `source_create`, `source_update`, `source_credential_set`, `source_schedule_set`, `source_pause`, `source_pull`, `source_delete`, `sales_upload`, `mapping_save`, `depletion_run`, `depletion_run_void`

Left to other slices: `menu-item-map`, `modifier-map`, `menu-item-list` (slice 2 — this slice links to them from the unmapped queue), the watches (slice 8),
the forecaster that reads `sales_daily` (slice 8), the `sales_by_day` share (Phase 4).

## Activity log events (every row: `scope_id`)
`source.create|update|credential_set|schedule_set|pause|pull_start|pull_done|pull_fail|probe|delete`, `sale.import`, `depletion.run|void`, `plu.queue` (one per
new unmapped PLU an import created, `entity_type menu_item`), `mapping.save`, `screen.view`. A pull's `after` carries the counts; **a credential's secret and a
check's raw payload are never in a payload**.

## Notifications this slice queues (`ri_notify()`; the sender is slice 8's worker)
- `pull_failed` to the site's Manager after three consecutive failures (dedupe `pull_failed:{source}:{date}`), and once when the intake mailbox holds a file no source claims.
- `unmapped` to the site's Chef(s)? — DECISION: to the site's Manager once a day when an import created new unmapped PLUs that sold (dedupe `unmapped:{site}:{date}`); the queue itself is on the home screen.

## Status vocabulary
- source: `active` / `paused` (+ `backing off` when `backoff_until` is ahead); a pull: `running` → `ok` | `partial` | `failed`; a run: `pending` → `done` | `partial` (unmapped lines or modifiers) | `void`.
- chips: ok/done success, partial warning, failed/void danger, paused dark, running info; the day's run chip "none" secondary when no run exists.

## Mobile rule (375 × 740)
The upload: one column, the file input full width, the preview table scrolls inside its card, the dates seen as a list, Import fixed at the bottom; the day's
view: the tiles stack, the items table scrolls inside its card, the run card first; the source cards stack; every control ≥ 44 px; `scrollWidth ≤ clientWidth`.

## Vocabulary
source (where sales are read from) · connector (file, square) · intake (upload, email, sftp) · mapping (how a file's columns become a check) · pull (one
read of a source) · check (one POS sale with its lines) · PLU (the POS's code for a menu item) · unmapped (a PLU with no recipe — the queue) · business date
· the sales cut (the moment the day ends — `site_settings.sales_cut_time`) · depletion run (one per site per day) · backflush (what a sale does to a recipe's
lines — the estate's word) · void (never depletes) · comp (depletes, sold at zero).

## Out of scope for this slice
The Toast, Clover and Lightspeed API connectors (Extended — the mapping templates for their exports ship); the forecast and the watches (slice 8); the counts
(slice 6); the menu map screens (slice 2); the GL's `sales_by_day` read (Phase 4); OCR of a printed Z-report.

## Proof (`tests/phase3/slice5/run.sh`: the scratch database `ri_dev5`, the application on 9301 (`php -S` through `tests/dev_router.php`, or `RI_APP=apache`), the fake kernel 9302, the fake MaluDB + MaluMail 9303 (the intake mailbox's unread mail served by `fake_malumail.php` from `FAKE_MALUMAIL_INBOX`), **the fixture server on 9306** — `php -S 127.0.0.1:9306 -t tests/fixtures/pos tests/fixtures/pos/router.php` — for Square and the SFTP stand-in; members through dev hand-offs; curl with signed action and run tokens; `php bin/worker.php --passes=pos_pulls,pos_intake,depletion` as the worker; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: **≥ 260 checks** (sources 30, credentials 15, mappings and the preview 25, the upload and import 40, resolution and day parts 20, the depletion run 50, re-run and void 20, the worker's passes 25, health and the queue 15, json 10, browser 10).
The world (`tests/phase3/slice5/lib.php` `pos_world()`): slice 3's `ledger_world()` with the receipts posted (chicken, eggs, flour, limes, gin on the shelf) and
slice 2's recipes mapped: PLU 1001 → the chicken sandwich, the modifier `XCHK` → extra chicken (add), `NOTOM` → no tomato (remove — a tomato line added to the
sandwich for it), PLU 3001 → a gin and tonic (1.5 floz gin, 1 lime wedge = 1/8 lime); the fixtures hold two days with a void, a comp, both modifiers and the
unknown PLU 2002; the admin (1), Marco (Manager), Dana (Chef), Bea (Kitchen), Ann (Viewer), the expert.
- [ ] **Sources**: the admin creates "SMOKE Toast export" (file, `toast_item_selection`, intake upload), "SMOKE Nightly mail" (file, intake email, `email_from` pos@smoke.invalid), "SMOKE SFTP" (file, sftp with the fixture's local dir, every 60 min), "SMOKE Square" (square, location L1, the fixture server as `base_url`); a duplicate name at the site → the UNIQUE's sentence; a connector not in `ri_connectors()` → the CHECK's; `toast` as a connector → refused "Extended"; sftp with no host → 422 naming it; `source_create` by Dana → 403, by the expert → `other` pauses; the connector fixed after a pull; pause with a reason and resume; a source with sales deleted → 422, one without → gone.
- [ ] **Credentials**: a bearer set on Square → the row with label and last4, `credential_id` on the source, the secret opening through `ri_open()` (the proof's own call) and present in no `mcp_*` view (a `SELECT` over every view finds the text nowhere); rotated → a new row, the old `rotated_at`, `rotated: true` logged; an sftp password on the SFTP source; a kind the connector does not take → 422; Marco (`sources.credentials`? no — admin's) → 403, the admin 200; the agent → `other`.
- [ ] **Mappings and the preview**: the five rows seeded (`generic` + the four vendors) read-only; a copy of `generic` renamed "SMOKE custom" with `plu` → `Item Code`; the mapping's preview of `tests/fixtures/pos/generic.csv` shows five rows parsed; a missing required column (the `qty` column renamed in the sample) → red in the preview and 422 on save when required; a built-in's save → "Copy it to change it".
- [ ] **The upload and import**: Bea opens `sales-upload`, drops the Toast fixture, **Preview** → ten rows, two dates seen with their line counts and "no run yet", the unknown PLU 2002 listed, the malformed row with its reason; **Import** with deplete checked → the pull row `upload` ok, `sales_new` = the fixture's checks, `lines_seen`, `plus_new` 1, the file kept as an attachment, two runs posted, the notice on `sales-day-list`; the same file again → `sales_new` 0 (idempotent), no new runs (the days are not due); an XLSX of the same rows → the same counts; a file whose mapping cannot read it → `failed` with the reason; an upload to the Square source → 422 "fed by its API".
- [ ] **Resolution and day parts**: every line's `menu_item_id` set; PLU 2002 created unmapped with the line's name and price, logged `plu.queue`; the modifiers `XCHK`, `NOTOM` upserted on first sight; a check closed at 12:30 local → lunch, at 19:10 → dinner, at 03:30 → NULL day part (DECISION: before the sales cut and outside the day parts — the day's row still counts it); the business date from the file, not from the close time.
- [ ] **The depletion run**: day 1's run `partial` (the unmapped PLU), `lines_total` / `lines_depleted` / `lines_unmapped` / `lines_voided` per the fixture; the ledger rows: chicken = sandwiches sold × 188.9 g + XCHK × 85 g, the voided sandwich absent, the comped one present, NOTOM → tomato depleted less (the remove), gin = G&Ts × 44.36 ml, limes = G&Ts × 0.125; one row per ingredient per area per day at the depletion area; every row's `occurred_at` = the day's sales cut − 1 s and `business_date` = the day; `sales_daily` rows per item per day part plus the day row; `value` on the run = Σ qty × the cost as of the cut (walled on the view for Bea and Ann, shown to Dana); a day with no sales → 422; a site with no storage area → the function's sentence and the day left due.
- [ ] **Re-run and void**: Dana maps PLU 2002 to a recipe (slice 2's handler, by SQL here) → the day is due again (`ri_depletion_due()` lists it because the mapping changed? — no: DECISION, mapping a PLU marks its days due by touching `pos_sales.imported_at` of the checks holding it — slice 2's `menu_item_map` handler does so and this proof does it by SQL); **Re-run** → the old run `void`, its rows reversed, the new run `done` with `run_no 2`, the balances unchanged except the newly mapped item's depletion; **Void** by Dana → reversed, the day's chip `void`, the day due again; Bea's void → 403; the agent's `depletion_run_void` → `deletion` pauses.
- [ ] **The worker**: `pos_pulls` — the SFTP source due → a `scheduled` pull reading the newest unseen file from the fixture dir, `seen_files` kept, the days imported; the Square source due → `/v2/orders/search` on the fixture server with the bearer in the header (the fixture logs it), the orders normalized (line items, modifiers, voided/comped flags), imported; a Square 401 → `failed`, `consecutive_failures 1`, `backoff_until` +15 min, skipped on the next pass, the third failure → `pull_failed` to Marco once; `pos_intake` — a mail from pos@smoke.invalid with the Toast CSV attached in the fake inbox → an `email` pull for "SMOKE Nightly mail", the mail marked seen; a mail from a stranger → left unread, one notice; `depletion` — the days the pulls brought run with `started_by NULL`; `source.pull_start|pull_done|pull_fail` logged.
- [ ] **Health and the queue**: `ri_pos_health()` → the days missing this week per source (a closed day in `calendar_days` not counted), the unmapped counts, the pending runs; the cards' chips agree; the Unmapped tab lists PLU 2002 with sold and net and its **Map** link to `/menu/{id}/map`; `ri_unmapped_plus()` sorted by sold.
- [ ] **Walls and sites**: Ann sees the day list without values, the run card without value; a source of Airport is not in Downtown's list (`mcp_sources` by held site); `settings` null in `mcp_sources` for Dana, present for the admin.
- [ ] **JSON mode**: `sales_upload` answers `{dates_seen, sales_new, lines_seen, plus_new, runs[]}`; `depletion_run` the card; `source_credential_set` `{last4}` and never the secret; 422 shapes; `_partial=1` on `source_update` keeps `settings.sftp` when only the schedule is sent; `is_eval` refuses every write.
- [ ] **375 × 740 and 1280 × 800**: the upload's preview under the form on a phone with Import fixed at the bottom; the day's view tiles stacking; the source form's sub-form switch (and every sub-form visible with JavaScript off); `scrollWidth ≤ clientWidth` everywhere.

**Decisions taken in this spec (not questions):**
- A pull now runs inline with a 20-second budget; the scheduled ones are the worker's.
- An uploaded file is kept as an attachment on its pull by default (`settings.keep_files`, on).
- A check closed outside every day part and before the sales cut has no day part; it still belongs to its business date.
- Mapping a PLU marks the days that sold it due for a re-run (slice 2's handler touches `pos_sales.imported_at`; written there, relied on here).
- The SFTP connector reads through `curl`'s `sftp://` when present; the proof exercises the file path through a local directory the fixture provides.
- The `unmapped` notice goes to the site's Manager once a day; the queue itself lives on the home screen and the source's tab.

## Built and proven

Built 2026-10-11 by the planning model (a fork beside slice 3's), on `/srv/apps/restaurant_inventory`; the application's commits are not pushed.

**Proof:** `tests/phase3/slice5/run.sh` — sources 35, credentials 25, mappings and the preview 34, the upload and import (with resolution, day
parts and the JSON shapes) 61, the depletion run with re-run and void 58, the worker's passes 46, health and the queue 23, the browser 32 (headless
Chromium at 1280 × 800, 375 × 740 and with JavaScript off), the registry, the approvals and the Phase 1 claim check: **317 checks under php -S and 317
under a user-level Apache rendering `deploy/apache-restaurant_inventory.conf`** (target ≥ 260). The scratch database is `ri_dev3e` and the port block
9320–9324 (the application, the fake kernel, the fake MaluDB + MaluMail with the mailbox MCP, the POS fixture server — a fake Square Orders API —,
the internal vhost), not this spec's `ri_dev5` / 9301–9306, so the proof runs beside the other slices'. The registry this commit carries reads
53 screens and 61 actions built (13 + 11 of them this slice's). Screenshots in `/tmp/ri-shots-s5`.

**Files:** `app/features/pos/{handler,queries,present,import,worker,credentials,mailbox}.php` and `app/features/pos/connectors/{Connector,Csv,Xlsx,
Normalizer,FileConnector,SquareConnector,ManualConnector}.php`; `html/sales/` (`index.php`, `view.php`, `upload.php`, `sources/{index,form,view,save,
credential,schedule,pause,pull,probe,pulls,delete}.php`, `mappings/{index,view,save,preview}.php`, `runs/{index,view,run,void}.php` — the five
placeholders replaced); the views under `app/views/pos/`; `html/assets/js/pos-forms.js`; the slice's CSS at the end of
`html/assets/css/app-overrides.css`; the worker's three passes in `bin/worker.php` (now one line each into `app/features/pos/worker.php`); two
rewrites — a business date and a mapping by key — in both vhosts of `deploy/apache-restaurant_inventory.conf` and in `tests/dev_router.php`;
`db/023_slice5_pos.sql`; `tests/fixtures/pos/` (five template CSVs, the SFTP and email days, two Square pages, `router.php`); `tests/phase3/slice5/*`.

**What the schema and this spec got wrong (fixed or reported):**
- **`ri_depletion_run()` refused any day with a check closed outside every day part** (db/011): its per-day-part branch made a second whole-day
  row and `sales_daily`'s key (`COALESCE(day_part_id, 0)`) refused it. db/023 limits that branch to checks with a day part.
- **A run's number restarted after a void** (db/011 took the live run's number + 1): run, void, run again was "run 1" twice. db/023 counts every run
  of the day, so it is run 3.
- **`ri_unmapped_plus()` counted every sale a PLU ever had** (db/016): its 28-day window sat on an outer join that never limited the lines. db/023
  joins the lines to their checks inside the window (proven with a sale 40 days old).
- **Square's template named "Price Point Name" as the unit price** (db/018) — that column is the variation's name. db/023 nulls it; the price is
  derived from Gross Sales ÷ Qty. db/023 also gives every builtin mapping its void and comp words, `comp_rule` and modifier separator.
- **`attachments.record_type` admits no `source_pull`** (db/006), so "the file kept as an attachment on its pull" failed silently. The file is kept
  on the SOURCE (`record_type = 'source'`) and the pull's `attachment_id` names it; `mcp_source_pulls` has no `attachment_id` either, so the pulls
  list reads it from the pull's row, admitted only through `mcp_attachments`. No migration: the other slice's fork shares db/006's check.
- **"is_eval refuses every write" is not the application's to prove:** the kit's handler prelude reads run facts only on an agent's first admission;
  the kernel's actions server withholds an evaluation run's writes before they reach any application. Not proven here — owed by the kit if wanted.
- **"Preview → ten rows":** the fixture holds eight readable lines and one malformed row; the preview shows every readable row up to ten.
- **The expert's `source_create` → `other`** (and every pause) is the kernel's hook: proven from the registry's approval class, not by a run.
- `settings.manage` and `sources.credentials` are the admin's alone (db/004): Marco (Manager) is refused a source, a mapping and a credential.

**Decisions taken while building (reported, not questions):**
- With no PLU column, the **item name is the line's PLU** (menu items keyed by name for that source).
- The upload's preview lists the **PLUs it will create**; a PLU already there but unmapped is the queue's (the source's Unmapped tab), not the preview's.
- A modifier's quantity is **per line, as the file states it** (`XCHK` on a line of two sandwiches is 85 g once; `XCHK:2` is twice) — the spec's
  "sandwiches × 188.9 g + XCHK × 85 g".
- A check whose content is unchanged is skipped (no day becomes due); a changed check replaces its lines and makes its day due — a re-imported file
  with one changed check re-runs only that day.
- A mail from a known sender with no CSV or XLSX is **handled**: a failed `email` pull says why and the mail is marked seen. A stranger's mail is left
  unread and the admins get one notice a day naming the sender.
- The run card **omits** the value for someone who cannot see cost (not "Value: —"); the day's tiles go two and one on a phone.
- A source's state reads active / paused / backing off; the card counts its failures.
- Probe is not a manifest action: a pull of kind `probe` and `source.probe` in the log, nothing else changed.
- The mailbox MCP client (`malumail_mcp_call()`) lives in `app/features/pos/mailbox.php`, not the kit's `app/mail.php`.
- **The files differ from "Files (exactly these)"** where the registry builder or the seed decides: the day is `html/sales/view.php` and the
  mapping editor `html/sales/mappings/view.php` (the builder maps `/sales/{date}` and `/sales/mappings/{mapping}` to `view.php`, not `day.php` /
  `edit.php`); the sub-forms and the day's tables are inline in `sources/form.php` and `day.php` (plus `_pulls-table.php`, shared by the view and
  the pulls page); `pos_import()` is `pos_import_file()` over `pos_import_checks()`; the fixtures are named after db/018's seeded keys
  (`toast_items.csv`, `square_items.csv`, `clover_items.csv`, `lightspeed_items.csv`) with the SFTP and email days and Square's second page beside
  them; the fake mailbox is `tests/phase3/slice5/fake_mail.php` and the servers `servers.sh`; the proof is split by area (`sources.php` …
  `health.php`). Every query and present function the list names exists under its name.

## Open questions
None. The connector interface, the mapping templates and the import's shape are `pos-and-depletion.md`'s; this spec builds exactly them.
