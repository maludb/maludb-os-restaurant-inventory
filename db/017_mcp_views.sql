-- 017: WHO SEES WHAT — the mcp_* visibility views, the only thing the two read roles see (memory.md §1; design §3).
-- Every WHERE clause IS the row rule; the servers never filter in Python. Caller checks are scalar subqueries tested ONCE
-- per statement: (SELECT ri_is_member_here()), the site through ri_held_scope_ids(), cost through ri_sees_cost(scope).
--
-- COST IS THE WALL: every cost column is nulled unless ri_sees_cost(the row's site). A SOURCE'S CREDENTIAL IS NOBODY'S:
-- mcp_source_credentials exposes id, source, kind, label, last4 — never the ciphertext. A token hash, a link hash and an
-- outbox body are not selected. Shared catalog rows (ingredients, recipes, suppliers, units) are visible to every member
-- here; site rows only at the sites held. The primary key is re-aliased <entity>_id.
-- The writer role gets every table (one shape for screens and tools; the handlers go through the functions).
BEGIN;

CREATE OR REPLACE FUNCTION mcp_admit_agent(p_member_id bigint) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER SET search_path = public AS $$
DECLARE n integer;
BEGIN
    UPDATE members SET capability = 'write', synced_at = now() WHERE id = p_member_id AND member_kind = 'agent' AND status = 'active' AND capability IS NULL;
    GET DIAGNOSTICS n = ROW_COUNT;
    RETURN n = 1;
END$$;
REVOKE ALL ON FUNCTION mcp_admit_agent(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_admit_agent(bigint) TO restaurant_inventory_records_ro, restaurant_inventory_activity_ro, restaurant_inventory_rw;
CREATE OR REPLACE FUNCTION mcp_member_kind(p_member_id bigint) RETURNS text
    LANGUAGE sql STABLE SECURITY DEFINER SET search_path = public AS $$ SELECT member_kind FROM members WHERE id = p_member_id AND status = 'active' AND capability IS NOT NULL $$;
REVOKE ALL ON FUNCTION mcp_member_kind(bigint) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION mcp_member_kind(bigint) TO restaurant_inventory_records_ro, restaurant_inventory_activity_ro, restaurant_inventory_rw;

-- The sites where the caller sees cost (ri_held_scope_ids() is the kit's). The views test them as ARRAY(SELECT …) — an
-- InitPlan evaluated once per statement (the kernel's db/160 lesson).
CREATE OR REPLACE FUNCTION ri_cost_site_ids() RETURNS SETOF bigint
    LANGUAGE sql STABLE AS $$ SELECT s FROM ri_held_scope_ids() s WHERE ri_sees_cost(s) $$;

-- People, sites, settings, vocabulary.
CREATE OR REPLACE VIEW mcp_members WITH (security_barrier = true) AS
SELECT m.id AS member_id, m.display_name, m.member_kind, m.is_agent, m.business_role, m.is_external, m.status, m.capability, m.job_title, m.timezone,
       CASE WHEN m.id = app_current_member_id() OR (SELECT ri_is_admin()) THEN m.email::text END AS email,
       (SELECT array_agg(dm.department_id) FROM department_members dm WHERE dm.member_id = m.id AND dm.left_at IS NULL) AS department_ids,
       (SELECT jsonb_agg(jsonb_build_object('scope_id', r.scope_id, 'roles', r.roles)) FROM member_site_roles r WHERE r.member_id = m.id AND r.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()))) AS sites
  FROM members m WHERE m.status = 'active' AND m.capability IS NOT NULL AND (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_departments WITH (security_barrier = true) AS
SELECT d.id AS department_id, d.name, d.description, d.parent_id, d.manager_member_id, d.is_system, d.system_key, d.archived_at FROM departments d WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_sites WITH (security_barrier = true) AS
SELECT s.scope_id, s.location_id, s.name, s.address, s.timezone, s.removed_at, ss.kitchen_sees_cost, ss.depletion_area_id, ss.sales_cut_time, ss.manager_member_id, ss.temperature_check
  FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_day_parts WITH (security_barrier = true) AS
SELECT d.id AS day_part_id, d.scope_id, d.key, d.name, d.starts_at, d.ends_at, d.service_name FROM day_parts d WHERE d.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_settings WITH (security_barrier = true) AS
SELECT s.* FROM ri_settings s WHERE s.id = 1 AND (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_units WITH (security_barrier = true) AS
SELECT u.code AS unit_code, u.name, u.measure_class, u.to_base_factor, u.is_base, u.display_order, u.active FROM units u WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_ingredient_categories WITH (security_barrier = true) AS
SELECT c.id AS category_id, c.key, c.name, c.kind, c.parent_id, c.safety_stock_pct, c.price_alert_pct, c.temperature_check, c.sort_order, c.active FROM ingredient_categories c WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_menu_categories WITH (security_barrier = true) AS
SELECT c.id AS category_id, c.key, c.name, c.kind, c.sort_order, c.active FROM menu_categories c WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_reason_codes WITH (security_barrier = true) AS
SELECT r.id AS reason_code_id, r.code, r.name, r.applies_to, r.stage, r.sort_order, r.active FROM reason_codes r WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_document_sequences WITH (security_barrier = true) AS
SELECT d.* FROM document_sequences d WHERE (SELECT ri_has_right_anywhere('sequences.manage'));
CREATE OR REPLACE VIEW mcp_tax_rates WITH (security_barrier = true) AS
SELECT t.id AS tax_rate_id, t.name, t.rate, t.is_default, t.archived_at FROM tax_rates t WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_attachments WITH (security_barrier = true) AS
SELECT a.id AS attachment_id, a.record_type, a.record_id, a.filename, a.mime_type, a.byte_size, a.uploaded_by, a.created_at FROM attachments a WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_notes WITH (security_barrier = true) AS
SELECT n.id AS note_id, n.record_type, n.record_id, n.body, n.member_id, n.created_at FROM notes n WHERE (SELECT ri_is_member_here());

-- The catalog: shared across sites; cost per site through mcp_ingredient_site_costs.
CREATE OR REPLACE VIEW mcp_ingredients WITH (security_barrier = true) AS
SELECT i.id AS ingredient_id, i.code, i.name, i.category_id, i.measure_class, i.base_unit_code, i.recipe_unit_code, i.density_g_per_ml, i.each_weight_g, i.each_volume_ml, i.yield_pct, i.catch_weight,
       i.shelf_life_days, i.costing_method, i.variance_tolerance_pct, i.variance_tolerance_value, i.allergens, i.is_prep, i.notes, i.active, i.archived_at, i.created_at, i.updated_at
  FROM ingredients i WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_ingredient_units WITH (security_barrier = true) AS
SELECT u.id AS ingredient_unit_id, u.ingredient_id, u.unit_code, u.name, u.to_base_factor, u.kind, u.is_default_count, u.active FROM ingredient_units u WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_ingredient_site_settings WITH (security_barrier = true) AS
SELECT s.ingredient_id, s.scope_id, s.par_base, s.par_mode, s.reorder_min_base, s.service_level, s.preferred_supplier_item_id, CASE WHEN s.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN s.cost_override_per_base END AS cost_override_per_base, s.default_area_id, s.active
  FROM ingredient_site_settings s WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_ingredient_site_costs WITH (security_barrier = true) AS
SELECT c.ingredient_id, c.scope_id, CASE WHEN c.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN c.avg_cost_per_base END AS avg_cost_per_base,
       CASE WHEN c.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN c.last_cost_per_base END AS last_cost_per_base, c.last_cost_at, c.on_hand_base,
       CASE WHEN c.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN c.on_hand_value END AS on_hand_value, c.updated_at
  FROM ingredient_site_costs c WHERE c.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_price_history WITH (security_barrier = true) AS
SELECT h.id AS price_history_id, h.ingredient_id, h.scope_id, h.old_per_base, h.new_per_base, h.per_unit_code, h.per_unit_price, h.source_kind, h.reference_kind, h.reference_id, h.reason, h.changed_by, h.changed_at
  FROM price_history h WHERE (h.scope_id IS NULL AND cardinality(ARRAY(SELECT ri_cost_site_ids())) > 0) OR h.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids()));
CREATE OR REPLACE VIEW mcp_yield_tests WITH (security_barrier = true) AS
SELECT y.id AS yield_test_id, y.ingredient_id, y.ap_qty_base, y.ep_qty_base, y.trim_loss_pct, y.cook_loss_pct, y.byproducts, y.yield_pct, y.applied, y.notes, y.tested_by, y.tested_at FROM yield_tests y WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_containers WITH (security_barrier = true) AS
SELECT c.id AS container_id, c.ingredient_id, c.name, c.full_weight_g, c.empty_weight_g, c.pourer_tare_g, c.volume_ml, c.is_default, c.active FROM containers c WHERE (SELECT ri_is_member_here());

-- Suppliers (terms are Manager's and above: suppliers.write), their items (cost columns walled), schedules, guides.
CREATE OR REPLACE VIEW mcp_suppliers WITH (security_barrier = true) AS
SELECT s.id AS supplier_id, s.name, s.kind, s.contact_name, s.email, s.phone, s.address, s.notes, CASE WHEN (SELECT ri_has_right_anywhere('suppliers.write')) THEN s.terms_days END AS terms_days,
       s.lead_time_days, s.order_method, s.order_email, s.portal_url, s.min_order_value, s.allow_split_case, s.match_tolerance_pct, s.match_tolerance_value,
       CASE WHEN (SELECT ri_has_right_anywhere('suppliers.write')) THEN s.account_number END AS account_number, s.rep_name, s.rep_phone, s.commissary_scope_id, s.active, s.created_at, s.updated_at
  FROM suppliers s WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_supplier_schedules WITH (security_barrier = true) AS
SELECT s.id AS supplier_schedule_id, s.supplier_id, s.scope_id, s.order_dow, s.cutoff_time, s.delivery_dow, s.lead_days, s.active FROM supplier_schedules s WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_supplier_items WITH (security_barrier = true) AS
SELECT i.id AS supplier_item_id, i.supplier_id, i.ingredient_id, i.supplier_sku, i.description, i.purchase_unit_code, i.pack,
       CASE WHEN cardinality(ARRAY(SELECT ri_cost_site_ids())) > 0 THEN i.last_price END AS last_price, i.last_price_at,
       CASE WHEN cardinality(ARRAY(SELECT ri_cost_site_ids())) > 0 THEN i.contract_price END AS contract_price, i.contract_until, i.moq, i.catch_weight, i.lead_time_days, i.active
  FROM supplier_items i WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_order_guides WITH (security_barrier = true) AS
SELECT g.id AS order_guide_id, g.supplier_id, g.scope_id, g.name, g.active, (SELECT jsonb_agg(jsonb_build_object('supplier_item_id', l.supplier_item_id, 'seq', l.seq, 'preferred', l.preferred) ORDER BY l.seq) FROM order_guide_lines l WHERE l.guide_id = g.id) AS lines
  FROM order_guides g WHERE g.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_storage_areas WITH (security_barrier = true) AS
SELECT a.id AS storage_area_id, a.scope_id, a.name, a.kind, a.walk_order, a.allow_negative, a.active FROM storage_areas a WHERE a.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));

-- Recipes: quantities for everyone here; cost is computed by the tools through ri_recipe_cost at a held site with cost.
CREATE OR REPLACE VIEW mcp_recipes WITH (security_barrier = true) AS
SELECT r.id AS recipe_id, r.code, r.name, r.kind, r.category_id, r.output_ingredient_id, r.portion_qty, r.portion_unit_code, r.method, r.photo_attachment_id, r.allergens_rollup, r.active, r.archived_at, r.created_at, r.updated_at,
       ri_recipe_version_on(r.id, current_date) AS current_version_id
  FROM recipes r WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_recipe_versions WITH (security_barrier = true) AS
SELECT v.id AS recipe_version_id, v.recipe_id, v.version_no, v.status, v.effective_from, v.effective_to, v.batch_yield_qty, v.batch_yield_unit_code, v.expected_loss_pct, v.change_note, v.activated_at, v.activated_by, v.created_by, v.created_at
  FROM recipe_versions v WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_recipe_lines WITH (security_barrier = true) AS
SELECT l.id AS recipe_line_id, l.version_id, l.seq, l.ingredient_id, l.sub_recipe_id, l.qty, l.unit_code, l.qty_base, l.line_yield_pct, l.group_name, l.optional, l.notes FROM recipe_lines l WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_menu_items WITH (security_barrier = true) AS
SELECT m.id AS menu_item_id, m.pos_source_id, m.plu, m.name, m.category_id, m.sale_price, m.recipe_id, m.portions, m.active, m.first_seen_at, m.last_sold_at, m.mapped_by, m.mapped_at
  FROM menu_items m WHERE (SELECT ri_is_member_here()) AND (m.pos_source_id IS NULL OR m.pos_source_id IN (SELECT id FROM sources WHERE scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()))));
