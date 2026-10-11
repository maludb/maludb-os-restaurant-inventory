# Build spec: projection and ordering — the forecaster, the explosion, the suggested order, the worker and the morning note

Written by the planning-class model (Phase 1, 2026-10-11) from **the schema as built in Phase 0** (db/014 projection and ordering;
db/007 schedules and `ri_next_order_windows()`; db/010 purchase orders; db/015 watches, proposals, notifications; db/016 the
gatherers — proven by `db/proof/phase0_proof.sql`) and design §1, §5, §6.8, D8, D9, D14, the research `docs/research/02-mechanics.md`
§4 and §5. A worker relies on this document for three things: **what the Python forecaster writes and when the SQL baseline stands in**,
**how a forecast becomes a drafted purchase order with every step on the line**, and **the worker's passes and the morning note**.
Nothing here changes the schema: where the spec says *as built*, `db/*.sql` is the authority.

Schema (never modify; a change is a numbered additive migration with sign-off): `calendar_days`, `forecast_runs`, `item_forecasts`,
`forecast_overrides`, `forecast_accuracy`, `covers_expected`, `ri_forecast_baseline()`, `ri_covers_factor()`, `ri_item_forecast()`,
`ri_ingredient_demand()`, `ri_projected_stock()`, `ri_stockout_on()`, `ri_z()`, `ri_suggested_order()`, `ri_suggested_order_draft()`,
`ri_suppliers_due()`, `ri_forecast_accuracy_compute()` (db/014); `supplier_schedules`, `ri_next_order_windows()`,
`ingredient_site_settings`, `ingredient_categories.safety_stock_pct`, `supplier_items.moq`, `suppliers.allow_split_case`,
`suppliers.min_order_value` (db/007); `purchase_orders`, `purchase_order_lines` (the need/have/on-order/cap/safety/raw/rounding columns),
`ri_on_order()` (db/010); `sales_daily` (db/011); `watches`, `ri_watch_state()`, `ri_watches_fire()`, `buyer_proposals`,
`agent_dispatches`, `ri_notify()` (db/015); `ri_price_movers()`, `ri_days_on_hand()`, `ri_pos_health()`, `ri_unmapped_plus()`,
`ri_count_variance()`, `ri_purchase_orders_open()`, `ri_deliveries_due()`, `ri_stockouts_projected()` (db/016); the views
`mcp_forecast_runs`, `mcp_item_forecasts`, `mcp_forecast_overrides`, `mcp_forecast_accuracy`, `mcp_covers_expected`, `mcp_calendar_days`,
`mcp_watches`, `mcp_buyer_proposals`, `mcp_agent_dispatches` (db/017).

**The division of labour, in one sentence** (design §6.8): Python (in the worker) **forecasts menu items** and writes `item_forecasts`;
SQL does **everything after** — overrides, the covers factor, the explosion into ingredients, projected stock, the suggested order;
the Purchasing agent **proposes** from the gatherers and drafts through `ri_suggested_order_draft()`; a person **places** the order
(`money_out` pauses an agent). PHP never forecasts, explodes or rounds.

---

## 1. The forecast of a menu item

### 1.1 The SQL baseline — always available (`ri_forecast_baseline(site, item, on)`)
The same-weekday weighted average of the last four weeks: weights 0.4, 0.3, 0.2, 0.1 for 1, 2, 3, 4 weeks back; a week whose day the
site has **no sales at all** is skipped and a **closed** day (`calendar_days.closed`) too, the remaining weights renormalised; a
closed target day forecasts 0; no usable week → NULL (the caller treats it as 0 and flags thin history). Worked (the proof): 20 on
weekdays and 40 on Fridays and Saturdays for five weeks → 20 / 40 exactly; with today's real sales of 3 among the four back-weeks the
weighted average is 13.2 — the honest floor (research 02 §4.3).

