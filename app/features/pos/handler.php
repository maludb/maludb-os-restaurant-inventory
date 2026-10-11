<?php
declare(strict_types=1);

/**
 * The POS slice's shared prelude (pos.md "Handlers"): every handler ri_handler_begin() → the right at the source's site → ri_guard() →
 * the verb → log_activity (scope_id; a source's rows entity_type source) → ri_done(). The 404s answer in words.
 */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';

/** The source a request names (an id or a name at the current site) the caller may see, else 404; the caller's right asked at ITS site. */
function source_or_404(PDO $pdo, ?string $value, ?string $right = null): array
{
    $id = null;
    if ($value !== null && $value !== '') {
        if (ctype_digit($value)) {
            $id = (int) $value;
        } else {
            $id = one_value($pdo, 'SELECT source_id FROM mcp_sources WHERE scope_id = :s AND lower(name) = lower(:n)', ['s' => (int) current_site_id(), 'n' => $value]);
            $id = $id === null ? null : (int) $id;
        }
    }
    $row = $id === null ? null : source_row($pdo, $id);
    if ($row === null) {
        refuse(404, 'That source is not here.');
    }
    require_site((int) $row['scope_id']);
    if ($right !== null) {
        require_right($right, (int) $row['scope_id']);
    }
    return $row;
}

function run_or_404(PDO $pdo, ?int $id): array
{
    $r = $id === null ? null : run_for($pdo, $id);
    if ($r === null) {
        refuse(404, 'That depletion run is not here.');
    }
    require_site((int) $r['scope_id']);
    return $r;
}

/** A source's settings for its connector, validated (pos.md "The source form"); errors by field. $keep: the settings already stored (update). */
function source_settings_from_request(PDO $pdo, string $connector, array $keep, array &$errors, bool $partial): array
{
    $raw = req_val('settings');
    $in = [];
    if ($raw !== null && $raw !== '') {
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            $errors['settings'] = 'The settings are not JSON.';
            return $keep;
        }
        $in = $j;
    }
    foreach (['mapping_key', 'intake', 'email_from', 'location_id', 'delimiter', 'lookback_days'] as $k) {           // the form's flat fields
        if (req_has('settings_' . $k)) {
            $in[$k] = req_val('settings_' . $k);
        }
    }
    foreach (['host', 'port', 'path', 'pattern', 'user', 'local_dir'] as $k) {
        if (req_has('settings_sftp_' . $k)) {
            $in['sftp'][$k] = req_val('settings_sftp_' . $k);
        }
    }
    if (req_has('settings_keep_files')) {
        $in['keep_files'] = ri_yes('settings_keep_files');
    }
    $s = $partial ? array_replace_recursive($keep, $in) : array_replace($keep, $in);
    foreach ($s as $k => $v) {
        if ($v === '' || $v === null) {
            unset($s[$k]);
        }
    }
    if (isset($s['sftp']) && is_array($s['sftp'])) {
        $s['sftp'] = array_filter($s['sftp'], static fn ($v) => $v !== null && $v !== '');
        if (isset($s['sftp']['port'])) {
            $s['sftp']['port'] = (int) $s['sftp']['port'];
        }
    }
    if (isset($s['local_dir'])) {
        $s['sftp']['local_dir'] = $s['local_dir'];
        unset($s['local_dir']);
    }
    if ($connector === 'file') {
        $s['intake'] ??= 'upload';
        $s['mapping_key'] ??= 'generic';
        if (!isset(SOURCE_INTAKES[$s['intake']])) {
            $errors['settings_intake'] = 'The intake is upload, email or sftp.';
        }
        if (mapping_for($pdo, (string) $s['mapping_key']) === null) {
            $errors['settings_mapping_key'] = 'There is no mapping called ' . $s['mapping_key'] . '.';
        }
        if ($s['intake'] === 'email' && trim((string) ($s['email_from'] ?? '')) === '') {
            $errors['settings_email_from'] = 'An email source names the sender its files come from (an address or a domain).';
        }
        if ($s['intake'] === 'sftp') {
            if (trim((string) ($s['sftp']['host'] ?? '')) === '') {
                $errors['settings_sftp_host'] = 'An SFTP source needs its host.';
            }
            if (trim((string) ($s['sftp']['path'] ?? '')) === '') {
                $errors['settings_sftp_path'] = 'An SFTP source needs the folder its files are in.';
            }
        }
    } elseif ($connector === 'square') {
        if (trim((string) ($s['location_id'] ?? '')) === '') {
            $errors['settings_location_id'] = 'A Square source names its location id.';
        }
        unset($s['intake'], $s['mapping_key']);
    }
    if (isset($s['lookback_days'])) {
        $s['lookback_days'] = max(1, min(30, (int) $s['lookback_days']));
    }
    return $s;
}

/** log_activity() for a source's row. */
function source_log(PDO $pdo, string $action, int $sourceId, int $scope, array $opts = []): void
{
    log_activity($pdo, $action, 'source', $sourceId, ['scope_id' => $scope] + $opts);
}

/** What a source looks like in the log (never a credential, never an SFTP password). */
function source_loggable(array $row): array
{
    $s = is_array($row['settings']) ? $row['settings'] : (json_decode((string) $row['settings'], true) ?: []);
    unset($s['seen_files']);
    return ['name' => $row['name'], 'connector' => $row['connector'], 'schedule_minutes' => (int) $row['schedule_minutes'], 'settings' => $s, 'base_url' => $row['base_url'] ?? null];
}

/** An uploaded file from $_FILES[$name], checked for size and kind; [tmp path, original name]. */
function pos_uploaded_file(string $name = 'file'): array
{
    $f = $_FILES[$name] ?? null;
    if ($f === null || ($f['name'] ?? '') === '') {
        ri_refuse_fields([$name => 'Choose the POS export to upload (CSV or XLSX).']);
    }
    if ((int) ($f['error'] ?? 0) !== UPLOAD_ERR_OK) {
        ri_refuse_fields([$name => in_array((int) $f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'That file is too large.' : 'The upload did not arrive.']);
    }
    if ((int) $f['size'] > attachment_max_bytes()) {
        ri_refuse_fields([$name => 'A file is at most ' . intdiv(attachment_max_bytes(), 1048576) . ' MB.']);
    }
    if (!preg_match('/\.(csv|txt|xlsx)$/i', (string) $f['name'])) {
        ri_refuse_fields([$name => 'A POS export is a CSV or an XLSX file.']);
    }
    return [(string) $f['tmp_name'], basename((string) $f['name'])];
}
