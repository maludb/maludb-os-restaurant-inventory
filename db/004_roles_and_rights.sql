-- 004: Restaurant Inventory's roles and the rights they give (kernel db/145; roles-and-rights.md; the design §3, D2).
--
-- Published to the kernel by app_roles on the records MCP (os.app-roles/1). The kernel grants a SET of roles PER SITE; the
-- claims and the feed carry them back into member_site_roles. What a role lets its holder do is this application's to enforce:
-- ri_has_right(right, scope_id) answers from the catalogue and the mirror. Rights are always asked AT A SITE — there is no
-- business-wide right here; a record shared across sites (an ingredient, a recipe, a supplier) is changed by whoever holds the
-- right at ANY site they hold (ri_has_right_anywhere). The base role's KEY IS `user` on purpose (Kitchen): the installer's
-- --grant-standing-departments gives a department the role keyed member/user/write — though this application is not granted to
-- standing departments by default (D3).
--
-- FIVE ROLES (D2, the owner approved 2026-10-11): Viewer (`viewer`, read — never cost), Kitchen (`user`, write), Chef (`chef`,
-- write), Manager (`manager`, write — the money), Restaurant Inventory admin (`admin`, is_admin). Each role's rights = the lower
-- roles' + its own, as §3 lists. A member the kernel has not yet sent roles for at a site is read from that site's capability:
-- admin = admin, write = user (Kitchen), read = viewer.
--
-- COST IS THE WALL (design §3): ri_sees_cost(scope_id) — Chef and above at the site; Kitchen when the site's `kitchen_sees_cost`
-- is on (db/005); never a Viewer. The mcp_* views null the cost columns through it, once per statement.
--
-- THE ESTATE'S DATA MODEL (design §6.9): the shape is Consultant Tracking db/004 = Inventory db/004 = MaluMail db/004 (ct_/ml_ ->
-- ri_), with txtSchedules db/004's per-site functions (ts_has_right(right, scope), ts_scopes_with_right(right)); the roles and
-- rights are this application's; ri_right_refusal(right) is MaluMail's sentence for a person refused.
BEGIN;

CREATE TABLE ri_rights (
    right_key   text PRIMARY KEY CHECK (right_key ~ '^[a-z][a-z0-9_.]{0,59}$'),
    description text NOT NULL,
    sort_order  integer NOT NULL DEFAULT 0
);
CREATE TABLE ri_roles (
    role_key    text PRIMARY KEY CHECK (role_key ~ '^[a-z][a-z0-9_]{0,39}$'),
    name        text NOT NULL,
    description text NOT NULL,
    capability  text NOT NULL CHECK (capability IN ('read', 'write', 'admin')),
    is_admin    boolean NOT NULL DEFAULT false,
    sort_order  integer NOT NULL DEFAULT 0,
    CHECK (NOT is_admin OR capability = 'admin')
);
CREATE UNIQUE INDEX ri_roles_one_admin ON ri_roles (is_admin) WHERE is_admin;
CREATE TABLE ri_role_rights (
    role_key  text NOT NULL REFERENCES ri_roles(role_key) ON DELETE CASCADE,
    right_key text NOT NULL REFERENCES ri_rights(right_key) ON DELETE CASCADE,
    PRIMARY KEY (role_key, right_key)
);

