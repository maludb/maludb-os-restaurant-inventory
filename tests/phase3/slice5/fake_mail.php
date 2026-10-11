<?php
/**
 * Slice 5's fake MaluDB + MaluMail — `php -S 127.0.0.1:9322 tests/phase3/slice5/fake_mail.php`: /mcp answers the mailbox MCP's
 * list_messages, read_message and mark_message over $FAKE_MALUMAIL_STATE ({"mailboxes": {"<address>": {"<uid>": {headers, attachments,
 * flags}}}} — Help Desk's fake, the estate's intake pattern), every call logged to $FAKE_MALUMAIL_LOG.mcp; every other path is the kit's
 * tests/fake_maludb.php (the ingest's /v1/episodes and the notification /v1/send).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/mcp') { require dirname(__DIR__, 2) . '/fake_maludb.php'; return true; }
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . getenv('MALUMAIL_API_KEY')) { http_response_code(401); echo '{"error":"bad key"}'; return true; }
$stateFile = (string) getenv('FAKE_MALUMAIL_STATE');
$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$tool = (string) ($in['params']['name'] ?? ''); $args = (array) ($in['params']['arguments'] ?? []);
$answer = static function (array $result, bool $error = false): never { echo json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['type' => 'text', 'text' => json_encode($result)]], 'isError' => $error]]); exit; };
$addr = strtolower((string) ($args['address'] ?? ''));
$box = (array) ($state['mailboxes'][$addr] ?? []);
file_put_contents((string) getenv('FAKE_MALUMAIL_LOG') . '.mcp', json_encode(['tool' => $tool, 'args' => $args]) . "\n", FILE_APPEND);
if ($tool === 'list_messages') {
    $out = [];
    foreach ($box as $uid => $m) { if (!empty($args['unread_only']) && in_array('\\Seen', $m['flags'] ?? [], true)) { continue; } $out[] = ['uid' => (int) $uid, 'subject' => $m['headers']['subject'] ?? '', 'from' => $m['headers']['from'] ?? '', 'flags' => $m['flags'] ?? []]; }
    usort($out, static fn ($a, $b) => $a['uid'] <=> $b['uid']);
    $answer(['messages' => array_slice($out, 0, (int) ($args['limit'] ?? 50))]);
}
if ($tool === 'read_message') {
    $m = $box[(string) (int) ($args['uid'] ?? 0)] ?? null;
    if ($m === null) { $answer(['error' => 'no such message'], true); }
    $answer(['uid' => (int) $args['uid'], 'headers' => $m['headers'] ?? [], 'text' => $m['text'] ?? '', 'attachments' => $m['attachments'] ?? [], 'flags' => $m['flags'] ?? []]);
}
if ($tool === 'mark_message') {
    $uid = (string) (int) ($args['uid'] ?? 0);
    if (isset($state['mailboxes'][$addr][$uid])) {
        $flags = array_values(array_diff($state['mailboxes'][$addr][$uid]['flags'] ?? [], ['\\Seen']));
        if (!empty($args['seen'])) { $flags[] = '\\Seen'; }
        $state['mailboxes'][$addr][$uid]['flags'] = $flags;
        file_put_contents($stateFile . '.tmp', json_encode($state)); rename($stateFile . '.tmp', $stateFile);
    }
    $answer(['ok' => true]);
}
$answer(['error' => 'unknown tool ' . $tool], true);
