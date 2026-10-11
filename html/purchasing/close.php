<?php
declare(strict_types=1);
/** Action `purchase_order_close`: purchasing.send — ri_po_close(): the open lines close short; the order is `closed` (everything came, `short` no) or `closed_short`. Log purchase_order.close. */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.send');
$pdo = db();
$me = (int) current_member_id();
$po = po_from_request($pdo);
$short = ri_yes('short', false);
$after = ri_guard($pdo, static function () use ($pdo, $po, $me, $short): array {
    $pdo->beginTransaction();
    $st = $pdo->prepare('SELECT status FROM ri_po_close(:p, :by, :short)');
    $st->execute(['p' => $po['purchase_order_id'], 'by' => $me, 'short' => $short ? 't' : 'f']);
    $status = (string) $st->fetchColumn();
    $open = (int) one_value($pdo, "SELECT count(*) FROM purchase_order_lines WHERE purchase_order_id = :p AND status = 'closed_short'", ['p' => $po['purchase_order_id']]);
    po_log($pdo, 'purchase_order.close', (int) $po['purchase_order_id'], ['before' => ['status' => $po['status']], 'after' => ['status' => $status, 'short' => $short, 'lines_closed_short' => $open]]);
    $pdo->commit();
    return ['status' => $status, 'closed_short_lines' => $open];
});
ri_done('Closed ' . $po['number'] . ($after['status'] === 'closed_short' ? ' short — ' . $after['closed_short_lines'] . ' line' . ($after['closed_short_lines'] === 1 ? '' : 's') . ' will not come' : ''), (int) $po['purchase_order_id'],
    ri_land('/purchasing/' . $po['purchase_order_id'], 'closed'), 'purchaseOrderChanged', ['purchase_order_id' => (int) $po['purchase_order_id'], 'status' => $after['status'], 'closed_short_lines' => $after['closed_short_lines']]);