INSERT INTO ri_rights (right_key, description, sort_order) VALUES
 -- Viewer
 ('ri.read',            'See ingredients, recipes (method and quantities), stock on hand, counts, waste, orders and their status — never cost, price history, margin or a supplier''s terms', 1),
 ('tokens.own',         'Mint and revoke their own tokens for the two read MCP servers', 2),
 -- Kitchen
 ('stock.count',        'Enter a count sheet', 3),
 ('stock.waste',        'Log waste', 4),
 ('stock.receive',      'Receive a delivery against a purchase order or a delivery note', 5),
 ('stock.transfer',     'Move stock between storage areas and sites', 6),
 ('stock.produce',      'Post a prep batch', 7),
 ('notes.write',        'Add a note to a record', 8),
 -- Chef
 ('recipes.write',      'Recipes, versions and their activation, yields, the menu map, modifiers', 9),
 ('ingredients.write',  'Ingredients, units, pars, storage areas, count sheets', 10),
 ('counts.approve',     'Review and approve a count (posts the corrections)', 11),
 ('waste.review',       'Review the waste log', 12),
 ('purchasing.draft',   'Order guides, suggested orders, a purchase order drafted — never sent', 13),
 ('reports.read',       'The reports: usage, variance, COGS, days on hand, price movers, menu engineering', 14),
 -- Manager
 ('suppliers.write',    'Suppliers, their schedules, their items and contract prices', 15),
 ('purchasing.send',    'Send or place a purchase order with a supplier — the money', 16),
 ('invoices.write',     'Invoice lines, the three-way match, credits', 17),
 ('prices.write',       'Cost overrides and the price alerts'' thresholds', 18),
 ('periods.close',      'Close and reopen a usage period', 19),
 ('forecast.adjust',    'Override the forecast, set closures and holidays', 20),
 ('exports.all',        'Every export, including the ledger''s documents', 21),
 -- Restaurant Inventory admin
 ('settings.manage',    'The settings: cost basis, thresholds, blind counts, the costing calendar, the day parts', 22),
 ('sources.credentials','Set and rotate a POS source''s credential — never read back', 23),
 ('agents.settings',    'The shipped agents'' settings', 24),
 ('records.delete',     'Delete what deletion allows (design §6)', 25),
 ('sequences.manage',   'Document numbering', 26),
 ('sites.manage',       'The sites'' own settings (the depletion area, the sales cut, the Manager named)', 27);

INSERT INTO ri_roles (role_key, name, description, capability, is_admin, sort_order) VALUES
 ('viewer',  'Viewer',  'Reads ingredients, recipes, stock, counts, waste and orders at the site — never a cost.', 'read', false, 1),
 ('user',    'Kitchen', 'Counts, logs waste, receives a delivery, posts a prep batch, moves stock. Sees cost only when the site says so.', 'write', false, 2),
 ('chef',    'Chef',    'Keeps the recipes and the menu map, the ingredients and their pars, approves counts, reviews waste, drafts the orders; sees cost and food-cost %.', 'write', false, 3),
 ('manager', 'Manager', 'Suppliers and their schedules, sends purchase orders, enters invoices and settles the match, closes periods, adjusts the forecast, every export; everything of money.', 'write', false, 4),
 ('admin',   'Restaurant Inventory admin', 'Runs the application: every right plus the settings, the POS sources'' credentials, the agents'' settings, deletion, numbering and the sites. A super-admin holds this role at every site.', 'admin', true, 5);

INSERT INTO ri_role_rights (role_key, right_key)
SELECT 'viewer', r FROM unnest(ARRAY['ri.read','tokens.own']) r
UNION ALL SELECT 'user', r FROM unnest(ARRAY['ri.read','tokens.own','stock.count','stock.waste','stock.receive','stock.transfer','stock.produce','notes.write']) r
UNION ALL SELECT 'chef', r FROM unnest(ARRAY['ri.read','tokens.own','stock.count','stock.waste','stock.receive','stock.transfer','stock.produce','notes.write',
                                           'recipes.write','ingredients.write','counts.approve','waste.review','purchasing.draft','reports.read']) r
UNION ALL SELECT 'manager', r FROM unnest(ARRAY['ri.read','tokens.own','stock.count','stock.waste','stock.receive','stock.transfer','stock.produce','notes.write',
                                              'recipes.write','ingredients.write','counts.approve','waste.review','purchasing.draft','reports.read',
                                              'suppliers.write','purchasing.send','invoices.write','prices.write','periods.close','forecast.adjust','exports.all']) r
UNION ALL SELECT 'admin', right_key FROM ri_rights;