CREATE OR REPLACE VIEW mcp_menu_item_prices WITH (security_barrier = true) AS
SELECT p.menu_item_id, p.scope_id, p.price, p.effective_from FROM menu_item_prices p WHERE p.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_modifiers WITH (security_barrier = true) AS
SELECT m.id AS modifier_id, m.pos_source_id, m.code, m.name, m.action, m.recipe_id, m.qty_factor, m.active, m.first_seen_at FROM modifiers m
 WHERE (SELECT ri_is_member_here()) AND (m.pos_source_id IS NULL OR m.pos_source_id IN (SELECT id FROM sources WHERE scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()))));
CREATE OR REPLACE VIEW mcp_menu_item_modifiers WITH (security_barrier = true) AS
SELECT mm.menu_item_id, mm.modifier_id, mm.qty_factor, mm.recipe_id FROM menu_item_modifiers mm WHERE (SELECT ri_is_member_here());

-- The ledger and the documents: at the sites held; cost nulled where not seen.
CREATE OR REPLACE VIEW mcp_inventory_balances WITH (security_barrier = true) AS
SELECT b.ingredient_id, b.storage_area_id, a.scope_id, b.qty_base, b.updated_at FROM inventory_balances b JOIN storage_areas a ON a.id = b.storage_area_id WHERE a.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_inventory_transactions WITH (security_barrier = true) AS
SELECT t.id AS transaction_id, t.scope_id, t.group_id, t.txn_type, t.ingredient_id, t.storage_area_id, t.qty_base,
       CASE WHEN t.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN t.unit_cost_per_base END AS unit_cost_per_base, CASE WHEN t.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN t.value END AS value,
       t.counterparty_kind, t.counterparty_id, t.reason_code_id, t.reference_kind, t.reference_id, t.reverses_id, t.note, t.occurred_at, t.business_date, t.posted_at, t.actor_member_id
  FROM inventory_transactions t WHERE t.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_goods_receipts WITH (security_barrier = true) AS
