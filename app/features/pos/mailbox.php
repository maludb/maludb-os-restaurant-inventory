<?php
declare(strict_types=1);

/** MaluMail's mailbox MCP for the POS's email intake (slice 5) — kept in the slice's own files; app/mail.php (the kit's sender) is untouched. */

/**
 * One tool of MaluMail's mailbox MCP (slice 5, the POS's email intake — Help Desk's client, the estate's intake pattern): JSON-RPC
 * `tools/call` at MALUMAIL_MCP_URL with the notification key; list_messages, read_message (attachments as base64), mark_message. The
 * mailbox is named by address in $args; the application holds no mailbox password. 15 s. A failure raises.
 */
function malumail_mcp_call(string $tool, array $args): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init((string) env('MALUMAIL_MCP_URL', 'https://api.malumail.com/mcp'));
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json, text/event-stream'],
        CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if ($status !== 200 || !is_array($body)) {
        throw new RuntimeException('MaluMail did not answer ' . $tool . ' (HTTP ' . $status . ').');
    }
    if (isset($body['error'])) {
        throw new RuntimeException('MaluMail refused ' . $tool . ': ' . (string) ($body['error']['message'] ?? 'no reason'));
    }
    $text = (string) ($body['result']['content'][0]['text'] ?? '');
    if (!empty($body['result']['isError'])) {
        throw new RuntimeException('MaluMail refused ' . $tool . ': ' . mb_substr($text, 0, 300));
    }
    $out = json_decode($text, true);
    return is_array($out) ? $out : [];
}
