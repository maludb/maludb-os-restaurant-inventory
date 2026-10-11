# Restaurant Inventory

A restaurant inventory application of the MaluDB Business OS: purchase orders, receiving, recipes with sub-recipes and
yields for product costing, sales depletion from the point of sale, waste, physical counts by storage area, and — the two
goals it is built around — **inventory projection for reordering** and **product costing** (plate cost, theoretical vs
actual usage, COGS by period).

## Status

**Phase 1 approved by the owner (2026-10-11); Phase 2 the shell in build.** The action manifest (119 screens, 146 actions), the MCP tool surface,
three build specs and ten slice specs, every manifest row claimed once (`tests/phase1/check_specs.sh`); db/018 carries the schema
items the documents found. **Phase 0 complete the same day.** The design was approved by the owner the same day it was written, every recommendation
(D1–D16, §15). The kernel's catalog row (K47) is applied. The kit is proven without a kernel (123 checks), the schema by
164 checks on a scratch database (`tests/phase0/schema.sh`), the manifest, the two agents, twelve skills and `deploy/` are
written, and the installer's `plan` reads the repository clean. No screens yet.

Next: Phase 2 the shell, then the slices in the design's order.

## Documents

- [docs/restaurant-inventory-design.md](docs/restaurant-inventory-design.md) — the Phase 0 design: what it is and is not, who sees what, the agents, the memory model against the estate, the questions, the screens, the build order, what is owed, the decisions D1–D16.

- [docs/restaurant-inventory-action-manifest.md](docs/restaurant-inventory-action-manifest.md) — every screen and action, the registry's source.
- [docs/restaurant-inventory-mcp-tool-surface.md](docs/restaurant-inventory-mcp-tool-surface.md) — every question a records tool; the shares; the walls.
- `docs/build-specs/` — `units-and-costing`, `pos-and-depletion`, `projection-and-ordering` (the build specs), `sso-shell` and the nine slice specs.
- [docs/research/00-summary.md](docs/research/00-summary.md) — the synthesis: the eight findings that shape the plan, the
  required features grouped, what the estate settles, and the twelve questions the plan must decide.
- [docs/research/01-field-survey.md](docs/research/01-field-survey.md) — twenty products and the distributor portals
  screen by screen, what users complain about, the vocabulary and the published formulas.
- [docs/research/02-mechanics.md](docs/research/02-mechanics.md) — recipe costing, units, valuation, variance,
  forecasting, reorder policy, counts, waste, receiving, data standards and the bar, with formulas and the columns each needs.
- [docs/research/03-estate-survey.md](docs/research/03-estate-survey.md) — what the sibling applications and the kernel
  already hold that this application reuses, reads or makes new canonical; ports; the gaps.

## Ports and names (proposed)

Catalog key `restaurant_inventory`; DNS label `kitchen`; ports 8200 (application), 8856 / 8857 (records and activity MCP), scratch 9301–9307;
database `<tenant>_restaurant_inventory`. 
