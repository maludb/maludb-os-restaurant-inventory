# POS fixtures (slice 5) — synthetic, named SMOKE, no vendor data

The same two business days (2026-10-05 and 2026-10-06, a site in America/New_York with the default 04:00 sales cut) written in the
shape of each mapping template (`db/018`, `db/023`): five checks, eight readable lines and one malformed row. Day 1: two chicken
sandwiches with one "extra chicken" (XCHK) and a gin and tonic at lunch (check 001); a voided sandwich, a comped sandwich with "no
tomato" (NOTOM) and one PLU no recipe maps (2002, "SMOKE Mystery Special") at dinner (002); two gin and tonics closed at 03:30 — before
the cut, so still day 1, outside every day part (003). Day 2: one sandwich with XCHK ×2 and NOTOM at lunch (004); three gin and tonics
at dinner (005); a row whose quantity is "abc" (006 — cannot be read).

| File | Mapping |
|---|---|
| `generic.csv` | `generic` |
| `square_items.csv` | `square_items` (comp = a discount of the whole line, void = a Refund event) |
| `toast_items.csv` | `toast_items` (US dates, 12-hour times) |
| `clover_items.csv` | `clover_items` |
| `lightspeed_items.csv` | `lightspeed_items` |
| `sftp/sales_20261007.csv` | `generic` — one day (2026-10-07) the SFTP stand-in serves |
| `email/nightly_20261009.csv` | `toast_items` — one day (2026-10-09) mailed to the intake mailbox |
| `square/orders-page1.json`, `orders-page2.json` | the fake Orders API (`router.php`): 2026-10-08, two pages through a cursor |

The column names of the vendor exports are the seeded templates' — to be confirmed against a real export in Phase 5 (pos-and-depletion.md §2.2).
