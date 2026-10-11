<?php
declare(strict_types=1);
/**
 * Actions `purchase_order_draft` (no `purchase_order`) / `purchase_order_update` (with — a draft's header): purchasing.draft. A new PO lands as a draft with origin `manual`
 * (`agent` for an agent's draft); the expected date defaults to the supplier's next delivery. `lines` (JSON) and the order guide's ticked rows (`guide[]`, `guide_qty[id]`)
 * go through the SAME reader as purchase_order_line_set and are added to the draft. A supplier change with lines is refused. Log purchase_order.draft / .update. Location /purchasing/{id}/edit.
 */
require_once dirname(__DIR__, 2) . '/app/features/purchasing/handler.php';
po_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$sees = sees_cost($site);
$cur = req_has('purchase_order') && trim((string) req_val('purchase_order')) !== '' ? po_from_request($pdo) : null;
if ($cur !== null) { po_require_draft($cur); }
$errors = [];
$f = po_header_from_request($pdo, $cur, $site, $errors, $cur !== null && po_line_count($pdo, (int) $cur['purchase_order_id']) > 0);
$lineIn = po_lines_in($pdo, $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$forLines = ['scope_id' => $site, 'supplier_id' => $f['supplier_id'], 'supplier' => $f['supplier']];
$lineErrors = [];
$parsed = [];
foreach ($lineIn as $k => $l) {
    $x = po_line_fields($pdo, $l, $forLines, null, $lineErrors, 'lines.' . $k . '.');
    if ($x !== null) { $parsed[] = $x; }
}
if ($lineErrors !== []) { ri_refuse_fields($lineErrors); }
$today = site_today($site);
$id = ri_guard($pdo, static function () use ($pdo, $cur, $f, $site, $me, $parsed, $today, $sees): int {
    $pdo->beginTransaction();
    if ($cur === null) {
        $st = $pdo->prepare("INSERT INTO purchase_orders (scope_id, supplier_id, status, origin, storage_area_id, ordered_on, cutoff_at, expected_on, notes, internal_notes, created_by)
                             VALUES (:s, :su, 'draft', :o, :a, :today::date, :c, :e, :n, :in, :by) RETURNING id");
        $st->execute(['s' => $site, 'su' => $f['supplier_id'], 'o' => po_is_agent() ? 'agent' : 'manual', 'a' => $f['storage_area_id'], 'today' => $today, 'c' => $f['cutoff_at'], 'e' => $f['expected_on'],
                      'n' => $f['notes'], 'in' => $f['internal_notes'], 'by' => $me]);
        $id = (int) $st->fetchColumn();
    } else {
        $id = (int) $cur['purchase_order_id'];
        $pdo->prepare("UPDATE purchase_orders SET supplier_id = :su, storage_area_id = :a, cutoff_at = :c, expected_on = :e, notes = :n, internal_notes = :in WHERE id = :id AND status = 'draft'")
            ->execute(['su' => $f['supplier_id'], 'a' => $f['storage_area_id'], 'c' => $f['cutoff_at'], 'e' => $f['expected_on'], 'n' => $f['notes'], 'in' => $f['internal_notes'], 'id' => $id]);
    }
    foreach ($parsed as $l) { save_po_line($pdo, $id, null, $l); }
    $g = po_for($pdo, $id);
    if ($cur === null) {
        po_log($pdo, 'purchase_order.draft', $id, ['after' => ['number' => $g['number'], 'supplier_id' => $f['supplier_id'], 'origin' => $g['origin'], 'expected_on' => $f['expected_on'], 'storage_area_id' => $f['storage_area_id'],
            'lines' => po_loggable_lines(po_lines($pdo, $id), $sees)] + ($sees ? ['total' => $g['total']] : [])]);
    } else {
        $before = ['supplier_id' => $cur['supplier_id'], 'storage_area_id' => $cur['storage_area_id'], 'expected_on' => $cur['expected_on'], 'notes' => $cur['notes']] + ($sees ? ['internal_notes' => $cur['internal_notes']] : []);
        $after = ['supplier_id' => $f['supplier_id'], 'storage_area_id' => $f['storage_area_id'], 'expected_on' => $f['expected_on'], 'notes' => $f['notes']] + ($sees ? ['internal_notes' => $f['internal_notes']] : []);
        $d = ri_diff($before, $after);
        if ($d['after'] !== [] || $parsed !== []) {
            po_log($pdo, 'purchase_order.update', $id, ['before' => $d['before'], 'after' => $d['after'] + ($parsed === [] ? [] : ['lines_added' => count($parsed)])]);
        }
    }
    $pdo->commit();
    return $id;
});
$g = po_for($pdo, $id);
ri_done(($cur === null ? 'Started ' : 'Saved ') . $g['number'] . ' to ' . $g['supplier'] . ($parsed !== [] ? ' with ' . count($parsed) . ' line' . (count($parsed) === 1 ? '' : 's') : ''), $id,
    ri_land('/purchasing/' . $id . '/edit', $cur === null ? 'created' : 'saved'), 'purchaseOrderChanged', ['purchase_order_id' => $id, 'number' => $g['number'], 'origin' => $g['origin'], 'status' => $g['status']] + ($sees ? ['total' => $g['total']] : []));
