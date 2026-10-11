<?php
declare(strict_types=1);

/**
 * A POS source's credential, sealed and opened — THE ONLY FILE THAT DECRYPTS (pos.md "The handlers' rules"; Inventory's
 * app/sources/credentials.php shape). libsodium secretbox under RI_SECRETS_KEY (64 hex characters = 32 bytes). Nothing here logs,
 * prints or keeps a copy; an exception's message never carries the secret or the key. The ciphertext is "v1.<base64url nonce+box>",
 * held in source_credentials.ciphertext (bytea) as its UTF-8 bytes. ri_open() is called by the connectors' callers alone
 * (pos_source_for_connector()), never by a screen.
 */

final class RiCredentialError extends RuntimeException
{
}

/** The kinds of source_credentials.kind (db/011). */
const RI_CREDENTIAL_KINDS = ['api_key', 'oauth_client', 'basic', 'sftp_password', 'sftp_key', 'bearer'];

/** The kinds a connector takes (pos.md source-credential): Square a token; an SFTP file source a password or a key. */
function credential_kinds_for(array $source): array
{
    if ($source['connector'] === 'square') {
        return ['bearer', 'api_key'];
    }
    $settings = is_array($source['settings'] ?? null) ? $source['settings'] : (json_decode((string) ($source['settings'] ?? '{}'), true) ?: []);
    if ($source['connector'] === 'file' && ($settings['intake'] ?? '') === 'sftp') {
        return ['sftp_password', 'sftp_key'];
    }
    return [];
}

function ri_secrets_key(): string
{
    $hex = (string) (env('RI_SECRETS_KEY', '') ?? '');
    if (!preg_match('~^[0-9a-fA-F]{64}$~', trim($hex))) {
        throw new RiCredentialError('secrets_key_missing: RI_SECRETS_KEY must be 64 hex characters in config/.env');
    }
    return sodium_hex2bin(trim($hex));
}

/** Seal a credential array ({kind, label, <secret field>, username?}). */
function ri_seal(array $cred): string
{
    if (!in_array($cred['kind'] ?? '', RI_CREDENTIAL_KINDS, true)) {
        throw new RiCredentialError('credential_kind_invalid');
    }
    $key = ri_secrets_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = json_encode($cred, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $box = sodium_crypto_secretbox($plain, $nonce, $key);
    sodium_memzero($plain);
    sodium_memzero($key);
    return 'v1.' . rtrim(strtr(base64_encode($nonce . $box), '+/', '-_'), '=');
}

/** Open a sealed credential; RiCredentialError on a wrong key, a tampered box or an unknown version. */
function ri_open(string $ciphertext): array
{
    $ciphertext = trim($ciphertext);
    if (!str_starts_with($ciphertext, 'v1.')) {
        throw new RiCredentialError('credential_unreadable: unknown version');
    }
    $bin = base64_decode(strtr(substr($ciphertext, 3), '-_', '+/'), true);
    if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
        throw new RiCredentialError('credential_unreadable: malformed');
    }
    $key = ri_secrets_key();
    $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $key);
    sodium_memzero($key);
    if ($plain === false) {
        throw new RiCredentialError('credential_unreadable: the seal does not open (tampered, or sealed under another key)');
    }
    $cred = json_decode($plain, true);
    sodium_memzero($plain);
    if (!is_array($cred) || !isset($cred['kind'])) {
        throw new RiCredentialError('credential_unreadable: not a credential');
    }
    return $cred;
}

/** The secret-bearing field by kind. */
function ri_credential_secret_field(string $kind): string
{
    return match ($kind) {
        'api_key' => 'api_key',
        'oauth_client' => 'client_secret',
        'basic', 'sftp_password' => 'password',
        'sftp_key' => 'private_key',
        'bearer' => 'token',
        default => 'secret',
    };
}

/** The last four a screen may show: a key's fingerprint prefix, else the secret's tail. */
function ri_credential_last4(string $kind, string $secret): string
{
    if ($secret === '') {
        return '????';
    }
    return $kind === 'sftp_key' ? substr(hash('sha256', $secret), 0, 4) : mb_substr($secret, -4);
}

/**
 * Set (or rotate) a source's credential: a new sealed row, the previous one `rotated_at`, the source's credential_id moved. Answers
 * ['credential_id', 'last4', 'rotated']. The caller is inside a transaction and logs `source.credential_set` — never the secret.
 */
function credential_set(PDO $pdo, array $source, string $kind, string $label, string $secret, ?string $username, int $by): array
{
    $cred = ['kind' => $kind, 'label' => $label, ri_credential_secret_field($kind) => $secret];
    if ($username !== null && $username !== '') {
        $cred['username'] = $username;
    }
    $sealed = ri_seal($cred);
    $last4 = ri_credential_last4($kind, $secret);
    $prev = $source['credential_id'] === null ? null : (int) $source['credential_id'];
    $st = $pdo->prepare("INSERT INTO source_credentials (source_id, kind, label, last4, ciphertext, created_by) VALUES (:s, :k, :l, :f, convert_to(:c, 'UTF8'), :by) RETURNING id");
    $st->execute(['s' => $source['id'], 'k' => $kind, 'l' => $label, 'f' => $last4, 'c' => $sealed, 'by' => $by]);
    $id = (int) $st->fetchColumn();
    sodium_memzero($sealed);
    if ($prev !== null) {
        $pdo->prepare('UPDATE source_credentials SET rotated_at = now() WHERE id = :id')->execute(['id' => $prev]);
    }
    $pdo->prepare('UPDATE sources SET credential_id = :c WHERE id = :s')->execute(['c' => $id, 's' => $source['id']]);
    return ['credential_id' => $id, 'last4' => $last4, 'rotated' => $prev !== null];
}

/** The opened credential of a source, or null (for the connectors' callers only). */
function credential_open_for(PDO $pdo, array $source): ?array
{
    if (($source['credential_id'] ?? null) === null) {
        return null;
    }
    $st = $pdo->prepare("SELECT convert_from(ciphertext, 'UTF8') FROM source_credentials WHERE id = :id AND source_id = :s");
    $st->execute(['id' => $source['credential_id'], 's' => $source['id']]);
    $c = $st->fetchColumn();
    return $c === false ? null : ri_open((string) $c);
}
