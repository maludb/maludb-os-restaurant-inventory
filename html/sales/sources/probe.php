<?php
declare(strict_types=1);
/** POST /sales/sources/probe.php (settings.manage or purchasing.draft at the site): the connector's probe() — what Square or the SFTP folder answers now — as a pull of kind `probe` (no checks read) and the answer inline (Pattern A) or as JSON. Logged source.probe. Not a manifest action: it changes nothing but the pull history. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$src = source_or_404($pdo, req_val('source'));
if (!has_right('settings.manage', (int) $src['scope_id']) && !has_right('purchasing.draft', (int) $src['scope_id'])) {
    require_right('purchasing.draft', (int) $src['scope_id']);
}
try {
    $res = ri_pos_connector((string) $src['connector'])->probe(pos_connector_source($pdo, $src));
} catch (RiCredentialError) {
    $res = ['ok' => false, 'message' => 'The credential could not be opened.', 'detail' => []];
} catch (DomainException $e) {
    $res = ['ok' => false, 'message' => $e->getMessage(), 'detail' => []];
}
$pullId = pos_pull_open($pdo, (int) $src['id'], 'probe', null, $me);
$pdo->prepare('UPDATE source_pulls SET finished_at = now(), status = :s, error = :e WHERE id = :id')->execute(['s' => $res['ok'] ? 'ok' : 'failed', 'e' => $res['ok'] ? null : mb_substr($res['message'], 0, 200), 'id' => $pullId]);
source_log($pdo, 'source.probe', (int) $src['id'], (int) $src['scope_id'], ['after' => ['pull_id' => $pullId, 'ok' => $res['ok'], 'message' => mb_substr($res['message'], 0, 200)]]);
emit_action_status($res['ok'], ['did' => $res['message']]);
if (wants_json()) { respond_saved(['ok' => $res['ok'], 'message' => $res['message'], 'detail' => $res['detail'], 'pull_id' => $pullId]); }
echo view('pos/sources/_probe-result.php', ['res' => $res]);
