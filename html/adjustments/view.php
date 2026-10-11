<?php
declare(strict_types=1);
/** /adjustments/{id} — one adjustment (screen `adjustment-view`). JSON: present_adjustment() + its movements. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$j = adjustment_or_404($pdo, request_integer('id') ?? request_integer('adjustment'));
$aid = $j['adjustment_id'];
$lines = adjustment_lines($pdo, $aid);
$sees = sees_cost($site);
log_activity($pdo, 'screen.view', 'inventory_adjustment', $aid, ['screen' => 'adjustment-view']);
if (wants_json()) { respond_screen(['adjustment' => present_adjustment($j, $lines, $sees), 'movements' => stock_doc_txns($pdo, 'adjustment', $aid)]); }
$notices = ['created' => ['success', 'The adjustment is started. Add each change.'], 'line_saved' => ['success', 'Line added.'], 'line_removed' => ['success', 'Line removed.'], 'posted' => ['success', 'Posted — the stock changed.']];
render_screen($j['number'], view('adjustments/view.php', ['adjustment' => $j, 'lines' => $lines, 'unitOptions' => array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo)),
    'mayEdit' => has_right('counts.approve'), 'seesCost' => $sees, 'movements' => $j['status'] === 'posted' ? document_movements($pdo, $site, 'adjustment', $aid) : [], 'trail' => find_record_activity($pdo, 'inventory_adjustment', $aid), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'adjustment-list', 'screen' => 'adjustment-view', 'entity' => 'inventory_adjustment', 'recordId' => (string) $aid]);
