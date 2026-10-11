# Build spec: purchasing — purchase orders, the supplier's door, receiving against, invoices and the three-way match, price alerts (slice 4)

Built by a worker model on slice 1's CRUD pattern and slice 3's documents, after slice 3 (the receipts this slice receives against) and before
slice 8 (the suggested order drafts the POs this slice sends). What exists at the end: the Manager drafts a purchase order to Sysco from its order
guide — three bags of flour, a case of eggs — or finds one the Purchasing agent drafted with need, have, on order and the rounding on every line;
sends it by email with a secure link the supplier opens to acknowledge, decline the eggs and set Tuesday; a cook receives two bags on Tuesday through
slice 3's receipt and the PO goes partial; the invoice arrives at $33 a bag, the three-way match flags the price, the Manager settles it and the cost
history takes the invoice's price; a cost that moved 20 % appears on the alerts list. **Sending or placing a PO is a person's word to pay** —
`money_out` pauses an agent; a message to a supplier leaves the business — `external_send`; settling a match changes the books — `other`.
Schema: `suppliers`, `supplier_schedules`, `ri_next_order_windows()`, `supplier_items`, `ri_supplier_item_cost_per_base()`, `order_guides`,
`order_guide_lines`, `storage_areas`, `ri_to_base()`, `ri_cost_as_of()`, `price_history` (db/007); `goods_receipts`, `goods_receipt_lines`,
`ri_post_receipt()`, `ri_post_txn()` (db/009); `purchase_orders`, `purchase_order_lines` (+ `ri_po_lines_before()`, `ri_po_lines_after()`,
`ri_po_totals()`), `purchase_order_events`, `supplier_links_secure`, `ri_supplier_link_mint()`, `ri_supplier_link_resolve()`, `ri_po_send()`,
`ri_po_supplier_event()`, `ri_receipt_posted_hook()` (the body that advances the PO), `ri_po_close()`, `ri_on_order()`, `supplier_invoices`,
`supplier_invoice_lines` (+ `ri_invoice_lines_after()`, `ri_invoice_totals()`), `ri_invoice_match()`, `ri_invoice_settle()`, `price_alerts`,
`ri_price_history_alert()` (db/010); `ri_purchase_orders_open()`, `ri_deliveries_due()`, `ri_price_movers()` (db/016); the views `mcp_suppliers`,
`mcp_supplier_schedules`, `mcp_supplier_items`, `mcp_order_guides`, `mcp_purchase_orders`, `mcp_purchase_order_lines`, `mcp_purchase_order_events`,
`mcp_goods_receipts`, `mcp_goods_receipt_lines`, `mcp_supplier_invoices`, `mcp_supplier_invoice_lines`, `mcp_price_alerts`, `mcp_ingredients`,
`mcp_ingredient_units`, `mcp_ingredient_categories`, `mcp_storage_areas`, `mcp_sites`, `mcp_settings`, `mcp_activity_log` (db/017). Never modify
them. **The database is the referee**: a PO's lines change only while it is a draft (after sending, only the supplier's and the receipts' columns);
a PO is sent once; the supplier's events move the status; a receipt against a PO advances its lines through the one hook; `ri_on_order()` is what
is still to come; the match compares in base units within the supplier's tolerances; settling writes price history on the receipt's date and
corrects a quantity through the ledger; a credit reverses on the original date; the link resolves by hash and dies; the handlers call the verb
inside `ri_guard()` and show its sentence.