SELECT g.id AS goods_receipt_id, g.number, g.scope_id, g.supplier_id, g.purchase_order_id, g.storage_area_id, g.status, g.delivery_note_ref, g.invoice_ref, g.received_on, g.received_at, g.temperature_ok, g.notes, g.posted_by, g.posted_at, g.created_by, g.created_at
  FROM goods_receipts g WHERE g.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_goods_receipt_lines WITH (security_barrier = true) AS
SELECT l.id AS goods_receipt_line_id, l.goods_receipt_id, g.scope_id, l.line_no, l.purchase_order_line_id, l.ingredient_id, l.supplier_item_id, l.qty_received, l.purchase_unit_code, l.qty_base, l.weight_qty, l.weight_unit_code,
       CASE WHEN g.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN l.unit_cost END AS unit_cost, CASE WHEN g.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN l.cost_per_base END AS cost_per_base,
       l.discrepancy_kind, l.discrepancy_note, l.temperature_c, l.temperature_ok, l.putaway_area_id
  FROM goods_receipt_lines l JOIN goods_receipts g ON g.id = l.goods_receipt_id WHERE g.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_inventory_transfers WITH (security_barrier = true) AS
SELECT t.id AS transfer_id, t.number, t.scope_id, t.to_scope_id, t.from_area_id, t.to_area_id, t.status, t.notes, t.shipped_by, t.shipped_at, t.received_by, t.received_at, t.created_by, t.created_at,
       (SELECT jsonb_agg(jsonb_build_object('line_id', l.id, 'ingredient_id', l.ingredient_id, 'qty', l.qty, 'unit_code', l.unit_code, 'qty_base', l.qty_base, 'qty_received_base', l.qty_received_base) ORDER BY l.line_no) FROM inventory_transfer_lines l WHERE l.transfer_id = t.id) AS lines
  FROM inventory_transfers t WHERE t.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids())) OR t.to_scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_inventory_adjustments WITH (security_barrier = true) AS