-- The roles a member holds AT A SITE, resolved: the kernel's list; a super-admin always holds admin; a site the kernel has not
-- yet sent roles for is read from its capability (admin = admin, write = user, read = viewer). Empty when the site is not held.
CREATE OR REPLACE FUNCTION ri_member_roles_at(p_member_id bigint, p_scope_id bigint) RETURNS text[]
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE r text[];
BEGIN
    SELECT CASE
             WHEN m.member_kind = 'human' AND m.business_role = 'super_admin' THEN (SELECT array_agg(DISTINCT x) FROM unnest(sr.roles || ARRAY['admin']) x)
             WHEN cardinality(sr.roles) > 0 THEN sr.roles
             WHEN sr.capability = 'admin' THEN ARRAY['admin']
             WHEN sr.capability = 'write' THEN ARRAY['user']
             ELSE ARRAY['viewer']
           END
      INTO r
      FROM member_site_roles sr
      JOIN sites s ON s.scope_id = sr.scope_id AND s.removed_at IS NULL
      JOIN members m ON m.id = sr.member_id AND m.status = 'active' AND m.capability IS NOT NULL
     WHERE sr.member_id = p_member_id AND sr.scope_id = p_scope_id;
    RETURN COALESCE(r, '{}');
END$$;

-- A right a given member's roles AT THE SITE give (the site live and held; the member active and admitted). One query.
CREATE OR REPLACE FUNCTION ri_member_has_right(p_member_id bigint, p_right text, p_scope_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (
        SELECT 1 FROM member_site_roles sr
          JOIN sites s ON s.scope_id = sr.scope_id AND s.removed_at IS NULL
          JOIN members m ON m.id = sr.member_id AND m.status = 'active' AND m.capability IS NOT NULL
          JOIN ri_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (sr.roles)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin' AND rr.role_key = 'admin')
                    OR (cardinality(sr.roles) = 0 AND rr.role_key = CASE sr.capability WHEN 'admin' THEN 'admin' WHEN 'write' THEN 'user' ELSE 'viewer' END))
         WHERE sr.member_id = p_member_id AND sr.scope_id = p_scope_id);
END$$;

