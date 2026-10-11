<?php
declare(strict_types=1);
/** Action `token_mint` (log `token.mint`: the label and the expiry, never the value): a personal token, shown once on the next screen; JSON carries it once. Own and tokens.own; people only. Location /me/tokens#token-row-{id}; refresh tokenChanged. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/me/queries.php';
ri_handler_begin();
require_human();
$pdo = db();
$label = (string) req_val('label');
if ($label === '' || mb_strlen($label) > 80) {
    ri_refuse_fields(['label' => 'A label is required (up to 80 characters) — what will use this token.']);
}
$errors = [];
$days = ri_int('expires_in_days', null, 1, 3650, 'The days', $errors, true);
if ($errors !== []) { ri_refuse_fields($errors); }
$minted = ri_guard($pdo, static function () use ($pdo, $label, $days): array {
    $minted = mint_token($pdo, (int) current_member_id(), $label, $days);
    log_activity($pdo, 'token.mint', 'mcp_access_token', $minted['id'], ['after' => ['label' => $label, 'expires_at' => $minted['expires_at']], 'token_id' => $minted['id']]);
    return $minted;
});
$land = '/me/tokens#token-row-' . $minted['id'];
emit_action_status(true, ['did' => 'Minted the token "' . $label . '" — shown once', 'record_id' => $minted['id'], 'refresh' => 'tokenChanged']);
if (wants_json()) {
    respond_saved(['did' => 'Minted the token "' . $label . '" — shown once', 'record_id' => $minted['id'], 'token' => $minted['raw'], 'location' => $land, 'refresh' => 'tokenChanged']);
}
$_SESSION['minted_token'] = $minted;
saved_go('/me/tokens', 'tokenChanged');
