# Build spec: the ledger — receiving, transfers, adjustments, production, waste; stock on hand (slice 3)

Built by the planning-class model on slice 1's CRUD pattern, before slice 4 (purchasing) and slice 5 (the POS). What exists at the end: a
cook with a phone in the walk-in receives Tuesday's delivery line by line — two bags of flour, a 38.5 lb case of chicken typed by its
declared weight, a case of eggs — checks the temperature where the category asks, posts it, and the balances move in grams and eaches while
the site's average cost advances; the chef moves limes to the airport site on a transfer the other kitchen receives; a damaged flat of eggs
goes on an adjustment with a reason; a prep batch of chicken stock is posted and the stock lands at the rolled-up cost of what went in; a
returned sandwich is logged as plate waste and its ingredients leave the shelf at cost — and **every one of these is a transaction the
database posted**, never a balance written by hand. Levels and movements are tables anyone here reads in the unit on the shelf and in the
base unit; cost shows only behind the wall (`ri_sees_cost(scope)`: Chef and above, Kitchen when the site says so).
Schema: `storage_areas`, `ingredient_site_costs` (+ `ri_site_costs_guard()`), `ri_to_base()`, `ri_from_base()`, `ri_cost_as_of()`,
`ri_depletion_area()` (db/007); `recipes`, `recipe_versions`, `ri_recipe_version_on()`, `ri_explode()` (db/008 — read only here);
`reason_codes`, `inventory_balances` (+ `ri_balances_guard()`), `inventory_transactions` (+ `ri_transactions_before()`,
`ri_transactions_after()`, `ri_transactions_late()` from db/013), `ri_post_txn()`, `ri_reverse_transaction()`, `ri_on_hand()`,
`ri_on_hand_as_of()`, `ri_lines_only_while_draft()`, `goods_receipts`, `goods_receipt_lines` (+ `ri_receipt_lines_before()`),
`ri_post_receipt()`, `ri_receipt_posted_hook()` (replaced by db/010 — slice 4 calls it through the same post), `inventory_transfers`,
`inventory_transfer_lines` (+ `ri_transfer_lines_guard()`), `ri_transfer_send()`, `ri_transfer_receive()`, `inventory_adjustments`,
`inventory_adjustment_lines` (+ `ri_adjustment_lines_before()`), `ri_post_adjustment()`, `production_batches`, `ri_post_production()`,
`waste_logs`, `ri_post_waste()`, `ri_void_waste()` (db/009); `price_history` + `ri_price_history_alert()` (db/007, db/010); `ri_stock_value()`,
`ri_site_moment()` (db/013); `ri_days_on_hand()`, `ri_price_movers()` (db/016); `ri_number_document()` (db/006); the views `mcp_storage_areas`,
`mcp_ingredients`, `mcp_ingredient_units`, `mcp_ingredient_site_costs`, `mcp_inventory_balances`, `mcp_inventory_transactions`,
`mcp_goods_receipts`, `mcp_goods_receipt_lines`, `mcp_inventory_transfers`, `mcp_inventory_adjustments`, `mcp_production_batches`,
`mcp_waste_logs`, `mcp_reason_codes`, `mcp_suppliers`, `mcp_supplier_items`, `mcp_purchase_orders`, `mcp_purchase_order_lines`, `mcp_recipes`,
`mcp_day_parts`, `mcp_sites`, `mcp_activity_log` (db/017). Never modify them. **The database is the referee**: a balance is maintained only by
the ledger's trigger (a direct write is refused), a transaction is never changed or deleted (it is reversed, once), the site's average cost
advances on receipt and production_in and never on an issue, a costless receipt line lands at the running cost never at zero, a storage
area's balance may dip below zero unless the area refuses it (a kitchen depletes before it counts), a document's lines change only while it
is a draft, a quantity arrives in a unit the ingredient converts (`ri_to_base()` refuses what it cannot), catch weight lands at the declared
weight, a prep batch is only of a prep recipe, a waste reason is a waste reason, a transaction dated in a closed period lands in the next
open one flagged late; the handlers call the posting verb inside `ri_guard()` and show its sentence as a 422; PHP computes no balance and
no cost.

