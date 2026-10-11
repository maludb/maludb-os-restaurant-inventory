# Worker brief — Restaurant Inventory slices 4, 6, 7, 8, 9 and Phases 4–5 (Sonnet 5.5)

You build ONE slice (or one phase) of Restaurant Inventory from its build spec. The planning model built the kit, the shell (Phase 2) and slices 1,
2, 3 and 5 (the exemplars), and it verifies and commits your work. The owner approved this division (design §13.16, D16). Follow this brief
exactly; the spec is the contract.

## Your assignment and its ports
One worker at a time, in this order. Each owns a block of four ports inside the application's 93xx scratch block (design §14) and a scratch
database of its own:

| Work | Spec | App | Fake kernel | Fake MaluDB + MaluMail | Apache internal vhost | Scratch database |
|---|---|---|---|---|---|---|
| Slice 4 — purchasing, the supplier's door, invoices | `purchasing.md` | 9325 | 9326 | 9327 | 9328 | `ri_dev_s4` |
| Slice 6 — counts | `counts.md` | 9329 | 9330 | 9331 | 9332 | `ri_dev_s6` |
| Slice 7 — periods and usage | `periods.md` | 9333 | 9334 | 9335 | 9336 | `ri_dev_s7` |
| Slice 8 — projection and ordering (the third exemplar's spec, built by you) | `projection.md` + `projection-and-ordering.md` | 9337 | 9338 | 9339 | 9340 | `ri_dev_s8` |
| Slice 9 — reports, exports, admin, the home's cards | `reports-admin.md` | 9341 | 9342 | 9343 | 9344 | `ri_dev_s9` |
| Phase 4 — the records and activity MCP servers, the registry wrapper | `docs/restaurant-inventory-mcp-tool-surface.md` | 9345 | 9346 | 9347 (records MCP) | 9348 (activity MCP) | `ri_dev_p4` |
| Phase 5 — the install's files and the end-to-end proof script | design §10 Phase 5 row, `deploy/` | — | — | — | — | — |

Phase 5's `apply`, DNS, TLS and the hires are the **owner's**: you prepare `deploy/ROOT_STEPS.sh`, the end-to-end proof script and its
checklist, and run the installer's `plan` only through the planning model.

## Where and what you may touch
- The repository is `/srv/apps/restaurant_inventory` (main branch, NOT installed — `config/.env` holds no live password). Work in it directly.
- **Never commit, never push, never `git stash`/`reset`/`checkout` away anyone's work.** Leave every change in the working tree; the planning
  model reviews the diff and commits.
- Never touch `/var/www` (the kernel) or another application under `/srv/apps/*` — read them for reference only. Never run `app_install.php apply`.
- Databases: only the scratch database your proof makes (`sudo -n -u postgres` is allowed for scratch `ri_*` databases and the three
  `restaurant_inventory_*` roles only). `tests/setup_dev.sh` reuses the roles' passwords — never reset them.
- Never put a secret, a token or a transcript in a file.

## Read before writing any code
1. Your spec in `docs/build-specs/` — every section: what exists at the end, schema, screens, forms, posting step by step, files ("exactly
   these"), query functions (signatures fixed), handlers, manifest rows claimed, log events, notifications, status vocabulary, mobile rule,
   out of scope, proof, open questions. Slice 8 also reads `projection-and-ordering.md`; any POS-touching work reads `pos-and-depletion.md`;
   every slice reads `units-and-costing.md`.
2. The manifest rows it claims in `docs/restaurant-inventory-action-manifest.md`, the tools in `docs/restaurant-inventory-mcp-tool-surface.md`,
   and the design sections it names in `docs/restaurant-inventory-design.md`.
3. The schema it names (`db/*.sql`, including `db/018_phase1_fixes.sql`) — read the functions and triggers you call. **The database is the
   referee**: units it cannot convert, a case under one base unit, a cycle, a line changed after posting, a balance written by hand, a count
   approved before its recount, a period closed out of order — the schema refuses, and your handler shows its sentence.
4. The exemplars, which are binding on shape and idiom:
   - slice 1 (the CRUD exemplar): `app/features/catalog/`, `html/{ingredients,suppliers,areas,units,categories,notes,attachments}`;
   - slice 2 (the first exemplar): `app/features/recipes/`, `html/{recipes,menu,modifiers}` — the editor, the live cost panel, the what-if;
   - slice 3 (the ledger): `app/features/stock/`, `html/{stock,receipts,transfers,adjustments,production,waste}` — the 375 px screens;
   - slice 5 (the second exemplar): `app/features/pos/`, `app/pos/` (the connectors), `html/sales/`, `bin/worker.php`'s POS passes;
   - their proofs `tests/phase3/slice{1,2,3,5}/` (`lib.php`, the per-area PHP proofs, `browser.mjs`, `servers.sh`, `run.sh`);
   - the kit: `app/handler.php`, `app/http.php`, `app/auth.php`, `app/bootstrap.php`, `app/db.php`, `app/activity.php`, `app/picker.php`,
     `app/pickers.php` (+ `app/pickers/`), `app/mail.php`, `app/views/layout.php`, `app/features/shell/nav.php`;
   - each finished spec's `## Built and proven` section for decisions already taken (`sso-shell.md`, `catalog.md`, `recipes.md`, `ledger.md`, `pos.md`).
5. The builder plugin from its clone (the installed copy is stale): `~/htmx-php-builder/skills/php-patterns/`, `design-system/` (incl.
   `references/record-picker.md`), `malumail-send/` when you send mail, `mcp-servers/` for Phase 4, `chat-actions/` for the manifest; and
   `~/maludb-os-integration/skills/os-integration/references/` (`mcp-and-api.md`, `sms-and-reads.md` for K7, `scoped-applications.md`).

## The kit's idiom (must match)
- **Every screen is of the current site.** `require_login()` → `require_site()` / `current_site_id()`; a site row the person does not hold is
  the same 404 as a missing one. Rights are asked AT the site: `has_right($right, $scopeId)`, `require_right()`; cost through `sees_cost($scopeId)`.
- A write handler: `ri_handler_begin()` → load the record (a hidden record is the same 404 as a missing one) → the right at the site →
  field readers (`req_val`, `ri_text`, `ri_int`, `ri_choice`, `ri_ref`, `ri_yes`, `request_list`, …) collecting `$errors` →
  `ri_refuse_fields($errors)` → `ri_guard($pdo, fn)` (a transaction + `log_activity()`; it turns the schema's refusals into 422/404/403 with the
  schema's sentence) → `ri_done(message, id, ri_land(...), 'hxEvent', extra)`. Every state-changing handler is POST + CSRF + authorization +
  `log_activity()` with `scope_id` and the audit keys the record carries (`ingredient_id`, `recipe_id`, `purchase_order_id`, `count_id`).
- A screen: `log_screen_view()`, `wants_json()` → `respond_screen()` from a whitelist presenter in `present.php` (never raw rows), else
  `render_screen(title, view(...), [...activeNav, screen, entity, recordId])`; `#page-content` stamped `data-screen`, `data-entity`,
  `data-record-id`, `data-scope-id`.
- `view()` uses `extract(EXTR_SKIP)`: NEVER pass data keys named `template`, `data`, `path` or `viewsRoot`.
- **Quantities and costs render in ONE place:** `app/features/units/queries.php` (`ri_qty_sentence()`, `ri_cost_sentence()`,
  `ri_unit_options()`, `format_qty()`, `display_qty()`, `fmt_input()` for EVERY number input — a value with a thousands separator is dropped by
  the browser —, `money()`, `ri_fmt()`, `ri_money()`, `ri_from_base_db()`). A new shared helper is appended THERE; never a second copy. PHP
  never multiplies a unit factor or computes a cost: `ri_to_base()`, `ri_from_base()`, `ri_cost_as_of()` and the read functions do.
- **A quantity is always given with its unit** in forms and actions (`qty` + `unit`), converted by the database.
- Full pages, no modals (the record picker and `hx-confirm` are the only exemptions); works at 375 px with no sideways scroll; a form chooses a
  record through the record picker, never a `<select>` of rows; lists of named things are cards, ledgers and line items tables.
- Words, not codes, on screen; the spec's status vocabulary and element ids exactly.
- A credential, a secure token, a POS check's raw payload or an email body never goes into an activity-log payload.
- Match the surrounding code's comment density and naming. Constants and functions are global — check for a clash first
  (`grep -rn "function NAME\|const NAME\|define('NAME"`).

## Migrations
The existing `db/*.sql` are never modified. A schema defect you find gets a NEW numbered migration (the next free number — `ls db/`; name it
`db/0NN_slice<N>_<what>.sql`), additive, views keep `security_barrier` and their grants, applied by `tests/setup_dev.sh` with the rest,
recorded under "Found and fixed". Re-run the schema proof (`tests/phase0/schema.sh`) after it.

## Ambiguity
If the spec is silent on a detail that changes nothing outside the slice (wording, layout, an order), take the reading closest to the
exemplars and the design and record it under "Decisions taken here". If an answer would change the schema's meaning, a right, the manifest,
the tool surface, or contradicts another spec — STOP: end your turn with a report beginning `STOPPED:` and the exact question; you will be
resumed with the answer. Never improvise around a gap.

## Proof (the slice is not done until all of this is green)
- `tests/phase3/slice<N>/run.sh` modelled on slice 5's (and slice 2's `servers.sh`, which takes `P_APP`/`P_KERNEL`/`P_FAKE` and stops ONLY its
  own ports): a fresh scratch database (env `/tmp/ri-dev-s<N>.env`, state `/tmp/ri-dev-s<N>-state`, shots `/tmp/ri-shots/slice<N>`), your
  port block above, the schema proof, one PHP proof per area plus a JSON proof (every screen and handler in JSON mode under the action token,
  the HTMX stamps, the log vocabulary, the registry counts) and `browser.mjs` (Playwright from `/var/www/web/node_modules`, read only: the
  slice's screens at 375×740 and 1280×800, no sideways scroll, no console error, the enhancements and the no-JavaScript path), the server-log
  check, the registry `--check`. Everything the spec's Proof section lists is a check.
- Run it under php -S AND `RI_APP=apache` (the user-level Apache on your block's internal vhost port); both must pass.
- Look at the screenshots (the Read tool shows images) — viewport shots, not full-page — and fix what looks wrong (overflow, truncation, overlap).
- `php bin/build_action_registry.php` after the manifest rows are built; `--check` green.
- Re-run every earlier suite and keep them green, **one at a time, never two at once**: `tests/phase3/slice{1,2,3,5,…}/run.sh` (each on its own
  ports), then the shared ones — `tests/phase2/run.sh` (9301–9303), `tests/phase0/run.sh`, `tests/phase1/check_specs.sh`. An earlier proof that
  asserted this slice UNBUILT (a placeholder, a greyed link, a stub count) is changed to follow whether it is built, never deleted.
- **PHP's built-in server with several workers flakes under load** ("Failed to poll event" in its log; a whole section failing with status 0).
  Re-run once before diagnosing; if it repeats, look for another process on your ports (`ss -ltnp | grep :93`).
- Do not pipe `servers.sh start` into another command (its background servers hold the pipe).
- The kernel's installer `plan` is the planning model's to check; never run the installer.

## Docs (in the same working tree)
- The spec gains `## Built and proven` in the form of `recipes.md`'s and `pos.md`'s: what was built, **Proven** with each proof's count under
  both servers, **Found and fixed**, **Decisions taken here (within the spec)**.
- `docs/restaurant-inventory-design.md` §16 gains an entry after the last one in the form of slice 5's; `README.md`'s Status line extended.

## Your final report (your last message)
1. `DONE` or `STOPPED: <question>`.
2. Files added and changed (a list), migrations added and why.
3. Each proof's count under php -S and under Apache; the registry's screens/actions built.
4. Every earlier suite re-run and its result (both servers where it has two).
5. Decisions taken; defects found and fixed; anything in the spec NOT done, and why.
6. A draft commit message in the repository's style (`Restaurant Inventory slice <N>: … (date)`, one long paragraph like slice 5's).
