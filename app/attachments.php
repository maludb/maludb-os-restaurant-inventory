<?php
declare(strict_types=1);

/**
 * The one attachment contract (sso-shell.md "Query functions"): every later slice stores a file with attachment_store(), serves it through
 * html/files.php (the gated door) by ri_can_see_attachment() and attachment_path(), and removes it with attachment_delete(). The size cap is
 * ATTACHMENT_MAX_BYTES (25 MB by default); the type is SNIFFED (finfo), never taken from the browser; the allow-list is short (jpeg, png,
 * webp, heic, gif, pdf, csv, xlsx, txt — DECISION); the file lives under storage/attachments/<record_type>/<record_id>/<id>-<safe name>
 * (RI_STORAGE_DIR, else APP_ROOT/storage); the row carries the sha256. No thumbnailing in version 1 (the browser's <img> at card size).
 */

const ATTACHMENT_MIME_ALLOW = ['image/png', 'image/jpeg', 'image/webp', 'image/gif', 'image/heic', 'image/heif', 'application/pdf', 'text/csv', 'text/plain',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
const ATTACHMENT_REFUSAL = 'That kind of file is not accepted (a photo, a PDF, CSV, XLSX, text).';

function attachment_root(): string
{
    $dir = (string) (env('RI_STORAGE_DIR', '') ?: (APP_ROOT . '/storage'));
    return rtrim($dir, '/');
}

/** The cap in bytes: ATTACHMENT_MAX_BYTES when set; 25 MB otherwise; never under 1 MB. */
function attachment_max_bytes(): int
{
    $env = (int) (env('ATTACHMENT_MAX_BYTES', '0') ?: 0);
    return max(1048576, $env > 0 ? $env : 26214400);
}

/**
 * Store a file on a record. $file is a $_FILES entry or ['tmp_name', 'name', 'type', 'size'] (a file the worker or an import made).
 * Refuses in a DomainException (ri_guard() turns it into a 422). Returns the attachment id.
 */
function attachment_store(PDO $pdo, string $recordType, int $recordId, array $file, int $by): int
{
    if (!preg_match('/^[a-z_]{1,40}$/', $recordType)) {
        throw new DomainException('That is not a record a file attaches to.');
    }
    if (array_key_exists('error', $file) && (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new DomainException(in_array((int) $file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'The upload did not arrive.');
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) {
        throw new DomainException('The upload did not arrive.');
    }
    $max = attachment_max_bytes();
    $size = (int) ($file['size'] ?? filesize($tmp));
    if ($size > $max) {
        throw new DomainException('A file is at most ' . intdiv($max, 1048576) . ' MB.');
    }
    if ($size < 1) {
        throw new DomainException('That file is empty.');
    }
    $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $name = basename(str_replace('\\', '/', (string) ($file['name'] ?? 'file')));
    $name = mb_substr(preg_replace('/[^\w.\- ()]+/u', '_', $name) ?: 'file', 0, 120);
    if (!in_array($mime, ATTACHMENT_MIME_ALLOW, true) || preg_match('/\.(exe|bat|cmd|com|msi|scr|ps1|sh|js|vbs|jar|dll|html?|svg|php|phtml)$/i', $name)) {
        throw new DomainException(ATTACHMENT_REFUSAL);
    }
    $sha = hash_file('sha256', $tmp);
    $st = $pdo->prepare('INSERT INTO attachments (record_type, record_id, filename, mime_type, byte_size, sha256, storage_path, uploaded_by) VALUES (:t, :rid, :n, :m, :s, :h, :p, :by) RETURNING id');
    $st->execute(['t' => $recordType, 'rid' => $recordId, 'n' => $name, 'm' => $mime, 's' => $size, 'h' => $sha, 'p' => 'pending', 'by' => $by]);
    $id = (int) $st->fetchColumn();
    $dir = attachment_root() . '/attachments/' . $recordType . '/' . $recordId;
    if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new DomainException('The attachment store is not writable.');
    }
    $path = 'attachments/' . $recordType . '/' . $recordId . '/' . $id . '-' . $name;
    $full = attachment_root() . '/' . $path;
    if (!(is_uploaded_file($tmp) ? move_uploaded_file($tmp, $full) : rename($tmp, $full))) {
        throw new DomainException('The file could not be stored.');
    }
    @chmod($full, 0660);
    $pdo->prepare('UPDATE attachments SET storage_path = :p WHERE id = :id')->execute(['p' => $path, 'id' => $id]);
    return $id;
}

/** The mcp_attachments row (what the caller may see), or null. Today: ri_is_member_here() — every record is every reader's. */
function ri_can_see_attachment(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT attachment_id, record_type, record_id, filename, mime_type, byte_size, uploaded_by, created_at FROM mcp_attachments WHERE attachment_id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** The absolute path of an attachment's file (the base table's storage_path, under the store), or '' when it is not there. */
function attachment_path(array $row): string
{
    $id = (int) ($row['attachment_id'] ?? $row['id'] ?? 0);
    $st = db()->prepare('SELECT storage_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $rel = (string) ($st->fetchColumn() ?: '');
    if ($rel === '' || $rel === 'pending') {
        return '';
    }
    $root = realpath(attachment_root()) ?: attachment_root();
    $full = realpath($root . '/' . ltrim($rel, '/'));
    return $full !== false && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full) ? $full : '';
}

/** Remove the row and the file. */
function attachment_delete(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT storage_path FROM attachments WHERE id = :id');
    $st->execute(['id' => $id]);
    $rel = (string) ($st->fetchColumn() ?: '');
    $pdo->prepare('DELETE FROM attachments WHERE id = :id')->execute(['id' => $id]);
    if ($rel !== '' && $rel !== 'pending') {
        $root = realpath(attachment_root()) ?: attachment_root();
        $full = realpath($root . '/' . ltrim($rel, '/'));
        if ($full !== false && str_starts_with($full, $root . DIRECTORY_SEPARATOR) && is_file($full)) {
            @unlink($full);
        }
    }
}

/** Bytes in words: 12 KB, 3.4 MB. */
function fmt_bytes(int $n): string
{
    if ($n >= 1048576) { return rtrim(rtrim(number_format($n / 1048576, 1, '.', ''), '0'), '.') . ' MB'; }
    if ($n >= 1024) { return (int) round($n / 1024) . ' KB'; }
    return $n . ' B';
}
