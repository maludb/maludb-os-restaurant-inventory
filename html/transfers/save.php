<?php
declare(strict_types=1);
/** Actions `transfer_draft` (no `transfer`) / `transfer_update` (a draft's header): stock.transfer at this — the sending — site. to_site a site the person holds (another → 403); from and to areas differ (the CHECK's rule said in words). A draft's first lines from `lines` JSON or one `ingredient`+`qty`+`unit`. Log transfer.draft / transfer.update. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
stock_begin('stock.transfer');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$cur = req_has('transfer') && (string) req_val('transfer') !== '' ? transfer_or_404($pdo, (int) req_val('transfer')) : null;
if ($cur !== null && $cur['scope_id'] !== $site) { respond_not_found('That transfer is not here.'); }
if ($cur !== null && $cur['status'] !== 'draft') { refuse(422, 'Transfer ' . $cur['number'] . ' is ' . str_replace('_', ' ', $cur['status']) . ' — only a draft changes.'); }
$errors = [];
$toSite = $cur['to_scope_id'] ?? $site;
if (req_has('to_site') && (string) req_val('to_site') !== '') {
    $v = (string) req_val('to_site');
    $held = null;
    foreach (held_sites() as $s) { if ((string) $s['scope_id'] === $v || strcasecmp($s['name'], $v) === 0) { $held = $s; } }
    if ($held === null) { refuse(403, 'You do not hold that site — a transfer goes to a site you hold.'); }
    $toSite = (int) $held['scope_id'];
}
$from = req_has('from_area') || $cur === null ? stock_area_ref($pdo, req_val('from_area'), $site, 'from_area', 'The from area', $errors) : $cur['from_area_id'];
$to = req_has('to_area') || $cur === null ? stock_area_ref($pdo, req_val('to_area'), $toSite, 'to_area', 'The to area', $errors) : $cur['to_area_id'];
if ($from !== null && $to !== null && $from === $to) { $errors['to_area'] = 'From and to are the same area — a transfer moves stock between two.'; }
$notes = ri_text('notes', $cur['notes'] ?? null, 2000, 'Notes', $errors);
$lines = [];
if ($cur === null) {
    foreach (stock_json_lines($errors) as $k => $l) { $x = transfer_line_fields($pdo, $l, $errors, 'lines.' . $k . '.'); if ($x !== null) { $lines[] = $x; } }
    if (req_has('ingredient') && (string) req_val('ingredient') !== '' && req_has('qty')) { $x = transfer_line_fields($pdo, ['ingredient' => req_val('ingredient'), 'qty' => req_val('qty'), 'unit' => req_val('unit')], $errors); if ($x !== null) { $lines[] = $x; } }
}
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static function () use ($pdo, $cur, $site, $toSite, $from, $to, $notes, $lines, $me): int {
    $pdo->beginTransaction();
    if ($cur === null) {
        $st = $pdo->prepare('INSERT INTO inventory_transfers (scope_id, to_scope_id, from_area_id, to_area_id, notes, created_by) VALUES (:s, :t, :f, :a, :n, :by) RETURNING id');
        $st->execute(['s' => $site, 't' => $toSite, 'f' => $from, 'a' => $to, 'n' => $notes, 'by' => $me]);
        $id = (int) $st->fetchColumn();
        foreach ($lines as $l) { save_transfer_line($pdo, $id, null, $l); }
        stock_log($pdo, 'transfer.draft', 'inventory_transfer', $id, ['after' => ['to_scope_id' => $toSite, 'from_area_id' => $from, 'to_area_id' => $to, 'lines' => array_map(static fn (array $l): array => ['ingredient_id' => $l['ingredient_id'], 'qty' => $l['qty'], 'unit' => $l['unit'], 'qty_base' => $l['qty_base']], $lines)]]);
    } else {
        $id = (int) $cur['transfer_id'];
        $pdo->prepare("UPDATE inventory_transfers SET to_scope_id = :t, from_area_id = :f, to_area_id = :a, notes = :n WHERE id = :id AND status = 'draft'")->execute(['t' => $toSite, 'f' => $from, 'a' => $to, 'n' => $notes, 'id' => $id]);
        $d = ri_diff(['to_scope_id' => $cur['to_scope_id'], 'from_area_id' => $cur['from_area_id'], 'to_area_id' => $cur['to_area_id'], 'notes' => $cur['notes']], ['to_scope_id' => $toSite, 'from_area_id' => $from, 'to_area_id' => $to, 'notes' => $notes]);
        if ($d['after'] !== []) { stock_log($pdo, 'transfer.update', 'inventory_transfer', $id, $d); }
    }
    $pdo->commit();
    return $id;
});
$t = transfer_for($pdo, $id);
ri_done(($cur === null ? 'Started ' : 'Saved ') . $t['number'] . ': ' . $t['from_area'] . ' → ' . $t['to_site'] . ' · ' . $t['to_area'], $id, ri_land('/transfers/' . $id, $cur === null ? 'created' : null), 'transferChanged', ['transfer_id' => $id, 'number' => $t['number']]);
