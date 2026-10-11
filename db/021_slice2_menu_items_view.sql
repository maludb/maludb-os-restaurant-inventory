-- 021: slice 2 found (2026-10-11) that db/018 appended `menu_items.no_depletion` but never replaced the view that reads the table, so
-- every read of a menu item's no-depletion flag failed with "column does not exist". The view is replaced with the column appended LAST
-- (CREATE OR REPLACE VIEW admits only an appended column — migrations are additive). Nothing else changes.
BEGIN;
CREATE OR REPLACE VIEW mcp_menu_items WITH (security_barrier = true) AS
SELECT m.id AS menu_item_id, m.pos_source_id, m.plu, m.name, m.category_id, m.sale_price, m.recipe_id, m.portions, m.active, m.first_seen_at, m.last_sold_at, m.mapped_by, m.mapped_at,
       m.no_depletion
  FROM menu_items m WHERE (SELECT ri_is_member_here()) AND (m.pos_source_id IS NULL OR m.pos_source_id IN (SELECT id FROM sources WHERE scope_id = ANY (ARRAY(SELECT ri_held_scope_ids()))));
GRANT SELECT ON mcp_menu_items TO restaurant_inventory_records_ro, restaurant_inventory_rw;
COMMIT;
