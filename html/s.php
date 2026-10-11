<?php
declare(strict_types=1);
/**
 * /s/{token} — the supplier's door (screen `supplier-door`, purchasing.md "The public door"). NO session, NO sign-in: the 48-hex token is the authority —
 * ri_supplier_link_resolve() answers the purchase order (and counts the view) or the page is a plain 404 "not valid or expired". The vhost sends
 * /s/<token> and /s/<token>/<do> here (do = acknowledge | decline | date). The page shows the order in the supplier's units and NEVER a cost, an internal
 * note or another supplier; its three POSTs carry a signed hidden token (door_token()) and each writes one supplier event through ri_po_supplier_event()
 * (source `door`, no member), notifies the order's creator and sender and logs purchase_order.supplier_* as `portal` with no actor. A closed or cancelled
 * order's door is read-only with a sentence.
 */
define('RI_PUBLIC_DOOR', true);                     // bootstrap: no session, no cookie
$GLOBALS['__public_door'] = 'portal';              // activity_log.source for every row this request writes
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/features/purchasing/handler.php';
require_once dirname(__DIR__) . '/app/features/purchasing/door.php';

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$pdo = db();
$invalid = static function (): never {
    http_response_code(404);
    echo view('door/invalid.php', ['business' => business_name(db())]);
    exit;
};
$token = (string) ($_GET['token'] ?? '');
$do = (string) ($_GET['do'] ?? '');
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { $invalid(); }
$poId = one_value($pdo, 'SELECT ri_supplier_link_resolve(:t)', ['t' => $token]);
$po = $poId === null ? null : door_po($pdo, (int) $poId);
if ($po === null) { $invalid(); }
$poId = (int) $po['purchase_order_id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$today = site_today((int) $po['scope_id']);

/** Render the door's page (status $code), with the errors of a refused form and the notice of a done one. */
$page = static function (int $code, array $errors = [], ?string $notice = null, ?string $noticeKind = null) use ($pdo, $po, $token, $poId): never {
    http_response_code($code);
    $po = door_po($pdo, $poId) ?? $po;
    echo view('door/po.php', ['po' => $po, 'lines' => door_lines($pdo, $poId), 'token' => $token, 'doorToken' => door_token($poId), 'open' => door_open($po), 'closedWords' => door_closed_words($po),
        'errors' => $errors, 'notice' => $notice === null ? null : [$noticeKind ?? 'success', $notice], 'business' => business_name($pdo), 'today' => site_today((int) $po['scope_id'])]);
    exit;
};

if ($method === 'POST') {
    if (!in_array($do, ['acknowledge', 'decline', 'date'], true)) { $invalid(); }
    if (!door_verify($poId, (string) ($_POST['door_token'] ?? ''))) {
        $page(403, ['form' => 'This page has expired. Reload it and try again.']);
    }
    if (!door_open($po)) { $page(409, ['form' => door_closed_words($po)]); }
    $errors = [];
    $ref = trim((string) ($_POST['ref'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $date = po_date($_POST['date'] ?? '', 'date', 'The delivery date', $errors);
    if ($date !== null && $date < $today) { $errors['date'] = 'The delivery date is not before today.'; }
    if (mb_strlen($ref) > 80) { $errors['ref'] = 'Your reference is up to 80 characters.'; }
    if (mb_strlen($reason) > 300) { $errors['reason'] = 'The reason is up to 300 characters.'; }
    $lineId = (int) ($_POST['line'] ?? 0);
    $line = null;
    if ($do === 'decline') {
        foreach (door_lines($pdo, $poId) as $l) { if ($l['line_id'] === $lineId) { $line = $l; } }
        if ($line === null) { $errors['line'] = 'That line is not on this order.'; }
        elseif (!in_array($line['status'], ['open', 'acknowledged'], true)) { $errors['line'] = 'That line is ' . (PO_LINE_WORDS[$line['status']] ?? $line['status']) . ' already.'; }
        if ($reason === '') { $errors['reason'] = 'Say why you cannot fill it.'; }
    } elseif ($do === 'date' && $date === null && !isset($errors['date'])) {
        $errors['date'] = 'Say the delivery date.';
    }
    if ($errors !== []) { $page(422, $errors); }
    try {
        $pdo->beginTransaction();
        $kind = ['acknowledge' => 'acknowledge', 'decline' => 'decline', 'date' => 'date'][$do];
        $pdo->prepare('SELECT ri_po_supplier_event(:p, :k, :l, :d::date, :r, :ref)')->execute(['p' => $poId, 'k' => $kind, 'l' => $do === 'decline' ? $lineId : null, 'd' => $date, 'r' => $do === 'decline' ? $reason : null, 'ref' => $do === 'acknowledge' && $ref !== '' ? $ref : null]);
        $name = $po['supplier'];
        if ($do === 'acknowledge') {
            po_notify($pdo, $po, 'po_ack', $name . ' acknowledged ' . $po['number'], trim(($ref !== '' ? 'Their reference ' . $ref : '') . ($date !== null ? ($ref !== '' ? ' · ' : '') . 'delivery ' . format_date($date) : '')) ?: null);
            door_log($pdo, 'purchase_order.supplier_ack', $po, array_filter(['ref' => $ref ?: null, 'date' => $date]));
        } elseif ($do === 'decline') {
            po_notify($pdo, $po, 'po_decline', $name . ' declined ' . $line['reference'] . ' on ' . $po['number'], $reason);
            door_log($pdo, 'purchase_order.supplier_decline', $po, ['line_id' => $lineId, 'reason' => $reason]);
        } else {
            po_notify($pdo, $po, 'po_date', $name . ' set the delivery of ' . $po['number'] . ' to ' . format_date($date), null);
            door_log($pdo, 'purchase_order.supplier_date', $po, ['date' => $date]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $page(409, ['form' => db_message($e, 'That could not be recorded.')]);
    }
    header('Location: /s/' . $token . '?done=' . $do, true, 303);
    exit;
}
if ($do !== '') {                                   // a GET on an action's address: the page itself
    header('Location: /s/' . $token, true, 303);
    exit;
}
$done = (string) ($_GET['done'] ?? '');
$words = ['acknowledge' => 'Thank you — the order is acknowledged.', 'decline' => 'Thank you — we have noted that line.', 'date' => 'Thank you — the delivery date is set.'];
door_log($pdo, 'purchase_order.supplier_view', $po, ['views' => (int) one_value($pdo, 'SELECT view_count FROM supplier_links_secure WHERE token_hash = encode(sha256(:t::bytea), \'hex\')', ['t' => $token])]);
$page(200, [], $words[$done] ?? null, 'success');
