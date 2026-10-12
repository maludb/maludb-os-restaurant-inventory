<?php
declare(strict_types=1);
/** Action `buyer_proposal_decide` (log buyer.decide): purchasing.draft; proposal, decision (accept | dismiss). Accepting an order proposal lands on the drafted PO (a Manager sends it there); a forecast proposal's override is set or dismissed with it. */
require_once dirname(__DIR__, 3) . '/app/features/projection/handler.php';
proj_begin('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$me = (int) current_member_id();
$p = stock_here_or_404(find_proposal($pdo, (int) request_integer('proposal')), 'That proposal');
$errors = [];
$decision = ri_choice('decision', null, ['accept', 'dismiss'], 'The decision', $errors, false);
if ($decision === null && !isset($errors['decision'])) { $errors['decision'] = 'Say accept or dismiss.'; }
if ($errors !== []) { ri_refuse_fields($errors); }
$r = ri_guard($pdo, static function () use ($pdo, $p, $decision, $me): array {
    $pdo->beginTransaction();
    $r = decide_proposal($pdo, $p['proposal_id'], (string) $decision, $me);
    $pdo->commit();
    return $r;
});
proj_log($pdo, 'buyer.decide', 'buyer_proposal', $p['proposal_id'], ['purchase_order_id' => $p['drafted_record_type'] === 'purchase_order' ? $p['drafted_record_id'] : null,
    'after' => ['proposal_id' => $p['proposal_id'], 'kind' => $p['kind'], 'subject_type' => $p['subject_type'], 'subject_id' => $p['subject_id'], 'drafted_record_type' => $p['drafted_record_type'], 'drafted_record_id' => $p['drafted_record_id'], 'decision' => $decision]]);
ri_done($decision === 'accept' ? 'Accepted: ' . $p['title'] : 'Dismissed: ' . $p['title'], $p['proposal_id'],
    $r['location'] !== null ? ri_land($r['location'], 'accepted') : ri_land('/projection/proposals?date=' . $p['note_date'], $decision === 'accept' ? 'accepted' : 'dismissed', 'proposal-card-' . $p['proposal_id']), 'proposalChanged', ['proposal_id' => $p['proposal_id'], 'status' => $r['proposal']['status']]);
