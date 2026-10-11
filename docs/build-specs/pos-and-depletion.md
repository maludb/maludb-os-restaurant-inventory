# Build spec: the POS and depletion — the contract of `app/pos/` and slice 5's worker glue

Written by the planning-class model (Phase 1, 2026-10-11) from **the schema as built in Phase 0** (db/011 sources, sales, depletion
runs; db/008 menu items and modifiers; db/009 the ledger — proven by `db/proof/phase0_proof.sql`) and design §1, §4, §6.5, D7, the
research `docs/research/02-mechanics.md` §1.4, §2 and `01-field-survey.md` §6. A worker relies on this document for three things: **how
a day's sales arrive** (a file by upload, email or SFTP; the Square API), **how they become `pos_sales` rows and then one depletion
run**, and **what a screen may ask** (pull now, upload, re-run, void). Nothing here changes the schema: where the spec says *as built*,
`db/*.sql` is the authority.

Schema (never modify; a change is a numbered additive migration with sign-off): `sources`, `source_credentials`, `source_pulls`,
`sales_file_mappings`, `pos_sales`, `pos_sale_lines`, `sales_daily`, `depletion_runs`, `ri_connectors()`, `ri_pos_line_resolve()`,
`ri_pos_sales_before()`, `ri_day_depletion()`, `ri_depletion_run()`, `ri_depletion_void()`, `ri_depletion_due()` (db/011);
`menu_items`, `menu_item_prices`, `modifiers`, `menu_item_modifiers`, `ri_menu_items_before()`, `ri_recipe_version_on()`,
`ri_explode()` (db/008); `ri_post_txn()`, `ri_reverse_transaction()`, `ri_depletion_area()` (db/009, db/007); `day_parts`,
`site_settings.sales_cut_time` (db/005); `ri_pos_health()`, `ri_unmapped_plus()` (db/016); the views `mcp_sources`,
`mcp_source_credentials` (no ciphertext), `mcp_source_pulls`, `mcp_sales_file_mappings`, `mcp_pos_sales`, `mcp_pos_sale_lines`,
`mcp_sales_daily`, `mcp_depletion_runs`, `mcp_menu_items`, `mcp_modifiers` (db/017).

**The division of labour, in one sentence** (design §1): a connector only **reads** a day's checks and returns them normalized; the
schema **resolves the PLU** (`ri_pos_line_resolve()` — an unknown one becomes an unmapped menu item, never a dropped line) and
**depletes** (`ri_depletion_run()` — one transaction per ingredient per area per date); the worker **drives** (due pulls, the intake
mailbox, days due for depletion); the screens **ask** (upload, pull now, re-run, void) and **show** (health, pulls, the queue).

**DECISION — the connectors live in PHP, under `app/pos/`**, like Inventory's `app/sources/`: the worker is PHP (`bin/worker.php`) and
the upload handler is PHP; `mcp/` is Python for the two read servers only and holds no connector. The Square client is cURL in PHP.

---

## 1. The `sources` frame for a POS — as built (db/011)