SELECT a.id AS adjustment_id, a.number, a.scope_id, a.storage_area_id, a.reason_code_id, a.status, a.notes, a.posted_by, a.posted_at, a.created_by, a.created_at,
       (SELECT jsonb_agg(jsonb_build_object('line_id', l.id, 'ingredient_id', l.ingredient_id, 'qty_delta', l.qty_delta, 'unit_code', l.unit_code, 'qty_delta_base', l.qty_delta_base, 'note', l.note) ORDER BY l.line_no) FROM inventory_adjustment_lines l WHERE l.adjustment_id = a.id) AS lines
  FROM inventory_adjustments a WHERE a.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_production_batches WITH (security_barrier = true) AS
SELECT b.id AS production_batch_id, b.number, b.scope_id, b.recipe_id, b.recipe_version_id, b.batches, b.output_qty_base, b.storage_area_id, b.consume_area_id, b.status,
       CASE WHEN b.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN b.cost_total END AS cost_total, b.made_by, b.made_at, b.notes, b.posted_by, b.posted_at, b.created_at
  FROM production_batches b WHERE b.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_waste_logs WITH (security_barrier = true) AS
SELECT w.id AS waste_log_id, w.number, w.scope_id, w.logged_at, w.business_date, w.logged_by, w.station, w.day_part_id, w.stage, w.reason_code_id, w.ingredient_id, w.recipe_id, w.qty, w.unit_code, w.qty_base,
       w.gross_weight_g, w.tare_g, w.storage_area_id, CASE WHEN w.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN w.cost END AS cost, w.disposition, w.photo_attachment_id, w.notes, w.status, w.reviewed_by, w.reviewed_at
  FROM waste_logs w WHERE w.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));

