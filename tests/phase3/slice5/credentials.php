<?php
/** Slice 5 — credentials (pos.md "Proof": Credentials). Sealed under RI_SECRETS_KEY, opened here by the proof's own secretbox call, found in no view and no log. */
require __DIR__ . '/lib.php';
pos_world();
$admin = at(1, DOWNTOWN);
$marco = at(27, DOWNTOWN);
$dana = at(30, DOWNTOWN);
$since = last_activity_id();
$square = source_id('SMOKE Square');
$sftp = source_id('SMOKE SFTP');
$toast = source_id('SMOKE Toast export');
$SECRET = 'sq-smoke-token';

/** The proof's own open (ri_open's format: "v1." + base64url(nonce ‖ box), the JSON credential inside). */
function open_sealed(string $c): ?array
{
    $bin = base64_decode(strtr(substr($c, 3), '-_', '+/'), true);
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_hex2bin(need('RI_SECRETS_KEY')));
    return $plain === false ? null : json_decode($plain, true);
}
/** Every view's rows as the admin (both sites), as one string. */
function every_view_text(): string
{
    as_member(1);
    $out = '';
    foreach (q("SELECT table_name FROM information_schema.views WHERE table_schema = 'public' ORDER BY 1") as $v) {
        try { $out .= json_encode(q('SELECT * FROM ' . $v['table_name'] . ' LIMIT 5000')); } catch (Throwable) { pdo()->exec('ROLLBACK'); }
    }
    return $out;
}

echo "1. The screen and the walls\n";
$r = page($admin, '/sales/sources/' . $square . '/credential');
ok($r['code'] === 200 && has($r, 'No credential yet.') && has($r, 'id="source-credential-form-field-secret"') && has($r, 'value="bearer"') && has($r, 'type="password"'), 'the admin opens the credential screen: none yet, the form offers bearer, the secret a password field');
ok(page($admin, '/sales/sources/' . $toast . '/credential')['body'] !== '' && has(page($admin, '/sales/sources/' . $toast . '/credential'), 'id="source-credential-none"'), 'an upload source says it takes no credential');
ok(page($marco, '/sales/sources/' . $square . '/credential')['code'] === 403, "Marco (Manager) cannot open it: sources.credentials is the admin's");
ok(page($dana, '/sales/sources/' . $square . '/credential')['code'] === 403, 'Dana cannot');
ok(post_json($marco, '/sales/sources/credential.php', ['source' => (string) $square, 'kind' => 'bearer', 'label' => 'SMOKE', 'secret' => 'nope'])['code'] === 403, 'Marco cannot set one: 403');

echo "2. A bearer on Square\n";
$r = post_json($admin, '/sales/sources/credential.php', ['source' => (string) $square, 'kind' => 'bearer', 'label' => 'SMOKE Square sandbox', 'secret' => 'sq-wrong-token-0000']);
ok($r['code'] === 200 && ($r['json']['last4'] ?? '') === '0000' && ($r['json']['rotated'] ?? null) === false, 'set: the reply carries last4 0000, not rotated');
ok(!str_contains($r['body'], 'sq-wrong-token'), 'the reply never carries the secret');
$first = (int) one('SELECT credential_id FROM sources WHERE id = :s', ['s' => $square]);
$row = q("SELECT kind, label, last4, convert_from(ciphertext, 'UTF8') AS c, rotated_at FROM source_credentials WHERE id = :i", ['i' => $first])[0] ?? [];
ok($first > 0 && ($row['kind'] ?? '') === 'bearer' && $row['label'] === 'SMOKE Square sandbox' && $row['last4'] === '0000' && $row['rotated_at'] === null, 'the row: kind bearer, the label, last4; credential_id on the source');
ok(str_starts_with($row['c'] ?? '', 'v1.') && !str_contains($row['c'], 'sq-wrong-token'), 'the ciphertext is sealed ("v1." …) and holds no plain text');
$open = open_sealed($row['c']);
ok(($open['token'] ?? '') === 'sq-wrong-token-0000' && ($open['kind'] ?? '') === 'bearer', 'the seal opens under RI_SECRETS_KEY to the token');
$r = post_json($admin, '/sales/sources/credential.php', ['source' => (string) $square, 'kind' => 'bearer', 'label' => 'SMOKE Square production', 'secret' => $SECRET]);
$second = (int) one('SELECT credential_id FROM sources WHERE id = :s', ['s' => $square]);
ok($r['code'] === 200 && ($r['json']['rotated'] ?? null) === true && $second !== $first && ($r['json']['last4'] ?? '') === 'oken', 'rotated: a new row, the reply says rotated, last4 "oken"');
ok(one('SELECT rotated_at FROM source_credentials WHERE id = :i', ['i' => $first]) !== null && one('SELECT rotated_at FROM source_credentials WHERE id = :i', ['i' => $second]) === null, 'the old row rotated_at, the new one live');
$log = activity('source.credential_set', $since);
$after = array_map(static fn ($l) => json_decode($l['after'], true), $log);
ok(count($log) === 2 && $after[0]['rotated'] === false && $after[1]['rotated'] === true && $after[1]['last4'] === 'oken' && (int) $log[1]['scope_id'] === DOWNTOWN, 'source.credential_set logged twice with rotated false, then true');
ok(!str_contains(json_encode(q('SELECT * FROM activity_log WHERE id > :s', ['s' => $since])), 'sq-smoke-token') && !str_contains(json_encode(q('SELECT * FROM activity_log WHERE id > :s', ['s' => $since])), 'sq-wrong-token'), 'the log holds neither secret');
$text = every_view_text();
ok(strlen($text) > 1000 && !str_contains($text, $SECRET) && !str_contains($text, 'sq-wrong-token'), 'no view of the database shows either secret (' . count(q("SELECT 1 FROM information_schema.views WHERE table_schema = 'public'")) . ' views read as the admin)');
$r = page($admin, '/sales/sources/' . $square . '/credential');
ok($r['code'] === 200 && has($r, 'SMOKE Square production') && has($r, 'oken') && !has($r, $SECRET), 'the screen shows the label and …oken, never the token');
$r = req('GET', '/sales/sources/' . $square, ['jar' => $admin, 'headers' => ['Accept: application/json']]);
ok($r['code'] === 200 && !str_contains($r['body'], $SECRET) && str_contains($r['body'], '"oken"'), "the source's JSON carries the credential's last4, never the token");

