<?php
declare(strict_types=1);
/** Action `buyer_propose` (log buyer.propose): purchasing.draft; kind, subject_type, subject_id, title, detail (JSON), drafted_record_type, drafted_record_id — the Purchasing agent's finding of the day; a person accepts or dismisses it. Location the day's proposals. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$errors = [];
$kind = ri_choice('kind', null, PROPOSAL_KINDS, 'The kind', $errors, false);
if ($kind === null && !isset($errors['kind'])) { $errors['kind'] = 'Say what the finding is: ' . implode(', ', PROPOSAL_KINDS) . '.'; }
$stype = proj_required_text('subject_type', 40, 'The subject type', $errors);
$sid = ri_int('subject_id', null, 0, PHP_INT_MAX, 'The subject', $errors);
if ($sid === null && !isset($errors['subject_id'])) { $errors['subject_id'] = 'The subject is the record the finding is about (its id).'; }
$title = proj_required_text('title', 200, 'The title', $errors);
$detail = [];
if (req_has('detail') && (string) req_val('detail') !== '') {
    $d = json_decode((string) req_val('detail'), true);
    if (!is_array($d)) { $errors['detail'] = 'The detail is JSON — an object.'; } else { $detail = $d; }
}
$dtype = ri_choice('drafted_record_type', null, ['purchase_order', 'forecast_override'], 'The drafted record type', $errors);
$did = ri_int('drafted_record_id', null, 1, PHP_INT_MAX, 'The drafted record', $errors, true);
if ($dtype !== null && $did !== null && !isset($errors['drafted_record_id'])) {
    $ok = $dtype === 'purchase_order' ? one_value($pdo, 'SELECT 1 FROM mcp_purchase_orders WHERE purchase_order_id = :i AND scope_id = :s', ['i' => $did, 's' => $site]) : one_value($pdo, 'SELECT 1 FROM mcp_forecast_overrides WHERE override_id = :i AND scope_id = :s', ['i' => $did, 's' => $site]);
    if ($ok === null) { $errors['drafted_record_id'] = 'That ' . str_replace('_', ' ', $dtype) . ' is not at this site.'; }
} elseif (($dtype === null) !== ($did === null)) { $errors['drafted_record_id'] = 'Name both the drafted record\'s type and its id, or neither.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$id = ri_guard($pdo, static fn (): int => propose($pdo, $site, ['kind' => $kind, 'subject_type' => $stype, 'subject_id' => $sid, 'title' => $title, 'detail' => $detail, 'drafted_record_type' => $dtype, 'drafted_record_id' => $did], $me));
$date = (string) one_value($pdo, 'SELECT note_date FROM buyer_proposals WHERE id = :i', ['i' => $id]);
proj_log($pdo, 'buyer.propose', 'buyer_proposal', $id, ['purchase_order_id' => $dtype === 'purchase_order' ? $did : null, 'after' => ['proposal_id' => $id, 'kind' => $kind, 'subject_type' => $stype, 'subject_id' => $sid, 'drafted_record_type' => $dtype, 'drafted_record_id' => $did]]);
ri_done('Proposed: ' . $title, $id, ri_land('/projection/proposals?date=' . $date, 'proposed', 'proposal-card-' . $id), 'proposalChanged', ['proposal_id' => $id, 'note_date' => $date]);