-- Purchasing.
CREATE OR REPLACE VIEW mcp_purchase_orders WITH (security_barrier = true) AS
SELECT p.id AS purchase_order_id, p.number, p.scope_id, p.supplier_id, p.status, p.origin, p.storage_area_id, p.ordered_on, p.cutoff_at, p.expected_on, p.supplier_order_ref, p.sent_via, p.sent_at, p.sent_by, p.acknowledged_at,
       CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.subtotal END AS subtotal, CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.total END AS total,
       p.notes, CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.internal_notes END AS internal_notes, p.closed_at, p.cancelled_at, p.cancel_reason, p.created_by, p.created_at, p.updated_at
  FROM purchase_orders p WHERE p.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_purchase_order_lines WITH (security_barrier = true) AS
SELECT l.id AS purchase_order_line_id, l.purchase_order_id, p.scope_id, l.line_no, l.ingredient_id, l.supplier_item_id, l.supplier_sku, l.purchase_unit_code, l.qty_ordered, l.qty_ordered_base,
       CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN l.unit_cost END AS unit_cost, l.qty_received_base, l.expected_on, l.status, l.supplier_note,
       l.need_base, l.have_base, l.on_order_base, l.cap_base, l.safety_base, l.raw_base, l.rounding_note
  FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.purchase_order_id WHERE p.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_purchase_order_events WITH (security_barrier = true) AS
SELECT e.id AS event_id, e.purchase_order_id, p.scope_id, e.purchase_order_line_id, e.kind, e.source, e.supplier_order_ref, e.expected_on, e.reason, e.note, e.member_id, e.created_at
  FROM purchase_order_events e JOIN purchase_orders p ON p.id = e.purchase_order_id WHERE p.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_supplier_invoices WITH (security_barrier = true) AS
SELECT i.id AS invoice_id, i.number, i.scope_id, i.supplier_id, i.invoice_no, i.kind, i.invoice_date, i.received_on, i.purchase_order_id, i.goods_receipt_id, i.subtotal, i.tax, i.total, i.status, i.attachment_id, i.notes, i.settled_by, i.settled_at, i.created_by, i.created_at
  FROM supplier_invoices i WHERE i.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids()));