| Column | Meaning here |
|---|---|
| `scope_id` | **a POS belongs to a site** (unlike Inventory's sources); every sale it brings carries that site |
| `connector` | `file` or `square` in v1; `toast`, `clover`, `lightspeed` are admitted by `ri_connectors()` for Extended; `manual` = hand-entered days |
| `role` | always `pos` |
| `settings` jsonb | `file`: `{"mapping_key": "generic", "intake": "upload" \| "email" \| "sftp", "delimiter": ",", "sftp": {"host", "port", "path", "user"}, "email_from": "reports@pos.example"}`; `square`: `{"location_id": "L…", "lookback_days": 3}` |
| `credential_id` | the sealed secret (`source_credentials.ciphertext`, libsodium secretbox under `RI_SECRETS_KEY`, opened in **one** file `app/pos/credentials.php` — the kit's seal/open pair, copied from Inventory's `app/sources/credentials.php` shape) |
| `schedule_minutes` | 0 = on arrival (upload, email); SFTP and Square pull on this cadence (default 60, set by the screen) |
| `last_pull_id`, `last_ok_at`, `consecutive_failures`, `backoff_until`, `paused_at` | the health columns `ri_pos_health()` reads; three failures in a row → `backoff_until = now() + 1 hour`, ten → paused with the reason |

A `source_pulls` row per fetch: `kind` ∈ `scheduled | manual | upload | email | probe`, `file_name`, `attachment_id` (the file kept
as an attachment of the pull — `record_type = 'source_pull'`), `dates_seen date[]`, `sales_seen`, `sales_new`, `lines_seen`,
`plus_new`, `error` (≤ 200 chars), `started_by`. **A credential's ciphertext appears in no view, no tool, no log, no export.**

---

## 2. The `file` connector and the mappings

### 2.1 The normalized shape every connector returns
```php
['external_id' => 'CHK-1', 'business_date' => '2026-10-11', 'closed_at' => '2026-10-11T19:12:00-04:00', 'covers' => 2,
 'subtotal' => 42.00, 'tax' => 3.36, 'total' => 45.36, 'comps' => 0.00, 'voids' => 0.00,
 'lines' => [['external_line_id' => '1', 'plu' => '1001', 'name' => 'Chicken Sandwich', 'qty' => 2, 'unit_price' => 14.00,
              'line_total' => 28.00, 'voided' => false, 'comp' => false,
              'modifiers' => [['code' => 'XCHK', 'name' => 'Extra chicken', 'qty' => 1]]]],
 'raw' => null]
```
`app/pos/normalize.php`: `ri_pos_check()` validates and fills defaults (a line without `qty` is 1; a missing `business_date` is taken
from `closed_at` in the site's zone minus the `sales_cut_time` — a check closed at 01:30 belongs to the previous business day when
the cut is 04:00); `ri_pos_bool()` reads `yes/true/1/Y/VOID` as true. `raw` holds the source's own object for the day's first pull only
when the setting `keep_raw` is on (default off — `pos_sales.raw` stays NULL; a raw check is never in a log payload).

### 2.2 Mappings (`sales_file_mappings`)
A mapping is `{"delimiter": ",", "date_format": "Y-m-d", "time_format": null, "columns": {<field>: <column header>}}`; fields:
`external_id`, `business_date`, `closed_at`, `plu`, `item_name`, `qty`, `unit_price`, `line_total`, `voided`, `comp`, `modifiers`,
`covers`, optionally `external_line_id`, `tax`, `total`. One row per **line**; the check's header fields repeat on each line (the
generic shape, seeded as built). `modifiers` is either a JSON column or a `;`-separated list of `code:qty` or `name` tokens
(`ri_pos_modifiers_parse()`). Column headers match case-insensitively after trimming.

**Templates slice 5 seeds** (`db/0NN` additive — see Owed) from the exports' public documentation, each with ONE synthetic fixture
under `tests/fixtures/pos/` (five checks, one void, one comp, one modifier, one unknown PLU; names SMOKE; no vendor data):

| Key | Export | Columns (documented names) | Fixture |
|---|---|---|---|
| `generic` | ours | as seeded | `generic.csv` |
| `square_items` | Square Dashboard → Reports → Item Sales (CSV, one row per item sold) | `Transaction ID`, `Date`, `Time`, `Item`, `SKU`, `Qty`, `Gross Sales`, `Discounts`, `Net Sales`, `Modifiers Applied`, `Event Type` (Payment/Refund) | `square_items.csv` |
| `toast_items` | Toast → Reports → Item Selection Details export | `Order Id`, `Order Date`, `Sent Date`, `Item Id`, `Menu Item`, `Qty`, `Price`, `Net Price`, `Void?`, `Comp?`, `Option Group`, `Modifiers`, `Dining Option` | `toast_items.csv` |
| `clover_items` | Clover → Reporting → Item export | `Order ID`, `Order Date`, `Item ID`, `Item Name`, `Quantity`, `Item Price`, `Item Total`, `Refunded`, `Modifiers` | `clover_items.csv` |
| `lightspeed_items` | Lightspeed Restaurant (K-Series) → Sales by item export | `Receipt ID`, `Business Date`, `Time`, `PLU`, `Product`, `Quantity`, `Unit Price`, `Total`, `Voided`, `Complimentary`, `Modifiers` | `lightspeed_items.csv` |

A template's `date_format` and the `voided`/`comp` token words are part of the mapping. A mapping the business edits is copied from a
builtin (`is_builtin = false`) — the builtins are never edited in place. **The exact column names of the three vendor exports are
to be confirmed against a real file in Phase 5; the mapping screen (`mapping-edit`) exists so a person fixes them without code.**

