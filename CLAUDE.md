# Restaurant Inventory — CLAUDE.md

This repository is the **Restaurant Inventory** application of the MaluDB Business OS (`github.com/maludb/maludb-os-restaurant-inventory`,
**public** — the owner, 2026-10-11; local clone and install path `/srv/apps/restaurant_inventory`; catalog key `restaurant_inventory`;
DNS label **`kitchen`**). The kernel is `/var/www` (`maludb-os-core`); read its CLAUDE.md first — the kernel owns identity, the
directory, the sites, the agents, approvals and the token ledger. This application owns the kitchen's and the bar's **stock**:
ingredients in the units they are bought, counted and cooked in; costed recipes with sub-recipes and yields; purchasing and
receiving; the POS's sales depleting stock through the recipes; waste, transfers, prep batches and counts by storage area; usage
periods with actual against theoretical; and **the projection** — expected sales exploded through recipes into expected usage and
one suggested order per supplier. Every restaurant is a kernel **site**; the application is scoped by location.

## Read first
1. `docs/restaurant-inventory-design.md` — the plan (Phase 0), approved by the owner 2026-10-11, every recommendation (§15): what it
   is and is not (§1), the actors (§2), **who sees what — cost is the wall** (§3), the doors (§4), the agents (§5), the memory model
   against the estate (§6), the questions (§7), the kernel's part and the six shares (§8), the screens (§9), the build order (§10),
   Extended (§11), what is owed — K47, G5, T2 (§12), the decisions D1–D16 (§13), ports and files (§14), the state (§16).
2. `docs/research/` — the reports behind it (the field, the mechanics with every formula, the estate).
3. The plugins, **read from the local clones** (Claude Code's registered builder plugin is the stale 0.4.1): `maludb-os-integration`
   (`~/maludb-os-integration`, skill `os-integration` — `shared-schema.md`, `scoped-applications.md`, `agents.md`, `sms-and-reads.md`)
   and `htmx-php-builder` 0.9.0 (`~/htmx-php-builder`: `new-app`, `os-application`, `php-patterns`, `design-system` with the record
   picker and the switcher, `mcp-servers`, `chat-actions`).
4. The nearest exemplars: `/srv/apps/inventory` (the canonical storeroom ledger, purchasing, `sources`, the Buyer agent),
   `/srv/apps/txtschedules` (a site-scoped kit, `forecast_covers` from Reservations), `/srv/apps/cidery` and `/srv/apps/processcore`
   (versioned recipes, units), `/srv/apps/malumail` (the newest kit).

## Rules that bind here
- `htmx-php-builder` governs the PHP; `os-integration` governs the fit, and wins where they disagree: **no login form, no account
  of our own, no model key and no model call, no shared tables, no actions server of our own, no writes to the directory, no
  connection to another application's database or the kernel's.**
- **Every right is asked at a site** (`ri_has_right(right, scope_id)`; PHP `has_right()`, `require_right()` default to the session's
  current site). Ingredients, units, recipes and suppliers are shared across sites and opened by a right at ANY held site
  (`ri_has_right_anywhere`); stock, pars, costs, documents, sales, counts, periods and forecasts carry `scope_id`.
- **Cost is the wall**: `ri_sees_cost(scope_id)` — Chef and above, Kitchen when the site's `kitchen_sees_cost` is on, never a Viewer;
  the `mcp_*` views null the cost columns through it, once per statement.
- **Every stored quantity is numeric in the ingredient's base unit; every cost per base unit.** A case is never "1 each". Show the unit
  on every number and say what it converted from.
- **Perpetual balance, periodic close**: the ledger moves on every document; an approved count corrects it; a closed period freezes
  the ending value and COGS. A ledger row is never changed or deleted — reversed.
- **Agents propose; people commit money and approve counts**: sending a PO pauses as `money_out`; approving a count, closing a period,
  settling a match and activating a recipe version pause for an agent (design §5).
- Checkpoint gate: no feature PHP until the owner approves the schema, the MCP tool surface and the action manifest together.
- Commit each finished step on `main` in the repository's message style; never push unless the owner says so.
- The user runs every `sudo` command; ask for them — except `sudo -n -u postgres` for **scratch `ri_*` databases and the three
  `restaurant_inventory_*` roles** (the proofs), as the siblings have it.

## The kit (Phase 0, built 2026-10-11) and its proof
The kit is MaluMail's Phase 0 kit (the newest: the mirror fix for job title / phone / time zone, the guard reading five refusal
codes) renamed `ml_` → `ri_` and made SCOPED the way txtSchedules is: `db/001`–`006` (the identity mirror with `sites` and
`member_site_roles`; the activity log with `scope_id` and the audit keys `ingredient_id`, `recipe_id`, `purchase_order_id`,
`count_id`; tokens and keys; **the five roles and their rights, per site** — `ri_has_right`, `ri_scopes_with_right`,
`ri_has_right_anywhere`, `ri_is_admin`, `ri_sees_cost`, `ri_is_member_here`, `ri_right_refusal`; `ri_settings`, `site_settings`,
`day_parts`, `ri_site_materialise`; attachments, notes, `document_sequences` with `ri_number_document(kind)`, `tax_rates`), `app/`
(sign-on, the scoped mirror, the gates, the site and application switchers, handlers, the activity funnel, notification mail only),
`bin/` (the directory sync reading scopes.php first, the worker with twelve passes as stubs naming their slice, the registry builder
for `docs/restaurant-inventory-action-manifest.md`), `html/` (`/sso`, `/sso/logout`, `/logout.php`, `/switch-site.php`, health, the
Phase 0 home), `mcp/` (the read servers' base and the ingest bridge), `tests/`.
- `bash tests/setup_dev.sh` — the scratch database `ri_dev0` (`RI_MIGRATIONS_UPTO=006` for the kit alone) and its environment in
  `/tmp/ri-dev0.env`.
- `RI_MIGRATIONS_UPTO=006 bash tests/phase0/run.sh` — the kit without a kernel: **123 checks** (2026-10-11), ports 9301–9303.
- The schema proof (`db/proof/phase0_proof.sql`, `tests/phase0/schema.sh`) comes with db/007+.

## Numbers
Ports `APP_INTERNAL_PORT=8200`, `MCP_RECORDS_PORT=8856`, `MCP_ACTIVITY_PORT=8857`; scratch 9301 (the app), 9302 (the fake kernel),
9303 (the fake MaluDB + MaluMail REST), 9304–9305 (the MCP servers), 9306–9307 spare; prefix `ri_`; scratch databases `ri_*`;
database `<tenant>_restaurant_inventory`. Kernel items K47 (the catalog row, db/182 — built and applied 2026-10-11), K48 (a per-site
duty hour, optional); the GL owes G5; txtSchedules T2.
