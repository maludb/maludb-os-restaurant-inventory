# Build spec: Phase 2 — sign-on, the mirror, the sites, the shell (before any slice)

What exists at the end: a person clicks Restaurant Inventory on the launcher — or types `kitchen.<domain>` on a phone in the walk-in — and
lands, signed in, at the site the hand-off named (or the first they hold), on a home that looks finished though it holds no ingredient yet:
the sidebar lists Home · Ingredients · Recipes · Stock · Count · Purchasing · Sales · Usage · Projection · Reports · Admin, each shown only to a
role that may open it at the current site; the site switcher and the application switcher in the header; the bell; the command bar answering
through the kernel; the bottom tab bar Home · Stock · Count · Order · Me at 375 px; every other menu item a placeholder naming the slice that
builds it. The mirror refreshes every minute; a revoked grant shuts every door within a minute; a site not held is 404; cost is hidden from a
Viewer and from Kitchen until the site says otherwise; every request is logged with its `scope_id`. The kit is in the repository already
(Phase 0: MaluMail's identity, roles and sign-on files made site-scoped the way txtSchedules is; proven without a kernel by
`tests/phase0/run.sh`, 123 checks); this phase wires the shell around it and proves it.
Schema: `members`, `departments`, `department_members`, `sites`, `member_site_roles`, `directory_sync_state`, `sso_nonces`, `member_sessions`
(with `scope_id`), `app_current_member_id()`, `app_is_active_member()`, `app_is_super_admin()`, `ri_held_scope_ids()`, `ri_holds_scope()`,
`ri_site_timezone()` (db/001); `activity_log` (with `scope_id`, `ingredient_id`, `recipe_id`, `purchase_order_id`, `count_id`),
`activity_ingest_state` (db/002); `mcp_access_tokens` + `mcp_resolve_token()` (db/003); `ri_rights`, `ri_roles`, `ri_role_rights`,
`ri_member_roles_at()`, `ri_has_right()`, `ri_has_right_anywhere()`, `ri_is_admin()`, `ri_sees_cost()`, `ri_is_member_here()`,
`ri_right_refusal()`, `mcp_app_roles` (db/004); `ri_settings`, `site_settings`, `day_parts`, `ri_site_materialise()` (db/005); `attachments`,
`notes` (db/006); `notifications`, `notification_prefs` (db/015); `ri_find()` (db/016); `mcp_members`, `mcp_departments`, `mcp_sites`,
`mcp_settings`, `mcp_attachments`, `mcp_notes`, `mcp_notifications`, `mcp_activity_log` (db/017). Never modify them. **The database is the
referee**: who belongs here (`ri_is_member_here()`), what a role may do AT A SITE (`ri_has_right(right, scope)`), which sites a person holds
(`ri_held_scope_ids()`), whether cost shows at a site (`ri_sees_cost(scope)`) — PHP asks, never re-decides; `has_right()`, `require_right()`,
`require_site()`, `held_sites()`, `current_site_id()`, `is_ri_admin()`, `sees_cost()` in `app/auth.php` are the one-call mirrors.

