<?php
declare(strict_types=1);
/** Action `count_start` (log count.start): stock.count; the whole site, or a sheet; kind; as_of (the day's sales cut by default); blind (the setting by default — a Chef may change it); notes. ri_count_start() freezes the book figure as of the moment. */
require_once dirname(__DIR__, 2) . '/app/features/counts/handler.php';
counts_begin('stock.count');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$sheet = null;
if (req_has('sheet') && (string) req_val('sheet') !== '') {
    $sheet = ctype_digit((string) req_val('sheet')) ? find_count_sheet($pdo, (int) req_val('sheet')) : null;
    if ($sheet === null || $sheet['scope_id'] !== $site) { $errors['sheet'] = 'That count sheet is not at this site.'; $sheet = null; }
    elseif (!$sheet['active']) { $errors['sheet'] = 'That count sheet is archived.'; }
}
$kind = ri_choice('kind', $sheet['kind'] ?? 'full', array_keys(COUNT_KINDS), 'The kind', $errors, false) ?? 'full';
$asOfRaw = req_val('as_of');
$asOf = count_parse_as_of(($asOfRaw === null || $asOfRaw === '') ? default_count_as_of($site) : $asOfRaw, $site, $errors);
$blind = null;
if (has_right('counts.approve', $site) && req_has('blind')) { $blind = ri_yes('blind', blind_counts_setting($pdo)); }
$notes = ri_text('notes', null, 2000, 'Notes', $errors);
if ($errors !== []) { ri_refuse_fields($errors); }
$res = ri_guard($pdo, static function () use ($pdo, $site, $me, $sheet, $asOf, $kind, $blind, $notes): array {
    $pdo->beginTransaction();
    $r = start_count($pdo, $site, $me, $sheet['count_sheet_id'] ?? null, $asOf, $kind, $blind, $notes);
    $c = find_count($pdo, $r['count_id']);
    count_log($pdo, 'count.start', 'inventory_count', $r['count_id'], $r['count_id'], ['after' => ['number' => $c['number'], 'kind' => $kind, 'sheet_id' => $sheet['count_sheet_id'] ?? null, 'as_of' => json_ts($c['as_of']), 'blind' => $c['blind'], 'lines' => $r['lines']]]);
    $pdo->commit();
    return $r + ['number' => $c['number']];
});
ri_done('Started ' . $res['number'] . ' with ' . $res['lines'] . ' line' . ($res['lines'] === 1 ? '' : 's'), $res['count_id'], ri_land('/counts/' . $res['count_id'], 'started'), 'countChanged', ['count_id' => $res['count_id'], 'number' => $res['number'], 'lines' => $res['lines']]);
