<?php
declare(strict_types=1);
/** /transfers/{id} — one transfer (screen `transfer-view`), reachable from either end (a person holding the sending or the receiving site). JSON: present_transfer() + its movements. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$t = transfer_or_404($pdo, request_integer('id') ?? request_integer('transfer'));
$tid = $t['transfer_id'];
$lines = transfer_lines($pdo, $tid);
log_activity($pdo, 'screen.view', 'inventory_transfer', $tid, ['screen' => 'transfer-view']);
$mayEdit = has_right('stock.transfer', $t['scope_id']) && in_array($t['scope_id'], array_column(held_sites(), 'scope_id'), true);
$mayReceive = in_array($t['to_scope_id'], array_column(held_sites(), 'scope_id'), true) && has_right('stock.transfer', $t['to_scope_id']);
if (wants_json()) { respond_screen(['transfer' => present_transfer($t, $lines), 'movements' => array_map(static fn (array $x): array => present_txn($x, sees_cost((int) $x['scope_id'])), document_movements($pdo, $t['scope_id'], 'transfer', $tid)), 'may' => ['edit' => $mayEdit, 'receive' => $mayReceive]]); }
$notices = ['created' => ['success', 'The transfer is started. Add what moves.'], 'line_saved' => ['success', 'Line added.'], 'line_removed' => ['success', 'Line removed.'], 'sent' => ['success', 'Sent — the stock is in transit on the document.'], 'received' => ['success', 'Received — the stock is on the other shelf.']];
render_screen($t['number'], view('transfers/view.php', ['transfer' => $t, 'lines' => $lines, 'unitOptions' => array_map(static fn (array $u): array => ['code' => $u['unit_code'], 'label' => $u['unit_code'] . ' — ' . $u['name']], global_units($pdo)), 'mayEdit' => $mayEdit, 'mayReceive' => $mayReceive,
    'movements' => document_movements($pdo, $t['scope_id'], 'transfer', $tid), 'seesCost' => sees_cost($t['scope_id']), 'trail' => find_record_activity($pdo, 'inventory_transfer', $tid), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'transfer-list', 'screen' => 'transfer-view', 'entity' => 'inventory_transfer', 'recordId' => (string) $tid]);
