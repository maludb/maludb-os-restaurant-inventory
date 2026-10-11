> Research report for `docs/restaurant-inventory-design.md` §0, written 2026-10-11 by a research agent of the planning session (web sources cited inline). Kept as written; the design document is where its findings become decisions.

# Restaurant inventory — the mechanics: costing, projection, counting, purchasing, standards

## 0. The findings, in short

1. **Recipe cost is a tree walked bottom-up with a yield at every edge.** EP cost = AP cost ÷ yield; a sub-recipe's cost per unit = its ingredients' cost ÷ its *finished* yield (after trim and cook loss). The textbook method (BC Campus *Basic Kitchen and Food Service Management*) gives the butcher's test and cooking-loss test with worked numbers; a "cost factor" (EP cost ÷ AP cost) lets a recipe be re-costed from a new wholesale price with one multiplication. Columns needed: `yield_pct` on the item (and optionally per preparation), `cost_factor` derived, `portion_size` in the recipe unit. ([LibreTexts 3.02](https://workforce.libretexts.org/Bookshelves/Food_Production_Service_and_Culinary_Arts/Basic_Kitchen_and_Food_Service_Management_(BC_Campus)/03%3A_Food_Costing/3.02%3A_Yield_Testing), [3.03](https://workforce.libretexts.org/Bookshelves/Food_Production_Service_and_Culinary_Arts/Basic_Kitchen_and_Food_Service_Management_(BC_Campus)/03%3A_Food_Costing/3.03%3A_Cooking_Loss_Test))
2. **Units are the hard part, not arithmetic.** Every item needs a measure class (weight / volume / count), a purchase pack hierarchy (case → pack → each with sizes), a recipe unit, and — only when a recipe uses a volume of something bought by weight or vice versa — a density. Vendors' conversion tables are averages they disclaim; the per-item density must be the operator's own. ([COGS-Well](https://cogs-well-inc.helpscoutdocs.com/article/739-what-is-a-measure-class-faq), [Galley](https://support.galleysolutions.com/conversions))
3. **The variance engine is one identity in two directions.** Actual usage = beginning + purchases + transfers-in − transfers-out − ending (waste is *inside* actual, not subtracted); theoretical usage = Σ (POS quantity sold × recipe quantity, with modifiers). Variance = actual − theoretical, in units and at cost; it is the single most used report and its accuracy is bounded by the weakest input (an uncounted shelf, a credit posted late, a modifier that does not deplete). ([Toast](https://pos.toasttab.com/blog/on-the-line/actual-vs-theoretical-food-cost), [meez](https://www.getmeez.com/blog/actual-vs-theoretical-food-costs))
4. **Costing basis is a design choice with three defensible values**: moving weighted average (what most restaurant platforms use; one receipt updates the average), last price (what a chef wants to see on a recipe card), and standard cost (a frozen price list that makes variance decomposable into price vs. usage). GAAP/IFRS permit FIFO and weighted average everywhere; LIFO only under US GAAP, never IAS 2. A perpetual inventory at moving average with period-end counts is what the restaurant platforms implement. ([KPMG](https://kpmg.com/us/en/articles/2026/inventory-accounting-ifrs-accounting-standards-vs-us-gaap.html), [Deloitte](https://dart.deloitte.com/USDART/home/publications/deloitte/additional-deloitte-guidance/roadmap-ifrs-us-gaap-comparison/chapter-1-assets/1-4-inventories), [Supy](https://supy.io/blog/learn-recipe-cost-weighted-average-audit-trail))
5. **Forecast at the menu-item level, explode through recipes to ingredient demand.** The literature shows weekly seasonality (m = 7), holidays and weather as the dominant features; gradient boosting with calendar/lag features generally beats Holt-Winters when those features exist, Holt-Winters stays competitive and cheap on short histories; a seasonal-naive or 4-week same-weekday average is the honest baseline every vendor measures against (Tenzo claims 30–50 % better than a 4-week average). Zero-sale days (closures) break MAPE — use sMAPE/WAPE or MAE. ([MDPI 2022](https://www.mdpi.com/2504-4990/4/1/6), [Kim 2025](https://pmc.ncbi.nlm.nih.gov/articles/PMC12528577/), [Tenzo](https://gotenzo.com/?p=4276))
6. **The reorder policy that fits vendor delivery days is periodic review (R, S), not continuous reorder points.** S = d·(R+L) + z·σ·√(R+L); order = max(S − inventory position, 0), rounded up to the pack, floored by the vendor MOQ, and — for perishables — capped by what can be sold inside shelf life (the newsvendor critical fractile C_u/(C_u+C_o)). ([MetricGate (R,S)](https://metricgate.com/docs/periodic-review-order-up-to-RS/), [Wikipedia newsvendor](https://en.wikipedia.org/wiki/Newsvendor_model))
7. **Crunchtime's suggested order is literally Need − Have**: need = forecast consumption to the next delivery + par/safety, have = on hand + on order; it is the shape every suggested-order generator takes. ([Crunchtime](https://www.crunchtime.com/blog/how-to-translate-restaurant-sales-forecasts-into-better-prep-ordering-and-labor-planning))
8. **Counts are shelf-to-sheet, by storage area, in the unit on the shelf, with decimals for partials; bottles are weighed** (fraction = (gross − empty) ÷ (full − empty)); blind counts and a variance-threshold recount are standard practice; a count is a snapshot "as of" a timestamp and the book side must be computed to that same instant. ([Restaurant365](https://docs.restaurant365.com/docs/shelf-to-sheet-inventory), [Sculpture Hospitality](https://www.sculpturehospitality.com/blog/why-weighing-is-the-most-efficient-and-accurate-bar-inventory-method))
9. **Waste has a stable taxonomy**: Leanpath's three stages (ingredient / finished product / plate) × reasons (overproduction, trim, spoilage, expiration, contamination, overcooked/burned/dropped); WRAP's long-standing split is 21 % spoilage / 45 % preparation / 34 % plate. A waste row is item, quantity, unit, reason, stage, cost at the time, who, when, station. ([Leanpath](https://blog.leanpath.com/food-waste-tracking-stages), [WRAP via Business Wales](https://businesswales.gov.wales/documents/top-tips-improving-your-resource-efficiency-hospitality-and-food-services))
10. **Receiving is a three-way match with two foodservice twists** — catch weight (ordered in cases, received and invoiced by actual pounds) and the cold-chain check at the door (TCS food ≤ 41 °F, hot ≥ 135 °F, shell eggs ≤ 45 °F ambient, frozen solid). The invoice line is the only price of record. ([SPS Commerce](https://www.spscommerce.com/wp-content/uploads/2019/09/SPS-Three-Way-Match-Invoice-Reconciliation.pdf), [Infor catch weight](https://docs.infor.com/sxe/2021.x/en-us/sxolh/eiw1589404147150.html), [S.C. Code 61-25 §3-202](https://www.law.cornell.edu/regulations/south-carolina/R-61-25-3.202))
11. **Standards to adopt verbatim**: GTIN-14 for cases (indicator 9 = variable measure), GS1-128 AIs 01/10/15/17/310n/30 for the case label, EDI 850/855/856/810 (or the grocery UCS 875/880) for distributors, USDA FoodData Central per-100 g nutrients, FDA's nine allergens and the EU's fourteen. ([GS1 UK](https://gs1uk.org/support-training/help-to-use-and-implement-gs1-standards/barcode-variable-measure-items), [TEC-IT AI table](https://www.tec-it.com/en/support/knowbase/gs1-application-identifier/Default.aspx), [MSU on FASTER](https://www.canr.msu.edu/news/the-faster-act-sesame-as-major-food-allergen), [FSAI on 1169/2011](https://www.fsai.ie/Business-Advice/Labelling/Food-Information-to-Consumers))
12. **The bar is the same model with different units**: a bottle is an item whose recipe unit is the fluid ounce (or ml), a keg is a variable-measure container weighed like a bottle, and pour cost % is food cost % by another name with lower targets (liquor ~18–24 %, draft beer ~20 %, wine ~30 %). ([BevSpot](https://bevspot.com/blog/bar-management-pricing-drinks-pour-costs/), [Wikipedia keg](https://en.wikipedia.org/wiki/Keg))

---

## 1. Recipe costing

### 1.1 Yield: AP → EP

The open textbook *Basic Kitchen and Food Service Management* (BC Campus; LibreTexts mirror) is the citable source for the classroom method ([3.02 Yield Testing](https://workforce.libretexts.org/Bookshelves/Food_Production_Service_and_Culinary_Arts/Basic_Kitchen_and_Food_Service_Management_(BC_Campus)/03%3A_Food_Costing/3.02%3A_Yield_Testing)):

```
EP_weight   = AP_weight − waste
yield_pct   = EP_weight / AP_weight × 100
cost_factor = 100 / yield_pct                 (= AP_weight / EP_weight)
EP_cost     = AP_cost × cost_factor           (per unit of EP)
AP_needed   = EP_needed / (yield_pct / 100)   (what to order for a required EP amount)
portions    = quantity_on_hand × yield_pct / portion_size
```

Worked (textbook): tenderloin 2 500 g AP, 750 g waste → 1 750 g EP → 70 %; factor 1.42; AP $23.00/kg → EP $32.66/kg. Sockeye 64.51 %; canned tuna (drained) 53.33 %, $5.50/can → $10.78 per 750 ml of drained flakes — the same arithmetic serves weight and volume.

**Butcher's test** (meat yield with by-products that have value): usable value = total cost − value of the by-products; EP cost per kg = usable value ÷ usable kg. Textbook pork loin: 2.5 kg at $12.14 ($30.35); fat/gristle 34 %, cutting loss 4 %, trim 10 %, usable 52 % (1.3 kg); by-products credited $0.17 + $1.87; usable value $28.31 → $21.78/kg usable; 250 g portion $5.44; **cost factor per kg 1.79** and **per portion 0.45**, so a wholesale move to $13.00 re-costs the portion as 0.45 × 13.00 = $5.85 without re-testing.

**Cooking-loss test** ([3.03](https://workforce.libretexts.org/Bookshelves/Food_Production_Service_and_Culinary_Arts/Basic_Kitchen_and_Food_Service_Management_(BC_Campus)/03%3A_Food_Costing/3.03%3A_Cooking_Loss_Test)) adds a second loss after trim:

```
loss_in_cooking     = trimmed_weight − cooked_weight
saleable_weight     = cooked_weight − bones_and_trim
cost_per_usable_kg  = total_value / saleable_weight
portion_cost        = cost_per_usable_kg × portion_size
cost_factor_per_kg  = cost_per_usable_kg / AP_cost_per_kg
```

Leg of lamb: 3 750 g at $6.50 ($24.38); trimmed 2 850 g (24 % trim loss); cooked 2 350 g (13.33 % cook loss); bones 750 g; saleable 1 600 g (42.67 %) → $15.24/kg; 125 g portion $1.91; cost factor 2.3446.

**Data each formula needs** (columns): `item.ap_unit`, `item.yield_pct` (default from a yield test; may be overridden per recipe line because a dice yields differently from a julienne), `yield_test(item, ap_qty, ep_qty, byproducts[] {item, qty, unit_value}, trim_loss, cook_loss, tested_at, by)`, `recipe_line.portion_qty`, `recipe_line.unit`.

### 1.2 The recipe tree, sub-recipes and roll-up

A recipe is a list of lines; a line references either an inventory item or another recipe (prep/batch recipe). A batch recipe has a **batch yield** (e.g. "makes 4.0 L" or "makes 24 portions") and the cost of one recipe unit is:

```
batch_cost            = Σ line_cost
line_cost (item)      = qty_in_recipe_unit × item_cost_per_recipe_unit / (line_yield_pct/100)
line_cost (sub)       = qty_in_recipe_unit × sub_recipe_cost_per_unit
sub_recipe_cost/unit  = batch_cost / batch_yield_qty        (batch yield already net of cook loss)
plate_cost            = Σ line_cost of the plate recipe (+ garnish/packaging lines)
food_cost_pct         = plate_cost / menu_price
menu_price_from_target= plate_cost / target_food_cost_pct   (e.g. $3.20 / 0.30 = $10.67, then round to a price point)
```

The roll-up is a depth-first evaluation of a DAG; cycles must be refused at save time (a recipe may not contain itself through any path). The practical ordering: compute every leaf item's cost per recipe unit once, then evaluate recipes in topological order and cache `recipe.current_cost` with `costed_at`.

**Which price feeds the leaf** — three bases exist in the products:

| Basis | Definition | Who uses it | Trade-off |
|---|---|---|---|
| Last price | most recent invoice line for the item at this site | chefs reviewing a card | jumps on every invoice; a one-off special distorts |
| Moving weighted average | (on-hand qty × old avg + received qty × price) ÷ (on-hand + received), recomputed at each receipt | Supy, most platforms' valuation | lags a price rise (Supy: 20 kg at $6.00 + 30 kg at $7.20 → $6.72, so a 250 g portion costs $1.68 not $1.80) ([Supy](https://supy.io/blog/learn-recipe-cost-weighted-average-audit-trail), [Cleverence](https://www.cleverence.com/articles/for-business/inventory-weighted-average-cost-4829/)) |
| Standard cost | a frozen price per item for the period | chains with a price book | variance splits into price variance (actual − standard price) × actual qty and usage variance (actual − theoretical qty) × standard price |

A zero-priced receipt pulls the average below true cost and understates every recipe on that item — refuse or flag zero-cost receipts in the blend ([Supy](https://supy.io/blog/learn-inactive-zero-priced-items-average-cost)). Multi-site: the same ingredient can carry a different average at each site; the recipe card should say which site's cost it shows.

**Versioning.** None of the vendor sources describe effective-dated recipe versions, but theoretical usage for a past period must use the recipe that was in force then, so: `recipe_version(recipe_id, version_no, effective_from, effective_to, lines[])`; a POS sale on date d explodes through the version where `effective_from ≤ d < effective_to`. Costs are *not* stored on the version — they are recomputed from price history as of a date (`item_price_history(item, site, vendor, price, unit, effective_at, source=invoice|manual|contract)`).

### 1.3 Units of measure

The vendor help centres converge on one model ([COGS-Well measure class](https://cogs-well-inc.helpscoutdocs.com/article/739-what-is-a-measure-class-faq), [COGS-Well conversion tables](https://cogs-well-inc.helpscoutdocs.com/article/348-recipe-unit-conversion-tables-faqs), [Galley](https://support.galleysolutions.com/conversions)):

- **Measure class** per item: `weight | volume | count`. Within a class, conversion is a global constant table (lb ↔ oz ↔ kg ↔ g; gal ↔ qt ↔ fl oz ↔ L ↔ ml).
- **Across classes** needs a per-item fact: a **density** (g per ml) for weight ↔ volume, an **each weight/volume** for count ↔ weight/volume (Galley's "1 each apple = 5.5 oz"). COGS-Well ships average tables but disclaims their accuracy; the operator's own measurement wins.
- **Purchase hierarchy**: `vendor_item(item, vendor, vendor_code, case_qty_of_packs, pack_qty_of_eaches, each_size, each_unit, price, price_unit=case|lb|each)`. A COGS-Well "recipe unit factor" of 80 for onions in a 5 lb bag priced by weight = 5 lb × 16 oz: cost per recipe unit = case price ÷ (packs × eaches × each_size in recipe units).
- Canned goods: cost by the can's volume, not its drained weight, unless the yield test says otherwise.

```
cost_per_recipe_unit = purchase_price / recipe_units_per_purchase_unit
recipe_units_per_purchase_unit =
    packs_per_case × eaches_per_pack × each_size × convert(each_unit → recipe_unit, item.density, item.each_weight)
```

Columns: `item.measure_class`, `item.recipe_unit`, `item.density_g_per_ml` (nullable), `item.each_weight_g` / `item.each_volume_ml` (nullable), `uom(code, class, to_base_factor)`, `vendor_item.*` above.

### 1.4 POS mapping and modifiers

The sources agree on the shape ([Craftable](https://help.craftable.com/learning/theoretical-inventory-from-your-pos), [Supy modifier recipes](https://help.supy.io/en/articles/11643137-modifier-recipes), [Restaurant365 Radar](https://radar.help.restaurant365.com/support/solutions/articles/12000086473), [NCR Aloha](https://docs.ncrvoyix.com/restaurant/aloha-pos/implementing/item_availability/feature_interaction_with_item_availability)):

- Every POS button (PLU) maps to exactly one recipe (`pos_item_map(pos_id, pos_item_code, recipe_id, size/portion variant)`); unmapped sales are listed as a gap, never silently dropped.
- Every POS modifier maps to a **modifier recipe** with an action: `add` (deplete its lines), `remove` (return the parent line's quantity, i.e. negative depletion), `swap` (remove one, add another), `none` (cosmetic). Aloha's built-in "NO" does not deplete unless copied to a custom code; a generic "No tomato" means a different quantity on each parent, so either the map is per (parent, modifier) or the modifier carries a quantity per parent (R365 "concatenation": `Wedge Salad - No Tomato`).
- Combos/meals explode to their components; sizes are separate recipes or a quantity multiplier on one recipe.

### 1.5 Menu engineering (Kasavana & Smith)

Michigan State's Kasavana & Smith (1982) classify each item in a category on two axes ([FIU Hospitality Review 1995](https://digitalcommons.fiu.edu/record/17973/files/V13n195e.pdf), [beancount summary](https://beancount.io/blog/2026/07/08/menu-engineering-food-cost-percentage-stars-plowhorses-puzzles-dogs-matrix)):

```
CM_item        = price − plate_cost                     (contribution margin, $)
CM_avg         = Σ(CM_item × qty_sold) / Σ qty_sold     (weighted by mix)
mix_pct_item   = qty_sold_item / Σ qty_sold
mix_threshold  = (1 / n_items_in_category) × 0.70       (the "70 % rule")
high_CM        = CM_item ≥ CM_avg
high_pop       = mix_pct_item ≥ mix_threshold
Star      = high CM, high pop   → keep, feature, hold price
Plowhorse = low CM,  high pop   → raise price a little / cut cost / reposition
Puzzle    = high CM, low pop    → feature, rename, lower price
Dog       = low CM,  low pop    → remove or re-engineer
```

Columns needed: sales by item per period (`qty_sold`, `net_price`), `plate_cost` as of the period, `menu_category`.

---

## 2. Theoretical usage, actual usage, variance, COGS

### 2.1 The identity

```
actual_usage(item, period)  = begin_qty + purchases + transfers_in − transfers_out − end_qty
actual_cogs(period)         = Σ actual_usage × unit_cost          (or: begin_value + purchases_value ± transfers − end_value)
actual_food_cost_pct        = actual_cogs / food_sales × 100
theoretical_usage(item)     = Σ over sales lines (qty_sold × recipe_qty_per_unit_sold, modifiers applied, yields applied)
theoretical_cogs            = Σ theoretical_usage × unit_cost  = Σ (plate_cost × qty_sold)
theoretical_food_cost_pct   = theoretical_cogs / food_sales × 100
variance_qty(item)          = actual_usage − theoretical_usage
variance_$(item)            = variance_qty × unit_cost
variance_pct                = (actual_cogs − theoretical_cogs) / food_sales × 100
```

Toast, meez and Supy state these in the same words ([Toast](https://pos.toasttab.com/blog/on-the-line/actual-vs-theoretical-food-cost), [meez](https://www.getmeez.com/blog/actual-vs-theoretical-food-costs), [Supy](https://supy.io/blog/learn-theoretical-food-cost-vs-actual-guide)); vendor rules of thumb: a well-run house keeps the variance under 2 points; above 5 is systemic ([Orbisk](https://orbisk.com/?p=25548495)). Theoretical is always ≤ actual in practice; a negative variance is a data defect (a missed receipt, a count error, an unmapped PLU) before it is good news.

**Explaining the variance** — waste logged (§7), comps/staff meals (sold at $0 but depleting), transfers, each a bucket:

```
unexplained_variance = variance_qty − logged_waste − comps_depletion − known_adjustments
```

### 2.2 Pitfalls (each one is a column or a rule)

- **Timing of receipts**: a delivery counted on the shelf but whose invoice posts after the count date → purchases must be dated by *receipt date*, not invoice date; `receipt.received_at` is the accounting date, `invoice.invoice_date` is informational ([SPS Commerce](https://www.spscommerce.com/wp-content/uploads/2019/09/SPS-Three-Way-Match-Invoice-Reconciliation.pdf)).
- **Credits and returns**: a short-ship credit after the fact must reverse the receipt's quantity and value on the original date, or it sits in purchases as phantom stock.
- **Uncounted areas**: a storage area skipped at count time (the walk-in's back shelf, the bar's speed rail) becomes "usage"; the count must enumerate every storage location and mark each counted/skipped.
- **Unlogged waste / staff meals / comps** show up as variance; log them to explain it (§7).
- **Cross-utilised items** (the same chicken in three recipes): item-level variance is fine; recipe-level attribution of variance is not possible from counts alone — report at item level, show the recipes that consume it.
- **Prep in progress**: counted prep (a batch of sauce) must be converted back to raw items at the sub-recipe's quantities, or counted as its own item with its own cost — the two conventions give different ending values; pick one (count sub-recipes as items at their rolled-up cost).
- **Period boundaries**: the count's "as of" moment must equal the sales cut (the end of a business day, not midnight).

### 2.3 Periods

COGS posts per period. Restaurants use calendar months, 4-4-5 (and 4-5-4, 5-4-4) quarters, or 13 four-week periods; Toast's xtraCHEF offers exactly those four options ([xtraCHEF](https://intercom.help/toast-central/en/articles/14726130-xtrachef-get-started-with-accounting-periods), [LiveFlow](https://www.liveflow.com/post/4-4-5-accounting)). Equal weeks make year-over-year comparable (same weekday mix); 52 weeks = 364 days so a 53rd week is added every 5–6 years. A weekly count-to-count variance with a period roll-up is the common operating cadence. Columns: `fiscal_calendar(kind, year_start)`, `period(no, starts_on, ends_on, closed_at, closed_by)`.

---

## 3. Inventory valuation and the period close

**What the standards allow.** IAS 2 permits FIFO and weighted average (specific identification for non-interchangeable items) and prohibits LIFO; ASC 330 permits the same plus LIFO, and US book/tax conformity makes LIFO a tax-driven choice. Measurement after cost is lower of cost and net realisable value under IAS 2 and for FIFO/average under ASC 330 ([KPMG](https://kpmg.com/us/en/articles/2026/inventory-accounting-ifrs-accounting-standards-vs-us-gaap.html), [Deloitte](https://dart.deloitte.com/USDART/home/publications/deloitte/additional-deloitte-guidance/roadmap-ifrs-us-gaap-comparison/chapter-1-assets/1-4-inventories)).

**What restaurants do.** The platforms run a perpetual quantity ledger with a **moving weighted average** per item per site, and a periodic physical count that overrides the book quantity; FIFO is physical rotation practice, not usually the valuation method. Formula (perpetual):

```
on receipt:  avg_cost' = (on_hand × avg_cost + qty_received × unit_price) / (on_hand + qty_received)
on issue:    value −= qty × avg_cost        (issues never change the average)
```
([Cleverence](https://www.cleverence.com/articles/for-business/inventory-weighted-average-cost-4829/))

**The close.** Ending inventory = Σ counted qty × unit cost at the count; the count is approved, the period is closed (`period.closed_at`), and COGS for the period = begin + purchases ± transfers − end is posted (to the GL application through K7 — the kernel keeps no books). After close, a late invoice or credit lands in the *next* open period, flagged as late, exactly as the kernel's own AI statement does. Restaurant365 frames every purchase, transfer, waste entry and count as a GL event ([Restaurant365](https://www.restaurant365.com/blog/why-restaurant-inventory-management-is-an-accounting-function)).

Columns: `stock_ledger(item, site, location, qty_delta, unit_cost, value_delta, kind=receipt|sale_depletion|waste|transfer|count_adjust|return, ref, at)`, `item_site_cost(item, site, avg_cost, last_price, standard_cost)`, `period_close(period, site, ending_value, cogs, posted_ref)`.

---

## 4. Demand forecasting

### 4.1 What the literature says

- A literature survey (Lasek, Cercone & Saunders, "Restaurant Sales and Customer Demand Forecasting: Literature Survey and Categorization of Methods", Springer 2016) categorises the methods used: multiple regression, Poisson regression, ARIMA, exponential smoothing/Holt-Winters, neural networks, Bayesian networks, hybrids, and association rules; multiple regression was the most accurate in one institutional foodservice study ([eudl.eu PDF](https://eudl.eu/pdf/10.1007/978-3-319-33681-7_40)).
- *Machine Learning Based Restaurant Sales Forecasting* (MDPI Machine Learning & Knowledge Extraction 4(1), 2022) evaluates with sMAPE because MAPE fails on zero-sale days (closures, holidays); a temporal fusion transformer forecast zero-sale days reliably and holidays ranked above most other features after weekly differencing ([MDPI](https://www.mdpi.com/2504-4990/4/1/6)).
- Kim (*Food Science and Biotechnology* 34(15), 2025) models ~384 menu items individually from POS + weather (daily mean temperature, precipitation) + calendar (day of year, holiday, weekend); mean Pearson r 0.79 across items, and its MAPE is meaningless (~272 000 %) because of near-zero actuals — the strongest evidence that item-level accuracy must be judged by MAE/WAPE, not MAPE. It cites Güler et al. 2023 (Prophet + weather + special days improved restaurant forecasts) and Chae et al. 2024 (external data improved ML/DL for a chain) ([PMC](https://pmc.ncbi.nlm.nih.gov/articles/PMC12528577/)).
- Daily customer flow 2019–2024: XGBoost with weather, public holidays and menu features was the most effective ([AIMS 2025](https://www.aimspress.com/article/doi/10.3934/aci.2025011?viewType=HTML)); a Bayesian canteen study notes most published work forecasts aggregates, not per-item daily sales ([arXiv 2005.12647](https://arxiv.org/pdf/2005.12647)).
- Food-retail perishables: Holt-Winters MAPE 4.97 % vs ARIMA 5.66 % ([ResearchGate](https://www.researchgate.net/publication/286314562_Demand_forecasting_in_food_retail_A_comparison_between_the_Holt-Winters_and_ARIMA_models)). Open benchmarks on daily sales: gradient boosting with lag/calendar features beats Holt-Winters by a few points of WAPE when promotions/holidays exist; Holt-Winters is ~10× cheaper and needs no features ([hbtabi/forecast-ai](https://github.com/hbtabi/forecast-ai), [AdityaMalik5](https://github.com/AdityaMalik5/Store-Sales-Forecasting)).

### 4.2 What the vendors do

Tenzo: past sales + weather + seasons + holidays, per-location factor weights, hourly segments; claims 30–50 % better than a 4-week average ([Tenzo](https://gotenzo.com/?p=4276)). Lineup.ai: POS history + weather + TV/events + foot traffic → sales, labour and item-level forecasts ([Lineup/PAR](https://www.businesswire.com/news/home/20201209005074/en)). 5-Out: needs 3–6 months of POS history, imports holiday/school/sports calendars, standardised item categories for item-level ([5-Out setup](https://aiindigo.com/tutorials/getting-started-with-5-out-automate-restaurant-labor-forecasting)). Crunchtime: the sales forecast drives prep and ordering ([Crunchtime](https://www.crunchtime.com/blog/how-to-translate-restaurant-sales-forecasts-into-better-prep-ordering-and-labor-planning)).

### 4.3 The methods, as formulas

**Baseline (same-weekday average)** — the honest floor and the cold-start method:

```
F(d) = mean( y(d − 7k) for k = 1..4 )           optionally weighted (0.4, 0.3, 0.2, 0.1)
```

**Day-of-week profile × level**: forecast weekly total with simple exponential smoothing, split by a DOW profile `p_dow = Σ y_dow / Σ y_week` over the last N weeks.

**Holt-Winters additive, m = 7** ([IMSL](https://help.imsl.com/java/current/apidocs/com/imsl/stat/HoltWintersExponentialSmoothing.html), [Nixtla](https://nixtlaverse.nixtla.io/statsforecast/docs/models/HoltWinters)):

```
L_t = α (y_t − S_{t−7}) + (1−α)(L_{t−1} + b_{t−1})
b_t = β (L_t − L_{t−1}) + (1−β) b_{t−1}
S_t = γ (y_t − L_t)     + (1−γ) S_{t−7}
F_{t+k} = L_t + k b_t + S_{t+k−7}
```
Needs ≥ 1 full cycle (2–3 weeks in practice) to initialise; multiplicative when the weekend swing grows with the level.

**Regression / gradient boosting**: target `y(item, d)`, features: DOW one-hot, week-of-year or Fourier terms, holiday flag and holiday-eve, closure flag, promotion flag, weather (temperature, precipitation; forecasted for the horizon), event flags, lags y(d−7), y(d−14), rolling means, item category, price. Train per site, predict per item; XGBoost/LightGBM in the papers above. Prophet = trend + weekly/yearly seasonality + holiday regressors, the same decomposition with less tuning.

**Explode to ingredients** (the step that makes a sales forecast an inventory projection):

```
ingredient_demand(i, d) = Σ_items F(item, d) × recipe_qty(item → i, through sub-recipes, yields applied)
                        + Σ_modifiers F(modifier, d) × modifier_qty
projected_on_hand(i, d) = on_hand_now + scheduled_receipts(≤ d) − Σ_{t ≤ d} ingredient_demand(i, t) − planned_waste
```

**New items, promotions, holidays**: a new item inherits its category's DOW profile and a seeded level (the operator's guess or a similar item), replaced by its own history after 3–4 weeks; a promotion is an uplift factor on the item (`promo(item, from, to, uplift)`), learned from past promotions when any exist; holidays are a calendar table with a per-holiday learned effect, plus a `closed` flag that forces zero and is excluded from training/error.

**Accuracy to store**: per item per horizon, `MAE`, `WAPE = Σ|y−F| / Σy`, `bias = Σ(F−y)/Σy`; never MAPE alone.

Columns: `sales_daily(site, item, pos_item, date, qty, net)`, `calendar(date, dow, holiday_id, closed, event_ids[])`, `weather_daily(site, date, t_mean, precip, forecasted?)`, `forecast(site, item, date, method, qty, lo, hi, made_at)`, `forecast_accuracy(...)`, `promotion(...)`.

---

## 5. Reorder logic and the suggested order

### 5.1 Par

Fixed par: `order = max(par − on_hand − on_order, 0)`. Dynamic par: par recomputed from the forecast for the cover period (Crunchtime: static or dynamic, driven by consumption and the sales forecast, checked against delivery dates; "Need − Have") ([Crunchtime suggested order](https://crunchtime.com/blog/benefits-of-suggested-order), [Restaurant365 pars](https://www.restaurant365.com/blog/how-to-set-up-par-levels-and-reorder-alerts-with-suppliers/)).

### 5.2 Periodic review (R, S) — the policy that matches vendor delivery days

Because a vendor delivers on fixed days, the review period R is the gap to the *next* order opportunity and the lead time L is cut-off → delivery ([MetricGate (R,S)](https://metricgate.com/docs/periodic-review-order-up-to-RS/), [Cleverence](https://www.cleverence.com/articles/for-business/replenishment-formula-7364/)):

```
protection_interval P = days from this delivery to the delivery after the NEXT order opportunity
S  = d̄ × P + z × σ_d × √P                    (σ_d daily, from the forecast residuals or history)
S  = μ_P + z × √(P σ_d² + d̄² σ_L²)           (when lead time varies)
IP = on_hand + on_order − backorders/commitments
Q  = max(S − IP, 0)
z  = Φ⁻¹(service level): 90 % → 1.28, 95 % → 1.645, 98 % → 2.05
```
With a forecast, replace `d̄ × P` by `Σ forecast demand over P` (the forecast carries the weekday shape) and keep the safety term. Units must match (daily σ with days; a σ from weekly buckets inflates safety stock by √7).

**Continuous reorder point** (for a vendor that delivers any day): `ROP = d̄ × L + z σ_d √L` ([Cleverence ROP](https://www.cleverence.com/articles/for-business/how-to-calculate-safety-stock-and-reorder-point-4827/)).

### 5.3 Perishables: the newsvendor cap

For an item that must sell within its shelf life, the order that maximises expected profit is the critical fractile ([Wikipedia](https://en.wikipedia.org/wiki/Newsvendor_model), [MetricGate](https://metricgate.com/calculator/newsvendor-critical-fractile)):

```
C_u = price − cost  (lost margin per unit short)      C_o = cost − salvage (= cost for food with no salvage)
CF  = C_u / (C_u + C_o)
Q*  = F⁻¹(CF)  = μ + z_CF σ for normal demand over the shelf-life window
```
For a $10.67 plate costing $3.20 with no salvage, CF = 7.47/10.67 = 0.70 → stock the 70th percentile of demand over the shelf life. The caveat (Halkos & Kevork) is that with a large coefficient of variation and demand truncated at zero the normal approximation misleads — use the empirical distribution of recent demand ([MPRA 40414](https://mpra.ub.uni-muenchen.de/40414/)).

### 5.4 Generating the suggested order, step by step

1. For each vendor whose **next cut-off** is approaching (`vendor_schedule(vendor, site, order_dow, cutoff_time, delivery_dow, lead_days)` — the field pattern of real SOPs: "Order due Saturday 1 PM for Tuesday delivery"; schema.org's `cutoffTime` adds a day when missed) ([Waverly Stone SOP](https://waverlystonepub.notion.site/Vendor-Ordering-Deadlines-Delivery-Schedule-2c7e3441fc2280d793cff6613f2bc023), [schema.org](https://bib.schema.org/cutoffTime)).
2. For each item on that vendor's **order guide** (`order_guide(vendor, site, item, vendor_item, preferred, seq)`), compute P = delivery date → the delivery after the next cut-off.
3. Need = Σ forecast ingredient demand over P (§4.3) + safety stock (or the dynamic par); Have = on_hand (perpetual, last count + ledger since) + on_order (open POs due before the delivery).
4. Q_raw = max(Need − Have, 0); for perishables, cap at the newsvendor Q* over shelf life (`item.shelf_life_days`).
5. Convert to the vendor's **order unit** and round: `cases = ceil(Q_raw / recipe_units_per_case)` (or allow split cases if `vendor_item.allow_split`), apply `vendor_item.min_order_qty` and `vendor.min_order_value`/free-delivery threshold.
6. Present one draft PO per vendor with the arithmetic shown per line (need, have, cap, rounding); a person edits and places it; an agent proposes, never sends (money-out pauses).

### 5.5 Multi-unit and commissary

The platforms treat the commissary either as a site that fulfils **transfers** (COGS-Well: deplete the "from" site, add to the "to" site, a transfer journal exported to the GL, optional labour/markup) or as a **vendor** the units order from (MarginEdge: order → invoice, exported as journal entries). Transfer at cost unless the units are separate legal entities ([COGS-Well transfers](https://cogs-well-inc.helpscoutdocs.com/article/388-transfers-faqs), [COGS-Well commissary](https://cogs-well-inc.helpscoutdocs.com/article/414-commissary-features-faqs), [MarginEdge](https://help.marginedge.com/hc/en-us/articles/360049203893)). A requisition is an internal PO: `requisition(from_site, to_site, lines, requested_for, status)` → `transfer(shipped_at, received_at, lines qty_sent, qty_received, unit_cost)`; the commissary's production (a batch recipe made in quantity) is a stock conversion: raw items out, finished item in at rolled-up cost.

---

## 6. Physical counts

- **Kinds**: full (every item, period end), cycle (a rolling subset weekly, high-value/high-velocity first), spot (one item/one area on demand). Cycle-count SOPs target 95–98 % accuracy per area ([Cleverence cycle count](https://www.cleverence.com/articles/business-blogs/how-to-cycle-count-4827/), [Supy](https://supy.io/blog/learn-restaurant-cycle-counting-vs-stocktake)).
- **Shelf-to-sheet**: the count template lists storage locations in walking order and items in shelf order within each (upper-left to lower-right), rearranged by drag-and-drop; a storage location is a folder; items can live in several locations ([Restaurant365](https://docs.restaurant365.com/docs/shelf-to-sheet-inventory), [QSROnline](https://support.qsronline.com/knowledge-base/1-client-website-adminuser-food-cost-web-counts/), [NetSuite](https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_157502179539.html)).
- **Count units**: each line is counted in whatever unit is on the shelf — cases, packs, eaches, weight, with partial cases as a decimal (2.5 cases) or tenths; the system converts to the item's base unit (`count_line(count, item, location, unit, qty, converted_base_qty)`).
- **Weight-based counting (bar and bulk)** ([Sculpture Hospitality](https://www.sculpturehospitality.com/blog/why-weighing-is-the-most-efficient-and-accurate-bar-inventory-method), [BevSpot kegs](https://bevspot.com/blog/how-to-weigh-draft-beer-kegs-for-bar-inventory-with-free-spreadsheet/), [Wasserstrom scale guide](https://www.wasserstrom.com/wcsstore/WasserstromCatalogAssetStore/Attachment/BC-10%20Specs.pdf)):

```
fraction_remaining = (gross_weight − empty_weight − pourer_weight) / (full_weight − empty_weight)
remaining_volume   = fraction_remaining × container_volume
   (or: liquid_weight / density_of_the_liquid, e.g. 0.95 g/ml for 40 % ABV spirits)
```
Columns: `container(item, full_weight_g, empty_weight_g, volume_ml, density)`, `count_line.gross_weight_g`, `count_line.pourer_tare_g`.
- **Freezing the count**: a count is `as_of` a timestamp; the book quantity to compare is `last_approved_count + ledger movements until as_of`; sales and receipts after `as_of` belong to the next period even if entered during the count. Secure the area (pause receiving/transfers) while counting ([MangoApps SOP](https://www.mangoapps.com/templates/sop/inventory-cycle-count)).
- **Blind counts, thresholds, approval**: hide the expected quantity; if |variance| exceeds a tolerance (per item: value or %), require a second blind recount by another person before the system reveals the book figure; then a manager **approves** the count, which writes `count_adjust` ledger rows and freezes the period's ending value ([Racklify](https://racklify.com/encyclopedia/when-numbers-dont-match-understanding-cycle-count-variance/)). Columns: `count(site, kind, template, as_of, started_by, status=open|recount|approved, approved_by, approved_at)`, `count_line.recount_qty`, `item.variance_tolerance_pct`, `item.variance_tolerance_value`.

---

## 7. Waste

**Taxonomy.** Leanpath tracks three stages — ingredient waste (trim, spoilage, expiration, damaged in delivery/storage), finished-product waste (overproduction: unserved batch, buffet, catering overage), plate waste — with loss reasons overproduction, trim waste, spoilage, contamination, overcooking/burned/dropped, expiration ([Leanpath stages](https://blog.leanpath.com/food-waste-tracking-stages), [Leanpath what to track](https://blog.leanpath.com/15-food-waste-questions-to-ask-your-kitchen-staff-right-now)). WRAP's hospitality method segregates spoilage / preparation / plate bins and weighs them; its sector split is 21 % / 45 % / 34 % and 75 % of outlet waste is avoidable ([Business Wales/WRAP](https://businesswales.gov.wales/documents/top-tips-improving-your-resource-efficiency-hospitality-and-food-services), [WRAP info sheet](https://www.sustainabilityexchange.ac.uk/files/wrap_info_sheet-_want_to_cut_costs.pdf)). ReFED names overproduction the leading back-of-house cause and supplier MOQs/fixed delivery schedules as drivers of over-stocking ([ReFED](https://refed.org/articles/refed-collaborates-with-menus-of-change-university-research-collaborative-on-new-repurposing-report-and-toolkit/), [ReFED Beyond the Plate](https://refed.org/downloads/refed-inc-beyond-the-plate-turning-food-waste-from-a-cost-into-an-opportunity-v2.pdf)). Toast adds spillage, refires and miscalculated portions ([Toast](https://pos.toasttab.com/blog/on-the-line/reduce-food-waste)). For the variance report the extra buckets that are *not* waste but explain it: staff meals, comps/promos, samples, transfers.

**The waste row**: `waste(site, at, who, station, stage=ingredient|finished|plate, reason, item_or_recipe, qty, unit, weight_g (if weighed, net of container tare), unit_cost_at_time, value, destination=landfill|compost|donation|staff_meal, note, photo?)`. Value is fixed at entry (the cost then), so the report does not drift when prices change.

**Feeding variance**: waste depletes the perpetual ledger (`kind=waste`) and so is inside actual usage; the variance report subtracts logged waste from the gap to show the *unexplained* remainder (§2.1). A recipe-level waste (a batch of soup) explodes to its ingredients at the recipe's quantities.

---

## 8. Purchasing and receiving

- **Order guides** per vendor per site: the items the site buys from that vendor, the vendor's item code, pack, price, preferred flag, sequence (the shape every platform uses; Crunchtime/R365 build the suggested order from it) ([Crunchtime inventory](https://www.crunchtime.com/inventory-management)). Brokers and split cases are vendor-item attributes (`allow_split`, `split_price`, `broker_id`).
- **Receiving against the PO**: per line, ordered qty/unit, received qty/unit, **actual weight** for catch-weight lines, substitution (`received_vendor_item ≠ ordered`), short-ship/overage, damaged/refused with reason, temperature at the door (`temp_f`, `probe`, `accepted`), lot and best-before from the GS1-128 label (§9). The HotSchedules receiving module requires actual weights before accepting a catch-weight line and prompts when the weight entered is far from the ordered weight ([HotSchedules](https://help.hotschedules.com/hc/en-us/articles/360053525351)). Infor's example: a PO at a per-pound replacement cost estimated $40.00, actual weights make it $36.00 ([Infor](https://docs.infor.com/sxe/2021.x/en-us/sxolh/eiw1589404147150.html)). Catch-weight invoice reconciliation compares PO, receiving record/weight label and invoice; the usual exceptions are estimated vs final weight, partials, substitutions and later credit/debit notes ([invoicedataextraction guide](https://invoicedataextraction.com/blog/catch-weight-invoice-reconciliation)).
- **Food-safety at receiving** (FDA Food Code 3-202.11 as adopted by the states): refrigerated TCS food ≤ 41 °F (5 °C) except where other law sets a limit (milk, molluscan shellfish); hot-shipped TCS ≥ 135 °F; shell eggs in equipment at ≤ 45 °F ambient; frozen food frozen solid; nothing with evidence of temperature abuse ([S.C. 61-25 §3-202](https://www.law.cornell.edu/regulations/south-carolina/R-61-25-3.202), [Minnesota 4626.0165](https://www.revisor.mn.gov/rules/4626.0165/), [Utah R392-102-12](https://www.law.cornell.edu/regulations/utah/Utah-Admin-Code-R392-102-12)). The receiving log is the HACCP record: `receipt_line.temp_f`, `receipt.checked_by`, `receipt.rejected_reason`.
- **Three-way match**: PO ↔ receipt ↔ invoice on quantity, unit price and item; tolerances per vendor (price %, qty); mismatches routed to a person; the invoice line is the price of record that updates `item_price_history` and the moving average ([SPS Commerce](https://www.spscommerce.com/wp-content/uploads/2019/09/SPS-Three-Way-Match-Invoice-Reconciliation.pdf), [Files.com 810](https://www.files.com/edi/810)). Freight/SAC charges not on the PO are a classic short-pay cause.
- **Price history and alerts**: every invoice line writes `(item, vendor, site, date, price, unit)`; an alert fires when `price > last_price × (1 + threshold)` or above the **contract price** (`vendor_contract(vendor, item, price, valid_from, valid_to, rebate_pct)`); a rebate is an accrual on purchases of the contracted items, posted per period.

---

## 9. Data standards

- **GTIN-14 and variable measure.** Each packaging level (each, inner pack, case, pallet) carries its own GTIN; trade items sold by variable measure that do not cross retail POS are identified by a GTIN-14 with **indicator digit 9**, and the variable-measure data is mandatory for complete identification ([GS1 UK](https://gs1uk.org/support-training/help-to-use-and-implement-gs1-standards/barcode-variable-measure-items), [GS1 Global Data Model guide](https://www.gs1.org/standards/gs1-global-data-model-attribute-implementation-guide/14)). Columns: `item_gtin(item, level=each|pack|case|pallet, gtin14, qty_of_child)`.
- **GS1-128 application identifiers** on the case label ([TEC-IT](https://www.tec-it.com/en/support/knowbase/gs1-application-identifier/Default.aspx), [GS1 NZ](https://support.gs1nz.org/hc/en-us/articles/360039409592-GS1-128-Basics)): `(01)` GTIN n14; `(02)` GTIN of contained items; `(10)` batch/lot an..20; `(11)` production date, `(13)` pack date, `(15)` best-before, `(17)` use-by/expiry — all YYMMDD; `(21)` serial; `(30)` variable count n..8; `(37)` count in a logistic unit; `(310n)` net weight kg with n decimals (`3102 001156` = 11.56 kg); `(320n)` net weight lb; `(392n)/(393n)` amount payable for variable measure; `(00)` SSCC n18; `(400)` customer PO number. A scanner at receiving therefore yields GTIN, lot, best-before and net weight in one read — the receipt line's `lot_no`, `best_before`, `actual_weight` come straight off it.
- **GDSN attributes for foodservice**: distributors (e.g. Gordon Food Service) require core, marketing, nutrition and allergen attributes plus images, published through a data pool; GS1's Trade Item Implementation Guideline describes the hierarchy and net-content attributes ([GFS GDSN policy](https://gfs.ca/wp-content/uploads/2025/11/NA-GDSN-Policy_Final.pdf), [GS1 TIIG](https://ref.gs1.org/guidelines/tiig/)). An item master should hold `net_content`, `net_content_uom`, `gross_weight`, `storage_temp_class`, `shelf_life_days`, `allergens[]`, `nutrients{}`, and the vendor's own `vendor_item_code` beside the GTIN.
- **EDI in foodservice**: 850 PO → 855 acknowledgement → 856 ASN → 810 invoice (X12), the sequence an AP three-way match consumes; the grocery UCS alternative is 875 (PO, segments G50/G68) and 880 (invoice), which grocery wholesalers often require instead of 850/810; 832 is the price/sales catalog. Common rejections: UPC/case code not in the supplier's master, quantity in the wrong pack unit, an 810 referencing an unknown PO or exceeding the 856's shipped quantity ([CASRAI](https://casrai.org/dictionary/term/electronic-data-interchange-edi), [Stacksync US Foods 810](https://www.stacksync.com/edi/us-foods/810), [Stacksync 875](https://www.stacksync.com/edi/labatt-food-service/labatt-food-service-via-edict-systems/x12-875-grocery-products-purchase-order), [Fulfil 880](https://www.fulfil.io/platform/edi/documents/880/)). A design that imports 810/880 invoice lines (or a distributor's CSV/API export) gets price history and receipts for free.
- **Nutrition**: USDA FoodData Central REST API (`api.nal.usda.gov/fdc/v1`), data types Foundation, SR Legacy, FNDDS, Branded; nutrients are per 100 g; recipe nutrition = Σ grams of each ingredient × nutrient per 100 g ÷ 100, through the same yield-adjusted gram weights the costing uses; legacy FDC ids are retired and replaced ([USDA MCP server docs](https://glama.ai/mcp/servers/cyanheads/usda-mcp-server), [tidyverse data dictionary](https://data-dict.tidyverse.org/examples/foodbank.html)). Column: `item.fdc_id`, `item.grams_per_recipe_unit`.
- **Allergens**: FDA's nine major allergens — milk, eggs, fish, crustacean shellfish, tree nuts, peanuts, wheat, soybeans, sesame (FASTER Act 2021, labelling from 2023-01-01) ([MSU Extension](https://www.canr.msu.edu/news/the-faster-act-sesame-as-major-food-allergen)); EU Regulation 1169/2011 Annex II's fourteen — cereals containing gluten, crustaceans, eggs, fish, peanuts, soybeans, milk, nuts, celery, mustard, sesame, sulphur dioxide/sulphites > 10 mg/kg or mg/l, lupin, molluscs ([FSAI](https://www.fsai.ie/Business-Advice/Labelling/Food-Information-to-Consumers)). Allergens roll up through the recipe tree exactly as cost does (`recipe.allergens = ∪ lines' allergens`), with "may contain" as a separate flag.
- **Lot/expiry, FIFO rotation, recall**: `receipt_line.lot_no`, `.best_before`, `.use_by` from the label; stock by lot where it matters (proteins, dairy, seafood); FIFO is a *rotation* rule (issue the earliest best-before first) and an expiry report; a recall is a query "every receipt line with GTIN g and lot l, where it went (transfers, waste, counts), and what recipes consumed it since".

---

## 10. Alcohol specifics

- **Containers and pours**: a 750 ml bottle is 25.36 US fl oz; at a 1.5 oz pour that is 16.9 pours (16 full); 1 L = 33.8 oz = 22.5 pours; 1.75 L = 59.2 oz = 39.5 pours. Wine by the glass: 750 ml ÷ 5 oz (148 ml) ≈ 5 glasses, ÷ 6 oz ≈ 4.2. The bottle's `full_weight_g`, `empty_weight_g`, `volume_ml` are the counting facts (§6).
- **Kegs** ([Wikipedia](https://en.wikipedia.org/wiki/Keg)): half barrel 15.5 US gal = 58.67 L = 1 984 oz (165 × 12 oz, 124 × 16 oz); quarter barrel 7.75 gal = 992 oz; sixth barrel ("sixtel") ≈ 5.17 gal = 19.6 L ≈ 661 oz (55 × 12 oz); Cornelius 5 gal; Euro 50 L = 13.2 gal ≈ 1 691 oz. Sources disagree on the sixtel (5.16–5.2 gal appears most consistently). A keg is counted by weight like a bottle (empty keg tare ~ 13.5 kg for a half barrel, vendor-specific — store it per keg type) ([BevSpot kegs](https://bevspot.com/blog/how-to-weigh-draft-beer-kegs-for-bar-inventory-with-free-spreadsheet/)).
- **Pour cost** ([BevSpot](https://bevspot.com/blog/bar-management-pricing-drinks-pour-costs/), [PourMyBeer](https://pourmybeer.com/how-to-price-beer-wine-and-liquor-in-your-bar-or-restaurant/), [GetSauce](https://www.getsauce.com/post/pouring-on-the-profits-understanding-and-managing-your-restaurant-s-beverage-costs)):

```
drink_cost   = Σ (pour_oz × bottle_cost / bottle_oz) + mixers + garnish
pour_cost_%  = drink_cost / sale_price × 100          (per drink)
pour_cost_%  = beverage_COGS / beverage_sales × 100   (per period, by category)
price_from_target = drink_cost / target_pour_cost_%
```
Benchmarks quoted: liquor 18–24 % (some say 14–20 %), draft beer ~20 %, bottled beer ~25 %, wine 22–40 % (commonly ~30 %), overall 20–25 %; shrinkage around 20 % of liquor is the industry's rule of thumb and is why actual pour cost exceeds theoretical ([BevSpot study](https://bevspot.com/?p=10132)). Categories for the report: liquor (by spirit type), draft beer, packaged beer, wine by the glass, wine by the bottle, non-alcoholic; `item.beverage_category`, `item.abv`, `recipe_line.pour_oz`. Comps, spills and over-pours are waste reasons (§7) specific to the bar; theoretical bar usage comes from POS drink sales × pour recipes exactly as food does (§2).

---

### Sources not fetched in full

The BC Campus/opentextbc original, the FIU Hospitality Review PDF, the MDPI article page and GS1 UK's page returned 403 to the fetcher; their content above comes from the LibreTexts mirror, the search engine's extracts and the Wikipedia/TEC-IT copies of the same material, and is marked where it is a secondary reading. The Lasek et al. survey PDF was fetched but is image-only; its section list (methods 3.1–3.8) is from the document outline.
