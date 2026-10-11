<?php
declare(strict_types=1);
/** Action `transfer_receive`: stock.transfer at the RECEIVING site (the person may be at either end); `received` — JSON [{line, qty, unit}] or the form's received[line] + received_unit[line] — converted per line through ri_to_base(); a line not named arrives whole. ri_transfer_receive(). Log transfer.receive. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
ri_handler_begin();
stock_refuse_eval();
$pdo = db();
$t = transfer_or_404($pdo, (int) (req_val('transfer') ?? 0) ?: null);
require_right('stock.transfer', $t['to_scope_id']);
$lines = [];
foreach (transfer_lines($pdo, $t['transfer_id']) as $l) { $lines[$l['line_id']] = $l; }
$errors = [];
$recv = [];
$given = [];
$raw = $_POST['received'] ?? null;
if (is_string($raw) && trim($raw) !== '') {
    $j = json_decode($raw, true);
    if (!is_array($j)) { ri_refuse_fields(['received' => 'Received is a JSON list of {line, qty, unit}.']); }
    foreach ($j as $k => $x) { if (is_array($x)) { $given[(int) ($x['line'] ?? $x['line_id'] ?? 0)] = ['qty' => $x['qty'] ?? null, 'unit' => $x['unit'] ?? null, 'k' => 'received.' . $k . '.']; } }
} elseif (is_array($raw)) {
    foreach ($raw as $lid => $q) { $given[(int) $lid] = ['qty' => $q, 'unit' => $_POST['received_unit'][$lid] ?? null, 'k' => 'received.' . (int) $lid . '.']; }
}
foreach ($given as $lid => $g) {
    $l = $lines[$lid] ?? null;
    if ($l === null) { $errors[$g['k'] . 'line'] = 'That is not a line of ' . $t['number'] . '.'; continue; }
    if (trim((string) $g['qty']) === '0') { $recv[] = ['line_id' => $lid, 'qty_base' => '0']; continue; }
    $q = stock_qty_in($pdo, $l['ingredient_id'], $g['qty'], $g['unit'] ?? $l['unit_code'], $g['k'] . 'qty', 'The quantity received', $errors);
    if ($q !== null) {
        if ((float) $q['qty_base'] > (float) $l['qty_base'] + 0.0001) { $errors[$g['k'] . 'qty'] = 'More arrived than was sent — receive what was sent and adjust the rest.'; continue; }
        $recv[] = ['line_id' => $lid, 'qty_base' => $q['qty_base']];
    }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$me = (int) current_member_id();
ri_guard($pdo, static function () use ($pdo, $t, $me, $recv): void {
    $pdo->beginTransaction();
    $pdo->prepare('SELECT ri_transfer_receive(:t, :by, :r::jsonb)')->execute(['t' => $t['transfer_id'], 'by' => $me, 'r' => $recv === [] ? null : json_encode($recv)]);
    log_activity($pdo, 'transfer.receive', 'inventory_transfer', (int) $t['transfer_id'], ['scope_id' => $t['to_scope_id'], 'after' => ['status' => 'received', 'received' => array_map(static fn (array $l): array => ['line_id' => $l['line_id'], 'ingredient_id' => $l['ingredient_id'], 'qty_received_base' => $l['qty_received_base']], transfer_lines($pdo, (int) $t['transfer_id']))]]);
    $pdo->commit();
});
$after = transfer_lines($pdo, $t['transfer_id']);
ri_done('Received ' . $t['number'] . ' into ' . $t['to_area'], $t['transfer_id'], ri_land('/transfers/' . $t['transfer_id'], 'received'), 'stockChanged',
    ['transfer_id' => $t['transfer_id'], 'received' => array_map(static fn (array $l): array => ['line_id' => $l['line_id'], 'ingredient_id' => $l['ingredient_id'], 'qty_base' => $l['qty_base'], 'qty_received_base' => $l['qty_received_base'], 'unit' => $l['unit_code']], $after)]);