### 1.2 Holt-Winters in the worker (`bin/forecast.py`, pure Python, no NumPy — this VM has no AVX for Polars and the data is small)
Run by the worker's `forecast_pass` **nightly per site at 02:00 site time** (and on `forecast_run`): per menu item with ≥ 12 weeks of
`sales_daily` (`day_part_id IS NULL`), additive, seasonal period m = 7:
```
L_t = α (y_t − S_{t−7}) + (1−α)(L_{t−1} + b_{t−1});  b_t = β (L_t − L_{t−1}) + (1−β) b_{t−1};  S_t = γ (y_t − L_t) + (1−γ) S_{t−7}
F_{t+k} = L_t + k·b_t + S_{t+k−7}
```
Initialisation from the first two weeks (L_0 = mean of week 1; b_0 = (mean week 2 − mean week 1) / 7; S_i = y_i − L_0 for the first
seven days); α, β, γ chosen by a grid over {0.1 … 0.9 step 0.1} × {0, 0.05, 0.1, 0.2} × {0.1 … 0.9 step 0.2} minimising the one-step
MAE over the last 28 days; closed days are skipped in the fit (their S stays) and forecast 0; negatives clipped to 0; σ = the standard
deviation of the last 28 one-step residuals; `lo`/`hi` = F ∓ 1.645 σ (clipped at 0). Items with < 12 weeks are **not** written —
`ri_item_forecast()` falls back to the baseline for them. One `forecast_runs` row per site per run (`method holt_winters`,
`horizon_days 21`, `history_days 364`, `params` the chosen α/β/γ per item count, `items`), the previous run's `is_current` cleared in
the same transaction; `item_forecasts(run_id, menu_item_id, on_date, qty, lo, hi, sigma)` for each of the 21 days. The script reads
`config/.env` (the kit's DSN), takes `--site` and `--as-of`, prints one JSON line, exits 1 on failure; the PHP pass shells to it with
`escapeshellarg` and logs `forecast.run`.

### 1.3 Precedence (`ri_item_forecast(site, item, on)` → `qty, source, sigma`)
1. an **override** in force (`forecast_overrides`, `status = set`, the item's own before an all-items one, the newest first): `qty`
   replaces, `factor` multiplies; `source = override`;
2. else the current run's row (`source = run`), else the baseline (`source = baseline`);
3. × `ri_covers_factor(site, on)`: booked covers (`covers_expected`, from Reservations through K7) ÷ the mean covers of the same weekday
   over the last four weeks (`pos_sales.covers`); **1 unless the difference exceeds `ri_settings.covers_factor_threshold_pct` (15 %)
   or no cover history exists** (D8: covers are a factor, never the forecast).
`forecast_override_propose` (an agent) writes `status = proposed`; `forecast_override_decide` sets or dismisses it; only `set` rows
count. A `calendar_days` row with `closed` forces 0 through the baseline and the run alike.

### 1.4 Accuracy (`ri_forecast_accuracy_compute(site, window)` — the worker after each nightly run)
Per item over the window, the **baseline's** one-week-ahead error against `sales_daily` (closed days excluded): `mae`, `wape = Σ|f−y| /
Σy`, `bias = Σ(f−y) / Σy`, `n`; **never MAPE** (zero-sale days make it meaningless — research 02 §4.1). Written to
`forecast_accuracy (scope_id, menu_item_id, horizon_days = 7, window_days)`. Owed (§9): the same for the run's own forecasts once two
runs' horizons overlap the actuals.

---

## 2. From items to ingredients (`ri_ingredient_demand(site, from, to)` → `ingredient_id, on_date, qty_base`)
For every active mapped menu item sold in the last 12 weeks, for every day: `ri_item_forecast().qty × menu_items.portions ÷
version.batch_yield_qty × ri_explode(version in force that day, that day)` — a prep is a leaf (taken from stock), line yields applied;
summed per ingredient per day. Modifiers are **not** in the demand in v1 (Owed: an attachment-rate table). Worked: one egg per
sandwich → the egg demand on a day equals the sandwich forecast.

**Projected stock** (`ri_projected_stock(site, ingredient, days)` → per day `demand_base, receipts_base, projected_base`): on hand now +
the open PO lines due by each day (`expected_on` of the line, else the PO's, else ordered + 1) − Σ demand × (1 + the waste share) —
the waste share = Σ waste ÷ Σ depletion of the ingredient over the last 28 days; `ri_stockout_on()` = the first day under zero;
`ri_stockouts_projected(site, days)` lists them for the home and the morning note (prep ingredients excluded — they are made, not bought).

---

## 3. The suggested order (`ri_suggested_order(site, supplier, as_of)`) — step by step, as built
1. **Windows**: `ri_next_order_windows(supplier, site, as_of)` → the next cut-off and its delivery, and the delivery after the **next**
   cut-off; the **protection interval P** = next_delivery − delivery (days). Worked: Sysco at Downtown orders Saturday 13:00 for Tuesday
   and Wednesday 13:00 for Friday → P is 3 or 4; a supplier with no schedule orders any day with its lead time (P = 1).
2. **Lines**: the supplier's active items on the site's order guide (`order_guides` + `order_guide_lines`, in `seq`), else every item of
   the supplier whose ingredient has an active site setting naming no other preferred supplier item; an ingredient whose
   `preferred_supplier_item_id` is another supplier's item is skipped.
3. **History**: `thin_history` = fewer than `ri_settings.dynamic_par_after_weeks` (4) distinct weeks of `sales_daily` in the last year;
   `par_mode` = `dynamic` only when the site setting says so **and** history is not thin (D9).
4. **Demand** over [delivery, next_delivery): Σ `ri_ingredient_demand()` for the ingredient.
5. **Need**: fixed → `par_base` (or demand + the category's `safety_stock_pct` when no par is set); dynamic → demand +
   **safety stock** = `ri_z(service_level) × σ_daily × √P` where σ_daily is the standard deviation of the last 28 days' depletion per
   day (n ≥ 7), else the category's safety %. `ri_z(0.95) = 1.645`, `0.98 → 2.054`, `0.90 → 1.282`.
6. **Have** = `ri_on_hand()` + `ri_on_order(ingredient, site, delivery)` (open PO lines due by the delivery).
7. **Cap** (perishables, `shelf_life_days < P + 7`): the 0.7 quantile of the last 56 days' daily depletion × shelf life (the newsvendor
   fractile with no salvage — research 02 §5.3); `need = min(need, cap)`.
8. **Raw** = max(need − have, 0) in base units.
9. **Units**: raw ÷ `ri_to_base(ingredient, 1, purchase_unit)`; split cases allowed (`suppliers.allow_split_case`) → ceil to 0.01;
   else **round down when the whole unit above would exceed the raw need by more than 20 % and the shortfall below is under 20 % of a
   unit** (Craftable's rule), else ceil; then `max(units, supplier_items.moq)`. `rounding_note` says which ("rounded up to the case",
   "rounded down (within 20 %)", "split case allowed", "covered", "; minimum N").
10. **Cost** = the live contract price else the last price, per purchase unit.
Worked (the proof): flour fixed par 250 kg, on hand 161 kg → raw 88 975 g = 3.92 bags → "rounded up to the bag50" = 4 (the shortfall
20 936 g is not under 20 % of a bag); eggs dynamic, five weeks of history, demand + 1.645 σ √P, no cap (21 days of shelf life);
chicken covered.

`ri_suggested_order_draft(site, supplier, by, origin)` writes the PO (`origin = suggested`, `cutoff_at`, `expected_on = delivery`) with
one line per suggestion whose units > 0, **every step on the line** (`need_base`, `have_base`, `on_order_base`, `cap_base`,
`safety_base`, `raw_base`, `rounding_note` + the par mode and a thin-history flag); nothing to order → no PO, NULL. **It never sends.**
`ri_suppliers_due(site, hours)` lists the suppliers whose next cut-off falls within the hours (36 by default).

The `suggested-order-list` screen shows one card per supplier due with the lines and their arithmetic (a Viewer sees no cost), **Draft
the PO** → `suggested_order_run` (`supplier`, `draft = yes`), **Draft all due**; the drafted PO opens on `purchase-order-view` where a
Manager sends it (`purchase_order_send`, `money_out`). `special-check` reuses the explosion: a recipe × portions on a date against
`ri_projected_stock()` that day, the shortfalls and the supplier whose delivery arrives in time (`ri_next_order_windows()`).

---

## 4. Watches (`ri_watch_state()`, `ri_watches_fire()`)
Kinds: `below_par` (on hand + on order < the site par), `stockout_projected` (`ri_stockout_on(…, 7)` not null), `cost_above` (the
cost per base above the threshold), `cost_moved` (the last price-history step ≥ the threshold %). `ri_watches_fire()` evaluates every
active watch, **fires once per false → true** (`last_state`), notifies the watcher (`ri_notify` kind `watch`, a text when `text_me`),
and dispatches the named agent (`agent_dispatches` kind `watch`, `via chat`) — the worker's `dispatches` pass turns it into one chat
turn through the kernel. The proof: a `stockout_projected` watch on eggs fires once and not again.

---

## 5. The Purchasing agent's morning note (design §5; `maludb-os.json` duty `0 6 * * *`, runbook `skills/morning-note`)
The worker's `morning_note_inputs` pass (at the duty hour per site, in the site's zone — Owed K48 until then it runs at the UTC hour)
writes `buyer_proposals` rows (`proposed_by` = the Purchasing agent's member id, `note_date`), one per finding, from these gatherers:

| Heading | Gatherer | Proposal kind / drafted record |
|---|---|---|
| (1) suggested orders due today or tomorrow | `ri_suppliers_due(site, 36)` → `ri_suggested_order_draft()` per supplier | `order` / the drafted `purchase_order` (every line with its arithmetic; `thin_history` lines flagged in `detail`) |
| (2) projected stock-outs before the next delivery | `ri_stockouts_projected(site, 7)` | `stockout` / none |
| (3) prices | `price_alerts` unseen since yesterday; `ri_price_movers(site, 7)`; contract vs invoice drift (`supplier_items.contract_price` vs the last receipt's cost) | `price` |
| (4) the POS | `ri_pos_health(site)`: failed pulls, `days_missing`, `unmapped_plus`, `unmapped_modifiers`, `pending_runs` | `pos` |
| (5) the last count | the last approved count's `ri_count_variance()` lines over tolerance; `ri_count_areas_skipped()` | `count` |
| (6) the forecast | `forecast_accuracy` items whose WAPE worsened by > 0.1 week on week; `calendar_days` closures or holidays in 14 days with no override | `forecast` |
| (7) purchase orders and deliveries | `ri_purchase_orders_open(site)` unacknowledged past `ack_days` (3); `ri_deliveries_due(site, today)` with no receipt | `po_overdue`, `delivery` |

The agent's run (`morning_note_send`, the runbook) reads the day's proposals (`buyer_proposals` tool), writes the note in the seven
headings (the skill's wording; cost named only where the reader may see it), sends it to the site's Manager (`site_settings.manager_member_id`,
else the first super-admin — `ri_notify` kind `morning_note`) and to `#kitchen` in Spaces when installed; it **drafts and never sends**
a PO. A person accepts or dismisses a proposal (`buyer_proposal_decide`); acceptance of an `order` opens the PO.

---

## 6. The worker's passes — order and cadence (`bin/worker.php`, `WORKER_PASSES` as built)
| Pass | When | Does |
|---|---|---|
| `pos_pulls` | each minute: sources whose `schedule_minutes` elapsed and not backed off | SFTP and Square pulls (pos-and-depletion.md §2.3, §3) |
| `email_intake` | each minute | the intake mailbox |
| `depletion_runs` | each minute | `ri_depletion_due()` → `ri_depletion_run()` |
| `price_alerts` | each minute | notify the Manager of new `price_alerts` (dedupe per alert) — the rows are raised by the trigger |
| `link_expiry` | each minute | supplier links past `expires_at` rotated out |
| `forecast_pass` | 02:00 site time, once a day per site (and on demand) | `bin/forecast.py --site`, then `ri_forecast_accuracy_compute()`; `covers_sync` (K7 `reservations.covers_by_service`, 62 days, into `covers_expected`) first |
| `suggested_orders` | each minute: suppliers whose cut-off is within 36 h and have no `suggested` draft since the last delivery | `ri_suggested_order_draft()` (so a Manager finds the draft without the agent) |
| `watches` | each minute | `ri_watches_fire()` |
| `notify_outbox` | each minute | emails and texts queued (`notification_outbox`, the kit's sender; K6 for texts) |
| `dispatches` | each minute | `agent_dispatches` `sent` → one chat turn through the kernel; the reply excerpt kept |
| `morning_note_inputs` | the duty hour per site | §5's proposals |
| `key_usage_prune` | daily | the kit's |
Each pass is its own try; `RI_WORKER_NOW` replaces the clock in the proofs; every pass logs counts, never a payload.

---

## 7. Proof (`tests/phase3/slice8/run.sh`)
- `bin/forecast.py` on 16 weeks of synthetic sales (20/40 weekday pattern + a trend) writes a run with 21 days per item, `lo ≤ qty ≤
  hi`, a closed day 0; an item with 8 weeks gets no row and `ri_item_forecast()` answers `baseline`; the run's `is_current` moves.
- The grid search picks α/β/γ with a lower 28-day MAE than α = β = γ = 0.3.
- An override factor and a quantity; a proposed override does not count until decided; the covers factor at 300 booked vs a norm of
  200 = 1.5, at 210 = 1.
- `ri_ingredient_demand`, `ri_projected_stock`, a stock-out, `ri_suggested_order` as §3's worked numbers; `rounding_note` for the
  three rounding cases and a MOQ; a split-case supplier at 0.01.
- The worker's `suggested_orders` pass drafts once per cut-off and not twice; `morning_note_inputs` writes the seven kinds; the
  agent's `morning_note_send` through the fake kernel notifies the Manager; the drafted PO stays `draft`.
- A watch fires once; a dispatch becomes one chat turn against the fake kernel.
- `forecast_accuracy` rows carry MAE/WAPE/bias and no MAPE column exists.

## 8. Owed to the schema
- `forecast_accuracy` is computed for the baseline only; the run's own accuracy needs the horizon kept per forecast row — propose
  `item_forecasts.made_for_horizon smallint` and a `ri_forecast_accuracy_runs(p_scope_id bigint, p_window_days integer) RETURNS integer`
  (db/0NN, slice 8's, additive).
- Modifier attachment rates in the demand: `ri_modifier_attachment(p_scope_id bigint, p_menu_item_id bigint, p_days integer) RETURNS
  TABLE (modifier_id bigint, per_sale numeric)` and its use in `ri_ingredient_demand()` — Extended unless slice 8 finds the demand
  materially short.
- K48 (the kernel): a per-site duty hour — until then the `morning_note_inputs` pass fires at the UTC hour and the note names every site.
- `ri_suppliers_due()` lists only suppliers with a schedule; a supplier with none (order any day) never appears — by design (a person
  orders it); recorded.