## Screens (receiving, production and the waste log are designed at 375 px first — a cook in the walk-in; the rest usable at 375, designed at 1280)
| Screen id | Canonical URL | Purpose |
|---|---|---|
| `stock-levels` | `/stock/?area=&category=&q=&below_par=&page=&format=` | the table `levels-table` (`level-row-{ingredient}-{area}`): ingredient (a link to `ingredient-view`), category, area, on hand in the **default count unit** (`ri_from_base()`) and in the base unit beside it muted, days on hand (`ri_days_on_hand()`), a `below par` mark when on hand + on order < the site's par (`ingredient_site_settings`), value (walled — "—" with the title "cost withheld"); filters in one row; a footer with the site total value (walled); `?format=csv` answers the same rows as `text/csv` (logged `screen.view` with `after.format = csv`; the formal exports are slice 9's); 100 a page; **Record a prep batch**, **Log waste**, **Receive** as the three big buttons at 375 |
| `stock-movements` | `/stock/movements?ingredient=&area=&type=&from=&to=&page=` | the ledger as a table `movements-table` (`movement-row-{id}`): when (the site's zone), business date, type chip, ingredient (a link), area, quantity signed in the count unit with the base unit in the title, unit cost (walled), document (`reference_kind` + the document's number, a link to its view), counterparty (an area, a site, a supplier, "customer", "disposal"), reason, by; **Reverse** on a posted row with no reversal (`counts.approve`, `hx-confirm`) → `stock_reverse`; a late row shows a `late` chip from its note; the last 30 days by default; 100 a page |
| `stock-value` | `/stock/value?as_of=` | the table (`value-row-{category}`): category, ingredients, on hand value as of the moment `ri_site_moment(site, as_of, true)` (`ri_stock_value()`), the site total; a Viewer reaches it and reads `withheld` on every amount (the whole screen is cost: an empty state saying so, DECISION); the expanded rows per ingredient under a category (`hx-get` Pattern A, `value-ingredient-row-{ingredient}`) |
| `receipt-list` | `/receipts/?status=&supplier=&from=&to=&page=` | the table `receipts-table` (`receipt-row-{id}`): number (a link), status chip, supplier, PO number (a link to `purchase-order-view` — slice 4's screen; a plain number until it exists), area, received on, lines, a thermometer mark when any line carries a temperature (green all ok, red any not ok), posted by/at; drafts first; **New receipt** (`stock.receive`) |
| `receipt-add` | `/receipts/new?purchase_order=&supplier=&area=` | the header form (below) → **Create** lands on `receipt-view` of the draft, where the lines are made; with `?purchase_order=` the open lines of that PO (`mcp_purchase_order_lines`: status open/acknowledged/partial, `qty_ordered_base − qty_received_base > 0`) are listed as checked rows with their purchase unit and quantity to prefill — the create posts them as `lines` JSON |
| `receipt-view` | `/receipts/{receipt}` | **the receiving screen** (a draft): the header (number, supplier, PO, area, received on, delivery note, invoice ref); the line form `receipt-line-form` (below) with the ingredient through the record picker (`/pick/?source=ingredient` — a supplier chosen narrows it to that supplier's items first, `source=supplier_item&supplier=`), **quantity and its unit side by side** (`receipt-line-form-field-qty`, `…-unit`: the ingredient's purchase units first, then its count units, then the global units of its class), the **declared weight** fields appearing when the ingredient (or the supplier item) is catch weight (`…-weight_qty`, `…-weight_unit` lb/kg — a small script on the picker's change, server-rendered hidden otherwise), the cost per purchase unit when `sees_cost()` (`…-unit_cost`), the **temperature** fields when the category asks (`…-temperature_c`, `…-temperature_ok` as two big buttons OK / Not OK), discrepancy kind and note, put-away area (the receipt's by default); the lines table `receipt-lines-table` (`receipt-line-row-{id}`: line, ingredient, quantity in the purchase unit + the base quantity muted, weight when catch weight, cost (walled), temperature, discrepancy chip, put-away, **Edit** inline (Pattern C), **Remove**); the footer: lines, the amount at cost (walled); **Post** (`hx-confirm` "Post GR-00012: 5 lines into Walk-in?"), **Cancel** (`hx-confirm`); a posted receipt: the lines read-only, the movements it posted (`mcp_inventory_transactions` where `reference_kind = 'goods_receipt'`), the price-history rows it wrote (walled), attachments (a delivery note photo — slice 1's `attachment_add`), the trail; `data-entity="goods_receipt"` |
| `receipt-edit` | `/receipts/{receipt}/edit` | a draft's header: supplier, purchase order, area, received on, delivery note ref, invoice ref, notes |
| `transfer-list` | `/transfers/?status=&page=` | the table (`transfer-row-{id}`): number, status chip, from site/area → to site/area, lines, sent by/at, received by/at; transfers **from or to** this site (`mcp_inventory_transfers`); **New transfer** (`stock.transfer`) |
| `transfer-add` | `/transfers/new?to_site=&from_area=&to_area=&ingredient=` | the form: to site (a select of the sites the person holds — this site first; a site not held is refused), from area (this site's active areas), to area (the to-site's active areas, re-listed on the site's change by a small `hx-get` to `/transfers/area-options?site=`, Pattern A), notes; a first line when `?ingredient=` |
| `transfer-view` | `/transfers/{transfer}` | a draft: the header, the line form (ingredient through the picker, quantity + unit), the lines table (`transfer-line-row-{id}`: ingredient, quantity in its unit, base, received), **Send** (`hx-confirm` "Send TR-00003: 2 lines leave Walk-in?"), **Cancel**; in transit: the lines with a **quantity received** input per line prefilled with the sent quantity in the same unit, **Receive** (`hx-confirm`; a short line says "N left in transit on the document"); received: read-only with both halves' movements (the receiving site's at the sender's average cost); the receiving half is reachable from the **to** site too (`require_site(to_scope_id)` admits either end); `data-entity="inventory_transfer"` |
| `adjustment-list` | `/adjustments/?status=&reason=&page=` | the table (`adjustment-row-{id}`): number, status chip, area, reason, lines, posted by/at; **New adjustment** (`counts.approve`) |
| `adjustment-add` | `/adjustments/new?area=&reason=&ingredient=` | the header: area, reason (select of `mcp_reason_codes` where `'adjustment' = ANY (applies_to)` and active), notes; with `?ingredient=` one line prefilled (the quantity delta empty, focused) |
| `adjustment-view` | `/adjustments/{adjustment}` | a draft: the header; the line form (ingredient through the picker, quantity delta signed and never zero + unit, unit cost per that unit when `sees_cost()` — an opening balance carries its cost (the reason `opening`), note); the lines table (`adjustment-line-row-{id}`); **Post** (`hx-confirm`; the manifest's `stock_adjust` — `deletion` for an agent); **Cancel**; posted: the movements it posted and the trail; `data-entity="inventory_adjustment"` |
| `production-list` | `/production/?status=&recipe=&from=&to=&page=` | the table (`batch-row-{id}`): number, recipe (a link to `recipe-view`), batches, output in the output ingredient's count unit, made at, status chip, cost (walled), by; **Record a prep batch** (`stock.produce`) |
| `production-add` | `/production/new?recipe=` | **the batch form at 375 px**: the prep recipe through the picker (`source=recipe&kind=prep`), batches (a big number input, 1), the area the output lands in (select), where the inputs come from (select, "each ingredient's own area" by default), the actual output when it differs (quantity + a unit of the output ingredient), made at (now), notes; **what it will consume** rendered under the form as soon as a recipe is chosen (`hx-get` `/production/preview?recipe=&batches=`, Pattern A: `ri_explode()` × batches per ingredient in its count unit against on hand, short lines marked red — a warning, not a stop, because an area may allow a negative); **Post**, or **Save as draft** |
| `production-view` | `/production/{batch}` | one batch: the header, what it consumed (per ingredient, quantity and cost walled), what it made (the output, its cost per base unit — walled), the movements, the trail; a draft: **Post**, **Cancel**; `data-entity="production_batch"` |
| `waste-list` | `/waste/?from=&to=&stage=&reason=&station=&page=` | the table (`waste-row-{id}`): day, time, stage chip, reason, the ingredient or the plate (a link), quantity in its unit (or the net weight), cost (walled), station, day part, disposition, who, status chip (posted / reviewed / voided); **the week's totals** above as three tiles by stage and a small table by reason (`ri_share_waste_summary()`), cost walled; **Review** per row (`waste.review`); **Log waste** (`stock.waste`) |
| `waste-add` | `/waste/new?stage=&ingredient=&recipe=` | **the waste log at 375 px**: stage as three big buttons (ingredient / prep / plate), reason (the reasons whose `applies_to` holds waste, the stage's reasons first), the ingredient (stage ingredient — the picker) or the recipe (stage prep → prep recipes; stage plate → menu-item recipes; the picker), **quantity + unit** (for a recipe: `portion` or `batch`), **or** a gross weight in grams with the tare (the scale's reading — `gross_weight_g`, `tare_g`; shown for an ingredient), station (a text with the site's recent stations as datalist), day part (the current one by default from `mcp_day_parts`), area (the ingredient's depletion area by default), disposition (trash by default), a photo (`capture="environment"`), notes; **Log** posts at once (`ri_post_waste()` inside the same transaction) and lands on `waste-list` with the row highlighted |
| `waste-view` | `/waste/{waste}` | one log: the facts, the photo, the movements it posted (a plate's waste shows every ingredient), cost (walled), **Review** (`waste.review`), **Void** (`hx-confirm`, `waste.review`); `data-entity="waste_log"` |

## The receipt header form (`receipt-form`, ids `receipt-form-field-{name}`)
| Field | Input | Required | Rule |
|---|---|---|---|
| area | select (this site's active areas, `mcp_storage_areas`) | yes | where it lands; a line's put-away area overrides |
| supplier | the record picker (`source=supplier`) | no | a free receipt (a delivery note alone) has none |
| purchase_order | select of the supplier's open POs at this site (`mcp_purchase_orders`: status sent/acknowledged/partial) + none; re-listed after a supplier is chosen (`hx-get` `/receipts/po-options?supplier=`, Pattern A) | no | the PO's supplier and site must match (the handler's sentence) |
| received_on | date | yes | today by default; not in the future; `received_at` = that day at the site's current time (today) or the day's opening moment + 1 h (a back-dated receipt — the accounting moment of every line) |
| delivery_note_ref | text ≤ 100 | no | |
| invoice_ref | text ≤ 100 | no | the supplier's invoice number when it came with the delivery (slice 4's invoice may name it) |
| notes | textarea ≤ 2,000 | no | |
| lines | JSON (an agent, or the PO prefill) `[{ingredient|supplier_item, qty, unit, unit_cost, weight_qty, weight_unit, temperature_c, temperature_ok, discrepancy_kind, discrepancy_note, putaway_area, purchase_order_line}]` | no | each line through the same validation as `receipt_line_add` |

A line (`receipt-line-form`, ids `receipt-line-form-field-{name}`): ingredient — the picker (a `supplier_item` chosen fills the ingredient, the
purchase unit and the last price); qty > 0 in **unit** (a purchase unit of the ingredient, a count unit, or a global unit of its class — `ri_to_base()`
decides; a unit it cannot convert → the schema's sentence "limes have no each-weight"); **weight_qty + weight_unit** (lb by default) when the
ingredient or the supplier item is catch weight — then the quantity that lands is the weight and the cost is per weight unit (the trigger
`ri_receipt_lines_before()`); unit_cost ≥ 0 per purchase unit (shown and accepted only for `sees_cost()` — a Kitchen caller sending one at a site
where Kitchen sees no cost is refused in words; a blank cost lands at the running cost — `allow_zero_cost` says a free sample truly costs nothing);
temperature_c and temperature_ok when the ingredient's category has `temperature_check` (the site's `temperature_check` off hides them); discrepancy_kind
none/short/over/damaged/wrong_item/substitute + note; purchase_order_line (a line of the receipt's PO with the same ingredient; the prefill sets it);
putaway_area (active, this site). **The same ingredient added twice makes a second line** (two cases at two temperatures) — DECISION; the line's
**Edit** changes a quantity.

## Posting, step by step (what each handler calls)
- `receipt_post` → `ri_post_receipt(receipt, by)`: the header `posted`; per line one `receipt` transaction at `COALESCE(putaway_area, area)` for
  `qty_base` at `cost_per_base` (or the running cost when blank), `occurred_at = received_at`, `business_date = received_on`, counterparty the
  supplier; a priced line writes `price_history` (source `receipt`, `old` the cost before the receipt) — which may raise a `price_alerts` row
  (db/010's trigger) — and sets the supplier item's `last_price`; then `ri_receipt_posted_hook()` advances the PO's lines and status (slice 4's
  body; a no-op until db/010 — already applied, so it runs here too: a receipt against a PO marks the lines partial/received and the PO partial/received).
  The ledger's trigger moves the balance and the site's average: `avg' = (on_hand × avg + qty × cost) ÷ (on_hand + qty)`, `last_cost` the line's.
- `transfer_send` → `ri_transfer_send(transfer, by)`: `in_transit`; per line a `transfer_out` at the from area at the sender's average
  (`ri_cost_as_of`), the line's `unit_cost_per_base` kept; the counterparty an area (within a site) or a site.
- `transfer_receive` → `ri_transfer_receive(transfer, by, received JSON)`: per line a `transfer_in` at the to area for the received quantity at the
  sender's cost (the receiving site's average advances through the trigger — `transfer_in` with counterparty `site` is inbound); `received`.
- `stock_adjust` → `ri_post_adjustment(adjustment, by)`: per line an `adjustment` at the area for `qty_delta_base` (a reason not of kind adjustment
  refused in words); a positive line with a cost writes `price_history` (source `manual`, the reason's name) — an opening balance.
- `production_post` → the handler inserts the batch (`draft` when asked, else straight on) then `ri_post_production(batch, by)`: the version in
  force at `made_at`; per exploded ingredient a `production_out` at `consume_area` or the ingredient's depletion area for `qty × batches` at the
  running cost; one `production_in` of the output ingredient at the landing area for `output_qty_base` (the actual output, else the batch yield ×
  batches × (1 − loss)) at Σ cost ÷ output; `price_history` (source `production`) on the output; `cost_total` kept.
- `waste_log` → the handler inserts the row then `ri_post_waste(waste, by)` in the same transaction: an ingredient → one `waste` row for the
  quantity (or the net weight through `g`) at the depletion area or the one named; a recipe → `ri_explode()` of the version in force on the
  business date × portions (÷ batch yield) or × batches, one `waste` row per ingredient; `cost` and `group_id` kept on the log.
- `waste_void` → `ri_void_waste()`: every `waste` row of the group reversed; `voided`. `stock_reverse` → `ri_reverse_transaction(txn, by, note)`.
- Every posting verb runs inside `ri_guard()` in one transaction; the handler re-reads the record through its `mcp_*` view after and answers
  `did` in words with the quantities in the unit the person used.

## Files (exactly these)
- `app/features/stock/queries.php` — `stock_levels()`, `stock_movements()`, `stock_value_rows()`, `balance_for()`, `txn_for_reverse()`, `unit_options_for()`,
  `area_options()`, `po_options_for_supplier()`, `po_open_lines()`, `receipt_for()`, `receipt_lines()`, `transfer_for()`, `transfer_lines()`,
  `adjustment_for()`, `adjustment_lines()`, `batch_for()`, `batch_preview()`, `waste_for()`, `waste_week()`, `recent_stations()`
- `app/features/stock/present.php` — the JSON presenters (`present_receipt()`, `present_transfer()`, `present_adjustment()`, `present_batch()`,
  `present_waste()`, `present_level()`, `present_movement()`; cost keys absent when walled)
- `app/features/stock/units.php` — `qty_with_unit(ingredient, qty_base, unit_code)` (the one formatter: "2 bag50 (45 359 g)"), `parse_qty_unit($post)`
- `app/views/stock/{levels,movements,value}.php`, `app/views/stock/_levels-table.php`, `_movements-table.php`
- `app/views/receipts/{list,form,view}.php`, `_lines-table.php`, `_line-form.php`, `_po-prefill.php`, `_po-options.php`
- `app/views/transfers/{list,form,view}.php`, `_lines-table.php`, `_area-options.php`
- `app/views/adjustments/{list,form,view}.php`, `_lines-table.php`
- `app/views/production/{list,form,view}.php`, `_preview.php`
- `app/views/waste/{list,form,view}.php`, `_week.php`
- `html/stock/index.php`, `movements.php`, `value.php`, `reverse.php`
- `html/receipts/index.php`, `form.php`, `view.php`, `save.php`, `line.php`, `line-remove.php`, `post.php`, `cancel.php`, `po-options.php`
- `html/transfers/index.php`, `form.php`, `view.php`, `save.php`, `line.php`, `line-remove.php`, `send.php`, `receive.php`, `cancel.php`, `area-options.php`
- `html/adjustments/index.php`, `form.php`, `view.php`, `save.php`, `line.php`, `line-remove.php`, `post.php`, `cancel.php`
- `html/production/index.php`, `form.php`, `view.php`, `save.php`, `cancel.php`, `preview.php`
- `html/waste/index.php`, `form.php`, `view.php`, `save.php`, `review.php`, `void.php`
- `html/assets/js/stock-forms.js` — the catch-weight and temperature fields' show/hide on the picker's change; the unit select re-listed per
  ingredient (`/stock/unit-options?ingredient=`); no framework; the forms work without it (every field rendered, the irrelevant ones collapsed)
- `app/pickers.php` gains `supplier_item` (search by SKU/description/ingredient name, `supplier=` narrowing) and `recipe` with `kind=` (slice 2 added
  `ingredient` and `recipe`; this slice adds the narrowing params only)
- `tests/phase3/slice3/run.sh`, `lib.php` (`ledger_world()`), `browser.mjs`
- Nothing in `db/`.

## Query functions (signatures fixed; the tools of Phase 4 call these — `stock_on_hand`, `stock_by_area`, `stock_movements`, `stock_value`, `receipts_open`, `transfers_open`, `production_batches`, `waste_logs`)
- `stock_levels(PDO, int $scope, array $f, int $page): array` — rows of `mcp_inventory_balances` joined to `mcp_ingredients`, `mcp_ingredient_units`
  (default count), `mcp_ingredient_site_costs`, `ri_days_on_hand($scope)`; `f`: area, category, q, below_par; value null when walled
- `stock_movements(PDO, int $scope, array $f, int $page): array` — `mcp_inventory_transactions` + the document's number by `reference_kind`
- `stock_value_rows(PDO, int $scope, string $asOf): array` — `ri_stock_value($scope, ri_site_moment(...))` grouped by category; `withheld: true` when walled
- `unit_options_for(PDO, int $ingredient, string $purpose): array` — `[{code, name, factor}]`: purchase/count units of the ingredient (`mcp_ingredient_units`
  by `kind`), then the global units of its class (`mcp_units`); `purpose` receive|count|recipe|waste orders them
- `po_open_lines(PDO, int $po): array` — the open lines with `qty_open_base` and the purchase unit
- `receipt_for(PDO, int $id): ?array`, `receipt_lines(PDO, int $id): array`, and the same for transfer, adjustment, batch (`batch_for`), waste
- `batch_preview(PDO, int $recipe, float $batches, int $scope): array` — `ri_explode(version_on(today))` × batches with on hand per ingredient
- `waste_week(PDO, int $scope, string $from, string $to): array` — `ri_share_waste_summary()` as tiles and rows
- `recent_stations(PDO, int $scope): array` — the last 30 days' distinct stations

## Handlers (every one: `ri_handler_begin()`; `require_site(current_site_id())`; the right at the site; the record through its view; `ri_guard()` around one transaction; `log_activity` with `scope_id` (+ `ingredient_id` on a line, `purchase_order_id` on a receipt against a PO); `ri_done()`; `HX-Trigger: stockChanged` — `receiptChanged`, `transferChanged`, `adjustmentChanged`, `productionChanged`, `wasteChanged` for the documents)
- `receipt_draft` / `receipt_update` (`save.php`): the header; `lines` JSON each through `line_validate()`; a PO of another supplier or site → 422 in words
- `receipt_line_add` (`line.php`): `receipt_line` present → update; the unit converted by the trigger; a Kitchen caller's `unit_cost` where cost is walled → 422
- `receipt_post`, `receipt_cancel`, `stock_reverse`, `transfer_*`, `adjustment_*` / `stock_adjust`, `production_post` / `production_cancel`, `waste_log` /
  `waste_review` / `waste_void` as in "Posting"; `transfer_receive`'s `received` JSON converted per line through `ri_to_base()` in the line's ingredient
- Every refusal the schema raises (`check_violation`, `insufficient_privilege`, `no_data_found`, `foreign_key_violation`, `P0001`) reaches the person through
  `ri_guard()` as the schema's sentence (the kit's five codes)

## Manifest rows claimed
Screens (19): `stock-levels`, `stock-movements`, `stock-value`, `receipt-list`, `receipt-add`, `receipt-view`, `receipt-edit`, `transfer-list`, `transfer-add`, `transfer-view`, `adjustment-list`, `adjustment-add`, `adjustment-view`, `production-list`, `production-add`, `production-view`, `waste-list`, `waste-add`, `waste-view`
Actions (24): `receipt_draft`, `receipt_update`, `receipt_line_add`, `receipt_line_remove`, `receipt_post`, `receipt_cancel`, `stock_reverse`, `transfer_draft`, `transfer_update`, `transfer_line_set`, `transfer_line_remove`, `transfer_send`, `transfer_receive`, `transfer_cancel`, `adjustment_draft`, `adjustment_line_set`, `adjustment_line_remove`, `stock_adjust`, `adjustment_cancel`, `production_post`, `production_cancel`, `waste_log`, `waste_review`, `waste_void`

Left to other slices: `purchase-order-*` and `deliveries-due` (slice 4 — the receipt's PO link), `count-*` (slice 6), the formal exports (slice 9).

## Activity log events (every row: `scope_id`; a receipt against a PO: `purchase_order_id`; a line: `ingredient_id`)
`receipt.draft|update|line_add|line_remove|post|cancel`, `stock.reverse`, `transfer.draft|update|line_set|line_remove|send|receive|cancel`,
`adjustment.draft|line_set|line_remove|post|cancel`, `production.post|cancel`, `waste.log|review|void`, `screen.view`. `after` on a post carries
`lines`, the quantities in base units with their unit codes, `value` only when the actor sees cost; a waste row carries `stage`, `reason`, `qty_base`,
`cost` (walled the same way); never a photo path.

## Notifications this slice queues (`ri_notify()`; the sender is slice 8's worker)
- `delivery_due` is slice 4's; this slice queues none of its own — a receipt against a PO notifies the PO's creator `po_ack`-style only when slice 4 asks (left to it).
- A waste log over a value the settings name (none in v1) — none.

## Status vocabulary
- receipt: `draft` → `posted` | `cancelled`; transfer: `draft` → `in_transit` → `received` | `cancelled`; adjustment: `draft` → `posted` | `cancelled`;
  batch: `draft` → `posted` | `cancelled`; waste: `posted` → `reviewed` | `voided`.
- chips: `draft` secondary, `in_transit` info, `posted`/`received`/`reviewed` success, `cancelled`/`voided` dark; transaction types: `receipt` success,
  `depletion` primary, `waste` danger, `transfer_out`/`transfer_in` info, `production_in`/`production_out` warning, `count_correction` secondary,
  `adjustment` secondary, `return` success, `reversal` dark.

## Mobile rule (375 × 740)
Receiving, the batch form and the waste log: one column; the picker's button full width; quantity and unit in one `input-group` (the number ≥ 56 px
tall, the unit select ≥ 44 px); the declared-weight pair under it when shown; the temperature's OK / Not OK as two half-width buttons; **Post** fixed at
the bottom of the viewport above the tab bar; the lines table collapses to cards (`receipt-line-card-{id}`) under 576 px with the quantity large;
`scrollWidth ≤ clientWidth` on every screen; the levels and movements tables scroll inside their card horizontally, never the page.

## Vocabulary
on hand (the book balance now) · count unit (the unit on the shelf) · base unit (g, ml, each — what the ledger stores) · purchase unit (a case, a bag)
· declared weight (catch weight: what the box says it weighs) · put-away area · in transit · a prep batch (a prep recipe made in quantity) · plate
waste / prep waste / ingredient waste (Leanpath's stages) · reverse (the inverse row, once) · late (a row dated in a closed period).

## Out of scope for this slice
Purchase orders and the supplier's door (slice 4 — the receipt only reads an open PO's lines); invoices (slice 4); the POS and depletion (slice 5);
counts (slice 6); periods and usage (slice 7); the price alerts' screen (slice 4 — this slice's receipts raise them); the formal exports (slice 9);
photos' thumbnails (an `<img>` of the attachment is enough).

## Proof (`tests/phase3/slice3/run.sh`: the scratch database `ri_dev3` from `tests/setup_dev.sh`, the application on 9301 (`php -S` through `tests/dev_router.php`, or `RI_APP=apache`), the fake kernel 9302, the fake MaluDB 9303; members through `bin/dev_handoff.php <id> [scope]`; curl with signed action and run tokens; headless Chromium at 375 × 740 and 1280 × 800; the registry `--check`; `sync_approvals --check`)
Target: **≥ 250 checks** (levels and movements 25, receipts 60, catch weight and temperature 15, transfers 30, adjustments 20, production 25, waste 30, walls 20, json 15, browser 25).
The world (`tests/phase3/slice3/lib.php` `ledger_world()`): slice 1's `catalog_world()` — two sites (101 Downtown, 102 Airport), three areas at Downtown and one at
Airport, the ingredients flour (weight, `bag50`), chicken (weight, catch weight, yield 85, tolerance 3 %/$5), eggs (each, `case` 180 / `flat` 30, each-weight 50 g),
limes (each, `case` 200, shelf life 10), gin (volume, `bottle`/`case6`), oil (volume, density), stock (volume, prep); Sysco with items for flour, eggs, chicken (catch
weight, per lb) and the Produce Co with limes; slice 2's `recipe_world()` — the stock prep (2 kg chicken → 4 l, 10 % loss), the chicken sandwich (170 g at 90 %,
50 ml stock, an egg), the extra-chicken modifier; Bea (Kitchen at Downtown), Dana (Chef), Marco (Manager at Downtown, Kitchen at Airport), Ann (Viewer), the expert
(agent, Chef at both), the Purchasing agent.
- [ ] **Levels and movements**: an empty site shows an empty state with the three big buttons; after the receipts below, `stock-levels` lists flour as "4 bag50 (90 718 g)" and eggs as "12 flat"; `below par` marks flour once a par of 250 kg is set; `?format=csv` answers `text/csv` with the same rows; the movements table shows every type chip, the document link, the counterparty; a Viewer sees quantities and "—" for cost; `stock-value` says `withheld` to Ann and totals for Dana.
- [ ] **Receipts**: Bea creates a draft (area Dry store, supplier Sysco, received today); adds 2 `bag50` of flour at $25 (the base quantity shown 45 359.237 g); adds a case of chicken with the **declared weight 38.5 lb** at $3.10/lb → the line's `qty_base` 17 463.31 and `cost_per_base` $0.006834 (the trigger); adds 2 cases of eggs with temperature 3.5 °C OK; a line in `kg` for limes → 422 "limes have no each-weight"; a line in a unit of another class with no density → the sentence; a `unit_cost` sent by Bea where Kitchen sees no cost → 422 in words; Post → five transactions, flour on hand 45 359.237 g, the site's average $0.001102/g, `price_history` rows with `old` NULL, the PO untouched (none); a posted receipt's `line.php` → 422 "only while it is draft"; a second receipt at $30 → the average $0.0012125/g and a `price_alerts` row at ~20 %; a blank cost on limes → lands at the running cost (never zero); `allow_zero_cost` → zero; Cancel on a draft; Post on a cancelled → 422; a receipt dated inside a closed period (the proof closes one through SQL) → its rows land in the next open period with `late` in the note and the chip on the movements table.
- [ ] **Receipt against a PO**: a sent PO (seeded by SQL through `ri_po_send()`) with 3 bags → `receipt-add?purchase_order=` prefills the open line; receiving 2 → the PO line `partial`, the PO `partial`, `ri_on_order()` one bag; `purchase_order_id` on the activity row.
- [ ] **Transfers**: Marco drafts Downtown Walk-in → Airport Walk-in with 100 limes and 2 flats of eggs; Send → `transfer_out` ×2 at the average, `in_transit`, limes 300 at Downtown and 0 at Airport; Receive at Airport (Marco holds it as Kitchen) with 95 limes received → `transfer_in` 95 and 60 eggs, the line's `qty_received_base` 95, Airport's average = Downtown's; a to-site the person does not hold → 403; equal areas → the CHECK's sentence; a line on a received transfer → 422; Bea at Downtown cannot open the Airport-only view of a transfer she is not party to (404).
- [ ] **Adjustments**: Dana drafts at Walk-in with reason `breakage`, a line −1 `flat` of eggs → `qty_delta_base` −30; Post → an `adjustment` row at the running cost; a reason `plate_waste` (waste-only) → 422 "not an adjustment reason"; an `opening` adjustment of +10 `bottle` of gin at $18/bottle by Dana → the average set and `price_history` source `manual`; Bea (no `counts.approve`) → 403; an agent's `stock_adjust` under the relayed token → the kernel's hook sees `adjustment.post` (`deletion`).
- [ ] **Production**: Bea opens the batch form, picks the stock prep, the preview lists 2 kg chicken against on hand; Post 1 batch → `production_out` 2 000 g chicken at the running cost, `production_in` 3 600 ml stock at Σ ÷ 3 600, `price_history` source `production`, `cost_total` on the batch; a menu-item recipe → 422 "Only a prep recipe is produced"; an actual output of 3 400 ml → the cost per ml higher; a draft saved, then posted; cancel a draft; the batch's view shows what it consumed and made with cost to Dana and without to Bea.
- [ ] **Waste**: Bea logs plate waste of one sandwich (reason `returned_dish`, station pass) → three `waste` rows (chicken 188.9 g, stock 50 ml, an egg), `cost` on the log, the row highlighted on the list; a dropped flat of eggs by count → 30 eggs; a weighed waste of chicken: gross 1 240 g tare 240 g → 1 000 g; a reason `theft` → 422 "not a waste reason"; a prep-stage waste of 0.5 batch of stock → chicken 1 000 g (the prep's recipe exploded, DECISION: prep-stage waste of a recipe explodes to raw — the batch was not posted); Dana reviews (`reviewed`); Dana voids the flat → three… one reversal, eggs back; the week's tiles by stage and the reason table agree with `ri_share_waste_summary()`; a Viewer sees no cost; the photo saved as an attachment, never in a log payload.
- [ ] **The ledger's rules**: a direct `INSERT INTO inventory_balances` as the rw role → "maintained only by transactions"; an `UPDATE inventory_transactions` → "never changed"; `stock_reverse` on a receipt row → a `reversal` with the opposite quantity, a second → "Already reversed"; `ri_on_hand_as_of()` before and after; the balance may go negative at Walk-in (allow_negative) by a waste larger than on hand and is refused at an area set `allow_negative = false`.
- [ ] **Walls**: every cost column null in `mcp_*` for Ann and Bea at Downtown, present for Dana and Marco at Downtown, null for Marco at Airport; `kitchen_sees_cost = true` at Downtown → Bea sees cost on the receipt form and the view; `stock-value` withheld/not.
- [ ] **JSON mode**: every handler under a signed action token answers `{ok, did, record_id, location, refresh}` with its facts (`receipt_post` → the five transaction ids and the quantities with units; `transfer_receive` → the received per line; `production_post` → `output_qty_base`, `cost_total` walled; `waste_log` → `cost` walled, the rows); 422 `{error: {code: invalid, fields}}` naming the field; `_partial=1` on `receipt_update` keeps the untouched header; the expert (run token + relay) drafts a receipt and a transfer, logs waste; its `stock_adjust` pauses (the fake kernel's hook); `is_eval` refuses every write.
- [ ] **375 × 740 and 1280 × 800**: the receiving screen — the picker opens and chooses chicken, the declared-weight pair appears, the temperature buttons, Post fixed at the bottom; the waste log's three stage buttons and the scale fields; the batch preview rendering under the form; the levels and movements tables scrolling inside their cards; `scrollWidth ≤ clientWidth` on every screen; the headless run at 1280 shows the tables in full.

**Decisions taken in this spec (not questions):**
- A receipt line names an ingredient (or a supplier item that fills it); the same ingredient twice is two lines.
- `received_at` is the receipt's accounting moment: today at the current time, a back-dated day at its opening moment + 1 hour.
- A prep-stage waste of a recipe explodes to the raw ingredients (the batch was not posted); a plate-stage waste explodes to the menu item's lines with the prep taken from stock (`ri_explode(…, false)`) — exactly what `ri_post_waste()` does.
- The batch preview is a warning: short lines are red, the post is not stopped (an area may allow a negative; the count corrects).
- `stock_reverse` needs `counts.approve` (a Chef's) — a reversal changes the book like a count does.
- The temperature fields show when the category asks and the site's `temperature_check` is on; `temperature_ok` on the header is the AND of the lines, set by the post handler.

## Open questions
None. Every rule here is the schema's or the design's (§6.3, D10, D12).

## Built and proven

Built 2026-10-11 by the planning model's fork, beside slice 5 in the same working tree; the application's commits are not pushed. All 19 screens and
24 actions of this spec are built (the registry reads 72 screens and 85 actions built in all).

**Proof:** `tests/phase3/slice3/run.sh` runs on the scratch database `ri_dev3d`, with this slice's port block: the app on :9316, the fake kernel :9317,
the fake MaluDB :9318 and the internal vhost :9319. **347 checks pass under php -S and 347 under a user-level Apache rendering
`deploy/apache-restaurant_inventory.conf`.**

| File | Checks | What it proves |
|---|---|---|
| `receipts.php` | 78 | receipts, catch weight and temperature, the moving average, a price alert, a free sample, cancel, a late receipt, a PO receipt, `kitchen_sees_cost` |
| `levels.php` | 50 | the empty state, on hand, below par, CSV, movements of every type, value, the `mcp_*` walls |
| `transfers.php` | 75 | transfers, adjustments, an agent's `stock_adjust`, the ledger's rules, reversal, a negative balance, db/022 |
| `production.php` | 60 | the batch form and preview, posting, an actual output, draft and cancel, waste at three stages, the scale, the photo, review, void, the week |
| `json.php` | 38 | the action-token shape, `_partial`, 422 fields, the expert under its run token, an evaluation run |
| `browser.mjs` | 43 | headless Chromium at 375 × 740 and 1280 × 800, and with JavaScript off |
| registry, approvals, claim check | 3 | |

The shared suites ran once at the end:
- `tests/phase2/run.sh`: 349 green, after its gate matrix was brought to the built screens (below).
- `tests/phase1/check_specs.sh`: 52 green.
- `php bin/build_action_registry.php --check`: green.
- `tests/phase0/run.sh`: 122 green and 2 red, both from slice 5's commit 16f60f0, not this slice. The schema proof's "a no-depletion PLU leaves the unmapped queue" fails against db/023's new `ri_unmapped_plus()`. The worker check still expects `depletion_runs` to be a stub naming slice 5, but that pass is built now.

Screenshots are in `/tmp/ri-shots-s3`. The proofs are order-dependent by design: receipts first on the fresh database, for its first prices; levels makes its empty state and on-hand checks at Airport.

**Files:**
- **Feature code:** `app/features/stock/{queries,present,handler}.php` and `app/partial_targets/stock.php` (receipt_update's `_partial`).
- **Views:** `app/views/{stock,receipts,transfers,adjustments,production,waste}/`.
- **Controllers:** `html/{stock,receipts,transfers,adjustments,production,waste}/*`. The eight placeholders were replaced.
- **Assets:** `html/assets/js/stock-forms.js` and `html/assets/css/stock.css`, kept apart from `app-overrides.css`.
- **Shared helper:** `money_price()`, appended to `app/features/units/queries.php`.
- **Migration:** `db/022_slice3_adjustment_decrease_cost.sql`.
- **Tests:** `tests/phase3/slice3/*`, plus the Phase 2 gate matrix in `tests/phase2/{gates,sso}.php`.

**Decisions taken while building (reported, not questions):**
- **A Viewer reads the ledger.** The lists and views of receipts, transfers, adjustments, production, waste and stock value open under `ri.read`. This follows design §2 (a Viewer reads stock on hand, counts, waste, orders and their status, never cost) and this spec (a Viewer reaches `stock-value` and reads `withheld`; a Viewer sees no cost on the waste list). The doors that act keep the role's right: `/receipts/new` `stock.receive`, `/transfers/new` `stock.transfer`, `/adjustments/new` `counts.approve`, `/production/new` `stock.produce`, `/waste/new` `stock.waste`, and every write. Phase 2's gate matrix had the placeholders' menu rights; it now says so, and its "may not receive" checks use `/receipts/new`. The shell's menu rows are unchanged.
- **The proof's Kitchen cost.** The spec's proof has Bea, a Kitchen member, price flour at $25. Kitchen sees no cost at Downtown, so Dana (Chef) enters the costed lines and Bea's cost is the 422. With `kitchen_sees_cost` on, Bea prices a line.
- **`is_eval` refuses every write** (the manifest's rule). The kit never enforces it, because it asks for run facts only at an agent's first admission. `stock_refuse_eval()` in the slice prelude asks for each ledger write and answers 403 in words. A run the kernel does not vouch for gets 401.
- **No `stock/units.php`.** The spec's `app/features/stock/units.php` `qty_with_unit()` would collide with the catalog's request parser of that name. The slice uses the shared unit helpers and `stock_qty_in()`.
- **`production_post` posts a saved draft** through a `batch` parameter, which the manifest does not list. `draft=yes` saves a batch, and without `batch` a draft could never be posted.
- **A transfer received at a site the person does not hold answers 404**, the kit's `require_site()`. Drafting to such a site answers 403 in words, as the spec says.
- **An edited line keeps its ingredient.** A transfer line edited by `line` without an `ingredient` keeps the line's ingredient.
- **The business day folds under the time.** The movements and waste lists show the business day under the time in one cell, with the late chip there. A list's cells wrap at 992 px and wider, so every table fits at 1280. On a phone they keep `nowrap` and scroll inside their card.
- **`stock-forms.js` asks `/stock/unit-options` for JSON** without `HX-Request`, which would answer the options partial.

**What the spec or the schema got wrong:**
- **A cost on a decrease (schema; fixed by db/022).** `ri_post_adjustment()` hands a line's cost to its movement, and `ri_transactions_before()` fills the average only for a NULL. A cost typed on a decrease therefore valued that movement at the typed cost, not the running average. db/022 adds `ri_adjustment_line_cost()`, which refuses it in words.
- **The average keeps six decimals (schema).** `ingredient_site_costs.avg_cost_per_base` holds the spec's $0.0012125 / g as $0.001213. The proof allows the column's precision.
- **The negative-balance sentence shows raw base numbers (schema wording).** It reads "Only 0.0000 on hand in that area — the move needs 5.0000 …" with no units.
- **Sticky never engages under the theme (affects every slice).** The theme's `.main-content` is a scroll container (`overflow-x: hidden; overflow-y: auto`), so `position: sticky` never engages. Slice 2's "the cost panel sticks" checked only the computed style. The ledger sets `overflow-x: clip` on a panel holding a post bar.
- **The theme forces nowrap on table cells.** It sets `white-space: nowrap` on every cell, which is why wide tables overflow at 1280.
- **The spec's proof header names other slices' resources.** It names scratch `ri_dev3` and ports 9301–9303, which are Phase 2's. This slice's block is 9316–9319, per design §14.