-- The rule: a right the acting member's roles at the site give.
CREATE OR REPLACE FUNCTION ri_has_right(p_right text, p_scope_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN ri_member_has_right(app_current_member_id(), p_right, p_scope_id);
END$$;

-- The sites where the caller holds a right — the uncorrelated set a view tests once per statement
-- ("WHERE scope_id IN (SELECT ri_scopes_with_right('reports.read'))").
CREATE OR REPLACE FUNCTION ri_scopes_with_right(p_right text) RETURNS SETOF bigint
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN QUERY
        SELECT DISTINCT sr.scope_id FROM member_site_roles sr
          JOIN sites s ON s.scope_id = sr.scope_id AND s.removed_at IS NULL
          JOIN members m ON m.id = sr.member_id AND m.status = 'active' AND m.capability IS NOT NULL
          JOIN ri_role_rights rr ON rr.right_key = p_right
               AND (rr.role_key = ANY (sr.roles)
                    OR (m.member_kind = 'human' AND m.business_role = 'super_admin' AND rr.role_key = 'admin')
                    OR (cardinality(sr.roles) = 0 AND rr.role_key = CASE sr.capability WHEN 'admin' THEN 'admin' WHEN 'write' THEN 'user' ELSE 'viewer' END))
         WHERE sr.member_id = app_current_member_id();
END$$;

-- A right held at ANY site the caller holds: what opens a record shared across sites (an ingredient, a recipe, a supplier).
CREATE OR REPLACE FUNCTION ri_has_right_anywhere(p_right text) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN EXISTS (SELECT 1 FROM ri_scopes_with_right(p_right));
END$$;

-- Runs the application: holds the admin role at the site asked (a super-admin at every site), or — no site asked — at any site held.
CREATE OR REPLACE FUNCTION ri_is_admin(p_scope_id bigint DEFAULT NULL) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    IF p_scope_id IS NULL THEN
        RETURN ri_has_right_anywhere('settings.manage');
    END IF;
    RETURN ri_has_right('settings.manage', p_scope_id);
END$$;

-- Cost is the wall (design §3): Chef and above at the site; Kitchen when the site's kitchen_sees_cost is on; never a Viewer.
-- site_settings is db/005's — read dynamically so this file stands alone.
CREATE OR REPLACE FUNCTION ri_sees_cost(p_scope_id bigint) RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE v boolean := false;
BEGIN
    IF ri_has_right('reports.read', p_scope_id) THEN
        RETURN true;
    END IF;
    IF ri_has_right('stock.count', p_scope_id) AND to_regclass('public.site_settings') IS NOT NULL THEN
        EXECUTE 'SELECT COALESCE((SELECT kitchen_sees_cost FROM site_settings WHERE scope_id = $1), false)' INTO v USING p_scope_id;
        RETURN v;
    END IF;
    RETURN false;
END$$;

-- Someone who belongs in the business here: an active mirrored member with a capability who holds a live site (Inventory's
-- inv_is_member_here() shape). A grant with no site is not.
CREATE OR REPLACE FUNCTION ri_is_member_here() RETURNS boolean
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
BEGIN
    RETURN app_is_active_member() AND EXISTS (SELECT 1 FROM ri_held_scope_ids());
END$$;

-- Why the acting member lacks a right at the site, in a sentence for a person — NULL when they hold it. The screens say this
-- instead of a bare 403.
CREATE OR REPLACE FUNCTION ri_right_refusal(p_right text, p_scope_id bigint) RETURNS text
    LANGUAGE plpgsql STABLE SECURITY DEFINER SET search_path = public AS $$
DECLARE site_name text; role_names text;
BEGIN
    IF ri_has_right(p_right, p_scope_id) THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM ri_rights WHERE right_key = p_right) THEN
        RETURN 'That is not something Restaurant Inventory does.';
    END IF;
    IF NOT ri_is_member_here() THEN
        RETURN 'Your access to Restaurant Inventory names no restaurant yet. A super-admin grants a role at a site.';
    END IF;
    IF NOT ri_holds_scope(p_scope_id) THEN
        RETURN 'You do not hold that restaurant.';
    END IF;
    SELECT name INTO site_name FROM sites WHERE scope_id = p_scope_id;
    SELECT string_agg(r.name, ', ' ORDER BY r.sort_order) INTO role_names FROM ri_roles r
     WHERE r.role_key = ANY (ri_member_roles_at(app_current_member_id(), p_scope_id));
    IF app_member_kind() = 'agent' THEN
        RETURN 'The agent''s role at ' || COALESCE(site_name, 'this restaurant') || ' (' || COALESCE(role_names, 'none') || ') does not allow that.';
    END IF;
    RETURN 'Your role at ' || COALESCE(site_name, 'this restaurant') || ' (' || COALESCE(role_names, 'none') || ') does not let you do that.';
END$$;

-- What app_roles answers, in one read (the catalogue; the kernel reads it as itself).
CREATE OR REPLACE VIEW mcp_app_roles AS
SELECT r.role_key, r.name, r.description, r.capability, r.is_admin, r.sort_order,
       COALESCE((SELECT array_agg(rr.right_key ORDER BY h.sort_order) FROM ri_role_rights rr
                   JOIN ri_rights h ON h.right_key = rr.right_key WHERE rr.role_key = r.role_key), '{}') AS rights
FROM ri_roles r;

GRANT SELECT ON ri_rights, ri_roles, ri_role_rights, mcp_app_roles TO restaurant_inventory_rw, restaurant_inventory_records_ro;
GRANT EXECUTE ON FUNCTION ri_member_roles_at(bigint, bigint), ri_member_has_right(bigint, text, bigint), ri_has_right(text, bigint),
    ri_scopes_with_right(text), ri_has_right_anywhere(text), ri_is_admin(bigint), ri_sees_cost(bigint), ri_is_member_here(),
    ri_right_refusal(text, bigint)
    TO restaurant_inventory_rw, restaurant_inventory_records_ro, restaurant_inventory_activity_ro;

COMMIT;