echo "3. An SFTP password, and kinds refused\n";
$r = post_json($admin, '/sales/sources/credential.php', ['source' => (string) $sftp, 'kind' => 'sftp_password', 'label' => 'SMOKE SFTP login', 'username' => 'smoke', 'secret' => 'smoke-sftp-pw-9a2b']);
ok($r['code'] === 200 && ($r['json']['last4'] ?? '') === '9a2b' && open_sealed((string) one("SELECT convert_from(ciphertext, 'UTF8') FROM source_credentials WHERE id = (SELECT credential_id FROM sources WHERE id = :s)", ['s' => $sftp]))['username'] === 'smoke', 'an sftp password on the SFTP source, the username sealed with it');
$r = post_json($admin, '/sales/sources/credential.php', ['source' => (string) $square, 'kind' => 'sftp_password', 'label' => 'x', 'secret' => 'y']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['kind']), 'an sftp password on Square → 422 on kind');
$r = post_json($admin, '/sales/sources/credential.php', ['source' => (string) $toast, 'kind' => 'bearer', 'label' => 'x', 'secret' => 'y']);
ok($r['code'] === 422 && str_contains(json_encode($r['json']), 'takes no credential'), 'any credential on an upload source → 422 "takes no credential"');
$r = post_json($admin, '/sales/sources/credential.php', ['source' => (string) $square, 'kind' => 'bearer', 'label' => 'x', 'secret' => '   ']);
ok($r['code'] === 422 && isset($r['json']['error']['fields']['secret']), 'an empty secret → 422');
ok((int) one('SELECT credential_id FROM sources WHERE id = :s', ['s' => $square]) === $second, 'the refusals changed nothing');

echo "4. The probe uses it\n";
$r = post_json($admin, '/sales/sources/probe.php', ['source' => (string) $square]);
ok($r['code'] === 200 && ($r['json']['ok'] ?? false) === true, "Square's probe with the live token answers ok (the fixture server took the bearer)");
$lines = array_map(static fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) @file_get_contents(state_dir() . '/fixture/square.log'))));
$last = end($lines) ?: [];
ok(($last['auth_tail'] ?? '') === 'oken' && ($last['body_has_token'] ?? true) === false && ($last['version'] ?? '') !== '', 'the fixture saw the token in the Authorization header (…oken), never in the body, with a Square-Version');
ok((string) one("SELECT status FROM source_pulls WHERE source_id = :s AND kind = 'probe' ORDER BY id DESC LIMIT 1", ['s' => $square]) === 'ok' && activity('source.probe', $since) !== [], 'a probe pull row, ok, logged source.probe');
finish();