CREATE OR REPLACE VIEW mcp_supplier_invoice_lines WITH (security_barrier = true) AS
SELECT l.id AS invoice_line_id, l.invoice_id, i.scope_id, l.line_no, l.supplier_item_id, l.ingredient_id, l.description, l.qty, l.unit_code, l.unit_price, l.line_total, l.receipt_line_id, l.match_state, l.diff_qty, l.diff_value, l.settle_note
  FROM supplier_invoice_lines l JOIN supplier_invoices i ON i.id = l.invoice_id WHERE i.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids()));
CREATE OR REPLACE VIEW mcp_price_alerts WITH (security_barrier = true) AS
SELECT a.id AS price_alert_id, a.scope_id, a.ingredient_id, a.supplier_item_id, a.old_per_base, a.new_per_base, a.pct, a.price_history_id, a.raised_at, a.seen_by, a.seen_at
  FROM price_alerts a WHERE (a.scope_id IS NULL AND cardinality(ARRAY(SELECT ri_cost_site_ids())) > 0) OR a.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids()));

-- The POS: sources without credentials; sales at the sites held.
CREATE OR REPLACE VIEW mcp_sources WITH (security_barrier = true) AS
SELECT s.id AS source_id, s.scope_id, s.name, s.connector, s.role, s.base_url, CASE WHEN (SELECT ri_is_admin(s.scope_id)) THEN s.settings END AS settings, s.credential_id, s.schedule_minutes, s.last_pull_id, s.last_ok_at,
       s.consecutive_failures, s.backoff_until, s.paused_at, s.paused_reason, s.active, s.created_at, s.updated_at
  FROM sources s WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_source_credentials WITH (security_barrier = true) AS
SELECT c.id AS credential_id, c.source_id, s.scope_id, c.kind, c.label, c.last4, c.rotated_at, c.created_at FROM source_credentials c JOIN sources s ON s.id = c.source_id WHERE (SELECT ri_is_admin(s.scope_id));
CREATE OR REPLACE VIEW mcp_source_pulls WITH (security_barrier = true) AS
SELECT p.id AS pull_id, p.source_id, s.scope_id, p.kind, p.started_at, p.finished_at, p.status, p.file_name, p.dates_seen, p.sales_seen, p.sales_new, p.lines_seen, p.plus_new, p.error, p.started_by
  FROM source_pulls p JOIN sources s ON s.id = p.source_id WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_sales_file_mappings WITH (security_barrier = true) AS SELECT m.* FROM sales_file_mappings m WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_pos_sales WITH (security_barrier = true) AS
SELECT s.id AS sale_id, s.scope_id, s.source_id, s.external_id, s.business_date, s.day_part_id, s.closed_at, s.covers, s.subtotal, s.tax, s.total, s.comps, s.voids, s.pull_id, s.imported_at
  FROM pos_sales s WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_pos_sale_lines WITH (security_barrier = true) AS
SELECT l.id AS sale_line_id, l.sale_id, s.scope_id, s.business_date, l.external_line_id, l.plu, l.menu_item_id, l.name, l.qty, l.unit_price, l.line_total, l.voided, l.comp, l.modifiers
  FROM pos_sale_lines l JOIN pos_sales s ON s.id = l.sale_id WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_sales_daily WITH (security_barrier = true) AS
SELECT sd.scope_id, sd.menu_item_id, sd.business_date, sd.day_part_id, sd.qty, sd.net, sd.comps_qty FROM sales_daily sd WHERE sd.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_depletion_runs WITH (security_barrier = true) AS
SELECT r.id AS depletion_run_id, r.scope_id, r.business_date, r.status, r.lines_total, r.lines_depleted, r.lines_unmapped, r.lines_voided, r.modifiers_unmapped, r.ingredients,
       CASE WHEN r.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN r.value END AS value, r.run_no, r.started_at, r.finished_at, r.started_by, r.note
  FROM depletion_runs r WHERE r.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));

