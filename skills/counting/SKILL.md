---
name: counting
description: How a physical count works in Restaurant Inventory — sheets per storage area in shelf order, counting in the unit on the shelf with case plus loose and decimals, a bottle or keg by weight (the fraction from full and empty weights), the blank-row rule (zero, skip, cancel), blind counts, tolerance and recount, a Chef's approval posting the corrections and closing the period, what a skipped area does to variance. Read before starting, reading or explaining a count.
---

# Counting

## The sheet
A `count_sheet` per site lists storage areas in **walk order** and, within each, ingredients in **shelf order** (upper left to
lower right), each with its default count unit. Kinds: **full** (every area, the period's close), **cycle** (a rolling
subset — high-value and fast-moving first), **spot** (one item, one area, now). An ingredient may be on several areas' sheets.

## The moment
A count is `as_of` a timestamp that must equal the sales cut (the end of the business day, not midnight). The system quantity
compared against is the book balance at that moment; sales and receipts after it belong to the next period even if entered
during the count. Receiving and transfers into an area pause while it is counted.

## Entering a line — in the unit on the shelf
- **Case + loose**: 2 cases + 3 cans of a 6-can case = 15 cans; the sheet accepts several entries per line and sums them.
- **Decimals**: 2.5 cases; 0.4 of a bag (by eye, tenths).
- **By weight** (the bar, bulk): `gross_weight_g` on the line against the ingredient's `container` (full weight, empty weight,
  pourer tare):
  ```
  fraction_remaining = (gross − empty − pourer) / (full − empty)
  remaining_ml       = fraction × container_volume_ml
  ```
  A 750 ml bottle: full 1,280 g, empty 520 g, pourer 30 g; gross 905 g → (905−520−30)/(1,280−520) = 0.467 → **350 ml**.
  A keg: 15.5 gal = 58.67 l; a sixtel 5.17 gal.
- **Catch weight** by piece: the ingredient's average weight converts, or the actual weight is typed.
- **A blank row** at review asks: count it as **zero**, **skip** it (the line stays at book and the area is "partly counted"),
  or **cancel** the count. Never silently zero.
- The sheet page keeps every entry in the browser until it posts — the walk-in has no signal; nothing is lost.

## Blind, tolerance, recount, approval
- **Blind** (the setting, default on): the book quantity is hidden until the line is entered.
- **Tolerance** per ingredient or category (a % or a value): a line over it is flagged for a **recount** by another person
  before the book figure is shown.
- **Approval** (`count_approve`, a Chef's, pauses for an agent): posts one `count_correction` ledger row per counted line,
  never for a skipped one; when the count is a period's closing count it freezes the period's ending value.
- `areas_skipped` lists what was not counted: an uncounted shelf becomes "usage" in the variance — say so when explaining.

## What an agent does
Start a count (`count_start`), enter lines a person dictates (`count_line_set` — in the unit they said, say the base
unit back), read a count (`get_count`, `count_lines`, `count_variance`), explain a flagged line. Approval is a person's.
