<?php
declare(strict_types=1);
/** /sales/sources/{source}/credential — GET: screen source-credential (the current credential's label and last four; the form: kind, label, the secret never pre-filled, never shown again). POST: action source_credential_set (sources.credentials — the admin's; `other` for an agent): a new sealed row, the previous one rotated, credential_id on the source; the reply carries last4 only; log source.credential_set with rotated — never the secret. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    $src = source_or_404($pdo, (string) (request_integer('id') ?? ''), 'sources.credentials');
    $s = source_for($pdo, (int) $src['id']);
    log_screen_view($pdo, 'source-credential', ['scope_id' => (int) $src['scope_id']]);
    $kinds = credential_kinds_for($src);
    if (wants_json()) { respond_screen(['source' => present_source($s, true), 'kinds' => $kinds]); }
    render_screen('Credential · ' . $src['name'], view('pos/sources/credential.php', ['s' => $s, 'kinds' => $kinds, 'back' => back_link()]), ['activeNav' => 'source-list', 'screen' => 'source-credential', 'entity' => 'source', 'recordId' => (string) $src['id']]);
    return;
}
ri_handler_begin();
$me = (int) current_member_id();
$src = source_or_404($pdo, req_val('source'), 'sources.credentials');
$errors = [];
$kinds = credential_kinds_for($src);
$kind = (string) (req_val('kind') ?? '');
if ($kinds === []) {
    $errors['kind'] = 'This source takes no credential (it is fed by ' . ((json_decode((string) $src['settings'], true) ?: [])['intake'] ?? 'upload') . ').';
} elseif (!in_array($kind, $kinds, true)) {
    $errors['kind'] = 'This source takes a credential of kind ' . implode(' or ', $kinds) . '.';
}
$label = ri_text('label', null, 80, 'label', $errors, true);
$secret = (string) ($_POST['secret'] ?? '');
if (trim($secret) === '') {
    $errors['secret'] = 'Type the secret (it is sealed and never shown again).';
}
$username = req_val('username');
if ($errors !== []) {
    ri_refuse_fields($errors);
}
$r = ri_guard($pdo, static function () use ($pdo, $me, $src, $kind, $label, $secret, $username): array {
    $pdo->beginTransaction();
    try {
        $r = credential_set($pdo, $src, $kind, (string) $label, $secret, $username, $me);
    } catch (RiCredentialError $e) {
        throw new DomainException('The credential could not be sealed — RI_SECRETS_KEY is not set on this server.');
    }
    source_log($pdo, 'source.credential_set', (int) $src['id'], (int) $src['scope_id'], ['after' => ['credential_id' => $r['credential_id'], 'kind' => $kind, 'label' => $label, 'last4' => $r['last4'], 'rotated' => $r['rotated']]]);
    $pdo->commit();
    return $r;
});
ri_done(($r['rotated'] ? 'Rotated' : 'Set') . ' the credential of ' . $src['name'] . ' (…' . $r['last4'] . ')', (int) $src['id'], ri_land(return_path('/sales/sources/' . $src['id']), 'credential'), 'sourceChanged', ['last4' => $r['last4'], 'rotated' => $r['rotated'], 'credential_id' => $r['credential_id']]);