-- Counts.
CREATE OR REPLACE VIEW mcp_count_sheets WITH (security_barrier = true) AS
SELECT s.id AS count_sheet_id, s.scope_id, s.name, s.kind, s.active, s.created_by, s.created_at,
       (SELECT jsonb_agg(jsonb_build_object('line_id', l.id, 'storage_area_id', l.storage_area_id, 'ingredient_id', l.ingredient_id, 'shelf_order', l.shelf_order, 'count_unit_code', l.count_unit_code) ORDER BY l.storage_area_id, l.shelf_order) FROM count_sheet_lines l WHERE l.sheet_id = s.id) AS lines
  FROM count_sheets s WHERE s.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_inventory_counts WITH (security_barrier = true) AS
SELECT c.id AS count_id, c.number, c.scope_id, c.sheet_id, c.kind, c.as_of, c.status, c.blind, c.notes, c.started_by, c.started_at, c.approved_by, c.approved_at,
       CASE WHEN c.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN c.total_variance_value END AS total_variance_value, c.created_at
  FROM inventory_counts c WHERE c.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
-- a blind count hides the book figure and the variance from the counter until review; approvers (counts.approve) see them
CREATE OR REPLACE VIEW mcp_inventory_count_lines WITH (security_barrier = true) AS
SELECT l.id AS count_line_id, l.count_id, c.scope_id, l.storage_area_id, l.ingredient_id, l.shelf_order, l.count_unit_code,
       CASE WHEN NOT c.blind OR c.status <> 'open' OR ri_has_right('counts.approve', c.scope_id) THEN l.system_qty_base END AS system_qty_base,
       l.entries, l.gross_weight_g, l.container_id, l.counted_qty_base, l.state, l.recount_qty_base, l.recount_by, l.counted_by, l.counted_at,
       CASE WHEN NOT c.blind OR c.status <> 'open' OR ri_has_right('counts.approve', c.scope_id) THEN l.variance_base END AS variance_base,
       CASE WHEN (NOT c.blind OR c.status <> 'open' OR ri_has_right('counts.approve', c.scope_id)) AND c.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN l.variance_value END AS variance_value,
       l.over_tolerance, l.note
  FROM inventory_count_lines l JOIN inventory_counts c ON c.id = l.count_id WHERE c.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));

-- Periods, projection, ordering.
CREATE OR REPLACE VIEW mcp_costing_calendars WITH (security_barrier = true) AS SELECT c.id AS calendar_id, c.name, c.kind, c.year_start_on, c.week_starts, c.is_default FROM costing_calendars c WHERE (SELECT ri_is_member_here());
CREATE OR REPLACE VIEW mcp_periods WITH (security_barrier = true) AS
SELECT p.id AS period_id, p.scope_id, p.calendar_id, p.no, p.fiscal_year, p.starts_on, p.ends_on, p.status, p.opening_count_id, p.closing_count_id,
       CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.opening_value END AS opening_value, CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.purchases_value END AS purchases_value,
       CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.ending_value END AS ending_value, CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.cogs_value END AS cogs_value,
       CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.theoretical_value END AS theoretical_value, CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.waste_value END AS waste_value,
       CASE WHEN p.scope_id = ANY (ARRAY(SELECT ri_cost_site_ids())) THEN p.food_sales END AS food_sales, p.closed_at, p.closed_by, p.reopened_at
  FROM periods p WHERE p.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_calendar_days WITH (security_barrier = true) AS SELECT d.* FROM calendar_days d WHERE d.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_forecast_runs WITH (security_barrier = true) AS SELECT r.id AS forecast_run_id, r.scope_id, r.made_at, r.method, r.horizon_days, r.history_days, r.params, r.items, r.is_current, r.made_by FROM forecast_runs r WHERE r.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_item_forecasts WITH (security_barrier = true) AS
SELECT f.run_id, r.scope_id, f.menu_item_id, f.on_date, f.qty, f.lo, f.hi, f.sigma FROM item_forecasts f JOIN forecast_runs r ON r.id = f.run_id WHERE r.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_forecast_overrides WITH (security_barrier = true) AS SELECT o.id AS override_id, o.scope_id, o.menu_item_id, o.from_date, o.to_date, o.factor, o.qty, o.reason, o.status, o.set_by, o.created_at FROM forecast_overrides o WHERE o.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_forecast_accuracy WITH (security_barrier = true) AS SELECT a.* FROM forecast_accuracy a WHERE a.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_covers_expected WITH (security_barrier = true) AS SELECT c.scope_id, c.on_date, c.day_part_id, c.covers, c.source, c.synced_at FROM covers_expected c WHERE c.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_watches WITH (security_barrier = true) AS
SELECT w.id AS watch_id, w.member_id, w.agent_member_id, w.scope_id, w.ingredient_id, w.kind, w.threshold, w.text_me, w.last_state, w.fired_at, w.fire_count, w.active, w.note, w.created_at
  FROM watches w WHERE w.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids())) AND (w.member_id = app_current_member_id() OR w.agent_member_id = app_current_member_id() OR (SELECT ri_is_admin()));