### 2.3 The three intakes
| Intake | How the file arrives | Who runs it |
|---|---|---|
| `upload` | `sales-upload` screen → `sales_upload` action (multipart CSV/XLSX ≤ `ATTACHMENT_MAX_BYTES`; XLSX read by the kit's reader when present, else refused with "export as CSV") | the handler, synchronously; a preview first (`?preview=1`: the first 20 rows mapped, the dates seen, the unknown PLUs) |
| `email` | the POS mails its report to `RI_INTAKE_MAILBOX` at MaluMail; the worker's `email_intake` pass reads unread mail through the kit's MaluMail client (`app/mail.php`'s read side — the siblings' intake pattern), matches the sender to a source's `settings.email_from`, takes the first CSV/XLSX attachment, marks the message `\Seen` **only after the pull row is written** (D13: `\Seen` = handled, never set on a message it did not take) | `bin/worker.php` every minute |
| `sftp` | the worker's `pos_pulls` pass runs `sftp` (the system binary, a password or key from the opened credential, `-o BatchMode=yes`) listing `settings.sftp.path`, takes files newer than `last_ok_at` by name order | `bin/worker.php` on `schedule_minutes` |

Every intake ends in the same `ri_pos_import_file(PDO, int $sourceId, string $path, string $mappingKey, int $pullId): array` (§4).

---

## 3. The `square` connector (`app/pos/connectors/square.php`)
- Credential `kind = bearer` (a Square access token with `ORDERS_READ`); `settings.location_id`; base URL
  `https://connect.squareup.com` (`RI_SQUARE_BASE_URL` points the proofs at a stub).
- One pull = `POST /v2/orders/search` with `location_ids: [location_id]`, `query.filter.state_filter.states: ["COMPLETED"]`,
  `query.filter.date_time_filter.closed_at: {start_at, end_at}` (the window: `last_ok_at − lookback_days` to now — Square back-dates
  edits), `query.sort: {sort_field: "CLOSED_AT"}`, `limit: 500`, following `cursor` until absent; `Square-Version: 2025-01-23`.
- Fields read per order: `id` → `external_id`; `closed_at`; `line_items[]`: `uid` → `external_line_id`, `catalog_object_id` →
  `plu` (**the variation id is the PLU**; `name` + `variation_name` → `name`), `quantity`, `base_price_money.amount/100` →
  `unit_price`, `total_money.amount/100` → `line_total`, `modifiers[]` (`catalog_object_id` → `code`, `name`, `quantity`); a
  refund (`refunds[]`) of a line is a `voided` line in a later pull; a line whose `total_money.amount = 0` with
  `applied_discounts` naming a 100 % discount is a `comp`; `tenders` are not read (money is the ledger's); `covers` from
  `fulfillments[0].pickup_details` is absent → NULL.
- Idempotency: `pos_sales (source_id, external_id)` is unique; a re-pulled order **replaces** its lines (delete + insert inside one
  transaction) and the day is marked due for depletion again (`imported_at` moves → `ri_depletion_due()` lists it).
- Errors: 401 → `consecutive_failures + 1` and a notification `pull_failed` to the site's Manager with "the Square token was refused";
  429 → honour `Retry-After`, then `backoff_until`; a network error → failed pull, retry next pass.

The interface (`app/pos/Connector.php`): `interface RiPosConnector { public function probe(array $source): array; public function
pull(array $source, DateTimeImmutable $since, DateTimeImmutable $until): iterable /* of normalized checks */; }`;
`app/pos/registry.php` `ri_pos_connectors(): array`, `ri_pos_connector(string $key): RiPosConnector`; the `$source` array carries
`connector`, `settings` (decoded), `credential` (opened, or null), `timeout`, `transport` (tests only). A connector never touches the
database and never opens a credential.

---

## 4. The import algorithm (`app/pos/import.php` — `ri_pos_import_file()` and `ri_pos_import_checks()`)
1. Open the pull (`source_pulls` row `running`, `kind`, `file_name`, the file saved as an attachment of the pull).
2. Read rows through the mapping → group by `external_id` → `ri_pos_check()` each.
3. For each check, in **one transaction per business date**: `INSERT INTO pos_sales … ON CONFLICT (source_id, external_id) DO UPDATE`
   (the header refreshed; `pull_id`, `imported_at = now()`); delete and re-insert its `pos_sale_lines`. `ri_pos_sales_before()` sets
   `day_part_id` from `closed_at` and the site's `day_parts`; `ri_pos_line_resolve()` sets `menu_item_id` — **an unknown PLU creates
   an unmapped `menu_items` row** (`pos_source_id`, `plu`, `name`, `sale_price`) and every modifier code an unmapped `modifiers` row
   (`action = none` until a person maps it) — and bumps `menu_items.last_sold_at`.
4. Close the pull: `dates_seen`, `sales_seen`, `sales_new`, `lines_seen`, `plus_new` (menu items created), `status ok|partial|failed`,
   `sources.last_pull_id`, `last_ok_at`, `consecutive_failures` reset; `log_activity('sale.import', 'source_pull', id, ['scope_id',
   'after' => the counts])` — never a check's payload.
5. When the caller asked (`deplete = yes`, the default for the worker), run `ri_depletion_run(scope, date, by)` for each date seen.

**Rules**: a void never depletes, a comp always does (`pos_sale_lines.voided`, `.comp` — `ri_day_depletion()` filters `NOT voided`);
a line's `qty` is numeric (a half-portion is 0.5); money columns are informational for the kitchen (`net`, `comps`, `voids`) — the
ledger reads `sales_by_day`; a check dated before the site's earliest open period is imported and depleted — the late-receipt rule
of db/013 moves a transaction dated in a **closed** period into the next open one, flagged.

---

## 5. Depletion — as built (db/011)

```
ri_day_depletion(p_scope_id, p_date) RETURNS TABLE (ingredient_id, qty_base, lines)     -- the explosion of the day, no writes
ri_depletion_run(p_scope_id, p_date, p_by DEFAULT NULL) RETURNS depletion_runs           -- posts; re-runnable
ri_depletion_void(p_run_id, p_by, p_note DEFAULT NULL) RETURNS depletion_runs
ri_depletion_due() RETURNS TABLE (scope_id, business_date)                              -- days with sales newer than their live run
```
Step by step, `ri_depletion_run(site, day)`:
1. A live run for the day (`status <> 'void'`) is reversed — every `depletion`/`return` row of its `group_id` through
   `ri_reverse_transaction()` — and marked `void`; the new run takes `run_no + 1`.
2. The accounting moment `occ` = the **sales cut** (`site_settings.sales_cut_time`, default 04:00) of the next calendar day in the
   site's zone, minus a second — so every depletion of a business day sits at its end, inside the day's period, and **a count taken as of
   the sales cut includes it** (the proof's lesson: a count `as_of now()` at 18:00 would not).
3. Counts: `lines_total`, `lines_unmapped` (a non-void line whose menu item has no recipe), `lines_voided`, `modifiers_unmapped` (a
   non-`none` modifier with no recipe, neither its own nor a per-parent one).
4. `ri_day_depletion()`: for every non-void line with a recipe, `ri_explode(version in force on the day, day) × line.qty ×
   menu_items.portions ÷ version.batch_yield_qty`; for every modifier with action `add|remove|swap`, its (per-parent, else own)
   recipe's explosion × `modifier qty × qty_factor ÷ batch_yield`, **negative for `remove`**; summed per ingredient.
5. One `ri_post_txn()` per ingredient: `txn_type = depletion` (qty < 0) — or `return` when the sum is positive (a day of "no tomato"
   on every salad), area = `ri_depletion_area(ingredient, site)` (the ingredient's site default → the site's `depletion_area_id` →
   the first area in walk order; **a site with no area refuses the run**), cost = `ri_cost_as_of(ingredient, site, occ)`,
   `reference_kind = depletion_run`, `idempotency_key = depl:<run>:<ingredient>`, `counterparty_kind = customer`.
6. `sales_daily` for the day rebuilt: one row per item per day part and one per item for the whole day (`day_part_id NULL`): `qty`,
   `net` (non-comp line totals), `comps_qty`.
7. The run's `status`: `done`, or `partial` when any line or modifier was unmapped (the home shows it; mapping the PLU and
   re-running makes it `done`).

**Worked** (the proof): two sandwiches with one "extra chicken", one voided sandwich, one comped sandwich, one Mystery Special (PLU
2002, unmapped): `lines_total 4, lines_unmapped 1, lines_voided 1, lines_depleted 2, ingredients 3`; chicken = 3 × 188.8889 + 85 =
**651.67 g**, stock 150 ml, eggs 3; `sales_daily` says 3 sold of which 1 a comp, two day parts; a re-run voids run 1 (three
reversals) and posts run 2 with the balance unchanged.

**The worker's `depletion_runs` pass**: `SELECT * FROM ri_depletion_due()` → `ri_depletion_run()` per row (≤ `--limit`), logged
`depletion.run` with `scope_id` and the counts; a failure (no area) notifies the site's Manager once a day (`dedupe =
depletion:<site>:<date>`) and leaves the day due.

---

## 6. The PLU queue and mapping (slice 2 built the screens; slice 5 wires the flow)
- `ri_unmapped_plus(site, days)`: unmapped menu items of the site's sources with what they sold in the window — the home's card and
  `menu-item-list?unmapped=1`.
- `menu_item_map`: `recipe` (a `menu_item` recipe — the trigger refuses another kind), `portions`, `menu_category`, **`no_depletion`**
  = a PLU that sells nothing from stock (a service charge, a gift card, a bottled water the bar counts elsewhere): the handler maps it
  to the seeded recipe `R-NONE` (kind `menu_item`, one active version with **no lines** is refused by activation — DECISION: the handler
  instead sets `menu_items.active = false` with `recipe_id NULL` and a note "no depletion"; an inactive item is not counted as
  unmapped by `ri_unmapped_plus()` — **it filters `m.active`**, as built).
- `modifier_map`: `action` and the modifier recipe; `menu_item` + `parent_recipe`/`parent_qty_factor` write a `menu_item_modifiers`
  row for the one parent ("no tomato" means a different quantity on the wedge than on the burger — research 02 §1.4).
- A mapping made today does **not** rewrite yesterday's runs; the person re-runs the days (`sales-day-list` shows `partial` runs with a
  **Re-run** button; `depletion_run` with the date).

---

## 7. Health (`ri_pos_health(site)`)
Per source: `last_ok_at`, `consecutive_failures`, `paused_at`, `days_missing` (the last seven days the site was open with no sales
from this source — a day marked `closed` in `calendar_days` is not missing), `unmapped_plus` (sold in 28 days), `unmapped_modifiers`,
`pending_runs`. The home's POS card and the morning note's heading (4) read it.

---

## 8. Proof (`tests/phase3/slice5/run.sh`, and `tests/phase1/pos_fixtures.sh` for the fixtures alone)
- Each of the five fixtures imports through its mapping: 5 checks, the void not depleted, the comp depleted, the unknown PLU queued,
  the modifier applied; the same file imported twice adds nothing (`sales_new 0`).
- A check closed at 01:30 with a 04:00 cut lands on the previous business day; one at 04:30 on the day.
- `sales_upload` with `?preview=1` shows the dates and the unknown PLUs and writes nothing.
- The email intake: a fixture message with a CSV attachment from the source's `email_from` → a pull of kind `email`, the message
  `\Seen`; one from an unknown sender is left unread and logged `source.pull_fail` with "unknown sender".
- The Square stub: two pages of orders, a refunded line on the second pull turning `voided`, a 401 raising `pull_failed`.
- `ri_depletion_run` numbers as §5; a re-run; `ri_depletion_void`; a site with no storage area refused with the sentence.
- `mcp_source_credentials` has no `ciphertext` column; the credential screen shows the label and the last four only.
- A Kitchen member may upload and re-run (`stock.receive`); a Viewer may not; `source_credential_set` pauses for an agent.

## 9. Owed to the schema
- **A migration seeding the four vendor mapping templates** (`sales_file_mappings` rows `square_items`, `toast_items`, `clover_items`,
  `lightspeed_items`) — db/018, slice 5's: `INSERT … ON CONFLICT (key) DO NOTHING`.
- `sources.settings.keep_raw` and the mapping's `voided_words`/`comp_words` are jsonb keys, no migration.
- `ri_pos_health()` counts a `days_missing` day against the site, not the source's own schedule — a source that only reports weekly
  will show missing days; Extended: `sources.settings.cadence_days`.
