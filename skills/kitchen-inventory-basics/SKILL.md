---
name: kitchen-inventory-basics
description: What Restaurant Inventory is and how an agent works in it — what an ingredient, a unit, a recipe, a menu item, a depletion, a count, a period and a site are; which tool answers what; cost is the wall; what pauses. The one page every agent that reaches Restaurant Inventory reads first.
---

# Restaurant Inventory — the basics for an agent

A kitchen's and a bar's stock desk. Every restaurant is a **site**; you work at the sites you are granted and you say which
site a number is from. You read what your role may see, you draft, you propose; the kernel ledgers every call and pauses
the few actions that spend money or put a person's word on the books.

## The words
| Word | Means |
|---|---|
| **Ingredient** | Anything the restaurant stocks: a case of romaine, a bottle of gin, a roll of film. A **measure class** (weight, volume, each), a **base unit** (g, ml, each — every stored quantity is in it), a **recipe unit**, **purchase units** per supplier, **count units**, a **yield %** at receipt, a shelf life, a catch-weight flag. Shared across sites; pars, costs and stock per site. |
| **Unit** | A global table within a class (lb = 453.592 g). Across classes only through the ingredient's own density or each-weight. **A case is never "1 each"** — it holds 6 × #10 cans, or 50 lb. |
| **Recipe** | `menu_item` (a plate), `prep` (a batch that makes stock of an ingredient), `modifier` (add/remove/swap). **Versions** with effective dates; lines name an ingredient **or another recipe**, with a quantity in any unit and a line yield. Cost is computed as of a date, never stored on the version. |
| **Menu item** | What the POS sells, by **PLU**, mapped to one recipe; modifiers mapped to modifier recipes. A PLU with no recipe is the **unmapped queue**. |
| **Depletion** | The backflush of a day's sales through the recipes in force that day: one run per site per business date, one ledger row per ingredient per area. Voids never deplete; comps always do. |
| **Ledger** | Receipts, depletion, waste, transfers, production, count corrections, adjustments — signed, immutable, reversed never edited; the balance per ingredient per **storage area** kept only by it; weighted average cost advanced by receipts. |
| **Count** | A sheet per storage area in shelf order, counted in the unit on the shelf (case + loose, decimals, a bottle by weight); review, recount over tolerance, a Chef's approval posts the corrections. |
| **Usage** | **Actual** = opening + purchases + transfers in + production in − transfers out − production out − closing. **Theoretical** = Σ sales × recipe. Variance = actual − theoretical; waste, comps and transfers explain part; the rest is unexplained. |
| **Period** | The costing calendar's unit (a week by default, monthly roll-up). Closes on an approved count; freezes the ending value and COGS; a late receipt lands in the next open period, flagged. |
| **Projection** | Sales forecast per menu item per day → exploded through recipes → ingredient demand → projected stock per day → one **suggested order** per supplier for its next delivery, the arithmetic on every line. |

## Which tool answers what
| You want | Tool |
|---|---|
| Anything by name, code, PLU or SKU | `find`, `records_search` |
| An ingredient, its units, its cost (basis and date), its history, the cheapest supplier | `get_ingredient`, `ingredient_units`, `ingredient_cost`, `cost_history`, `cheapest_supplier` |
| On hand by area; movements; value; days on hand | `stock_on_hand`, `stock_by_area`, `stock_movements`, `stock_value`, `days_on_hand` |
| A recipe, its tree, its cost as of a date, what-if, who uses an ingredient, the version in force on a date | `get_recipe`, `recipe_tree`, `recipe_cost`, `recipe_what_if`, `recipes_using`, `recipe_version_at` |
| The menu map and the unmapped queue; menu engineering | `find_menu_items`, `unmapped_plus`, `menu_engineering` |
| Sales by day and item; the runs; the POS's health | `sales_by_day`, `sales_by_item`, `depletion_runs`, `pos_health` |
| Actual vs theoretical; the worst lines; food-cost %; periods | `usage_report`, `variance_top`, `food_cost_pct`, `periods` |
| Counts, lines, variance, areas skipped | `counts`, `get_count`, `count_lines`, `count_variance`, `areas_skipped` |
| Waste by stage and reason | `waste_logs`, `waste_summary` |
| Suppliers, items, schedules, order guides | `find_suppliers`, `supplier_items`, `supplier_schedules`, `order_guides` |
| The suggested orders; POs; deliveries due; invoices and mismatches | `suggested_orders`, `purchase_orders_open`, `deliveries_due`, `invoice_mismatches` |
| The forecast, demand, projected stock, accuracy, "can we run the special" | `item_forecast`, `ingredient_demand`, `projected_stock`, `forecast_accuracy`, `can_run_special` |
| A record's history; who did what | `record_history`, `actor_timeline` |

## Rules
- **Every number carries its unit and its site.** Say the base unit beside the count unit.
- **Cost is the wall**: shown to Chef and above; "cost withheld" means say so and go on.
- **What pauses** (ask only when the person asked for exactly that): `purchase_order_send` (money), `count_approve`. What you
  never do: close a period, settle an invoice, activate a version, override a cost or a par, delete, adjust without a
  document, void a run, touch a credential or the settings.
- Read the record before you draft it; say where each fact came from (the invoice, the count, the run, the version).