## Screens (acknowledging works on a phone; designed at 1280 — the Manager's desk; the door at 375 first — a supplier's rep on their phone)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `purchase-order-list` | `/purchasing/?status=&supplier=&from=&to=&page=` | the table `po-table` (`po-row-{id}`): number (a link), status chip, supplier, origin chip (manual / suggested / agent / requisition), ordered on, expected on, total (walled), acknowledged, the next event; filters; **New purchase order** (`purchasing.draft`); **Suggested orders** (a link to slice 8's screen) |
| `purchase-order-add` | `/purchasing/new?supplier=` | the header form (below) → **Create** lands on `purchase-order-edit` of the draft with the supplier's order guide at this site listed as rows to tick with a quantity in the purchase unit and the last price (walled) — ticking and **Add lines** posts them as `lines` JSON |
| `purchase-order-view` | `/purchasing/{purchase_order}?tab=` | the header (number, status, supplier with its order method and the next cut-off, origin, area, ordered, expected, the supplier's reference, totals walled); the lines table `po-lines-table` (`po-line-row-{id}`: line, ingredient, SKU, quantity in the purchase unit + the base quantity muted, unit cost (walled), line total (walled), expected, received so far, status chip, the supplier's note) and — on a `suggested` or `agent` PO — the **arithmetic row** under each line (`po-line-math-{id}`: need · have · on order · cap · safety · raw · the rounding note); the **Events** tab (`mcp_purchase_order_events`, newest first, source chip door / manual / email / phone / agent); the **Receipts** tab (the receipts against it with a link); the **Invoices** tab; the **Link** tab (`purchasing.send`: the link's state — minted, last used, views, expires; **Rotate**); the actions by status: draft → **Edit**, **Send**, **Mark placed**, **Cancel**; sent/acknowledged/partial → **Acknowledge** (by phone), **Receive** (→ `receipt-add?purchase_order=`), **Close short**, **Cancel** (nothing received), **Message the supplier**; received → **Close**; `data-entity="purchase_order"` |
| `purchase-order-edit` | `/purchasing/{purchase_order}/edit` | a draft: the header form; the line form (`po-line-form`: a supplier item through the picker `source=supplier_item&supplier=` — or an ingredient the supplier has no item for yet, then the ingredient's purchase units —, quantity + unit, unit cost per purchase unit (walled; the supplier item's contract or last price by default), expected); the lines table with inline **Edit** (Pattern C) and **Remove**; the order-guide rows not yet on the PO under it to tick; the totals |
| `deliveries-due` | `/purchasing/due?on=` | the table (`due-row-{id}`): the POs expected on or before the day at this site (`ri_deliveries_due()`): number, supplier, expected on, received? (a receipt posted against it); **Receive** per row → `receipt-add?purchase_order=`; **Overdue** marked red; the day defaults to today |
| `invoice-list` | `/invoices/?status=&supplier=&from=&to=&page=` | the table (`invoice-row-{id}`): our number (a link), theirs, kind chip (invoice / credit), supplier, invoice date, received on, total, status chip; filters; **Enter an invoice** (`invoices.write`); the whole screen is cost — Chef and above only (`mcp_supplier_invoices` is empty for others; the menu hides it) |
| `invoice-add` | `/invoices/new?receipt=&purchase_order=&supplier=` | the header form (below) → **Create** lands on `invoice-edit`; with `?receipt=` the receipt's lines are prefilled as invoice lines (supplier item, quantity, unit, the receipt's unit cost) for the person to correct against the paper |
| `invoice-view` | `/invoices/{invoice}` | the header (ours, theirs, kind, supplier, dates, the receipt and PO it bills, subtotal, tax, total, status, the attachment opened in a new tab); the lines table `invoice-lines-table` (`invoice-line-row-{id}`: description, SKU, quantity + unit, unit price, line total, the receipt line matched (quantity received and its cost per unit), **match state chip** ok / qty differs / price differs / both / unordered / settled, the differences in units and money); **Match** (runs `ri_invoice_match()` — on a draft or a disputed one), **Settle** (`hx-confirm` "Settle INV-5501: the invoice becomes the truth — 1 price difference writes cost history, 0 quantity corrections?"), **Void** (`hx-confirm`), **Edit** (a draft or disputed); the credit note it pairs with (same supplier, `kind credit`, naming this invoice's number in its notes — a link); `data-entity="supplier_invoice"` |
| `invoice-edit` | `/invoices/{invoice}/edit` | a draft or disputed invoice: the header form; the line form (`invoice-line-form`: supplier item through the picker — or an ingredient —, description, quantity + unit, unit price, the receipt line it bills (a select of the named receipt's lines of the same ingredient; found by supplier item when left blank)); the lines table with inline edit and remove; the totals recomputed by the trigger |
| `price-alert-list` | `/purchasing/alerts?seen=&from=&page=` | the table (`alert-row-{id}`): raised at, ingredient (a link), supplier item, old → new per purchase unit and per recipe unit, %, the document (receipt or invoice — a link), seen by; **Seen** per row and **Mark all seen**; the **threshold** form at the top (`prices.write`): the default % and a category's own → `price_alert_threshold_set`; the whole screen is cost (`mcp_price_alerts` empty for others) |

## Suppliers
Slice 1 owns the supplier screens (`supplier-*`, the schedules, the items, the order guide). This slice reads them: the PO form's supplier picker,
the order guide's rows, the schedules' next window (`ri_next_order_windows()`) shown on the PO's header and on the list's "next cut-off" column.

## The purchase-order header form (`po-form`, ids `po-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| supplier | the record picker (`source=supplier`) | yes | active; fixed once a line exists (the handler's sentence) |
| area | select (this site's active areas) | no | where it will be received; the receipt may override |
| expected_on | date | no | the next delivery of the supplier's schedule by default (`ri_next_order_windows()`); not before today |
| notes | textarea ≤ 2,000 | no | to the supplier — on the door and the email |
| internal_notes | textarea ≤ 2,000 | no | never on the door; walled like cost (Chef and above) |
| lines | JSON (an agent, or the guide's ticked rows) `[{supplier_item|ingredient, qty, unit, unit_cost, expected_on}]` | no | each line through the same validation as `purchase_order_line_set` |

A line (`po-line-form`, ids `po-line-form-field-{name}`): supplier_item (the picker; fills the ingredient, `supplier_sku`, the purchase unit, the price) **or**
ingredient (one the supplier has no item for — the ingredient's purchase units; the handler's sentence when it has none: "Flour has no purchase unit — add one on
the ingredient"); qty > 0 in **unit** (a purchase unit of the ingredient; a count or global unit is accepted and converted — the PO is in the unit the supplier
reads, so the screen shows the purchase unit first); unit_cost ≥ 0 per purchase unit (walled); expected_on. A line of a supplier item of another supplier → 422.

## The invoice header form (`invoice-form`, ids `invoice-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| supplier | the picker | yes | fixed once a line exists |
| invoice_no | text ≤ 60 | yes | unique per supplier and kind (the UNIQUE's sentence: "Sysco already has an invoice INV-5501") |
| kind | select invoice / credit | yes | invoice by default |
| invoice_date | date | yes | |
| received_on | date | yes | today by default |
| receipt | select of the supplier's posted receipts at this site in the last 60 days (+ none) | no | the match finds lines on it; a PO may be named instead |
| purchase_order | select of the supplier's POs sent/received in the last 60 days (+ none) | no | |
| tax | money ≥ 0 | no | 0 by default; `total = subtotal + tax` by the trigger |
| file | multipart (image or PDF ≤ `ATTACHMENT_MAX_BYTES`) | no | saved as an attachment on the invoice (`attachment_id`) |
| notes | textarea | no | a credit names the invoice it credits here |
| lines | JSON `[{supplier_item|ingredient, description, qty, unit, unit_price, receipt_line}]` | no | |

## Send, place, acknowledge, decline, date, receive, close, cancel, the link, the message
- `purchase_order_send` → `ri_po_send(po, by, via, ref)` (`sent`, the `sent` event); then the handler mints the link (`ri_supplier_link_mint()`, 60 days) and
  sends the email through `app/mail.php` (MaluMail): the PO's lines in the supplier's units, our notes, the link; `via` defaults to the supplier's
  `order_method`; `email` with no `order_email` → 422 "Sysco has no order email — set one on the supplier or mark it placed". The email is sent after the
  commit (a failure to send is logged `purchase_order.send` with `after.mail = failed` and shown as a notice; the PO stays sent — DECISION, the link
  can be copied from the Link tab). An agent → `money_out` pauses before the handler runs (the kernel's hook on `purchase_order.send`).
- `purchase_order_place` → `ri_po_send(po, by, via, supplier_order_ref)` with `via` portal/phone/api/edi (the `placed` event); no email; the link minted
  anyway (the supplier may still acknowledge through it when we send it by hand).
- `purchase_order_acknowledge` (a person recording a phone call) → `ri_po_supplier_event(po, 'acknowledge', NULL, expected_on, NULL, ref)` with
  `source = manual` (the handler passes the source — `ri_po_supplier_event()` writes `door`; this handler inserts the event itself with `manual` and
  then applies the same status change through the function — DECISION: the function gains no parameter; the handler writes `purchase_order_events`
  directly for `manual` then calls `ri_po_supplier_event()` for the state; to keep one writer, the worker model ADDS `p_source text DEFAULT 'door'` to
  `ri_po_supplier_event()` in `db/018_phase1_fixes.sql` (additive, CREATE OR REPLACE) and the door passes `door`).
- `purchase_order_line_decline` → `ri_po_supplier_event(po, 'decline', line, NULL, reason, NULL, 'manual')`.
- Receiving: slice 3's `receipt_post` → `ri_receipt_posted_hook()` advances `qty_received_base`, the lines' status (partial / received), the PO's
  (partial / received), writes a `received` event. Nothing here.
- `purchase_order_close` → `ri_po_close(po, by, short)`: open lines `closed_short`; `closed` or `closed_short`.
- `purchase_order_cancel` → the handler: a draft → `cancelled`; a sent one with nothing received → `cancelled` + a `cancelled` event (+ the supplier told
  by email when it was sent by email, DECISION); anything received → 422 "Receive it or close it short". `deletion` for an agent.
- `supplier_link_rotate` → `ri_supplier_link_mint()` (the old row `rotated_at`); the new raw token shown once on the Link tab.
- `supplier_message` → an email through MaluMail to the supplier's `order_email` (or `email`) with the body and the PO's number when named; logged
  `supplier.message` with the subject only (never the body); `external_send` for an agent.

## The public door — the supplier's purchase-order page
`/s/{token}` → `html/s.php` (no session; the vhost's allow-list carries `/s/`; `require_login()` is never called): `ri_supplier_link_resolve(token)` → the
PO id (counts the view) or a plain "This link is not valid or has expired." page (404). The page (`supplier-door`, `data-screen="supplier-door"`, no
shell, the business's name and the site's address from `mcp_sites`… — the door reads through the rw role with `app.member_id` unset: DECISION, `s.php`
reads the PO through SQL as the application (`purchase_orders`, `purchase_order_lines` joined to `ingredients`, `supplier_items`), never the `mcp_*`
views): the PO number, our site's name and delivery address, expected date, our notes; the lines in the supplier's purchase unit with the SKU and our
reference — **never our cost target, never another supplier's name, never internal notes**; the three POSTs (each `verify_csrf()` on the door's own
session-less token — a hidden field signed with `action_token_key()` over the PO id and the hour — DECISION): **Acknowledge** (their reference, the
delivery date) → `ri_po_supplier_event(po, 'acknowledge', NULL, date, NULL, ref)`; **Decline a line** (reason) → `'decline'`; **Set the delivery date**
→ `'date'`. Each POST notifies the PO's creator (`ri_notify(created_by, 'po_ack'|'po_decline'|'po_date', 'purchase_order', id, …)`) and logs
`purchase_order.supplier_ack|supplier_decline|supplier_date` with `source = 'door'`, `actor_member_id NULL`, `after.ref`/`reason`/`date`. A closed or
cancelled PO's door is read-only with a sentence. At 375 px: one column, the lines as cards, the three buttons full width.

## Files (exactly these)
- `app/features/purchasing/queries.php` — `po_list()`, `po_for()`, `po_lines()`, `po_events()`, `po_receipts()`, `po_invoices()`, `po_link()`, `guide_rows_for()`,
  `supplier_window()`, `deliveries_due()`, `invoice_list()`, `invoice_for()`, `invoice_lines()`, `receipt_lines_for_invoice()`, `receipts_for_supplier()`,
  `pos_for_supplier()`, `alerts_list()`, `thresholds()`
- `app/features/purchasing/present.php` — `present_po()`, `present_po_line()` (the arithmetic keys when present), `present_event()`, `present_invoice()`,
  `present_invoice_line()`, `present_alert()`; cost keys absent when walled
- `app/features/purchasing/mail.php` — `po_email_html()`, `po_email_text()`, `supplier_message_send()`
- `app/features/purchasing/door.php` — `door_po()`, `door_token()`, `door_verify()`
- `app/views/purchasing/{list,form,view,edit}.php`, `_lines-table.php`, `_line-form.php`, `_line-math.php`, `_events.php`, `_link.php`, `_guide-rows.php`, `due.php`,
  `alerts.php`, `_threshold-form.php`
- `app/views/invoices/{list,form,view,edit}.php`, `_lines-table.php`, `_line-form.php`
- `app/views/door/{po,invalid}.php`, `app/views/emails/{purchase-order,supplier-message,po-cancelled}.php`
- `html/purchasing/index.php`, `form.php`, `view.php`, `edit.php`, `save.php`, `line.php`, `line-remove.php`, `send.php`, `place.php`, `acknowledge.php`,
  `line-decline.php`, `close.php`, `cancel.php`, `link.php`, `message.php`, `due.php`, `alerts.php`, `alerts/seen.php`, `alerts/threshold.php`
- `html/invoices/index.php`, `form.php`, `view.php`, `edit.php`, `save.php`, `line.php`, `line-remove.php`, `match.php`, `settle.php`, `void.php`
- `html/s.php`
- `db/018_phase1_fixes.sql` — `ri_po_supplier_event(..., p_source text DEFAULT 'door')` (additive; the proof re-applies every migration)
- `tests/phase3/slice4/run.sh`, `lib.php` (`purchasing_world()`), `browser.mjs`

## Query functions (signatures fixed; the tools of Phase 4 call these — `find_purchase_orders`, `get_purchase_order`, `purchase_order_events`, `purchase_orders_open`, `deliveries_due`, `find_invoices`, `get_invoice`, `invoice_mismatches`, `price_exceptions`)
- `po_list(PDO, int $scope, array $f, int $page): array` — `mcp_purchase_orders` + the supplier's name, the next event; `f`: status, supplier, from, to
- `po_for(PDO, int $id): ?array`, `po_lines(PDO, int $id): array` (the arithmetic columns included), `po_events(PDO, int $id): array`
- `supplier_window(PDO, int $supplier, int $scope): array` — `ri_next_order_windows()` as one row
- `deliveries_due(PDO, int $scope, string $on): array` — `ri_deliveries_due()`
- `invoice_for(PDO, int $id): ?array`, `invoice_lines(PDO, int $id): array` (each with its receipt line's quantity and cost per unit)
- `receipt_lines_for_invoice(PDO, int $receipt): array` — the prefill
- `alerts_list(PDO, int $scope, array $f, int $page): array` — `mcp_price_alerts` + per-unit figures through `ri_from_base()` and the supplier item's purchase unit

## Handlers (every one: `ri_handler_begin()`; `require_site()`; the right; the record through its view; `ri_guard()` around one transaction; `log_activity` with `scope_id` and `purchase_order_id` (an invoice's: the PO it bills when any); `ri_done()`; `HX-Trigger: purchaseOrderChanged` or `invoiceChanged` or `priceAlertChanged`)
- `purchase_order_draft` / `purchase_order_update` (`save.php`): the header; a supplier change with lines → 422; `lines` JSON through `line_validate()`
- `purchase_order_line_set` / `_remove` (`line.php`, `line-remove.php`): `line` present → update; the unit converted by the trigger (`qty_ordered_base`); a
  cost sent by a caller the wall refuses → 422
- the rest as above; `price_alert_seen` with no `alert` marks every open alert at the site; `price_alert_threshold_set` writes `ri_settings.price_alert_pct`
  or `ingredient_categories.price_alert_pct` (`other` for an agent)
- `invoice_create` with `receipt` and no `lines` → the receipt's lines prefilled; `invoice_match` on a settled or void invoice → 422 (the function's sentence);
  `invoice_match_settle` (`other` for an agent) → `ri_invoice_settle()`; `invoice_void` on a settled one → 422 "Settled — enter a credit"

## Manifest rows claimed
Screens (11): `purchase-order-list`, `purchase-order-add`, `purchase-order-view`, `purchase-order-edit`, `deliveries-due`, `invoice-list`, `invoice-add`, `invoice-view`, `invoice-edit`, `price-alert-list`, `supplier-door`

The door `supplier-door` (the manifest's last section) is this slice's too — it is not in the registry today because that section's table has no `Screens:` marker before it; once the manifest gains one, add `supplier-door` to the Screens line above (11).
Actions (21): `purchase_order_draft`, `purchase_order_update`, `purchase_order_line_set`, `purchase_order_line_remove`, `purchase_order_send`, `purchase_order_place`, `purchase_order_acknowledge`, `purchase_order_line_decline`, `purchase_order_close`, `purchase_order_cancel`, `supplier_link_rotate`, `supplier_message`, `invoice_create`, `invoice_update`, `invoice_line_set`, `invoice_line_remove`, `invoice_match`, `invoice_match_settle`, `invoice_void`, `price_alert_seen`, `price_alert_threshold_set`

Left to other slices: the receipt itself (slice 3 — `receipt-add?purchase_order=`), `suggested-order-list` and `suggested_order_run` (slice 8), the suppliers'
screens (slice 1), the ledger's `purchases_received` share (slice 7 / Phase 4).

## Activity log events (every row: `scope_id`, `purchase_order_id`; an invoice row: `purchase_order_id` when it bills one)
`purchase_order.draft|update|line_set|line_remove|send|place|acknowledge|line_decline|close|cancel|link_rotate|supplier_view|supplier_ack|supplier_decline|supplier_date`,
`supplier.message`, `invoice.create|update|line_set|line_remove|match|settle|void`, `price_alert.seen|threshold_set`, `screen.view`. `after` on a send carries
`via`, `mail` (sent / failed), the line count and the total only when the actor sees cost; a door event carries `source = door` and `actor_member_id NULL`;
**the raw link token is never logged** (its last four are).

## Notifications this slice queues and sends
- `po_ack`, `po_decline`, `po_date` to the PO's creator (and its `sent_by` when different) from the door and from `purchase_order_acknowledge`.
- `delivery_due` to the site's Manager (`site_settings.manager_member_id`, else nobody) the morning of `expected_on` with no receipt — queued by slice 8's
  worker pass `deliveries` from `ri_deliveries_due()`; this slice ships the function and the kind.
- `invoice_dispute` to the invoice's creator when a match disputes (queued by `invoice_match`).
- `price_alert` to the site's Manager once a day with the count (slice 8's pass; this slice's rows).
- The PO email and the supplier message are sent by the handler through `app/mail.php` (not the outbox — they go to a supplier, not a member).

## Status vocabulary
- purchase order: `draft` → `sent` → `acknowledged` → `partial` → `received` → `closed` | `closed_short` | `cancelled`; a line: `open` → `acknowledged` |
  `declined` → `partial` → `received` | `closed_short` | `cancelled`.
- invoice: `draft` → `matched` | `disputed` → `settled` | `void`; a line's match state: `unmatched`, `ok`, `qty_diff`, `price_diff`, `both_diff`, `unordered`, `settled`.
- chips: draft secondary, sent info, acknowledged primary, partial warning, received/closed/settled/matched success, closed_short warning, cancelled/void dark,
  disputed danger, declined danger.

## The 375 px rule
The PO view's lines as cards under 576 px with the arithmetic row folded under a "why" toggle; Acknowledge / Receive / Close as full-width buttons; the door at
375 first: one column, the three forms stacked, every control ≥ 44 px; the invoice edit's line form in one column; `scrollWidth ≤ clientWidth` everywhere.

## Vocabulary
order guide · cut-off and delivery (the supplier's schedule at this site) · on order (sent, not yet received) · placed (on a portal or by phone — no email) ·
the door (the supplier's link) · acknowledged · declined line · closed short · three-way match (PO, receipt, invoice) · settle (the invoice becomes the truth)
· credit note · price alert · the arithmetic row (need · have · on order · cap · rounding — slice 8 writes it, this slice shows it).

## Out of scope for this slice
The suggested order and the Purchasing agent's drafting (slice 8 — this slice shows what it wrote on the line); receiving (slice 3); API and EDI ordering
(Extended); invoice OCR (Extended — the attachment is kept for a person); a vendor return document; the General Ledger's reads (Phase 4 / the GL's G5).

## Proof (`tests/phase3/slice4/run.sh`: the scratch database `ri_dev4`; the application on 9301 (`php -S` through `tests/dev_router.php`, or `RI_APP=apache`); the fake kernel 9302; the fake MaluDB + MaluMail 9303 (`FAKE_MALUMAIL_LOG` for the sent mail); members through dev hand-offs; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: **≥ 230 checks** (POs 50, send and place 20, the door 35, receiving against 15, close and cancel 15, invoices and the match 45, alerts 15, walls 15, json 15, browser 20).
The world (`tests/phase3/slice4/lib.php` `purchasing_world()`): slice 3's `ledger_world()` with its receipts posted; Sysco's schedules at Downtown (Saturday 13:00 →
Tuesday, Wednesday 13:00 → Friday); the order guide with flour, eggs, chicken; Marco (Manager), Dana (Chef), Bea (Kitchen), Ann (Viewer), the expert and the
Purchasing agent; `INV_WORKER_NOW`-style clock moves are not needed (dates are relative to today).
- [ ] **POs**: Dana drafts to Sysco from the guide (3 bags flour at $30, 1 case eggs at $54) → `qty_ordered_base` 68 038.86 g, the total $144 (the trigger); a line in `lb` of flour → converted to base and the screen shows "0.5 bag50"-style? — no: DECISION the PO line keeps the unit the person gave (`purchase_unit_code = lb`), the base quantity is what the receipt matches on; a supplier item of the Produce Co on a Sysco PO → 422; changing the supplier with lines → 422; `ri_on_order()` 0 while draft; Bea → 403; the expected date defaults to the next Tuesday/Friday window.
- [ ] **Send and place**: Marco sends by email → `sent`, the `sent` event, the email in the fake MaluMail's log with the three lines and a `/s/` link, the link row minted; sending again → "only a draft is sent"; a second PO marked placed with `SY-778` → the `placed` event, no email; Dana (`purchasing.draft`) sending → 403; the expert's `purchase_order_send` under the relayed token → the fake kernel's hook sees `purchase_order.send` (`money_out`) and the handler never runs; a supplier with no order email → 422 in words; `supplier_message` → the email logged with the subject only.
- [ ] **The door**: `GET /s/<token>` → 200 with the lines in `bag50`, our address, no cost, no internal notes (`grep -c '\$' = 0` on the lines, the internal note's text absent); a wrong token → 404; **Acknowledge** with `SY-778` and Tuesday → `acknowledged`, `expected_on`, the event `source = door`, Marco notified `po_ack`; **Decline** the eggs with "out of stock" → the line `declined`, the event, `po_decline`; **Set the date** → `po_date`; the view count climbed; a rotated link → the old token 404, the new 200; a CSRF-less POST → 403; a cancelled PO's door read-only.
- [ ] **Receiving against**: slice 3's receipt of 2 bags against the PO → the line `partial`, the PO `partial`, `received` event, `ri_on_order()` one bag; the Receipts tab lists it; `deliveries-due` for Tuesday lists the PO with "received: yes" once posted and marks an earlier one overdue.
- [ ] **Close and cancel**: close short → `closed_short`, the flour line `closed_short`, nothing on order; a cancel of a received PO → 422 "Receive it or close it short"; a draft cancelled; a sent-by-email PO cancelled → the `po-cancelled` email in the log; an agent's cancel → `deletion` pauses.
- [ ] **Invoices and the match**: Marco enters INV-5501 against the receipt with the prefilled line corrected to $33 → Match → `disputed`, the line `price_diff` with `diff_value` $6; Settle → `settled`, `price_history` source `invoice` dated the receipt's `received_at`, the supplier item's `last_price` 33, a `price_alerts` row (10 %); a quantity difference (3 bags invoiced, 2 received) → `qty_diff`, settle → an `adjustment` transaction of +1 bag at the invoice's cost with reason `data_entry` dated the receipt; a credit note of −1 bag → the opposite; an invoice line with no receipt line and no supplier item → `unordered`; a duplicate invoice number → the UNIQUE's sentence; match on a settled invoice → 422; void on a settled → 422; Dana (`invoices.write`? no — Manager's) → 403 for Dana, 200 for Marco; an agent's settle → `other` pauses.
- [ ] **Alerts**: the list shows the flour alerts with old → new per bag and per gram and the %; **Seen** marks one, **Mark all seen** the rest; the threshold set to 25 % → a 20 % move raises none; a category threshold of 5 % on dry goods → raises; `price_alert_threshold_set` by Dana → 403, by Marco 200; by the expert → `other` pauses.
- [ ] **Walls**: Ann sees the PO list without totals and the view without cost or the arithmetic's money, no `/invoices/` rows (`mcp_supplier_invoices` empty), no alerts; Bea the same; Dana and Marco everything; the internal notes absent for Ann and Bea.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` (`purchase_order_send` → `mail: sent`, `link_last4`; `invoice_match` → the states per line; `invoice_match_settle` → the price-history and adjustment ids); 422 `{error: {code: invalid, fields}}`; `_partial=1` on `purchase_order_update`; the Purchasing agent's `purchase_order_draft` (allowed) creates a draft with `origin = agent`; `is_eval` refuses every write.
- [ ] **375 × 740 and 1280 × 800**: the door's three forms on a phone; the PO view's arithmetic toggle; the invoice edit's picker choosing a supplier item and the receipt line select narrowing; the alerts' threshold form; `scrollWidth ≤ clientWidth` everywhere.

**Decisions taken in this spec (not questions):**
- `ri_po_supplier_event()` gains `p_source` in `db/018` so the door and a person's phone record share one writer.
- A PO line keeps the unit the person ordered in; the receipt matches on the base quantity.
- The PO email goes after the commit; a failed send leaves the PO sent and says so; the link is on the Link tab.
- The door reads the PO as the application (plain SQL, the allow-listed columns), never through the member views; its POSTs carry a signed hidden token over the PO id and the hour.
- A cancelled PO that was emailed emails the supplier a one-line cancellation.
- Invoices and alerts are whole-screen cost: Chef and above (the views are empty for others; the menu hides them).

## Open questions
None.

## Built and proven

Built 2026-10-11 by a Sonnet 5.5 worker from this spec, in the working tree beside the planning model's commits (nothing committed or pushed by the worker). All 11 screens and
21 actions of this spec are built (the registry reads 83 of 119 screens and 106 of 146 actions built in all); the two worker passes `price_alerts` and `link_expiry` are real.

**Proof:** `tests/phase3/slice4/run.sh` runs on the scratch database `ri_dev_s4`, with this slice's port block: the app on :9325, the fake kernel :9326, the fake MaluDB +
MaluMail :9327 and the internal vhost :9328. **452 checks pass under php -S and 452 under a user-level Apache rendering `deploy/apache-restaurant_inventory.conf`.**

| File | Checks | What it proves |
|---|---|---|
| `orders.php` | 50 | drafting from the guide, the lines in the unit the supplier reads, the header, the refusals in words, who may draft, the log |
| `send.php` | 48 | send by email (the mail carries the link and no cost), mark placed, the refusals, a MaluMail failure, the supplier message, the registry's approval categories |
| `door.php` | 47 | `/s/{token}`: no session, no cost, the three answers, the signed form token, rotation and expiry, a cancelled order's read-only door |
| `receiving.php` | 45 | the supplier's answers by phone, receiving against (partial, `ri_on_order()`), deliveries due and overdue, close short, close, cancel (with the cancellation email) |
| `invoices.php` | 65 | entering against a receipt, the match, settle (price history on the receipt's date, last price, the alert, the quantity correction, a credit), unordered, duplicates, void, the paper, who may |
| `alerts.php` | 32 | the list in units and percent, Seen, the default and a category's threshold, the wall |
| `walls.php` | 37 | cost, the internal note, the site, `kitchen_sees_cost`, the arithmetic row |
| `worker.php` | 17 | `price_alerts` (the Manager is told once with the count) and `link_expiry` |
| `json.php` | 52 | every handler under a signed action token, `_partial`, 422 fields, the Purchasing agent and the expert, an evaluation run, the HTMX stamps, the log vocabulary, the registry counts |
| `browser.mjs` | 56 | headless Chromium at 375 × 740 and 1280 × 800 and with JavaScript off: drafting and sending on a phone, the supplier's rep answering on theirs, the "Why this much" fold, the receipt-line select narrowing, the threshold forms, all eleven screens at both widths, the tables in full |
| registry, approvals, claim check | 3 | |

Screenshots are in `/tmp/ri-shots/slice4`. The PHP proofs are order-dependent by design (they share one scratch database; `orders` first).

**Files:** `app/features/purchasing/{queries,present,handler,mail,door,worker}.php`; `app/views/{purchasing,invoices,door,emails}/`; `html/purchasing/*` (including `alerts/seen.php`,
`alerts/threshold.php`), `html/invoices/*` (including `options.php`), `html/s.php`; `html/assets/js/purchasing-forms.js`; `app/partial_targets/purchasing.php`; `db/025_slice4_invoice_match_exact.sql`;
`tests/phase3/slice4/*`. Changed beside them: `app/bootstrap.php` (the door defines `RI_PUBLIC_DOOR`: no session, no cookie), `app/features/stock/queries.php` (`po_for()` gained the names the
PO screens print), `app/features/catalog/queries.php` (`record_view()` knows `supplier_invoice`, the attachments' record type), `html/assets/js/stock-forms.js` (the price field may be `unit_price`),
`bin/worker.php` (the two passes), `bin/build_action_registry.php` (a public door's `/s/{token}` is its one file `s.php`), `tests/phase2/gates.php` (the matrix, below).

**Found and fixed:**
- **db/025 — the match compared the invoice with a six-decimal figure (schema).** `ri_invoice_match()` set the invoice's price per base unit against the receipt's STORED `cost_per_base`, which keeps six
  decimals. Flour at $30 a 50-lb bag is $0.001323 a gram: three significant figures. Two lines at exactly the same price differed by cents (a quantity-difference invoice showed a −$0.02 price
  difference) and the spec's own example ($33 against $30, two bags) read $5.99 not $6.00. The match now prefers the receipt line's own `unit_cost` divided by its unit's factor (the weight unit for a catch-weight
  line) and falls back to `cost_per_base` only for a line received at the running cost. The tolerances, states and dispute are db/010's. The invoice screen shows the price the line came in at in the
  invoice's unit from the receipt's own `unit_cost` for the same reason.
- **The 10 % alert read 9.98 % — fixed by the planning model in review (db/026).** `price_history` keeps old and new per base to six decimals, so
  $30 → $33 a bag read 9.98 %, under the default 10 % threshold. The worker set the proof's threshold to 9; the planning model judged it a defect
  (a 10 % rise must trip a 10 % alert) and **db/026** reads the move from the quoted per-unit prices when both rows share a unit. The proof now
  keeps the default 10 % and requires exactly 10.00 %.
- **The vhost's canonical `/x/{id}/edit` goes to `form.php`**, and a file named `edit.php` would win in Apache's earlier "page of a record" rule but not in the dev router. The spec's `edit.php` files are therefore
  `form.php` (new and edit, as the receipts' are) rendering `purchasing/form.php` or `purchasing/edit.php`.
- **The activity log's `source` has no `door`** (db/002's list is web, assistant, mcp, cron, agent, desk, webhook, portal, api, application, system). The door's rows are source `portal` (the kit's
  `__public_door`), `actor_member_id` NULL, with `after.source = 'door'`; `purchase_order_events.source` is `door` as the spec says.
- **A public door needs no session**, and the kit started one (and redirected a stale cookie to the launcher). `RI_PUBLIC_DOOR` before `app/bootstrap.php` skips the session block; the proof sends a garbage `BDSID` cookie.
- **The registry builder** could not map `/s/{token}` to `s.php` (`supplier-door` read as unbuilt); one rule added.
- **Phase 2's gate matrix** held the placeholders' menu rights; under the 2026-10-11 rule it now says that deliveries due, invoices and price alerts READ under `ri.read` and their writing doors keep
  their rights (`/purchasing/new`, `/invoices/new`). A refusal-in-words check moved from Dana on `/invoices/` to Dana on `/invoices/new`.

**Decisions taken here (within the spec):**
- **Who may open a screen.** `purchase-order-list`/`-view`, `deliveries-due`, `invoice-list`, `invoice-view` and `price-alert-list` open to every reader at the site (`ri.read`), cost walled per field. An
  invoice or an alert is wholly cost: a Viewer or a Kitchen member without cost opens the list and is told so (no rows; JSON `cost_withheld`), and an invoice by its id is 404 (the view is empty). The menu keeps
  its rights. Writes and forms: `purchasing.draft` (drafting, the supplier's answers by phone, Seen), `purchasing.send` (send, place, close, cancel, link, message), `invoices.write`, `prices.write` (thresholds).
- **`purchase_order_send` only sends email.** `via` defaults to the supplier's order method; a supplier that takes phone or portal orders is refused "mark it placed instead". A supplier with neither order email
  nor email is refused "has no order email — set one on the supplier or mark it placed". The general email stands in when there is no order email.
- **The raw link is shown once**, in the sender's session (never stored, never logged — the log and the JSON carry the last four). Send, place and rotate set it; the PO page shows it once and a failed email
  says to copy it.
- **Cancel:** a draft and a sent order with nothing received are cancelled; the supplier is emailed when it was sent by email; a failure to send is a notice. Partial or received → "receive it or close it short".
- **Notifications** (`po_ack`, `po_decline`, `po_date`) go to the creator and the sender, once each when they are the same person; `invoice_dispute` to the invoice's creator.
- **The worker's `price_alerts` pass** tells the site's Manager (the site's, else the business's) once per site per pass with the count, its `record_id` the newest alert it covers; a pass that finds nothing newer says
  nothing. **`link_expiry`** rotates out a live link past its expiry or 30 days after its order closed or was cancelled.
- **Lines and the guide:** `purchase_order_update` also accepts `lines` (JSON) and the ticked guide rows (`guide[]`, `guide_qty[id]`) and adds them; a supplier-item given as a SKU is looked up among the PO's supplier's items.
  An ingredient with no supplier item takes its first purchase unit when none is given.
- **Invoices:** `invoice_create` with a receipt and no lines prefills them from the receipt (`prefill=no` starts empty); a catch-weight line is billed at its declared weight and weight unit; the PO of the receipt is
  filled when none is named; the tax's change recomputes the total (`ri_invoice_totals()`). A changed line goes back to "not matched yet". `invoice_void` keeps the reason in the notes and the log.
- **`/invoices/options?supplier=`** (not in the spec's file list) answers the two selects of the header form when the supplier is chosen; with JavaScript off the selects are filled when the supplier is known (a prefill, or on the edit page).
- **The door's three POSTs** go to `/s/<token>/acknowledge|decline|date` (the vhost's existing rewrite), answer 303 back to the page, 403 without the signed token, 409 on a closed order, 422 with the sentence; a view is any request
  that presents the token (`ri_supplier_link_resolve()` counts them); no rate limit beyond the token's 192 bits.
- **The shared `po_for()`** (the receipt reads it too) lives in `app/features/stock/queries.php` and was extended, not duplicated.

**Not done:** nothing of the spec. Out of scope as stated: the suggested order (slice 8), the home's cards (slice 9).
