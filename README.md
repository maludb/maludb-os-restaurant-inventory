# Restaurant Inventory

A restaurant inventory application of the MaluDB Business OS: purchase orders, receiving, recipes with sub-recipes and
yields for product costing, sales depletion from the point of sale, waste, physical counts by storage area, and — the two
goals it is built around — **inventory projection for reordering** and **product costing** (plate cost, theoretical vs
actual usage, COGS by period).

## Status

**Planning (Phase 0).** The research was written 2026-10-11 and the design the same day; the design awaits the owner's
word on its sixteen decisions (§13). No schema and no screens yet.

Next: the owner's answers → K47 (the catalog row) → the rest of Phase 0 (the schema proven on a scratch database, the
kit, the manifest, the agents, the skills, `deploy/`).

## Documents

- [docs/restaurant-inventory-design.md](docs/restaurant-inventory-design.md) — the Phase 0 design: what it is and is not, who sees what, the agents, the memory model against the estate, the questions, the screens, the build order, what is owed, the decisions D1–D16.

- [docs/research/00-summary.md](docs/research/00-summary.md) — the synthesis: the eight findings that shape the plan, the
  required features grouped, what the estate settles, and the twelve questions the plan must decide.
- [docs/research/01-field-survey.md](docs/research/01-field-survey.md) — twenty products and the distributor portals
  screen by screen, what users complain about, the vocabulary and the published formulas.
- [docs/research/02-mechanics.md](docs/research/02-mechanics.md) — recipe costing, units, valuation, variance,
  forecasting, reorder policy, counts, waste, receiving, data standards and the bar, with formulas and the columns each needs.
- [docs/research/03-estate-survey.md](docs/research/03-estate-survey.md) — what the sibling applications and the kernel
  already hold that this application reuses, reads or makes new canonical; ports; the gaps.

## Ports and names (proposed)

Catalog key `restaurant_inventory`; DNS label `kitchen` (proposed); ports 8200 (application), 8856 / 8857 (records and activity MCP), scratch 9301–9307;
database `<tenant>_restaurant_inventory`. 
