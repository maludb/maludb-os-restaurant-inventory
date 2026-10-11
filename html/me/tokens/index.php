<?php
declare(strict_types=1);
/** /me/tokens — a person's own access tokens for the two MCP servers (screen `my-tokens`). The minted value is shown ONCE from the session. People only. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once dirname(__DIR__, 3) . '/app/features/me/queries.php';
require_once dirname(__DIR__, 3) . '/app/features/me/present.php';
require_login();
require_human();
$pdo = db();
$rows = my_tokens($pdo, (int) current_member_id());
log_screen_view($pdo, 'my-tokens');
if (wants_json()) {
    respond_screen(['tokens' => array_map('present_token', $rows), 'records_url' => app_url('/mcp/records'), 'activity_url' => app_url('/mcp/activity')]);
}
$raw = $_SESSION['minted_token'] ?? null;
unset($_SESSION['minted_token']);
render_screen('Tokens', view('me/tokens.php', ['rows' => $rows, 'raw' => $raw, 'tz' => member_timezone()]), ['activeNav' => 'my-tokens', 'screen' => 'my-tokens', 'entity' => 'mcp_access_token']);