## Files
**Already in the repository (Phase 0, proven):** `app/bootstrap.php` (env from `config/.env` or `RI_DEV_ENV`, session cookie `RISID`,
`json_mode_begin()`, the action-token / approval-replay path, the per-request mirror re-check, the session's site re-checked against the
held sites), `app/db.php`, `app/http.php` (`render_screen()`, `view()`, `e()`, `refuse()`, `emit_action_status()`, `respond_saved()`,
`respond_invalid()`, `saved_go()`, `hx_location()`, `hx_trigger()`, `csrf_field()`, `verify_csrf()`, `request_integer()`, `request_date()`),
`app/handler.php` (`ri_handler_begin()`, `ri_guard()`, `ri_refuse_fields()`, `ri_done()`, `ri_diff()`, `ri_land()`, `ri_notice()`, `req_val()`,
`request_list()`, `ri_yes()`, `ri_int()`, `ri_text()`, `ri_choice()`, `ri_ref()`, `members_for_pick()`, `find_live_departments()`,
`require_any_right()`), `app/auth.php` (`held_sites()`, `current_site_id()`, `current_site()`, `site_timezone()`, `has_right()`,
`has_right_anywhere()`, `require_right()`, `require_right_anywhere()`, `require_site()`, `require_admin()`, `require_human()`,
`require_visible()`, `sees_cost()`, `is_ri_admin()`, `is_member_here()`, `right_refusal()`, `RIGHT_WORDS`, the token verifiers, `run_facts()`,
`kernel_call()`), `app/directory.php` (the mirror appliers incl. `mirror_apply_scope()`, `mirror_apply_holding()`, `mirror_apply_access()`),
`app/activity.php` (`log_activity()` with the audit keys `scope_id` (the session's site by default), `ingredient_id`, `recipe_id`,
`purchase_order_id`, `count_id`, `token_id`; `log_screen_view()`), `app/switcher.php` + `app/views/shared/app-switcher.php` (K31, the
kernel's `GET /api/v1/apps/mine.php`), `app/views/shared/site-switcher.php`, `app/mail.php`, `app/partial_update.php`, `app/api/bootstrap.php`,
`app/api/kernel.php` (`ask_assistant()`, `assistant_refresh_events()`, `kernel_send_text()`, `kernel_read()`), `app/views/shared/{flash,message}.php`,
`app/views/sso/refused.php`, `app/views/home/placeholder.php` (replaced by this phase), `html/index.php` (the Phase 0 placeholder — made real
here), `html/sso.php`, `html/sso/logout.php`, `html/logout.php`, `html/switch-site.php` (`site_switch`), `html/api/v1/health.php`,
`html/manifest.webmanifest`, `html/assets/` (the design system verbatim + `app-overrides.css`, `htmx.min.js`, `select2`, `Sortable.min.js`),
`bin/directory_sync.php` (`--full`, `--from-file`, scopes first), `bin/mint_mcp_token.php`, `bin/build_action_registry.php`,
`bin/sync_approvals.php`, `bin/dev_handoff.php <member> [scope]`, `bin/dev_directory.json` (two sites), `bin/worker.php` (twelve passes, every
one a stub), `mcp/activity_ingest.py`, `mcp/db.py`, `mcp/server_common.py`, `tests/dev_router.php`, `tests/setup_dev.sh`,
`tests/fake_kernel.php` (+ `scopes.php` + a K7 read), `tests/fake_maludb.php`, `tests/fake_malumail.php`, `deploy/*`.
**Phase 2 makes real (exactly these):**
- `db/018_member_prefs.sql` — ADDITIVE: `notification_prefs` **appends** `display_units text NOT NULL DEFAULT 'metric' CHECK (display_units IN
  ('metric', 'imperial'))` and `default_area_id bigint REFERENCES storage_areas(id) ON DELETE SET NULL` (the manifest's `preferences_save` names
  `units` and `default_area`; db/015 has no column for them — DECISION: the preferences row keeps them, no new table); `mcp_notification_prefs`
  (own row) is created here too. Every later slice reads `display_units` through `my_prefs()` to show a quantity in the person's units.
- `app/features/shell/nav.php` — the ONE menu table: `nav_groups()`, `nav_tabs()`, `nav_item()`, `nav_has_right()`, `role_badge()`, `hx_link()`,
  `back_link()`, `here_url()`, `with_back()`, `record_url()`, `NAV_SLICES`, `render_nav_stub()` (Inventory's `app/features/shell/nav.php` copied and
  rewritten to this menu and these rights, every right asked AT THE CURRENT SITE — the table below).
- `app/features/shell/queries.php` — `bell_count()`, `business_name()`, `my_roles()` (per site), `site_label()`.
- `app/features/home/queries.php` — `home_summary()` (every key null until its slice).
- `app/features/me/{queries,present}.php` — prefs, notifications, tokens, the trail.
- `app/features/search/queries.php` — `search_all()` over `ri_find()`.
- `app/attachments.php` — the attachment helper every later slice stores files with (`attachment_store()`, `attachment_delete()`,
  `attachment_path()`, `ri_can_see_attachment()`) and `html/files.php` (the gated door `/files/{id}`). Slice 1's `attachment_add` is the first
  caller; the waste log's photo (slice 3) and the invoice image (slice 4) follow.
- `app/picker.php`, `app/pickers.php` (empty: `return [];` — each slice adds its sources), `html/pick/index.php`, `app/views/shared/picker-rows.php`,
  `html/assets/js/record-picker.js`, the `#record-picker` modal in the layout, the CSS section in `app-overrides.css` — the record picker
  (plugin 0.8.0, `examples/php/record-picker/` verbatim; the UUID branches taken out — every key here is a bigint). Phase 2 ships it with the
  shell so slice 1's forms (category, supplier, ingredient) use it from their first render.
- `app/views/layout.php` (the nxl shell), `app/views/shared/{header,sidebar,tab-bar,assistant-bar,assistant-reply,notice,bell}.php`,
  `app/views/home/dashboard.php`, `app/views/me/{settings,notifications,tokens,trail,rows}.php`, `app/views/search/results.php`.
- `html/index.php` (screen `home`), `html/login.php` (302 to `OS_LAUNCHER_URL/launcher?app=restaurant_inventory` — never a login form),
  `html/search.php` (screen `search`), `html/me/settings/index.php` (screen `my-settings`) + `html/me/settings/save.php`,
  `html/me/notifications/index.php` (screen `my-notifications`) + `html/me/notifications/read.php`, `html/me/tokens/{index,mint,revoke}.php`
  (screen `my-tokens`), `html/me/trail.php` (screen `my-trail`), `html/assistant/ask.php` (the command bar's POST), `html/files.php`.
- The placeholders, one controller per not-yet-built menu item, each exactly `render_nav_stub('<nav id>', '<slice>', '<right>')`:
  `html/ingredients/index.php`, `html/suppliers/index.php`, `html/areas/index.php`, `html/units/index.php`, `html/categories/index.php`,
  `html/recipes/index.php`, `html/menu/index.php`, `html/menu/engineering.php`, `html/modifiers/index.php`, `html/menu/categories.php`,
  `html/stock/index.php`, `html/stock/movements.php`, `html/stock/value.php`, `html/receipts/index.php`, `html/transfers/index.php`,
  `html/adjustments/index.php`, `html/production/index.php`, `html/waste/index.php`, `html/purchasing/index.php`, `html/purchasing/due.php`,
  `html/purchasing/suggested/index.php`, `html/purchasing/alerts/index.php`, `html/invoices/index.php`, `html/sales/index.php`,
  `html/sales/sources/index.php`, `html/sales/upload.php`, `html/sales/runs/index.php`, `html/sales/mappings/index.php`, `html/counts/index.php`,
  `html/counts/sheets/index.php`, `html/counts/containers/index.php`, `html/periods/index.php`, `html/periods/calendars.php`, `html/usage/index.php`,
  `html/usage/cogs.php`, `html/projection/index.php`, `html/projection/calendar.php`, `html/projection/demand.php`, `html/projection/stock.php`,
  `html/projection/special.php`, `html/projection/proposals.php`, `html/projection/watches.php`, `html/reports/index.php`, `html/reports/exports.php`,
  `html/reports/sites.php`, `html/admin/settings.php`, `html/admin/sequences.php`, `html/admin/tax-rates.php`, `html/admin/reasons.php`,
  `html/admin/agents.php`, `html/admin/tokens.php`, `html/admin/trail.php`. The registry builder reads a controller that calls `render_nav_stub()`
  as unbuilt; removing the call is the slice's first step.
- `tests/phase2/` — `servers.sh`, `lib.php`, `sso.php`, `gates.php`, `sites.php`, `sync.php`, `ingest.php`, `kernel_compat.php`, `vhost.php`,
  `browser.mjs`, `run.sh` (Inventory's suite copied and rewritten: `inv_` → `ri_`, the ports 9301–9307, the fixture of `bin/dev_directory.json`
  with its two sites, the sites file new).
- `storage/` (`attachments/`, `exports/`, `pos-intake/`) `www-data`-owned, mode 0770, before `apply`: already in `deploy/ROOT_STEPS.sh` (step 0c);
  Phase 2 verifies it (the attachment door needs it first).

## Restaurant Inventory is not Inventory
- **Every screen is of the current site.** `current_site_id()` is read once per request; a screen whose record belongs to another site the
  person holds answers 404 unless the record's own view says the row is visible from here (a transfer between two held sites is); a site the
  person does not hold is 404 always (`require_site()`). The site switcher (`shared/site-switcher.php`, `POST /switch-site.php`) sits in the
  header beside the application switcher; with one site it renders the name alone. The layout stamps `data-scope-id` on `#page-content`.
- **Nobody is in by default** (D3): the installer grants no standing department; a person holds roles PER SITE because a super-admin granted
  them. The base role's key is `user` (Kitchen). The header's badge shows the highest role held at the current site: Restaurant Inventory
  admin › Manager › Chef › Kitchen › Viewer; a super-admin shows "Super-admin". `role_badge()` reads `ri_member_roles_at(member, scope)`.
- **Cost is the wall, not a menu.** The shell hides nothing by cost; the views null it. `sees_cost()` is read once per request and passed to the
  layout as `$seesCost` (and stamped `data-sees-cost` on `<body>`) so a partial may say "cost withheld" instead of an empty cell. Kitchen sees
  cost only where `site_settings.kitchen_sees_cost` is on.
- **Two panes, not three.** At 1280 px: the sidebar (280 px) and the main pane. At 375 px: one pane and the bottom tab bar **Home · Stock ·
  Count · Order · Me**; the sidebar opens as the offcanvas from the header's menu button (`a#mobile-collapse`, the theme's own).
- **The menu is data** (`nav_groups()`), every item gated by a right at the current site; the sidebar and the tab bar read the same table, so an
  item and the right that opens its screen never disagree. The table, exactly (`[id, url, icon, label, right]`; `|` = any one of):

| Group | id | URL | Icon | Label | Right |
|---|---|---|---|---|---|
| Kitchen | `home` | `/` | feather-home | Home | ri.read |
| | `search` | `/search` | feather-search | Search | ri.read |
| Ingredients | `ingredient-list` | `/ingredients/` | feather-box | Ingredients | ri.read |
| | `supplier-list` | `/suppliers/` | feather-truck | Suppliers | ri.read |
| | `area-list` | `/areas/` | feather-map-pin | Storage areas | ri.read |
| | `category-list` | `/categories/` | feather-grid | Categories | ingredients.write |
| | `unit-list` | `/units/` | feather-divide-square | Units | settings.manage |
| Recipes | `recipe-list` | `/recipes/` | feather-book-open | Recipes | ri.read |
| | `menu-item-list` | `/menu/` | feather-list | Menu items | ri.read |
| | `modifier-list` | `/modifiers/` | feather-sliders | Modifiers | recipes.write |
| | `menu-engineering` | `/menu/engineering` | feather-bar-chart-2 | Menu engineering | reports.read |
| | `menu-category-list` | `/menu/categories` | feather-tag | Menu categories | recipes.write |
| Stock | `stock-levels` | `/stock/` | feather-layers | On hand | ri.read |
| | `stock-movements` | `/stock/movements` | feather-activity | Movements | ri.read |
| | `stock-value` | `/stock/value` | feather-dollar-sign | Stock value | reports.read |
| | `receipt-list` | `/receipts/` | feather-download | Receive | stock.receive |
| | `transfer-list` | `/transfers/` | feather-repeat | Transfers | stock.transfer |
| | `adjustment-list` | `/adjustments/` | feather-edit-3 | Adjustments | counts.approve |
| | `production-list` | `/production/` | feather-coffee | Prep batches | stock.produce |
| | `waste-list` | `/waste/` | feather-trash-2 | Waste | stock.waste |
| Count | `count-list` | `/counts/` | feather-check-square | Counts | stock.count |
| | `count-sheet-list` | `/counts/sheets/` | feather-clipboard | Count sheets | ingredients.write |
| | `container-list` | `/counts/containers/` | feather-droplet | Bottles and kegs | ingredients.write |
| Purchasing | `suggested-order-list` | `/purchasing/suggested` | feather-shopping-cart | Suggested orders | purchasing.draft |
| | `purchase-order-list` | `/purchasing/` | feather-file-text | Purchase orders | ri.read |
| | `deliveries-due` | `/purchasing/due` | feather-calendar | Deliveries due | stock.receive |
| | `invoice-list` | `/invoices/` | feather-file | Invoices | invoices.write |
| | `price-alert-list` | `/purchasing/alerts` | feather-trending-up | Price alerts | purchasing.draft |
| Sales | `sales-day-list` | `/sales/` | feather-pie-chart | Sales by day | ri.read |
| | `source-list` | `/sales/sources/` | feather-rss | POS sources | settings.manage |
| | `sales-upload` | `/sales/upload` | feather-upload | Upload a POS file | stock.receive |
| | `depletion-run-list` | `/sales/runs/` | feather-zap | Depletion runs | stock.receive |
| | `mapping-list` | `/sales/mappings/` | feather-columns | File mappings | settings.manage |
| Usage | `usage-report` | `/usage/` | feather-bar-chart | Actual vs theoretical | reports.read |
| | `period-list` | `/periods/` | feather-calendar | Periods | reports.read |
| | `cogs-report` | `/usage/cogs` | feather-percent | COGS | reports.read |
| | `calendar-list` | `/periods/calendars` | feather-clock | Costing calendars | settings.manage |
| Projection | `forecast-list` | `/projection/` | feather-trending-up | Forecast | reports.read |
| | `projected-stock` | `/projection/stock` | feather-arrow-down-right | Projected stock | ri.read |
| | `demand-report` | `/projection/demand` | feather-layers | Ingredient demand | reports.read |
| | `calendar-days` | `/projection/calendar` | feather-calendar | Trading calendar | forecast.adjust |
| | `special-check` | `/projection/special` | feather-star | Can we run it? | ri.read |
| | `proposal-list` | `/projection/proposals` | feather-inbox | Purchasing agent | purchasing.draft |
| | `watch-list` | `/projection/watches` | feather-eye | Watches | ri.read |
| Reports | `report-list` | `/reports/` | feather-bar-chart-2 | Reports | reports.read |
| | `export-list` | `/reports/exports` | feather-download-cloud | Exports | exports.all |
| | `multi-site` | `/reports/sites` | feather-globe | Sites side by side | reports.read |
| Me | `my-settings` | `/me/settings` | feather-settings | My settings | ri.read |
| | `my-notifications` | `/me/notifications` | feather-bell | Notifications | ri.read |
| | `my-tokens` | `/me/tokens` | feather-key | Tokens | ri.read |
| | `my-trail` | `/me/trail` | feather-activity | My trail | ri.read |
| Admin | `admin-settings` | `/admin/settings` | feather-sliders | Settings | settings.manage |
| | `admin-sequences` | `/admin/sequences` | feather-hash | Sequences | sequences.manage |
| | `admin-tax-rates` | `/admin/tax-rates` | feather-percent | Tax rates | settings.manage |
| | `admin-reasons` | `/admin/reasons` | feather-bookmark | Reason codes | settings.manage |
| | `admin-agents` | `/admin/agents` | feather-cpu | Agents | agents.settings |
| | `admin-tokens` | `/admin/tokens` | feather-key | Tokens | settings.manage |
| | `admin-trail` | `/admin/trail` | feather-activity | Trail | settings.manage |

  The ids ARE the manifest's screen ids; `admin-site-settings` (`/admin/sites/{site}`) is reached from `admin-settings`, not from the menu.
  `NAV_SLICES` names the slice of every placeholder: ingredients, suppliers, areas, categories, units 1; recipes, menu items, modifiers, menu
  engineering, menu categories 2; on hand, movements, stock value, receive, transfers, adjustments, prep batches, waste 3; purchase orders,
  deliveries due, invoices, price alerts 4; sales by day, POS sources, upload, depletion runs, file mappings 5; counts, count sheets, bottles
  and kegs 6; actual vs theoretical, periods, COGS, costing calendars 7; suggested orders, forecast, projected stock, demand, trading calendar,
  can we run it, Purchasing agent, watches 8; reports, exports, sites side by side, every Admin item 9.
- **The tab bar** (`nav_tabs()`): Home `/`, Stock `/stock/`, Count `/counts/` (shown for `stock.count`; a Viewer sees Search instead), Order
  `/purchasing/suggested` (`purchasing.draft`; a Kitchen member sees Waste `/waste/` instead), Me `/me/settings`.
- **The attachment door** `/files/{id}` is rewritten by the vhost already; `html/files.php` answers 401 anonymous, 404 when
  `ri_can_see_attachment()` says no (today: `ri_is_member_here()` — every record is every reader's; a later slice narrows if its view does),
  streams from `storage/attachments/` with the stored `mime_type`, `Content-Disposition: inline` for images and PDFs, `attachment` otherwise.

## The receiver (`/sso`) — in order (as the kit does it; nothing changes here)
1. `verify_sso_token($token, app_key())` and `verify_sso_claims($claims)` — constant-time, both must pass, member ids must match.
2. Expiry, audience (`restaurant_inventory`), status, capability, then the nonce: `INSERT INTO sso_nonces … ON CONFLICT DO NOTHING`; zero rows =
   replay → refuse.
3. In one transaction: `mirror_apply_member()` (the row with `capability`), `mirror_apply_holding()` from `claims.scopes[]` (the sites the
   claims name are materialised with their names; the feed fills address and time zone within a minute), `mirror_apply_roles()` from
   `claims.roles` (only keys `ri_roles` knows).
4. Open the session: `session_regenerate_id(true)`, `$_SESSION['member_id']`, `$_SESSION['scope_id']` = `claims.scope` when held, else the first
   held site by name, fresh CSRF token; `member_sessions` row with `scope_id`; `db_apply_context()`.
5. `log_activity('member.sign_on', 'member', $id, ['scope_id', 'after' => ['capability', 'roles', 'sites']])`; redirect to `/`.
6. Any failure: one page `sso/refused.php` (*"This sign-on link has expired. Open Restaurant Inventory from app.<domain> again."*),
   `member.sign_on.refused` with `after.reason` (token, claims, status, capability, replay, no_site, mirror) — never shown. **A member with a
   grant that names no site is refused `no_site`** (fail closed — the fixture's Nico).

## Every request (bootstrap, as the kit does it)
- Cookie `RISID`, `SameSite=Lax`, `Secure` when HTTPS, `HttpOnly`, strict mode. A signed-in request re-checks the mirror row, the session list
  and that the session's site is still held and open; a lost site moves the session to the first held site (logged `member.site_moved`); no
  site left → the session is destroyed (`ended_by = 'directory'`) and the visitor sent to the launcher with `?app=restaurant_inventory`.
- Action token (`X-Action-Token` + `X-Action-Relay`, or `X-Approval-Replay`): acts as that member for one request; `Set-Cookie` removed; the site
  is the request's `site` param when given and held, else the member's first held site; an id with no mirror row is refused and logged
  `member.refused`; a known, active agent with no capability is admitted at first contact only when the kernel's run-facts call vouches for it.
  `SET app.member_id` before any query; `log_screen_view()` on every rendered GET.
- `/sso/logout` (POST from the kernel): `verify_sso_logout_notice` → every session of the member ended (`ended_by = 'kernel'`); 204 always.

## The shell
- Design-system skeleton (`layout-skeleton.html`), exactly. **Phone first**: receiving, the count sheet and the waste log are read at 375 px
  (their slices); the recipe editor, usage and the reports at 1280 px. The header: the menu button (375), the site switcher (the current site's
  name; a dropdown of the held sites posting `site_switch`), the application switcher (K31), the command bar, the bell with its unread count
  (`mcp_notifications` where `read_at IS NULL`), my avatar with the role badge; `#page-content` is the HTMX target (`.nxl-content`); CSRF meta
  `<meta name="csrf-token" id="csrf-token-meta">` + the `htmx:configRequest` listener stamping `X-CSRF-Token`; `htmx:afterSwap` re-init
  (select2, tooltips, the picker); the layout re-stamps `data-screen` / `data-entity` / `data-record-id` / `data-scope-id` on `#page-content` and
  the menu highlight from `X-Screen` / `X-Entity` / `X-Record-Id` (`app/http.php` `render_screen()` sends them).
- **The sidebar** (from 992 px, and the offcanvas on a phone): the groups of the table in order, each item a link (`hx_link()`: `hx-get`,
  `hx-target="#page-content"`, `hx-push-url` set to the item's own canonical URL — never `true`), the current one highlighted, a group with no
  visible item not rendered; the Admin group only for a holder of one of its rights at the current site.
- **The command bar** (`#assistant-bar`): dictation-friendly input with Send shown only while in use; POST `/assistant/ask` → `ask_assistant()`
  (`app/api/kernel.php`: the kernel's chat endpoint as the person, agent `expert`, `utterance`, `screen`, `entity`, `record_id`, `scope_id`,
  `conversation_id` kept in the session), the reply rendered in `shared/assistant-reply.php`, a `navigate` answer that is a local path followed
  by `HX-Location` + `HX-Push-Url`, every entity changed fired as `{entity}Changed` (`assistant_refresh_events()`), a 202 polled by
  `ask_assistant()` itself (up to 55 s), the kernel down or refusing said in the bar in words (an `hx-on::before-swap` lets a 4xx and a 503
  land in the bar). `require_human()`: an agent never uses the bar. Nothing here calls a model.
- **Home** (`home_summary()` — every region's empty state names its slice): stock-outs projected before the next delivery (8), suggested orders
  due today (8), unmapped PLUs (5), the last count's variance (6), price movers (4), open POs and deliveries due (4), the forecast's thin items
  (8), the period's status (7); for Kitchen: to receive today, the open count (3, 6); for the admin: dispatches and the agents' proposals (8, 9).
  Each region a card `#home-{region}` with a count that opens its list; null until its slice fills the key.
- **Search** (`/search?q=`): `ri_find(q, 25)` grouped by kind (ingredients, recipes, menu items, supplier items, suppliers), each row a link
  through `record_url()`; the header's search box submits here; an empty `q` renders the box alone. The one place every name is found by voice.
- **The bell**, **My settings** (prefs: display units, the default count area (a select of the current site's active areas — the row's
  `default_area_id` is checked against the site on save), email on/off, text on/off, the kinds, the text kinds, the time zone shown read-only —
  it is the directory's), **Tokens** (`token_mint`, `token_revoke`), **My trail** (my own rows in words, `from`/`to`) are real in this phase.
- Every screen: `shared/header.php` (title, crumbs, Back from `?back=` through `back_link()`, the actions), the flash region `#flash`, no modal
  anywhere but the record picker and `hx-confirm`; forms are pages.

## Screens
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `home` | `/` | the regions above, each a card with an empty state naming its slice; the site's name in the title; `data-screen="home"` |
| `site-switch` | `/switch-site?site=` | GET: the held sites as a list of buttons (one form each, `site_switch`) — the phone's page for the header's dropdown; POST: the switch (the kit's `html/switch-site.php`) |
| `my-settings` | `/me/settings` | the prefs form (`prefs-form`): display_units (radio metric · imperial), default_area (select of this site's areas + none), email_enabled, text_enabled, kinds[] (the fifteen notification kinds of db/015 with one sentence each — `NOTICE_KINDS`), text_kinds[], the time zone read-only with "changed in the operating system"; the role badge and the roles held per site |
| `my-notifications` | `/me/notifications?unread=` | my notices newest first (`mcp_notifications`): kind chip, the site, title, body, when, read or not; **Mark read** per row and **Mark all read**; a row links to its record (`record_type` → `record_url()`) |
| `my-tokens` | `/me/tokens` | my tokens as a table (`mcp_access_tokens`, own rows — the rw role reads the table): label, last four, last used, expires, revoked; **Mint** (label, expires_in_days) and the minted value shown ONCE in a `warning` box on the next render (`$_SESSION['minted_token']`, cleared after); **Revoke** per live row (`hx-confirm`) |
| `my-trail` | `/me/trail?from=&to=&page=` | my own activity in words (`activity_sentence()`), newest first, 50 a page, every row with its site |
| `search` | `/search?q=` | the results of `ri_find()` grouped by kind; the query echoed escaped; an empty state in words |

Ids: `shell-sidebar`, `shell-tabbar`, `site-switcher`, `site-switcher-{scope_id}`, `app-switcher`, `assistant-bar`, `assistant-input`,
`assistant-send`, `assistant-reply`, `bell`, `bell-count`, `header-role-badge`, `header-site-name`, `header-search`, `page-content`, `flash`,
`nav-{id}` (a sidebar item), `tab-{id}` (a tab), `home-{region}`, `notification-row-{id}`, `notification-row-{id}-read`, `notifications-mark-all`,
`trail-list`, `trail-row-{id}`, `prefs-form`, `prefs-form-field-display-units`, `prefs-form-field-default-area`, `prefs-form-field-email-enabled`,
`prefs-form-field-text-enabled`, `prefs-form-field-kinds-{kind}`, `prefs-form-field-text-kinds-{kind}`, `tokens-table`, `token-row-{id}`,
`token-row-{id}-revoke`, `token-mint-form`, `token-mint-form-field-label`, `token-mint-form-field-expires-in-days`, `token-minted-value`,
`search-form`, `search-form-field-q`, `search-results`, `search-group-{kind}`, `search-row-{kind}-{id}`, `record-picker` (and its fixed parts).

## Query functions (signatures fixed)
- `home_summary(PDO, int $memberId, int $scopeId): array` (the keys of §Home, every value null until its slice; `app/features/home/queries.php`)
- `bell_count(PDO, int $memberId): int` · `business_name(PDO): string` · `my_roles(PDO, int $memberId, int $scopeId): array` (`[{role_key, name}]` through `ri_member_roles_at()`) · `site_label(array $site): string` (`app/features/shell/queries.php`)
- `my_prefs(PDO, int $memberId): array` (`notification_prefs` row or the table's defaults incl. `display_units`, `default_area_id`) · `save_prefs(PDO, int $memberId, int $scopeId, array $fields): array` (`['before', 'after']` of the changed keys; INSERT … ON CONFLICT; `default_area` must be an active area of `$scopeId` or null) · `NOTICE_KINDS` (the fifteen kinds of db/015 with one sentence each)
- `find_my_notifications(PDO, int $memberId, int $page, bool $unreadOnly): array` (`mcp_notifications`, 50 a page) · `mark_notifications_read(PDO, int $memberId, ?int $id): int` (rows marked)
- `my_tokens(PDO, int $memberId): array` · `mint_token(PDO, int $memberId, string $label, ?int $expiresInDays): array` (`['id', 'raw']`; `ri_` + 48 hex, sha256 stored — as `bin/mint_mcp_token.php` does) · `revoke_token(PDO, int $memberId, int $id): void` (own row, `revoked_at = now()`)
- `find_my_activity(PDO, int $memberId, int $pageNo, array $filters): array` (`from`, `to`) · `find_record_activity(PDO, string $type, int $id, int $limit = 50): array` · `find_activity_by_key(PDO, string $key, int $id, int $limit = 50): array` (`scope_id` / `ingredient_id` / `recipe_id` / `purchase_order_id` / `count_id`) · `activity_sentence(array $row): string` (`app/features/me/queries.php`, `present.php`)
- `search_all(PDO, string $q, int $limit = 25): array` (`ri_find()` rows grouped by `kind`, each with `url` from `record_url()`) · `record_url(string $recordType, int $id): ?string` (the ONE route map: ingredient, recipe, menu_item, modifier, supplier, supplier_item, storage_area, goods_receipt, inventory_transfer, inventory_adjustment, production_batch, waste_log, purchase_order, supplier_invoice, source, depletion_run, inventory_count, count_sheet, period, forecast_override, watch, buyer_proposal, member)
- `attachment_store(PDO, string $recordType, int $recordId, array $file, int $by): int` (a `$_FILES` entry or `['tmp_name', 'name', 'type', 'size']`; the size against `ATTACHMENT_MAX_BYTES` (25 MB by default); the MIME allow-list by sniffing: jpeg, png, webp, heic, gif, pdf, csv, xlsx, txt — DECISION; sha256; `storage/attachments/<record_type>/<record_id>/<id>-<safe name>`; the row) · `attachment_delete(PDO, int $id): void` (the row and the file) · `attachment_path(array $row): string` · `ri_can_see_attachment(PDO, int $id): ?array` (the `mcp_attachments` row or null)
- `picker_field(array $spec): string` · `form_picker(string $prefix, string $name, string $label, string $source, mixed $value, array $errors, array $opts = []): string` (`app/picker.php`, the plugin's) · `app/pickers.php` returns `[]` here; slice 1 adds `ingredient`, `supplier`, `supplier_item` (param `supplier`), `category`, `area`; slice 2 adds `recipe` (params `kind`, `exclude`), `menu_item`, `modifier`

## Handlers (every one: `ri_handler_begin()`; the gate; `ri_guard()`; `log_activity` with `scope_id`; `ri_done()`)
- `switch-site.php` (`site_switch`): the kit's; `require_human()`; a site not held → 403 "You do not hold that restaurant." and
  `site.switch.refused`; the switch logs **`site.switch`** today — **the manifest names `member.site_switch`; reconcile (see the report):
  Phase 2 renames the kit's two events to `member.site_switch` and `member.site_switch.refused`** (DECISION — the manifest is the authority).
  Location `/`; refresh `siteChanged`.
- `me/settings/save.php` (`preferences_save`): own, `require_human()`; `member.preferences_save` (`after`: the fields changed — `ri_diff()`;
  never the kinds' sentences); a `timezone` sent is refused in words (it is the directory's); location `/me/settings`; refresh `prefsChanged`.
- `me/notifications/read.php` (`notification_read`): own; `notification` empty marks all; `member.notification_read` (`after.count`); location
  `/me/notifications`; refresh `notificationChanged`.
- `me/tokens/mint.php` (`token_mint`): own and `tokens.own`, `require_human()` (an agent never mints); `token.mint` (`after`: `label`,
  `expires_at` — never the value); the raw token in the JSON reply once (`token`) and in `$_SESSION['minted_token']` for the browser once;
  location `/me/tokens#token-row-{id}`; refresh `tokenChanged`.
- `me/tokens/revoke.php` (`token_revoke`): own; `require_human()`; `token.revoke`; location `/me/tokens`.
- `logout.php` (exists): POST + CSRF; `end_session('member')`; `member.sign_out`; 302 to the launcher.
- `assistant/ask.php`: `require_human()`; `assistant.ask` (`after`: `length`, `screen`, `record_id` ≤ 40 chars, `scope_id`, `kernel` status,
  `run_id`, `status`, `actions` count, `navigate`, `cost` — never the words).
- `files.php` (GET): 401 anonymous, 404 unseen, the stream; logs nothing (a read).
- `pick/index.php` (GET): the picker's fragment; `require_login()`; the source's `roles` (rights, here) through `has_right()` at the current site;
  a search is not an action: nothing is logged.

## Manifest rows claimed
Screens (7): `home`, `site-switch`, `my-settings`, `my-notifications`, `my-tokens`, `my-trail`, `search`
Actions (5): `site_switch`, `preferences_save`, `notification_read`, `token_mint`, `token_revoke`
No agent approvals. `home` is rendered here with its empty states; slice 9 fills `home_summary()`'s keys and the cards (reports-admin.md names
the keys it fills and claims no row of this section).

| Action | File | Log | Who |
|---|---|---|---|
| `site_switch` | `/switch-site.php` | `member.site_switch` | ri.read (a site held) |
| `preferences_save` | `/me/settings/save.php` | `member.preferences_save` | own |
| `notification_read` | `/me/notifications/read.php` | `member.notification_read` | own |
| `token_mint` | `/me/tokens/mint.php` | `token.mint` | own and tokens.own |
| `token_revoke` | `/me/tokens/revoke.php` | `token.revoke` | own |

Every other screen of the manifest answers its placeholder (200 with the empty state after the right has been checked at the current site;
403 in the right's words; 501 `not_built` to JSON and to a POST).

## Activity log events
`member.sign_on`, `member.sign_on.refused`, `member.sign_out`, `member.refused`, `member.site_switch`, `member.site_switch.refused`,
`member.site_moved`, `directory.sync` (the timer), `member.preferences_save`, `member.notification_read`, `token.mint`, `token.revoke`,
`assistant.ask`, `screen.view` (every rendered GET, the placeholders included, with `scope_id`), `worker.pass`. **No row carries a token's
value, an utterance's words, or an email body.**

## Notifications this phase queues
None. It reads the bell and saves the preferences; the first producers are slice 4 (`price_alert`, `po_ack`), slice 5 (`pull_failed`,
`unmapped`), slice 6 (`count_review`, `count_recount`) and slice 8 (`watch`, `stockout`, `morning_note`).

## Status vocabulary
Role badge: Super-admin `danger`, Restaurant Inventory admin `danger`, Manager `primary`, Chef `info`, Kitchen `secondary`, Viewer `light`.
Notification kind chips: `watch` / `stockout` `danger`, `price_alert` `warning`, `pull_failed` / `unmapped` `warning`, `po_ack` / `po_date` /
`delivery_due` `info`, `po_decline` `danger`, `count_review` / `count_recount` `primary`, `variance` `warning`, `morning_note` `dark`, `mention`
`primary`, `agent_drafted` `secondary`, `period_due` `info`, `invoice_dispute` `warning`. Token: live `success`, revoked `dark`, expired `secondary`.

## Mobile rule (375 × 740)
One pane; the tab bar fixed at the bottom above the assistant bar; the sidebar an offcanvas; the site switcher a full-width row at the top of
the offcanvas (and `/switch-site` as a page); every control ≥ 44 px; `scrollWidth` = viewport; the notifications list and the trail as stacked
rows, the tokens table inside `.table-responsive`; the prefs form one column; the picker full screen with the search box pinned.

## Vocabulary
*shell* (the layout around `#page-content`), *site* (the current restaurant — the kernel's scope), *placeholder* (a screen its slice has not
built — 200, an empty state), *the bar* (the command bar), *the bell* (unread notifications), *a right* (`ri_rights`; what opens a menu item
at a site), *the badge* (the highest role held here), *the trail* (my activity in words), *a token* (a person's own MCP token; shown once),
*the picker* (the record picker modal).

## Out of scope for this phase
Every ingredient, recipe, stock, purchasing, sales, count, usage, projection, report and admin screen (slices 1–9): their menu items are
placeholders naming the slice; the home's regions (slice 9 fills them); the supplier's door `/s/` (slice 4 — the vhost's rewrite lands on 404
until then); the two MCP servers (Phase 4 — the proxies answer 502); the worker's passes (their slices); sending a notification (slice 4 on).

## Proof (`tests/phase2/run.sh`: the scratch database `ri_dev2`, the application on 9301 — `php -S` with `tests/dev_router.php`, or a real
Apache serving the rendered `deploy/apache-restaurant_inventory.conf` when `RI_APP=apache` (the internal vhost on 9307) —, the fake kernel on 9302,
the fake MaluDB + MaluMail on 9303, headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: **sso ≥ 55, gates ≥ 110, sites ≥ 30, sync ≥ 33, ingest ≥ 12, kernel_compat ≥ 12, vhost ≥ 28, browser ≥ 60; about 340 in all.**
The fixture (`bin/dev_directory.json`): 1 the owner (super-admin, admin at 101 Downtown and 102 Airport), 26 Priya (Kitchen at Airport), 27 Marco
(Manager at Downtown, Kitchen at Airport), 28 Bea (write at Downtown, no roles sent — read as Kitchen), 29 Ann (external Viewer at Downtown),
30 Dana (Chef at Downtown), 31 Lee (read at Airport, no roles — Viewer), 32 Nico (a write grant naming no site — refused), 40 the expert (an
agent, Chef at both), 41 Watcher (an agent not admitted), 42 the Purchasing agent (Manager at Downtown), 43 Ada (an agent admin at Downtown).
- [ ] **sso**: the Phase 0 checks again under the shell (hand-off 302 to `/`, replay 403, audience 403, unknown 403, tampered 403, Nico 403
  `no_site`, every refusal logged with its reason, the session listed with its `scope_id`, the sign-out notice 204 ending it); the home renders
  for each admitted member with their badge at their site (Super-admin, Kitchen, Manager at Downtown / Kitchen at Airport for Marco by the claim's
  `scope`, Kitchen for Bea, Viewer for Ann and Lee, Chef for Dana); the agent on the action-token path reaches a JSON screen and is kept off the
  bar, tokens and home (`require_human()`); an unknown id on the action-token path is refused and logged `member.refused`.
- [ ] **gates**: every screen of the manifest × the admitted members → 200 (built or the placeholder with its slice named), 403 in the right's
  words (Ann on `/receipts/`, `/counts/`, `/admin/settings`; Priya at Airport on `/recipes/` → 200 but `/modifiers/` → 403; Dana on `/invoices/`
  403, `/periods/` 200), or 302 anonymous; the sidebar shows Ann the `ri.read` items and no Admin group, Dana the Chef's, Marco at Downtown the
  Manager's and at Airport the Kitchen's, the owner everything; a POST to a placeholder → 501 `not_built`; JSON to a placeholder → 501.
- [ ] **sites**: Marco's hand-off with `scope: 102` lands at Airport; `site_switch` to 101 → `/` shows Downtown and the Manager badge; to a site
  not held (Priya → 101) → 403 and `member.site_switch.refused`; Ann sees one site and no switcher dropdown; the feed removing Marco's Airport
  holding moves a session parked there to Downtown within a minute (`member.site_moved`); a feed closing a site (`removed_at`) does the same;
  Nico gains a site through the feed and signs on; `data-scope-id` on `#page-content`; `screen.view` rows carry `scope_id`.
- [ ] **sync**: the fake kernel's incremental feed revokes Ann's grant (`capability: null`) → her next request ends her session within a minute
  (`ended_by directory`) and `/` answers 302; roles changed (Bea → `chef` at 101) → the badge changes on the next request; a department delivered
  and a member admitted appear in the mirror; `directory.sync` logged; the scopes read first (`scopes_at`).
- [ ] **ingest**: `mcp/activity_ingest.py` ships rows to the fake MaluDB with `"application": "restaurant_inventory"` first and advances the
  checkpoint; a rejected row stops it.
- [ ] **kernel_compat**: the run-facts gate (`valid: false` → refused; the kernel down → refused), a signed run token with the relay on the
  action-token path acts as the expert (`source agent`, `agent_run_id`) at the `site` given and at its first site when none, the approval replay
  header, `/sso/logout` 204 on a bad notice too; the application switcher renders the fake kernel's `apps/mine` rows and nothing when the route
  is absent.
- [ ] **vhost**: the rendered `deploy/apache-restaurant_inventory.conf` serves `/`, `/sso`, `/api/v1/health`, the MCP proxies (502 until Phase 4),
  `/s/<48hex>` → 404 (no handler yet), `/api/anything` → 404, `/files/1` → 401, `/pick/` → 302 anonymous, the canonical rewrites (`/ingredients/new`
  → 404 until slice 1's `form.php`; `/recipes/` → the placeholder's 200), the Header lines absent without `mod_headers` and present with it, the
  internal vhost on 9307 answering the same.
- [ ] **browser** (375 × 740 and 1280 × 800, JavaScript off too): the sidebar at 1280 and the tab bar at 375 (Ann's tab bar shows Search for
  Count and no Order; Priya's shows Waste for Order); the offcanvas with the site switcher row; the groups per role; the bell count after a
  notification row is inserted by SQL; the command bar answers the fake kernel's reply, shows "waits for approval" for a paused action, follows
  a `navigate` (`/recipes/`), says "The kernel is not reachable right now." when the fake kernel is stopped; My settings saves prefs (imperial,
  a default area of this site, kinds unticked persist; an area of the other site refused) and shows the time zone read-only; a token minted and
  shown once, gone on reload, then revoked; the trail shows the person's own rows in words with the site; search finds nothing gracefully (no
  catalog yet) and echoes the query escaped; the picker modal is in the layout and opens on a fixture field (`tests/phase2/picker.php` registers
  a `member` source for the proof alone); the manifest.webmanifest installable; every control ≥ 44 px; `scrollWidth` = viewport; no console
  errors; the home whole with JavaScript off.
- [ ] **registry and approvals**: `bin/build_action_registry.php --check` reads 7 screens and 5 actions built, every other screen unbuilt
  (`/switch-site.php` exists already — the registry reads `site_switch` built from Phase 0); `bin/sync_approvals.php --check` green (25
  approvals); `tests/phase1/check_specs.sh` green; `db/018` applied by `tests/setup_dev.sh` and the Phase 0 proofs still green (123 + 164).

**Decisions taken in this spec (not questions):**
- The menu table above is the one list; a slice adds no item — it removes `render_nav_stub()` from its controllers. Every right is asked at the
  current site; the Admin group needs one of its rights there.
- `db/018` appends `display_units` and `default_area_id` to `notification_prefs` — the manifest's preferences without a new table.
- The site switch's events are renamed to the manifest's `member.site_switch` / `member.site_switch.refused` (the kit logged `site.switch`).
- The record picker ships with the shell (not with slice 1 as Inventory did): slice 1's first forms choose a category, a supplier and an
  ingredient, and the exemplar recipe editor (slice 2) depends on it.
- The tab bar's third and fourth tabs follow the role (Count for `stock.count`, else Search; Order for `purchasing.draft`, else Waste for
  `stock.waste`, else Search) — a Viewer and a cook each get five useful tabs.
- A `timezone` sent to `preferences_save` is refused in words — the time zone is the directory's; the field is not in the form.
- `home` is rendered here (the empty states) and claimed here; slice 9 fills the keys without a row of its own.
- The attachment MIME allow-list is jpeg, png, webp, heic, gif, pdf, csv, xlsx, txt (a phone's photo of a waste bin or an invoice, a POS
  export); no thumbnailing in version 1 — the browser's `<img>` at card size.

## Open questions
(none)