CREATE OR REPLACE VIEW mcp_buyer_proposals WITH (security_barrier = true) AS
SELECT b.id AS proposal_id, b.scope_id, b.kind, b.subject_type, b.subject_id, b.title, b.detail, b.drafted_record_type, b.drafted_record_id, b.status, b.proposed_by, b.note_date, b.decided_by, b.decided_at, b.created_at
  FROM buyer_proposals b WHERE b.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()));
CREATE OR REPLACE VIEW mcp_notifications WITH (security_barrier = true) AS
SELECT n.id AS notification_id, n.member_id, n.scope_id, n.kind, n.record_type, n.record_id, n.title, n.body, n.read_at, n.created_at FROM notifications n WHERE n.member_id = app_current_member_id();
CREATE OR REPLACE VIEW mcp_agent_dispatches WITH (security_barrier = true) AS
SELECT d.id AS dispatch_id, d.record_type, d.record_id, d.scope_id, d.agent_member_id, d.kind, d.via, d.acting_member_id, d.run_id, d.request_id, d.status, d.reply_excerpt, d.attempts, d.created_at, d.answered_at, d.watch_id
  FROM agent_dispatches d WHERE (d.scope_id IS NULL OR d.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()))) AND (d.agent_member_id = app_current_member_id() OR d.acting_member_id = app_current_member_id() OR (SELECT ri_is_admin()));

-- The activity trail.
CREATE OR REPLACE VIEW mcp_activity_log WITH (security_barrier = true) AS
SELECT a.id AS activity_id, a.occurred_at, a.actor_member_id, m.display_name AS actor_name, m.member_kind = 'agent' AS actor_is_agent, a.source, a.action, a.screen, a.route,
       a.entity_type, a.entity_id, a.before, a.after, a.request_id, a.agent_run_id, a.department_id, a.scope_id, a.ingredient_id, a.recipe_id, a.purchase_order_id, a.count_id, a.token_id
  FROM activity_log a LEFT JOIN members m ON m.id = a.actor_member_id
 WHERE (SELECT ri_is_member_here()) AND (a.scope_id IS NULL OR a.scope_id = ANY (ARRAY(SELECT ri_held_scope_ids())))
   AND (a.actor_member_id = app_current_member_id() OR (SELECT ri_is_admin()) OR COALESCE(a.entity_type, '') NOT IN ('source_credential', 'settings', 'ri_settings', 'mcp_access_token'));

-- ---------------------------------------------------------------------------------------------
-- Grants: the read roles see the views; the writer every table and the views (one shape for screens and tools).
-- ---------------------------------------------------------------------------------------------
DO $$
DECLARE v text;
BEGIN
    FOR v IN SELECT table_name FROM information_schema.views WHERE table_schema = 'public' AND table_name LIKE 'mcp\_%' LOOP
        IF v = 'mcp_activity_log' THEN
            EXECUTE format('GRANT SELECT ON %I TO restaurant_inventory_activity_ro, restaurant_inventory_rw', v);
        ELSE
            EXECUTE format('GRANT SELECT ON %I TO restaurant_inventory_records_ro, restaurant_inventory_rw', v);
        END IF;
    END LOOP;
END$$;
GRANT SELECT ON mcp_members, mcp_departments, mcp_sites, mcp_ingredients, mcp_recipes, mcp_suppliers, mcp_purchase_orders, mcp_inventory_counts, mcp_storage_areas TO restaurant_inventory_activity_ro;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO restaurant_inventory_rw;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO restaurant_inventory_rw;
-- the read roles may call the read functions (they are STABLE and SECURITY INVOKER over the views' rules — the servers call them with the caller set)
GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO restaurant_inventory_rw;

COMMIT;
