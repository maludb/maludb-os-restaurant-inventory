---
name: post-count-review
description: Runbook for reviewing a physical count in Restaurant Inventory after it is entered — the flags over tolerance, the blank rows, the areas skipped, the explained and unexplained variance per line, the first thing to check for each, what the Chef approves and what approval does. Run when a count reaches review or the morning duty finds a new approved count.
kind: runbook
---

# Reviewing a count

**When**: a count's status is `review` (or `recount`), or the morning duty finds a new approved count to read. **Who**:
the expert on request, the Purchasing agent in the duty. **Writes**: notes, proposals. **Approves**: nobody but a Chef.

## 1. The count — `get_count`, `count_lines`
Site, sheet, kind, `as_of`, who started it, blind or not, how many lines counted / zero / skipped / pending.

## 2. The flags — `count_variance`
Lines over their tolerance (a % or a value), variance in the count unit, the base unit and at cost. For each, the first
thing to check, in this order:
1. **The unit** — a case entered as one each shows as a huge shortage; a bottle weighed without the pourer tare as a small one.
2. **A receipt or transfer not posted** before `as_of` (`receipts_open`, `transfers_open`, `stock_movements` since the last count).
3. **An unmapped PLU** that sold the item (`unmapped_plus`) — theoretical too low.
4. **Waste not logged** — ask; a sentence in the note, not a guess.
5. **Prep counted twice** — a batch counted as its own ingredient and its raw lines counted too.
6. **The recipe** — a quantity per portion off by the line yield.

## 3. The holes — `areas_skipped`
Areas or lines skipped: say what they do (an uncounted shelf becomes usage) and whether a spot count would settle it.

## 4. What to propose — `note_add`, `buyer_propose`
A recount on the worst lines (the system asks another person when the tolerance rule is on), a spot count of a skipped
area, a PLU to map, a receipt to post. Never change a line yourself; never approve.

## 5. After approval (the duty's reading)
`count_variance` on the approved count: the buckets per line (waste logged, comps, transfers), the unexplained total at
cost, the five worst by dollars, the period it closed (`get_period`) and its COGS and food-cost % (`food_cost_pct`).
Read `reading-variance` before you write the paragraph.
