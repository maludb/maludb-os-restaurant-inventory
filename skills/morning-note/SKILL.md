---
name: morning-note
description: Runbook for the Purchasing agent's daily duty — the seven headings per site in order (suggested orders with drafted purchase orders, projected stock-outs, prices, the POS, the last count, the forecast, purchasing), which tool answers each, what is drafted and what is never sent, and who the note goes to. Run every day 06:00 or when a person asks for the morning note.
kind: runbook
---

# The morning note

**When**: every day 06:00 (the duty), or when the Manager asks. **Who runs it**: the Purchasing agent. **Per site** you are
granted, one note each. **Takes**: about fifteen tool calls a site plus one draft per supplier due. **Writes**: drafts,
proposals, watches, one note. **Sends**: nothing outside the business.

## 1. Suggested orders — `supplier_schedules`, `suggested_orders`, then `purchase_order_draft`
Suppliers whose cut-off falls today or tomorrow at this site. For each, the suggested order (the rule in `ordering`);
**one drafted purchase order per supplier**, every line carrying need / have / on order / cap / rounding. A supplier with a
draft already open: list it, do not double. Lines on a thin forecast (under four weeks of history) are flagged "fixed par
used". Report each draft's number, delivery day and total at cost. **Drafted, never sent.**

## 2. Projected stock-outs — `projected_stock`
Ingredients whose projected on hand goes under zero before their next delivery. Name the day, the shortfall and the
supplier and schedule that could fill it; `watch_set` on the worst so a change wakes you.

## 3. Prices — `price_exceptions`, `price_movers`
Cost moved more than the threshold (default 10 % per category) on yesterday's invoices; a contract price the invoice did
not honour; the week's five biggest movers with the plates they hit most (`recipes_using`). Set nothing.

## 4. The POS — `pos_health`, `unmapped_plus`, `calendar_days`
Pulls failed or late; the last business date depleted; PLUs that sold with no recipe (count, sales, the top five by
quantity); modifiers unmapped; a day open on the calendar with no sales. You map nothing — a person does from the queue
(`plu-queue`).

## 5. The last count — `count_variance`, `areas_skipped`
The most recent approved count: lines over tolerance with the explained buckets and what is unexplained, the areas
skipped, the first thing to check for each (the rules of `reading-variance`).

## 6. The forecast — `forecast_accuracy`, `calendar_days`
Items whose WAPE worsened over the last two weeks; closures and holidays in the next fourteen days with no override.
Propose an override with its reason (`forecast_override_propose`) — a person applies it.

## 7. Purchasing — `purchase_orders_open`, `deliveries_due`, `invoice_mismatches`
POs unacknowledged past the setting's days; deliveries due today with no receipt; invoice lines that do not match the
receipt (quantity or price, the difference). A person chases and settles.

## The note — `morning_note_send`, then the kernel's `message_send`
Seven headings with counts; the five things that matter most first; drafts and proposals by number; cost only because the
Manager may see it; under two minutes to read. To the site's Manager (`RI_MANAGER_EMAIL` seeds it; the site settings
change it; the first super-admin until set), to the Manager's assistant by `message_send`, and to `#kitchen` in Spaces
when granted. `buyer_propose` once per kind and subject; never repeat a proposal already made or dismissed
(`buyer_proposals`). Nothing to report: the short note anyway.
