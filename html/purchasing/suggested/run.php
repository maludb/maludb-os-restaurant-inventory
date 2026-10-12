<?php
declare(strict_types=1);
/**
 * Action `suggested_order_run` (log purchase_order.suggest): purchasing.draft; supplier (one — every supplier due within due_hours when absent), due_hours (24, 36, 72 or 168; 36), draft (yes: draft the POs; no, the default: only read the rule).
 * ri_suggested_order() is the rule, ri_suggested_order_draft() writes the PO — a DRAFT, never sent (a Manager sends it: money out). A supplier with a draft made today is skipped. The origin is
 * `agent` under an agent's run, else `suggested`. Location the drafted PO (one supplier), else the suggested orders with a summary.
 */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$sup = ri_ref($pdo, 'supplier', null, 'SELECT 1 FROM mcp_suppliers WHERE supplier_id = :id AND active', 'supplier', $errors);
$hours = ri_int('due_hours', 36, 1, 336, 'The window', $errors);
$draft = ri_yes('draft', false);
if ($errors !== []) { ri_refuse_fields($errors); }
$origin = current_agent_run_id() !== null ? 'agent' : 'suggested';
$res = ri_guard($pdo, static function () use ($pdo, $site, $sup, $hours, $draft, $me, $origin): array {
    $pdo->beginTransaction();
    $res = run_suggested($pdo, $site, $sup, (int) $hours, $draft, $me, $origin);
    $pdo->commit();
    return $res;
});
$did = suggested_summary($res);
$rows = $res['suppliers'];
$made = array_values(array_filter($rows, static fn (array $r): bool => $r['drafted_po_id'] !== null && !$r['skipped']));
foreach ($rows as $r) {
    proj_log($pdo, 'purchase_order.suggest', 'purchase_order', $r['drafted_po_id'] ?? 0, ['purchase_order_id' => $r['drafted_po_id'],
        'after' => ['supplier_id' => $r['supplier_id'], 'cutoff_at' => $r['cutoff_at'], 'delivery_on' => $r['delivery_on'], 'lines' => $r['lines'], 'drafted_po_id' => $r['drafted_po_id'], 'skipped' => $r['skipped'], 'due_hours' => $hours, 'draft' => $draft]
        + (sees_cost($site) && $r['total'] !== null ? ['total' => $r['total']] : [])]);
}
$one = count($rows) === 1 ? $rows[0] : null;
$tally = ['d' => count($made), 'c' => count(array_filter($rows, static fn (array $r): bool => $r['reason'] === 'covered')), 'a' => count(array_filter($rows, static fn (array $r): bool => $r['reason'] === 'already drafted today'))];
if ($one !== null && $one['drafted_po_id'] !== null && !$one['skipped']) { $land = ri_land('/purchasing/' . $one['drafted_po_id'], 'drafted'); }
elseif ($one !== null) { $land = ri_land('/purchasing/suggested?supplier=' . $one['supplier_id'] . '&r=' . ($one['reason'] === 'covered' ? 'covered' : ($one['reason'] === 'already drafted today' ? 'already' : 'read')) . '&po=' . (int) $one['drafted_po_id'], 'run', 'supplier-card-' . $one['supplier_id']); }
else { $land = ri_land('/purchasing/suggested?' . http_build_query($tally), 'run'); }
ri_done($did, $one['drafted_po_id'] ?? null, $land, 'orderChanged', ['suppliers' => array_map(static fn (array $r): array => ['supplier_id' => $r['supplier_id'], 'supplier' => $r['supplier'], 'lines' => $r['lines'], 'purchase_order_id' => $r['drafted_po_id'], 'number' => $r['number'], 'skipped' => $r['skipped'], 'reason' => $r['reason']], $rows), 'drafted' => count($made)]);
