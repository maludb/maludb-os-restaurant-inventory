> Synthesis of the three research reports in this directory, written 2026-10-11 by the planning session. It lists what a restaurant inventory system must do and the questions the plan has to decide; it decides nothing itself. The design document (`docs/restaurant-inventory-design.md`, not yet written) is where findings become decisions.

# Restaurant Inventory — what the research says the system must do

The brief: a restaurant inventory application for the MaluDB Business OS — purchase orders, receiving, recipes for projection and costing, sales depletion, waste, physical counts — whose two major goals are **inventory projection for reordering** and **product costing**. Three reports:

- `01-field-survey.md` — twenty products and the distributor portals, screen by screen; what users complain about; the vocabulary and the published formulas.
- `02-mechanics.md` — the arithmetic and the data each formula needs: recipe costing, units, valuation, variance, forecasting, reorder policy, counts, waste, receiving, data standards, the bar.
- `03-estate-survey.md` — what the sibling applications and the kernel already hold that this application must reuse, read or make new canonical; ports; the gaps.

## 1. The eight findings that shape the plan

1. **Units of measure are the hard part, not the arithmetic.** Every product anchors on three measure classes (weight, volume, each) and gives an item several units: an inventory unit, purchase units (case → pack → each with sizes), a recipe unit, count units. A density per item is needed only when a recipe crosses classes. The one trap every vendor documents is a case defined as "1 each". (01 §2, 02 §1.) The estate's only units tables are in the two process applications' `app` schema; a kit application has none, so this application makes `units` / `item_units` new canonical (03 §9.1).
2. **Recipe cost is a tree walked bottom-up with a yield on every edge.** EP = AP ÷ yield; a sub-recipe costs its ingredients ÷ its finished yield after trim and cook loss; a plate cost is the portion of that; menu price follows from a target food-cost %. Yield lives in two places that the products keep apart — on the item at receipt (changes the cost per count unit) and on a recipe line (changes depletion, not cost). Recipes must be versioned with effective dates because a past period's theoretical usage must use the recipe then in force, and costs are recomputed from price history as of a date, never frozen on the version. (02 §1, 01 §5.) The cidery's and ProcessCore's versioned recipes are the family to copy by column name; neither has sub-recipes, per-line yield or a menu-item link (03 §4).
3. **Theoretical vs actual is one identity in every product.** Actual usage = beginning + purchases + transfers in − transfers out − ending (waste is inside actual). Theoretical = Σ POS quantity × recipe quantity, modifiers included. Unexplained variance = variance − logged waste − comps − staff meals. The pitfalls are each a rule: receipts dated by arrival not invoice date, credits reversed on the original date, every storage area marked counted or skipped, the count moment equal to the sales cut, sub-recipes counted as items. Reports whose arithmetic is hidden are a top complaint — publish the columns. (02 §2, 01 §12, §11.)
4. **Periodic or perpetual must be chosen and named.** Restaurant365 is periodic on purpose (purchases hit COGS at once, an approved count IS the inventory journal); MarketMan and Apicbase sell a perpetual running balance depleted by the POS. The estate's ledger (Inventory's `inventory_transactions` with balances by trigger) is perpetual-shaped. (01 §0.3, 02 §3.)
5. **Projection in practice is sales history exploded through recipes.** Forecast at menu-item level (weekly seasonality, day of week, daypart, holidays, closures excluded, promotions as uplift, new items inheriting their category's profile), explode through the active recipes to ingredient demand, then projected on hand per ingredient per day = on hand + receipts due − Σ demand. A same-weekday four-week average is the honest baseline; Holt-Winters is cheap and competitive on short histories; gradient boosting wins by a few points only when calendar and weather features exist. MAPE is useless at item level because of zero-sale days — store MAE, WAPE and bias. MarginEdge's published method adds category safety stock (proteins 20–30 %, dairy 15–20 %, dry 8–15 %). (02 §4, 01 §9.) The only forward signal in the estate is Reservations' `covers_by_service` share, already consumed by txtSchedules into `forecast_covers`; no sales history exists anywhere, so the application's own imported POS lines are the history (03 §5).
6. **The reorder rule that fits delivery days is periodic review.** Order-up-to level S = Σ forecast over the protection interval (order cycle + lead time) + safety stock z·σ·√P; quantity = max(S − on hand − on order, 0); perishables capped by the newsvendor fractile over shelf life; then rounded to the vendor's pack, minimum order and cut-off. Craftable's published simpler rule (par − in stock − placed − new, rounded up by at most 20 %, never auto-placed) is what most operators use; Supy adds a fifteen-day forecast with projected stock on the delivery date per line. A vendor schedule is order day + cut-off time + delivery day + lead days. (02 §5, 01 §3.) Inventory's Buyer proposals and `inv_reorder_candidates()` are the estate's pattern (03 §9.12).
7. **Counts, receiving and waste have settled shapes.** Counts: storage areas are the count sheets in shelf order; several count units summed; partials as case + loose → fraction, a tenths slider, or bottle weights against full/empty; a blank-row prompt (cancel / zero / skip); blind counts with a per-item tolerance, a second recount and a manager's approval that posts the adjustments and freezes the period; offline entry because walk-in Wi-Fi is the recurring complaint. Receiving: three-way match with per-vendor tolerances; catch weight ordered by each and received, invoiced and counted by weight with the cost recalculated; the FDA Food Code temperature check at the door as the HACCP log. Waste: Leanpath's three stages (ingredient, finished, plate) × reasons, with station, daypart, tare, cost at the time and destination. Commissary = a transfer that becomes an invoice at the destination. Invoices are the source of cost in the invoice-first products (line items, price-threshold alerts, one SKU related across vendors). (02 §6–8, 01 §4, §7, §8.)
8. **Most of the storeroom already exists in the estate; the catalog, the quantity type and everything kitchen-specific do not.** Inventory's `locations`, balances, transactions, receipts, transfers, adjustments, counts, reason codes, suppliers, purchase orders with their events and the supplier's secure link, and the `sources` connector frame fit a restaurant verbatim — except that every quantity there is an integer keyed by a retail variant with no unit. Reuse needs one recorded deviation: a numeric quantity in a base unit keyed by an ingredient, with a unit cost. Everything else — `ingredients`, recipes with sub-recipes and yields, `menu_items`, POS sales and lines, depletion runs, waste logs, count sheets, usage periods, forecasts — is new canonical. A restaurant is a kernel site, so this is the first scoped inventory application. (03 §2, §3, §9.)

## 2. The required features, grouped

Everything a mature product has, per the field survey's screen inventory (01 §10) and the mechanics (02), with where the estate already has it. "Core" is what the two goals need; "expected" is what every product has and users assume; "later" is what the plan may defer.

### Catalog and units — core
- Units of measure with measure class and chained equivalences; per-item purchase pack hierarchy, recipe unit, count units; a per-item density only when needed (02 §1.3; estate: cidery/ProcessCore `units`, `item_units`).
- Ingredients: code, name, category as data, base unit, costing method, standard cost, yield % at receipt, shelf life, catch-weight flag, allergens; per-site par and reorder settings (03 §9.2).
- Vendor items: what each supplier sells us, in its unit, with its code, pack, last price, minimum order, lead time; one ingredient related across vendors (01 §4; estate: `supplier_items`, the cidery's shape plus Inventory's `moq`).
- Price history on every cost change with its source (estate: Inventory `price_history`).

### Purchasing and receiving — core
- Suppliers with order method (email, portal, EDI, phone), order days, cut-off times, delivery days, lead days (02 §5.4).
- Order guides per vendor in shelf or category order (01 §3).
- Suggested order per vendor from par or forecast minus on hand minus on order, rounded to the pack, never placed automatically (02 §5; 01 §3).
- Purchase orders draft → sent → acknowledged → received, sent by email or the supplier's secure link (estate: Inventory's PO family verbatim).
- Receiving against the PO: quantities and weights, substitutions, shorts, damaged, credits; catch weight recalculating the delivered cost; the temperature check at the door (02 §8; estate: `goods_receipts` with the measured deviation).
- Invoice line capture — upload, photo, email — and the three-way match with tolerances; price-change alerts per item (01 §4, 02 §8). OCR itself is a later choice (the field's moat, labour-heavy; an agent may read the lines).

### Recipes and costing — core
- Recipes of three kinds: menu item, prep/batch (makes stock of an ingredient), modifier; versioned with effective dates; lines naming an ingredient or a sub-recipe with quantity, unit, yield/waste %; batch yield and portions (02 §1).
- Cost roll-up as of a date from price history; EP cost, portion cost, plate cost, food-cost % against the menu price; a cost factor to re-cost from a new wholesale price (02 §1.1–1.2).
- Menu items from the POS (PLU, name, category, price) mapped one-to-one to a recipe; modifiers mapped to modifier recipes with add / remove / swap / none (02 §1.5).
- Menu engineering: contribution margin and popularity, the Kasavana–Smith four quadrants (01 §5, 02 §1.6).
- Allergens and, later, nutrition rolled up through the recipe tree like cost (02 §9).

### Sales and depletion — core
- POS import as a `source` (CSV/SFTP export, an API with a key) with pulls, an unmatched-PLU queue, voids excluded and comps counted (01 §6; estate: Inventory's `sources` frame).
- Depletion by backflushing the active recipe version at the sale's business date into the ledger; a depletion run per site and day that can be re-run (03 §9.6).

### Counts, waste, transfers — core
- Count sheets per site and storage area in shelf order; count entry in count units with partials (fractions, tenths, bottle weights), the blank-row prompt, out-of-stock; review with flags and tolerance; approval posts the adjustments (02 §6, 01 §7).
- Waste log with stage, reason, station, daypart, quantity, weight net of tare, cost, who, disposition; photos (02 §7).
- Transfers between storage areas and between sites; a commissary as a site whose transfer becomes a bill at the destination (01 §8).
- Production: posting a prep recipe makes stock of its output ingredient and consumes its lines (01 §10; estate: the cidery's production family by analogy).

### Projection and reordering — core (the first goal)
- Forecast of menu-item sales per site, day and daypart from the POS history with weekly seasonality, holidays and closures; new items from the category profile; manual overrides; booked covers from Reservations through K7 as the forward signal (02 §4; 03 §5).
- Ingredient demand by exploding the forecast through recipes; projected on hand per ingredient per day; days on hand (02 §4.5).
- Reorder: fixed par or dynamic par from the forecast, the periodic-review rule against each vendor's delivery schedule, perishables capped by shelf life; the suggested order per vendor as a drafted PO (02 §5).
- Forecast accuracy kept as MAE / WAPE / bias per item so the method can be judged (02 §4.4).

### Variance, costing periods and reports — core (the second goal)
- Usage periods per site (week, month, 4-4-5 or 13 × 4 weeks) with a close that freezes ending inventory and posts COGS (02 §3).
- Actual vs theoretical by ingredient with every column published: opening, purchases, transfers, production, waste, theoretical usage, actual usage, variance in units and at cost, unexplained variance (02 §2).
- Valuation: weighted average by default, last price on the chef's card; refuse zero-cost receipts (02 §3.1).
- COGS and food-cost % by period and category; prime cost needs labour cost, which is txtSchedules' or HR's to share (01 §12).
- Days on hand, turns, usage; price movers; the multi-site comparison (01 §10).
- Pour cost % and bottle/keg arithmetic for the bar, the same model with the fluid ounce as the recipe unit (02 §10).

### Expected but not core
- Inventory valuation, purchases received and COGS summaries as K7 shares for the General Ledger; CSV/JSON exports (03 §6).
- GS1 barcodes on receiving (GTIN-14, GS1-128 lot and best-before) and lot/expiry for recall; EDI 850/855/856/810 to the broadline distributors (02 §9) — later, behind the same `sources` frame.
- Nutrition from USDA FoodData Central (02 §9) — later.

## 3. What the estate settles before the plan starts

| Topic | Settled by precedent | Where |
|---|---|---|
| The contract | Phase 0 schema + Phase 1 tool surface and action manifest before any feature PHP; the shared-schema survey before any `CREATE TABLE`; the installer's manifest keys | 03 §1 |
| Scope | a restaurant is a kernel site: `scopes.kind = "location"`, the mirror tables verbatim, txtSchedules' `sites` shape, `scope_id` on every row and share | 03 §7 |
| The storeroom | Inventory's ledger, receipts, transfers, adjustments, counts, reason codes, suppliers, POs, secure links, `sources` — reused with the measured-quantity deviation recorded | 03 §2–3 |
| Recipes' family | the cidery's `recipe_versions` column names; "backflush" is the estate's word for POS depletion | 03 §4 |
| The GL | shares and exports, never a write; the GL declares no reads yet, so a G-item is owed | 03 §6 |
| Catalog row | category `inventory`, area Operations — nothing widened | 03 §7 |
| Ports | internal 8200, records MCP 8856, activity MCP 8857, scratch 9301–9307 | 03 §8 |
| Repository | `maludb-os-restaurant-inventory`; catalog key `restaurant_inventory`; local clone `/srv/apps/restaurant_inventory` | the brief, 03 §0.12 |

## 4. What the plan must decide

1. **Periodic or perpetual.** The estate's ledger is perpetual; the periodic school says a purchased item never maps one-to-one to a sale. Which number does every screen show, and does an approved count post adjustments to the perpetual balance (the recommended hybrid: perpetual balance, periodic close)?
2. **The depletion grain.** One ledger row per ingredient per sale, or per ingredient per site per business date (a day's depletion run). Row volume and re-runnability both point to the day.
3. **The costing basis per screen.** Weighted average for valuation and variance, last price on the recipe card, standard cost optional for a price/usage split.
4. **Forecast method for v1.** The same-weekday four-week average plus Holt-Winters with weekly seasonality, in SQL or in Python inside the worker; gradient boosting later when calendar features exist. Where booked covers enter (a sales-per-cover mix, or a manager's adjustment).
5. **Reorder policy for v1.** Craftable's par rule as the default every operator understands, the periodic-review formula behind a "dynamic par" switch per ingredient or category.
6. **The POS door.** Which connectors ship in v1 (a CSV/SFTP export is universal; Toast, Square and Lightspeed have APIs with keys) and how a PLU is mapped (a queue like Inventory's unmatched listings).
7. **Invoice capture.** Line entry by a person against the receipt in v1; an agent reading an invoice image through the kernel's model credential as the first agent duty, or later.
8. **Count units and partials.** Case + loose and decimal partials in v1; bottle weights with a scale (the bar) in v1 or later.
9. **Multi-site and the commissary.** Transfers between sites in v1; the commissary-as-vendor pattern (a transfer that becomes a bill) in v1 or later.
10. **The quantity deviation from Inventory's canonical.** Propose it back to the catalogue as "the canonical shape widened for measured goods", or keep it as this application's own family.
11. **The agents.** An expert; a Buyer-like agent with a morning duty (the suggested orders, the variance of the closed period, price movers); whether counts, receiving and waste are people-only actions (money-out pauses already; a count approval should).
12. **What is owed outward.** The catalog row (K-item), the GL's reads (G-item), a txtSchedules share of labour cost for prime cost, a Reservations share of actual covers, the catalogue entries for the new canonical tables.

## 5. Vocabulary the plan will use

COGS = beginning + purchases ± transfers − ending. Food-cost % = COGS ÷ food sales. Prime cost = COGS + labour. Actual usage, theoretical usage, variance, unexplained variance as in §1.3. Par, order-up-to level, reorder point, safety stock, protection interval as in §1.6. AP/EP, yield %, cost factor, plate cost, contribution margin as in §1.2. Days on hand = on-hand value ÷ daily usage value. "Sitting inventory" has no product definition; use "on-hand value" (01 §12).
