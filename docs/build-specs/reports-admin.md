# Build spec: reports, exports, admin, the agents and the home (slice 9)

Built by the worker model from this spec, replicating slice 2's files, gates and proof style (`docs/build-specs/recipes.md`, the first
exemplar) and slice 1's CRUD pattern for the admin's small tables. What exists at the end: the **Home** every person lands on is real —
one card per heading of the design (projected stock-outs before the next delivery, suggested orders due today, unmapped PLUs, the last
count's variance, price movers, open POs and deliveries due, the forecast's thin items, the period's status), each a count with the lines
that matter and a link to its screen, and the role's own block (Kitchen: to receive, to count, waste today; Chef: the recipes uncosted,
the drafts; Manager: invoices disputed, POs unacknowledged, the period to close; the admin: the sources' health and the agents); the
**Reports** — stock value, usage and variance, COGS and food-cost %, days on hand, price movers, waste by stage and reason, menu
engineering, forecast accuracy, purchases by supplier, sales by day — each a screen with filters, a chart where one helps, and CSV / JSON;
the **Exports** — the ledger's five documents as downloads for an accountant without the General Ledger; the **multi-site** view; the
**Admin** — the business settings, each site's settings and day parts, the document sequences, the tax rates, the reason codes, the
**Agents** page, every MCP token, the whole trail; and the worker's last three passes (the outbox, the dispatch loop, the morning note's
inputs). Nothing new in the schema; nothing here posts a movement; the settings are the only thing an agent is paused on (`other`).
Schema: `ri_settings`, `site_settings`, `day_parts`, `ri_site_materialise()`, `ri_setting_*()` (db/005); `document_sequences`,
`next_document_number()`, `tax_rates` (db/006); `reason_codes` (db/009); `mcp_access_tokens`, `mcp_resolve_token()`, `key_usage` (db/003);
`ri_stock_value()`, `ri_usage()`, `ri_usage_by_category()`, `ri_food_sales()` (db/013); `ri_days_on_hand()`, `ri_price_movers()`,
`ri_menu_engineering()`, `ri_pos_health()`, `ri_unmapped_plus()`, `ri_count_variance()`, `ri_purchase_orders_open()`,
`ri_deliveries_due()`, `ri_stockouts_projected()`, the five `ri_share_*()` (db/016 — the exports call them); `forecast_accuracy`,
`ri_suppliers_due()` (db/014); `notification_outbox`, `notification_prefs`, `agent_dispatches`, `buyer_proposals`, `ri_notify()` (db/015);
`members`, `member_site_roles`, `sites` (db/001); the views `mcp_settings`, `mcp_sites`, `mcp_day_parts`, `mcp_document_sequences`,
`mcp_tax_rates`, `mcp_reason_codes`, `mcp_members`, `mcp_agent_dispatches`, `mcp_buyer_proposals`, `mcp_notifications`, `mcp_periods`,
`mcp_inventory_counts`, `mcp_supplier_invoices`, `mcp_purchase_orders`, `mcp_recipes`, `mcp_sources`, `mcp_waste_logs`, `mcp_sales_daily`,
`mcp_goods_receipts`, `mcp_activity_log` (db/017). Never modify them. **The database is the referee**: a report is its function's rows with
cost nulled by `ri_sees_cost()`; a setting's bounds are the table's CHECKs (PHP mirrors each bound so the person reads a field's name, never
a constraint's); a sequence's number only rises; one default tax rate (the partial unique index); a reason's code is unique; the shares
carry cost unnulled and are reached only behind `exports.all`; a token is resolved by its hash and never shown again.

## Divergences from the design, the manifest and the tool surface
1. **The home screen's partials are this slice's** though `home` is claimed by `sso-shell.md` (Phase 2 ships `html/index.php` with the
   placeholder cards): this slice REPLACES `app/views/home/*` and `app/features/home/*` and leaves `html/index.php` as Phase 2 wrote it
   (it calls `home_summary()`; Phase 2's stub answers empty cards). The claim stays with sso-shell; the files are listed here (DECISION 1).
2. The report `usage` is slice 7's `usage-report` screen; the Reports hub links it and `report_run` with `report = usage` answers slice 7's
   `usage()` rows as CSV / JSON (one reader, two doors — DECISION 2). Likewise `menu_engineering` is slice 2's screen; `report_run` answers its rows.
3. `agent_settings_save` writes `site_settings.manager_member_id`? — **no**: it writes the agent's `duty_hour` and `sites[]` into
   `ri_settings.agents` (a jsonb column the kit's settings carry? — **no such column**): DECISION 3 — `agent_settings_save` writes
   `app_settings`-style rows into **`site_settings`** per site (`duty_hour time`, appended by this slice's migration `db/018_phase1_fixes.sql`:
   `ALTER TABLE site_settings ADD COLUMN duty_hour time NOT NULL DEFAULT '06:00', ADD COLUMN purchasing_agent_enabled boolean NOT NULL DEFAULT
   true`; additive; the schema proof unchanged) and `enabled` per site; the kernel keeps the agent's hiring, grants and the cron — this page
   changes when the morning note is sent and whether the passes draft for a site.
4. `token_revoke_any` shares the manifest's log event `token.revoke` with the person's own `token_revoke` (the me section); the admin's
   row carries `after.by_admin = true`.

## Screens (375 px is the design for Home — a Manager on a phone at 06:30; the reports at 1280 px and usable at 375 px; the admin pages usable at 375 px, designed at 1280 px)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `report-list` | `/reports/` | the hub: one card per report (`report-card-{report}`: name, a sentence, the default window, the right it needs) for stock_value, usage (→ slice 7's screen), cogs, days_on_hand, price_movers, waste, menu_engineering (→ slice 2's screen), forecast_accuracy, purchases, sales; the **Exports** card; the **Sites** card for an admin or a Manager of several sites |
| `report-view` | `/reports/{report}?from=&to=&as_of=&category=&supplier=&site=` | one report: the filter form (`report-form`, `report-form-field-{name}` — the fields the report takes), the results (`report-results`: a table per report below and a chart where one helps — stock value by category as bars, days on hand as a sorted bar list, waste by stage as stacked bars, sales by day as a line; inline SVG from `app/features/reports/charts.php`, the dataviz neutrals, no library), **CSV** and **JSON** (`report_run` with `format`); the window remembered in the session per report |
| `export-list` | `/reports/exports?period=&as_of=` | the ledger's documents as cards (`export-card-{document}`): purchases_received (a window), stock_valuation (as of a date), cogs_summary (a period — select of closed periods), waste_summary (a window), sales_by_day (a window); each **CSV** / **JSON** (`export_download`); the sentence "The General Ledger reads these over the kernel's K7 when connected (G5); these downloads are for an accountant without it"; the last download's time per document from the trail |
| `multi-site` | `/reports/sites?from=&to=` | the sites the person holds side by side (`site-col-{scope}`): stock value, food sales, COGS, food-cost %, variance value, waste value, stock-outs projected (7 days), unmapped PLUs, the period's status — `multi_site(PDO, array $scopeIds, from, to)` over the per-site functions; cost walled per site by `ri_sees_cost(scope)`; a Manager of one site sees one column |
| `admin-settings` | `/admin/settings` | the form (`settings-form`, `settings-form-field-{name}`) grouped: **the business** (business_name, timezone), **costing** (cost_basis avg · last, calendar_kind, late_receipt_flag), **counts** (blind_counts), **prices** (price_alert_pct), **projection** (forecast_method baseline · holt_winters, covers_factor_threshold_pct, dynamic_par_after_weeks); the manager member for the morning note is per site (`admin-site-settings`); → `settings_save`; a changed-fields notice after save |
| `admin-site-settings` | `/admin/sites/{site}` | one site: name, address, time zone (the kernel's — read-only, "changed in the OS"); the form (`site-settings-form`): kitchen_sees_cost, depletion_area (select of the site's areas), sales_cut_time, manager (the record picker over members holding a role at the site), temperature_check, **duty_hour** and **purchasing_agent_enabled** (DECISION 3) → `site_settings_save`; the **day parts** table (`day-part-row-{id}`: key, name, starts, ends, Reservations' service name, order) with the inline form → `day_part_save`; the site list `/admin/sites/` is the switcher's list with a link per site (no separate screen — the admin reaches a site's page from the site switcher's gear; DECISION 4) |
| `admin-sequences` | `/admin/sequences` | the table (`sequence-row-{kind}`): kind, prefix, next value, padding, the last number issued; the inline form → `sequence_set` (next value may only rise — "The next number can only go up (now 44)") |
| `admin-tax-rates` | `/admin/tax-rates` | the table (`tax-rate-row-{id}`): name, rate, default, archived; the inline form → `tax_rate_save` |
| `admin-reasons` | `/admin/reasons` | the table (`reason-row-{id}`): name, code, applies to (chips), stage, order, active; the inline form → `reason_save`; a retired reason stays on its rows |
| `admin-agents` | `/admin/agents` | the agents admitted here (`agent-card-{member}`: name, the kernel's agent key, roles per site, capability, the duty hour and enabled per site, the last action from the trail, the last dispatch and its state, dispatches pending / failed, the proposals' acceptance rate for 30 days); the dispatches table (`dispatch-row-{id}`: when, kind, via, the record, state, the reply's first line, **Retry** on a failed one — not a manifest action: `dispatch_retry` is a people-only POST on the same page, DECISION 5); "hiring, grants and the cron are the OS's — `os.<domain>`" with the link |
| `admin-tokens` | `/admin/tokens` | every MCP token (`token-row-{id}`): member, label, scope, created, last used, calls today (`key_usage`), revoked; **Revoke** (`token_revoke_any`, `hx-confirm`) |
| `admin-trail` | `/admin/trail?actor=&entity=&from=&to=&page=` | the whole activity trail at this site (`mcp_activity_log` — the view's rule: an admin sees everything), one sentence per row (`activity_sentence()`), the actor, the record's link (`activity_record_link()`), filters; 100 a page; a row's `before`/`after` under a tap |

## Home — `home_summary(PDO, int $memberId, int $scopeId): array`
One query function, one array of cards in the design's order, each `{key, title, count, lines: [{text, href}], href, tone}` (≤ 5 lines a
card, the rest "and 3 more" on the link); the role's block from `home_role_block()`; every count the same function the screen it links to
uses, with cost walled:
| Card | Source | Shown to |
|---|---|---|
| Stock-outs before the next delivery | `ri_stockouts_projected(site, 7)` ∩ ingredients with no open PO line due before the stock-out day | reports.read |
| Suggested orders due today | `ri_suppliers_due(site, 24)` with the drafted PO's number when one exists today | purchasing.draft |
| Unmapped PLUs | `ri_unmapped_plus(site, 28)` | recipes.write |
| The last count | the latest approved count: `total_variance_value`, the lines over tolerance (`ri_count_variance`), areas skipped | stock.count (values walled) |
| Price movers | `ri_price_movers(site, 7)` top 5 by |pct| and the open alerts | prices.write or sees_cost |
| Open POs and deliveries due | `ri_purchase_orders_open(site)` unacknowledged, `ri_deliveries_due(site, today)` without a receipt | purchasing.draft |
| The forecast's thin items | `mcp_forecast_accuracy` rows absent or with `n` < 28, items sold in 28 days | reports.read |
| The period | `period_to_close()` (slice 7's) — "week 40 closes: 2 days undepleted" / the open period's live food-cost % | periods.close (values walled) |
| Kitchen's block | to receive (`ri_deliveries_due` today), to count (an open count the person may fill), waste today (count and value walled) | stock.count |
| Chef's block | recipes with an uncosted line (`uncosted_recipes()` over `ri_recipe_cost` for the versions in force — cached per request, ≤ 200 recipes), draft versions older than 7 days | recipes.write |
| Manager's block | invoices disputed, POs unacknowledged past 2 days, the period to close, the morning note's last send | purchasing.send |
| Admin's block | `ri_pos_health` failures, dispatches failed, tokens unused for 90 days | settings.manage |

## The trail — `activity_sentence(array $r): string`
One sentence per event of the manifest (`entity.verb` → a template with the `after` fields in words: "Dana activated version 2 of Chicken
sandwich from Oct 12", "Bea posted GR-00012: 5 lines into Walk-in", "the Purchasing agent drafted PO-00044 for Sysco: 6 lines"); an unknown
event falls back to "<actor> <verb> <entity> #<id>"; quantities are shown in the ingredient's count unit through `ri_from_base` when the row
carries `ingredient_id` and a base quantity; values only when `sees_cost()`; a credential, a token and a raw payload never appear (the log
never holds them). `activity_record_link(array $r): ?array` maps `entity_type` to `record_url()`.

## The worker's last passes (`bin/worker.php` — this slice fills `notify_outbox`, `dispatches`, `morning_note_inputs`; the rest are earlier slices')
| Pass | Every | Does |
|---|---|---|
| `notify_outbox` | minute | `outbox_send_batch(PDO, int $limit, DateTimeImmutable $now): array` — queued rows with `send_after ≤ now`, oldest first: `email` through `app/mail.php` (MaluMail; the recipient's address; a bounce or a 4xx → `attempts + 1`, retry in 2^attempts minutes, the fifth → `failed`); `text` through `kernel_send_text()` (K6 — `POST /api/v1/notify/sms.php`; a refusal code (`not_opted_in`, `no_phone`, `no_sender`, `rate_limited`) → `skipped` with the code in `detail`, never retried); `provider_ref` kept; answers `['sent' => n, 'skipped' => n, 'failed' => n]` |
| `dispatches` | minute | `dispatches_pass(PDO, int $limit, DateTimeImmutable $now): array` — the loop below |
| `morning_note_inputs` | at each site's `duty_hour` (site time; once a day — `morning_note_cut_at` kept in `site_settings`? no: the `buyer.note`/`worker.pass` rows of the day say so — `note_inputs_done_today()`) | per site: `period_due_check()` (slice 7's), the stock-out notifications the `suggested_orders` pass did not raise, and — when `purchasing_agent_member_id()` is NULL or the site's `purchasing_agent_enabled` is false — `send_morning_note(site, today, NULL)` (slice 8's) so the note goes out without the agent; answers `['sites' => n, 'notes_sent' => n]` |
| `key_usage_prune` | minute | the kit's |

### The dispatch loop (`app/features/agents/dispatch.php`)
1. **Due**: `agent_dispatches` with `status = 'sent'` and (`run_id IS NULL` and (`attempts ≤ 1` or `created_at + 2^attempts minutes ≤ now`)) —
   up to `$limit`, oldest first; and the running ones (`run_id IS NOT NULL`) to poll. Kinds: `watch` (from `ri_watches_fire()`), `ask`,
   `duty_proposal`.
2. **The utterance** (`dispatch_utterance(PDO, array $d): string`): for a watch — "<the watch's kind in words> on <ingredient>: <note>. Set by
   <member>. On hand <qty in the count unit>, on order <qty>, projected stock-out <date or none>. Look at it (`projected_stock`,
   `suggested_orders`) and draft what should be drafted — a purchase order, an override proposal, a watch — then say in two lines what you
   found. Propose; never send, approve or delete."; the context carries `screen: 'watch'`, `entity: 'watch'`, `record_id`, `scope_id`.
3. **The call**: `ask_assistant($pdo, (int) $d['acting_member_id'], $utterance, $context, (string) $d['agent_member_id'])` — the kernel's chat
   endpoint as that agent, the watch's member as the acting person, the application token (60 s wait, 75 s cap); at most five dispatches a pass.
4. **The answer**: `finished` with a reply → `status answered`, `run_id`, `request_id`, `reply_excerpt` (200 characters), `answered_at`; the
   member told (`ri_notify(acting, 'agent_drafted', 'agent_dispatch', id, "<agent>: <title>", reply ≤ 2,000, 'dispatch:<id>:reply')`); log
   `agent.reply` (`dispatch_id`, `agent_member_id`, `kind`, `run_id`, `request_id`, `reply_length`, `cost` when the kernel sent it — never the
   words); `pending_approval` → `awaiting_approval`; `finished false` (202) → running; refused (400/403/404/409/422) → `refused` + `agent.fail`;
   unreachable or 5xx → `attempts + 1`, the third → `failed` + the member told once.
5. **Polling**: `GET /api/v1/agents/chat.php?run=<run_id>` per running row; finished → step 4; past 10 minutes → `failed`.
6. **Retry** (`/admin/agents/retry.php`, `agents.settings`, people-only, DECISION 5): a `failed` or `refused` row → `sent`, `run_id NULL`, `detail NULL`.
7. Every call and outcome logged `agent.dispatch` / `agent.reply` / `agent.fail`. K8 replaces step 3 when it lands.

## Files (exactly these)
- `app/features/home/{queries,present}.php` (`home_summary()`, `home_role_block()`, `uncosted_recipes()`) · `app/views/home/{dashboard,partials/card,partials/role-kitchen,partials/role-chef,partials/role-manager,partials/role-admin,partials/bell}.php` — replacing Phase 2's placeholders (DECISION 1); `html/index.php` untouched
- `html/reports/index.php` (`report-list`) · `report.php` (`report-view`) · `run.php` (`report_run`) · `exports.php` (`export-list`) · `export.php` (`export_download`) · `sites.php` (`multi-site`)
- `app/features/reports/{queries,present,csv,charts}.php` (`report_rows()`, `report_csv()`, `report_json()`, `report_definitions()` — the ten reports: name, sentence, the filters each takes, the right, the function) · `app/features/exports/{queries,documents,csv}.php` (`export_document()` over the five `ri_share_*`, `export_csv()`, `export_may()`)
- `app/views/reports/{index,report,exports,sites,partials/card,partials/form,partials/results,partials/row-stock-value,partials/row-cogs,partials/row-days-on-hand,partials/row-price-movers,partials/row-waste,partials/row-forecast-accuracy,partials/row-purchases,partials/row-sales,partials/export-card,partials/site-col}.php`
- `html/admin/settings/index.php` (`admin-settings`) · `settings/save.php` (`settings_save`) · `sites/view.php` (`admin-site-settings`) · `sites/save.php` (`site_settings_save`) · `sites/day-part.php` (`day_part_save`) · `sequences/index.php` · `sequences/save.php` · `tax-rates/index.php` · `tax-rates/save.php` · `reasons/index.php` · `reasons/save.php` · `agents/index.php` (`admin-agents`) · `agents/save.php` (`agent_settings_save`) · `agents/retry.php` (`dispatch_retry`, people-only) · `tokens/index.php` (`admin-tokens`) · `tokens/revoke.php` (`token_revoke_any`) · `trail/index.php` (`admin-trail`)
- `app/features/admin/{queries,present,write,handler}.php` (`handler.php`: `require_right('settings.manage')` / `sequences.manage` / `agents.settings` gates, the settings' field readers with the CHECK bounds mirrored) · `app/features/agents/{queries,dispatch}.php` (`agents_here()`, `dispatches()`, `dispatches_pass()`, `dispatch_utterance()`, `poll_running_dispatches()`; slice 8's `note.php` untouched) · `app/features/activity/{queries,present}.php` (`activity_sentence()`, `activity_record_link()`, `find_record_activity()`, `find_site_activity()`) · `app/features/notify/{queue,send}.php` (`outbox_send_batch()`, `send_email()`, `send_text()`)
- `app/views/admin/{settings,site,sequences,tax-rates,reasons,agents,tokens,trail,partials/settings-group,partials/day-part-row,partials/sequence-row,partials/tax-rate-row,partials/reason-row,partials/agent-card,partials/dispatch-row,partials/token-row,partials/trail-row}.php`
- `db/018_phase1_fixes.sql` (DECISION 3: `site_settings.duty_hour`, `site_settings.purchasing_agent_enabled` — additive) · `db/proof/phase0_proof.sql` gains two checks for the columns' defaults
- `bin/worker.php`: `worker_pass_notify_outbox()`, `worker_pass_dispatches()`, `worker_pass_morning_note_inputs()` made real
- `app/features/shell/nav.php`: `back_link()` learns `/reports/{report}` "the reports", `/admin/sites/{id}` "the site's settings"; the admin menu group's items made real (placeholders removed)
- `deploy/apache-restaurant_inventory.conf` and `tests/dev_router.php`: `/reports/{report}` → `report.php?report=`, `/reports/exports` and `/reports/sites` before it, `/admin/sites/{id}` → `sites/view.php?id=` (four lines)
- `tests/phase3/slice9/{run.sh,lib.php,home.php,reports.php,exports.php,sites.php,settings.php,tables.php,agents.php,tokens.php,trail.php,worker.php,json.php,browser.mjs}`

## Query functions (signatures fixed; PDO first; the site from `current_site_id()` unless given — the tools of Phase 4 call these)
- `home_summary(PDO, int $memberId, int $scopeId): array` · `home_role_block(PDO, int $memberId, int $scopeId): array` · `uncosted_recipes(PDO, int $scopeId, int $limit = 20): array`
- `report_definitions(): array` · `report_rows(PDO, string $report, int $scopeId, array $params): array` (`['columns', 'rows', 'totals', 'chart']`) — `stock_value` (`ri_stock_value` by category — tool `stock_value`), `cogs` (slice 7's `cogs_by_period()`), `days_on_hand` (`ri_days_on_hand` — tool `days_on_hand`), `price_movers` (`ri_price_movers` — tool `price_movers`), `waste` (`mcp_waste_logs` by stage × reason × disposition with cost — tool `waste_summary`), `forecast_accuracy` (`mcp_forecast_accuracy` ⨝ `mcp_menu_items` — tool `forecast_accuracy`), `purchases` (`mcp_goods_receipt_lines` by supplier × category — the tool `purchase_orders_open` is slice 4's; this report's tool is `report_run`), `sales` (`mcp_sales_daily` by day × day part — tool `sales_by_day`), `usage` and `menu_engineering` (slices 7 and 2) · `report_csv(array $r): string` · `report_json(array $r): string` · `report_chart_svg(string $report, array $r): ?string`
- `export_document(PDO, string $document, int $scopeId, array $params): array` (the share's rows: `ri_share_purchases_received(site, from, to, offset, limit)` paged whole, `ri_share_stock_valuation(site, as_of)`, `ri_share_cogs_summary(site, period)`, `ri_share_waste_summary(site, from, to)`, `ri_share_sales_by_day(site, from, to)`) · `export_csv(string $document, array $doc): string` · `export_may(string $document): bool` (`exports.all` at the site — cost crosses unnulled here, DECISION 6) · `export_last_downloads(PDO, int $scopeId): array`
- `multi_site(PDO, array $scopeIds, string $from, string $to): array`
- `find_settings(PDO): array` · `settings_groups(): array` · `save_settings(PDO, array $fields, int $by): array` (`['changed' => [...]]`) · `find_site_settings(PDO, int $scopeId): array` · `save_site_settings(PDO, int $scopeId, array $fields, int $by): array` · `day_parts(PDO, int $scopeId): array` · `save_day_part(PDO, int $scopeId, ?int $id, array $fields): int` · `sequences(PDO): array` · `set_sequence(PDO, string $kind, ?string $prefix, ?int $next, ?int $padding): array` · `tax_rates(PDO, bool $includeArchived = true): array` · `save_tax_rate(PDO, ?int $id, array $fields): int` · `reasons(PDO, bool $includeInactive = true): array` · `save_reason(PDO, ?int $id, array $fields): int`
- `agents_here(PDO, int $scopeId): array` (the agents in `mcp_members` with a role at the site; each `{member_id, display_name, agent_key, roles, capability, duty_hour, enabled, last_action, last_dispatch, dispatches_pending, dispatches_failed, acceptance_rate}`) — tool `agents_here` · `dispatches(PDO, int $scopeId, array $filters, int $limit = 50): array` — tool `agent_dispatches` · `save_agent_settings(PDO, int $agentMemberId, array $sites, ?string $dutyHour, ?bool $enabled, int $by): array`
- `tokens_all(PDO, int $scopeId): array` (`mcp_access_tokens` for members with a role here + `key_usage` today) · `revoke_token(PDO, int $tokenId, int $by, bool $byAdmin): void`
- `find_site_activity(PDO, int $scopeId, array $filters, int $page = 1): array` · `find_record_activity(PDO, array $keys, int $page = 1): array` · `activity_sentence(array $r): string` · `activity_record_link(array $r): ?array` · `activity_actor(array $r): string`

## Handlers (every one: `ri_handler_begin()`; the gate; `ri_guard()`; `log_activity` with `scope_id`; `emit_action_status()`; `ri_done()`; `HX-Trigger`)
- `reports/run.php` (`report_run`): `reports.read` (the report's own right when stricter — `report_definitions()`); `report.run` (`report`, the params, `rows`, `format`); html → the results partial (no location); csv → the download; JSON → the rows (≤ 2,000; a larger set is told to narrow); no `HX-Trigger`.
- `reports/export.php` (`export_download`): `exports.all` (`export_may()`); `export.download` (`document`, the params, `rows`, `format`); the download or JSON.
- `admin/settings/save.php` (`settings_save`): `settings.manage`; the bounds mirrored (percentages 0–100, weeks 1–52, a known time zone, a calendar kind of the six); `settings.save` (`after` = the changed fields only); **other**; `settingsChanged`.
- `admin/sites/save.php` (`site_settings_save`): `settings.manage`; a site the person holds; `manager` a member holding a role at the site; `settings.site_save`; `siteChanged`. `admin/sites/day-part.php` (`day_part_save`): `settings.manage`; `starts_at` ≠ `ends_at`; `settings.day_part_save`; a Pattern C row.
- `admin/sequences/save.php` (`sequence_set`): `sequences.manage`; `next_value` ≥ the current; `settings.sequence_set`; **other**; `hx-confirm`. `admin/tax-rates/save.php` (`tax_rate_save`): `settings.manage`; `settings.tax_rate_save`; one default. `admin/reasons/save.php` (`reason_save`): `settings.manage`; `settings.reason_save`; the code unique (the UNIQUE's sentence).
- `admin/agents/save.php` (`agent_settings_save`): `agents.settings`; `agent.settings_save` (`agent_member_id`, `sites`, `duty_hour`, `enabled`); `agentChanged`. `admin/agents/retry.php` (`dispatch_retry`, not a manifest action, people-only — `require_human()`): `agents.settings`; `agent.dispatch` (`after.retry = true`).
- `admin/tokens/revoke.php` (`token_revoke_any`): `settings.manage`; `token.revoke` (`token_id`, `member_id`, `after.by_admin = true`); `hx-confirm`.
- The screens: `report-list`, `report-view`, `multi-site`: `reports.read`; `export-list`: `exports.all`; `admin-settings`, `admin-site-settings`, `admin-tax-rates`, `admin-reasons`, `admin-tokens`, `admin-trail`: `settings.manage`; `admin-sequences`: `sequences.manage`; `admin-agents`: `agents.settings`; `log_screen_view()` on each (`report-view` with `after.report`).

## Manifest rows claimed
Screens (12): `report-list`, `report-view`, `export-list`, `multi-site`, `admin-settings`, `admin-site-settings`, `admin-sequences`, `admin-tax-rates`, `admin-reasons`, `admin-agents`, `admin-tokens`, `admin-trail`
Actions (10): `report_run`, `export_download`, `settings_save`, `site_settings_save`, `day_part_save`, `sequence_set`, `tax_rate_save`, `reason_save`, `agent_settings_save`, `token_revoke_any`
Agent approvals: `settings_save`, `sequence_set` (`other`).

| Action | File | Log | Who | Approval |
|---|---|---|---|---|
| `report_run` | `/reports/run.php` | `report.run` | reports.read | |
| `export_download` | `/reports/export.php` | `export.download` | exports.all | |
| `settings_save` | `/admin/settings/save.php` | `settings.save` | settings.manage | other |
| `site_settings_save` | `/admin/sites/save.php` | `settings.site_save` | settings.manage | |
| `day_part_save` | `/admin/sites/day-part.php` | `settings.day_part_save` | settings.manage | |
| `sequence_set` | `/admin/sequences/save.php` | `settings.sequence_set` | sequences.manage | other |
| `tax_rate_save` | `/admin/tax-rates/save.php` | `settings.tax_rate_save` | settings.manage | |
| `reason_save` | `/admin/reasons/save.php` | `settings.reason_save` | settings.manage | |
| `agent_settings_save` | `/admin/agents/save.php` | `agent.settings_save` | agents.settings | |
| `token_revoke_any` | `/admin/tokens/revoke.php` | `token.revoke` | settings.manage | |

Every row of the manifest's reports-and-admin section is claimed; the home, me and search screens are sso-shell's (their partials replaced
here — DECISION 1). The manifest row `admin-settings` names "the forecast method" — a setting `forecast_method` the kit's `ri_settings` does
not carry: **`db/018_phase1_fixes.sql` adds `ri_settings.forecast_method text NOT NULL DEFAULT 'baseline' CHECK (IN ('baseline',
'holt_winters'))` too** (DECISION 7; slice 8's `forecast_pass` reads it).

## Activity log events (every row: `scope_id` where the record has one)
`report.run`, `export.download`, `settings.save|site_save|day_part_save|sequence_set|tax_rate_save|reason_save`, `agent.settings_save|
dispatch|reply|fail`, `token.revoke` (`after.by_admin`), `worker.pass`, `notification.sent|skipped|failed`? — **no** (DECISION 8): the
outbox row IS the record of a send; the pass logs one `worker.pass` row with the counts; `screen.view` on every screen (`report-view` with
`after.report`, the trail with the filters).

## Notifications this slice queues (and sends)
Sends every queued row (the outbox pass); queues: `agent_drafted` (the dispatch loop's replies and failures), `period_due` (slice 7's check,
called by the `morning_note_inputs` pass), `morning_note` (through slice 8's `send_morning_note()` when no agent is hired or it is disabled
for the site).

## Status vocabulary
Home card tones: `danger` (a stock-out within 2 days, a failed pull), `warning` (unmapped PLUs, an order due today, a count over
tolerance), `info` (the period, the forecast), `secondary` (nothing to do — "all covered"). Dispatch state chips: sent `secondary`
(running when `run_id`), answered `success`, awaiting_approval `warning`, refused `dark`, failed `danger`. Token rows: revoked `dark`,
unused 90 days `warning`. Ids: `home-cards`, `home-card-{key}`, `home-role-block`, `report-card-{report}`, `report-form`,
`report-form-field-{name}`, `report-results`, `report-chart`, `report-csv`, `report-json`, `export-card-{document}`,
`export-csv-{document}`, `export-json-{document}`, `site-col-{scope}`, `settings-form`, `settings-form-field-{name}`, `settings-group-{key}`,
`site-settings-form`, `site-settings-form-field-{name}`, `day-part-form`, `day-part-row-{id}`, `sequence-row-{kind}`, `sequence-form`,
`tax-rate-row-{id}`, `tax-rate-form`, `reason-row-{id}`, `reason-form`, `agent-card-{member}`, `agent-form-{member}`, `dispatch-row-{id}`,
`dispatch-retry-{id}`, `token-row-{id}`, `token-revoke-{id}`, `trail-table`, `trail-row-{id}`, `trail-filters`.

## Mobile rule (375 × 740)
Home is one column of cards, each with its count large and ≤ 3 lines, the role's block first for Kitchen (to receive, to count); the
reports' tables scroll inside `.table-responsive` with the first two columns pinned; the charts scale by `viewBox`; the admin forms are one
column with their groups collapsed but the first; the trail rows wrap the sentence. Every control ≥ 44 px; `scrollWidth` = viewport.

## Vocabulary
*report* (a function's rows with filters and a download), *export* (one of the ledger's five documents — what the General Ledger reads
over K7, offered here as a file), *multi-site* (the sites side by side), *setting* (the business's one row; a site's own row), *day part*
(lunch, dinner — Reservations' service names mapped), *sequence* (a document kind's next number), *dispatch* (what was handed to an agent and
what came back), *duty hour* (when the morning note is sent at a site), *trail* (the activity log in sentences).

## Out of scope for this slice
The morning note's assembly and send (slice 8 — this slice's pass only calls it when no agent is hired); the usage report and menu
engineering screens (slices 7 and 2 — the hub links them); hiring, granting and the cron of the agents (the kernel's); a report builder
(Extended); the ledger's K7 connection screen (the kernel's `app_connection.php`).

## Proof (`tests/phase3/slice9/run.sh`: the scratch database `ri_dev9`; the worlds of slices 1–8 composed by `lib.php` `admin_world()`; the app on
9301; the fake kernel 9302 (chat answering a canned reply, a 202 and a 503 on demand; K6 answering ok and `not_opted_in`); the fake MaluMail 9303;
`RI_WORKER_NOW`; members through `bin/dev_handoff.php`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800;
`bin/build_action_registry.php --check` reads **every** screen and action of the manifest as built (118 / 146 — nothing a stub);
`bin/sync_approvals.php --check`; ≥ 200 checks)
- [ ] **Home**: each of the eight cards' counts equals its screen's function; a Kitchen member sees the Kitchen block first and no cost; the Chef's
  uncosted recipes card; the Manager's disputed invoice and the period to close; the admin's failed pull; every card's link opens its screen; an
  empty site shows "all covered" tones.
- [ ] **Reports**: the ten definitions; each report's rows equal its function (stock value by category = `ri_share_stock_valuation`'s sum; days on
  hand; price movers; waste by stage; forecast accuracy; purchases by supplier; sales by day); the CSV answers the same rows; JSON ≤ 2,000 rows;
  a chart SVG where defined with no external script; the usage and menu engineering cards link to slices 7 and 2; Ann (`reports.read`? — a Viewer
  has `ri.read` only) → 403 in words; a Chef sees values, a Kitchen member "—" where a report admits them (none — reports are `reports.read`).
- [ ] **Exports**: the five documents as CSV and JSON equal the share functions' rows (cost unnulled — a Manager with `exports.all`); a Chef without
  `exports.all` → 403; the paging of purchases received walks every page; the last download's time on the card; `export.download` logged with `rows`.
- [ ] **Multi-site**: the owner sees Downtown and Airport side by side with values; Marco sees both sites with cost at Downtown only ("—" at Airport);
  Dana sees one column.
- [ ] **Settings**: the groups; a percentage of 150 refused by the field's name; `cost_basis = last` changes the recipe card's basis (slice 2's screen
  re-read); `forecast_method` saved (db/018); the changed-fields notice; the expert's `settings_save` **pauses** (`other`); `settings.save` logged
  with only the changed fields.
- [ ] **Site settings and day parts**: kitchen_sees_cost on → Bea sees cost on the ingredient card; the sales cut time changes `ri_site_moment` (a
  count's default as-of follows); the manager set; a day part added and mapped to "Dinner"; starts = ends refused; `duty_hour` and
  `purchasing_agent_enabled` saved (db/018).
- [ ] **The small tables**: a sequence's next value raised, lowered → "can only go up"; the next receipt takes the new number; two default tax rates
  → one; a reason's duplicate code refused in words; a retired reason stays on its old rows.
- [ ] **Agents**: the two shipped agents appear with roles per site, the duty hour, the last action's sentence; `agent_settings_save` disables the
  Purchasing agent at Airport → the `suggested_orders` pass drafts nothing there; the dispatches table; a failed dispatch retried by a person; an
  agent's POST to retry → 403 "people only".
- [ ] **Tokens**: every token with calls today; revoke by the admin → the token refused at `/mcp/records` (the kit's resolver), `token.revoke` with
  `by_admin`; Dana cannot open `/admin/tokens` (403).
- [ ] **The trail**: a sentence for every event kind the earlier proofs produced (a table of event → regex in `trail.php`; every manifest event
  covered — the fallback never fires on a known event); the filters; the record links resolve; a credential's set shows "set a credential (…x9k2)"
  and never the secret; 100 a page.
- [ ] **The worker**: `notify_outbox` sends the queued emails through the fake MaluMail and texts through the fake kernel; a `not_opted_in` → skipped
  with the code; a 503 → attempts + 1 and a later retry; the fifth failure → failed; `dispatches` turns a watch's dispatch into a chat call (the fake
  kernel's canned reply → answered, the member notified, `agent.reply` logged without the words), a 202 → running then polled to answered, a 503 ×
  3 → failed and the member told once, a 422 → refused; `morning_note_inputs` at the duty hour with no agent hired sends the note (slice 8's) and
  not twice; `period_due_check` raises the `period_due` notification once; one `worker.pass` row per run.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}`; `report_run` JSON answers `rows`;
  `export_download` JSON answers the document; 422 in words for the settings' bounds.
- [ ] **375 × 740 and 1280 × 800**: Home's cards stack with 44 px links; the reports' pinned columns; the admin forms' groups; no console errors;
  `scrollWidth` = viewport; the registry reads 118 screens and 146 actions built.

**Decisions taken in this spec (not questions):**
1. The home's partials and query functions are this slice's; the `home` screen stays claimed by sso-shell; `html/index.php` is Phase 2's.
2. `report_run` with `report = usage` / `menu_engineering` answers slices 7's and 2's query functions as CSV / JSON; their screens stay theirs.
3. `db/018_phase1_fixes.sql` adds `site_settings.duty_hour`, `site_settings.purchasing_agent_enabled` and `ri_settings.forecast_method`
   (additive); the kernel keeps hiring, grants and the cron.
4. There is no site list screen of its own: a site's settings page opens from the site switcher's gear (the admin) — the manifest's
   `admin-site-settings` is the one screen.
5. `dispatch_retry` is a people-only POST on the agents page, not a manifest action (no agent retries its own dispatch).
6. The exports carry cost unnulled behind `exports.all` at the site — the only door beside the K7 shares where cost crosses whole.
7. See 3 — `forecast_method` lives in `ri_settings`.
8. A send is recorded by its outbox row; no `notification.sent` activity rows (the trail would double every notification).

## Open questions
(none)
