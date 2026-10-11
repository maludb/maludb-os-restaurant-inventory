<?php
/**
 * The POS fixture server (slice 5's proofs) — `php -S 127.0.0.1:9323 tests/fixtures/pos/router.php`: a fake Square Orders API.
 *   POST /v2/orders/search — the bearer must be "sq-smoke-token" (else 401 — "sq-bad" is how the proof makes a refused token); the
 *   location must be in location_ids; no cursor → orders-page1.json (with a cursor), cursor SMOKE-CURSOR-2 → orders-page2.json. When
 *   $FIXTURE_STATE/square_refund exists, SQ-ORDER-1's gin and tonic (SQ-1-L2) comes back returned (`returns[]`). When
 *   $FIXTURE_STATE/square_429 exists → 429 with Retry-After: 120. Every request is appended to $FIXTURE_STATE/square.log (the
 *   Authorization header's last four only — the proof checks the token travelled in the header and nowhere else).
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$state = rtrim((string) getenv('FIXTURE_STATE'), '/');
header('Content-Type: application/json');
if ($path !== '/v2/orders/search' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(404); echo '{"errors":[{"code":"NOT_FOUND"}]}'; return true; }
$raw = (string) file_get_contents('php://input');
$in = json_decode($raw, true) ?: [];
$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
file_put_contents($state . '/square.log', json_encode(['auth_tail' => substr($auth, -4), 'version' => $_SERVER['HTTP_SQUARE_VERSION'] ?? '', 'cursor' => $in['cursor'] ?? null, 'locations' => $in['location_ids'] ?? [],
    'body_has_token' => str_contains($raw, 'sq-smoke-token'), 'window' => $in['query']['filter']['date_time_filter']['closed_at'] ?? null]) . "\n", FILE_APPEND);
if (is_file($state . '/square_429')) { http_response_code(429); header('Retry-After: 120'); echo '{"errors":[{"code":"RATE_LIMITED"}]}'; return true; }
if ($auth !== 'Bearer sq-smoke-token') { http_response_code(401); echo '{"errors":[{"category":"AUTHENTICATION_ERROR","code":"UNAUTHORIZED"}]}'; return true; }
if (!in_array('L-SMOKE', (array) ($in['location_ids'] ?? []), true)) { echo '{"orders":[]}'; return true; }
$page = ($in['cursor'] ?? null) === 'SMOKE-CURSOR-2' ? 'orders-page2.json' : 'orders-page1.json';
$out = json_decode((string) file_get_contents(__DIR__ . '/square/' . $page), true);
if ($page === 'orders-page1.json' && is_file($state . '/square_refund')) {
    $out['orders'][0]['returns'] = [['uid' => 'r1', 'source_order_id' => 'SQ-ORDER-1', 'return_line_items' => [['uid' => 'rl1', 'source_line_item_uid' => 'SQ-1-L2', 'quantity' => '1']]]];
}
echo json_encode($out);
return true;
