<?php
declare(strict_types=1);
/** /suppliers/ — the suppliers as cards at this site (screen `supplier-list`; params q, kind, status, page). JSON: the rows. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$filters = ['q' => request_string('q'), 'kind' => request_string('kind'), 'status' => request_string('status') ?: 'active'];
$page = max(1, request_integer('page') ?? 1);
$r = find_suppliers($pdo, $site, $filters, SUPPLIER_PAGE, ($page - 1) * SUPPLIER_PAGE);
log_screen_view($pdo, 'supplier-list');
if (wants_json()) { respond_screen(['filters' => $filters, 'page' => $page, 'total' => $r['total'], 'suppliers' => array_map('present_supplier', $r['rows'])]); }
$notices = ['deleted' => ['success', 'The supplier was deleted.']];
render_screen('Suppliers', view('catalog/suppliers.php', ['rows' => $r['rows'], 'total' => $r['total'], 'page' => $page, 'filters' => $filters, 'mayWrite' => has_right('suppliers.write'), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'supplier-list', 'screen' => 'supplier-list', 'entity' => 'supplier']);
