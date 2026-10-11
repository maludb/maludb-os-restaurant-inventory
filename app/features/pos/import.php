<?php
declare(strict_types=1);

/**
 * The import (pos-and-depletion.md §4; pos.md "The connector interface"): a pull is opened, the file (or the API's orders) becomes
 * normalized checks, each check is upserted in ONE TRANSACTION PER BUSINESS DATE — a check unknown here is inserted; a check already
 * here whose content is the same is left alone (a re-import of the same file changes nothing and makes no day due); a check whose
 * content changed (a Square order refunded since) has its header refreshed, its lines replaced and `imported_at` moved, so
 * ri_depletion_due() lists its day again. ri_pos_line_resolve() (db/011) resolves every PLU — an unknown one becomes an unmapped
 * menu item, logged `plu.queue` — and ri_pos_sales_before() sets the day part. The pull is closed with its counts; when the caller asks,
 * the days seen that are due are depleted (ri_depletion_run(), one run per site per day). Nothing of a check's content is logged.
 */
require_once __DIR__ . '/connectors/Connector.php';
require_once __DIR__ . '/credentials.php';
require_once __DIR__ . '/queries.php';

/** The site's zone and sales cut ('04:00:00'). */
function pos_site_clock(PDO $pdo, int $scopeId): array
{
    $st = $pdo->prepare('SELECT s.timezone, COALESCE(ss.sales_cut_time, \'04:00\')::text AS cut FROM sites s LEFT JOIN site_settings ss ON ss.scope_id = s.scope_id WHERE s.scope_id = :s');
    $st->execute(['s' => $scopeId]);
    $r = $st->fetch() ?: ['timezone' => 'UTC', 'cut' => '04:00:00'];
    return [(string) $r['timezone'], substr((string) $r['cut'], 0, 8)];
}

/** What a connector is handed (never a database handle): the settings decoded, the credential opened, the clock. */
function pos_connector_source(PDO $pdo, array $source): array
{
    [$tz, $cut] = pos_site_clock($pdo, (int) $source['scope_id']);
    $settings = is_array($source['settings']) ? $source['settings'] : (json_decode((string) $source['settings'], true) ?: []);
    return ['id' => (int) $source['id'], 'connector' => (string) $source['connector'], 'settings' => $settings, 'base_url' => $source['base_url'] ?? null,
            'credential' => credential_open_for($pdo, $source + ['credential_id' => $source['credential_id'] ?? null]), 'timeout' => 20, 'tz' => $tz, 'cut' => $cut];
}

function pos_pull_open(PDO $pdo, int $sourceId, string $kind, ?string $fileName, ?int $by): int
{
    $st = $pdo->prepare('INSERT INTO source_pulls (source_id, kind, file_name, started_by) VALUES (:s, :k, :f, :by) RETURNING id');
    $st->execute(['s' => $sourceId, 'k' => $kind, 'f' => $fileName === null ? null : mb_substr($fileName, 0, 200), 'by' => $by]);
    return (int) $st->fetchColumn();
}

/** Close a pull with its counts; on ok/partial the source's health resets, on failure the backoff grows (15 min × failures, ≤ 6 h). */
function pos_pull_close(PDO $pdo, int $pullId, int $sourceId, string $status, array $counts, ?string $error = null, ?int $attachmentId = null): void
{
    $pdo->prepare('UPDATE source_pulls SET finished_at = now(), status = :st, dates_seen = :d::date[], sales_seen = :ss, sales_new = :sn, lines_seen = :ls, plus_new = :pn, error = :e,
                   attachment_id = COALESCE(:a, attachment_id) WHERE id = :id')
        ->execute(['st' => $status, 'd' => pg_array_literal($counts['dates_seen'] ?? []), 'ss' => (int) ($counts['sales_seen'] ?? 0), 'sn' => (int) ($counts['sales_new'] ?? 0),
                   'ls' => (int) ($counts['lines_seen'] ?? 0), 'pn' => (int) ($counts['plus_new'] ?? 0), 'e' => $error === null ? null : mb_substr($error, 0, 200), 'a' => $attachmentId, 'id' => $pullId]);
    if ($status === 'failed') {
        $pdo->prepare("UPDATE sources SET last_pull_id = :p, consecutive_failures = consecutive_failures + 1,
                       backoff_until = now() + least(interval '15 minutes' * (consecutive_failures + 1), interval '6 hours') WHERE id = :s")->execute(['p' => $pullId, 's' => $sourceId]);
    } else {
        $pdo->prepare('UPDATE sources SET last_pull_id = :p, last_ok_at = now(), consecutive_failures = 0, backoff_until = NULL WHERE id = :s')->execute(['p' => $pullId, 's' => $sourceId]);
    }
}

/** A check's content as a comparable signature (numbers rounded, times as epochs) — the same for what came in and what is here. */
function pos_check_signature(array $c): string
{
    $lines = [];
    foreach ($c['lines'] as $l) {
        $mods = [];
        foreach ((array) $l['modifiers'] as $m) {
            $mods[] = [(string) $m['code'], round((float) ($m['qty'] ?? 1), 4)];
        }
        $lines[] = [(string) $l['external_line_id'], (string) $l['plu'], round((float) $l['qty'], 4), $l['unit_price'] === null ? null : round((float) $l['unit_price'], 2),
                    $l['line_total'] === null ? null : round((float) $l['line_total'], 2), (bool) $l['voided'], (bool) $l['comp'], $mods];
    }
    return json_encode([(string) $c['business_date'], $c['closed_at'] === null ? null : (new DateTimeImmutable((string) $c['closed_at']))->getTimestamp(),
                        $c['covers'] === null ? null : (int) $c['covers'], $lines]);
}

/** The check already here, as a normalized check (for the signature). */
function pos_existing_check(PDO $pdo, int $saleId): array
{
    $s = $pdo->query('SELECT business_date::text AS business_date, closed_at::text AS closed_at, covers FROM pos_sales WHERE id = ' . $saleId)->fetch();
    $lines = [];
    foreach ($pdo->query('SELECT external_line_id, plu, qty, unit_price, line_total, voided, comp, modifiers FROM pos_sale_lines WHERE sale_id = ' . $saleId . ' ORDER BY id')->fetchAll() as $l) {
        $lines[] = ['external_line_id' => (string) $l['external_line_id'], 'plu' => (string) $l['plu'], 'qty' => (float) $l['qty'], 'unit_price' => $l['unit_price'], 'line_total' => $l['line_total'],
                    'voided' => (bool) $l['voided'], 'comp' => (bool) $l['comp'], 'modifiers' => json_decode((string) $l['modifiers'], true) ?: []];
    }
    return ['business_date' => $s['business_date'], 'closed_at' => $s['closed_at'], 'covers' => $s['covers'], 'lines' => $lines];
}

/**
 * Upsert normalized checks for a source under a pull. Answers ['sales_seen', 'sales_new', 'sales_changed', 'lines_seen', 'plus_new',
 * 'dates_seen', 'new_plus' => [menu_item_id …], 'errors' => [sentence …]].
 */
function pos_import_checks(PDO $pdo, array $source, int $pullId, array $checks): array
{
    $sid = (int) $source['id'];
    $scope = (int) $source['scope_id'];
    $before = array_map('intval', $pdo->query('SELECT id FROM menu_items WHERE pos_source_id = ' . $sid)->fetchAll(PDO::FETCH_COLUMN));
    $out = ['sales_seen' => 0, 'sales_new' => 0, 'sales_changed' => 0, 'lines_seen' => 0, 'plus_new' => 0, 'dates_seen' => [], 'new_plus' => [], 'errors' => []];
    $byDate = [];
    foreach ($checks as $c) {
        $byDate[$c['business_date']][] = $c;
    }
    ksort($byDate);
    $find = $pdo->prepare('SELECT id FROM pos_sales WHERE source_id = :s AND external_id = :e');
    $insSale = $pdo->prepare('INSERT INTO pos_sales (scope_id, source_id, external_id, business_date, closed_at, covers, subtotal, tax, total, comps, voids, pull_id)
                              VALUES (:scope, :s, :e, :bd, :ca, :cv, :sub, :tax, :tot, :comps, :voids, :p) RETURNING id');
    $updSale = $pdo->prepare('UPDATE pos_sales SET business_date = :bd, closed_at = :ca, covers = :cv, subtotal = :sub, tax = :tax, total = :tot, comps = :comps, voids = :voids,
                              pull_id = :p, imported_at = now(), day_part_id = NULL WHERE id = :id');
    $insLine = $pdo->prepare('INSERT INTO pos_sale_lines (sale_id, external_line_id, plu, name, qty, unit_price, line_total, voided, comp, modifiers)
                              VALUES (:sale, :x, :plu, :n, :q, :up, :lt, :v, :c, :m::jsonb)');
    foreach ($byDate as $date => $group) {
        $pdo->beginTransaction();
        try {
            foreach ($group as $c) {
                $out['sales_seen']++;
                $out['lines_seen'] += count($c['lines']);
                $find->execute(['s' => $sid, 'e' => $c['external_id']]);
                $existing = $find->fetchColumn();
                $args = ['bd' => $c['business_date'], 'ca' => $c['closed_at'], 'cv' => $c['covers'], 'sub' => $c['subtotal'], 'tax' => $c['tax'], 'tot' => $c['total'],
                         'comps' => $c['comps'], 'voids' => $c['voids'], 'p' => $pullId];
                if ($existing === false) {
                    $insSale->execute($args + ['scope' => $scope, 's' => $sid, 'e' => $c['external_id']]);
                    $saleId = (int) $insSale->fetchColumn();
                    $out['sales_new']++;
                } else {
                    $saleId = (int) $existing;
                    if (pos_check_signature(pos_existing_check($pdo, $saleId)) === pos_check_signature($c)) {
                        continue;                                                   // the same check: nothing changes, no day becomes due
                    }
                    $updSale->execute($args + ['id' => $saleId]);
                    $pdo->exec('DELETE FROM pos_sale_lines WHERE sale_id = ' . $saleId);
                    $out['sales_changed']++;
                }
                foreach ($c['lines'] as $l) {
                    $insLine->execute(['sale' => $saleId, 'x' => $l['external_line_id'], 'plu' => $l['plu'], 'n' => $l['name'], 'q' => $l['qty'], 'up' => $l['unit_price'],
                                       'lt' => $l['line_total'], 'v' => $l['voided'] ? 't' : 'f', 'c' => $l['comp'] ? 't' : 'f', 'm' => json_encode(array_values($l['modifiers']), JSON_UNESCAPED_UNICODE)]);
                }
            }
            $pdo->commit();
            $out['dates_seen'][] = (string) $date;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $out['errors'][] = $date . ': ' . (str_contains($e->getMessage(), 'ERROR:') ? db_message($e, 'the day could not be imported') : $e->getMessage());
        }
    }
    $after = array_map('intval', $pdo->query('SELECT id FROM menu_items WHERE pos_source_id = ' . $sid)->fetchAll(PDO::FETCH_COLUMN));
    $out['new_plus'] = array_values(array_diff($after, $before));
    $out['plus_new'] = count($out['new_plus']);
    foreach ($out['new_plus'] as $mid) {
        $m = $pdo->query('SELECT plu, name, recipe_id FROM menu_items WHERE id = ' . $mid)->fetch();
        if ($m && $m['recipe_id'] === null) {
            log_activity($pdo, 'plu.queue', 'menu_item', $mid, ['scope_id' => $scope, 'after' => ['plu' => $m['plu'], 'name' => $m['name'], 'source_id' => $sid, 'pull_id' => $pullId]]);
        }
    }
    return $out;
}

/** Read a file through a source's mapping (no writes) — the preview and the import share it. */
function pos_read_file(PDO $pdo, array $source, string $path, ?string $mappingKey, ?string $fileName): array
{
    $settings = is_array($source['settings']) ? $source['settings'] : (json_decode((string) $source['settings'], true) ?: []);
    $key = $mappingKey ?: (string) ($settings['mapping_key'] ?? 'generic');
    $mapping = mapping_for($pdo, $key);
    if ($mapping === null) {
        throw new DomainException('There is no mapping called ' . $key . '.');
    }
    [$tz, $cut] = pos_site_clock($pdo, (int) $source['scope_id']);
    $m = $mapping['mapping'];
    if (($settings['delimiter'] ?? '') !== '' && ($m['delimiter'] ?? '') === '') {
        $m['delimiter'] = $settings['delimiter'];
    }
    return RiPosFileConnector::read($path, $m, $tz, $cut, $fileName) + ['mapping_key' => $key];
}

/** The upload's preview: the first ten lines as read, the dates seen with their line counts and whether a run exists, the unknown PLUs, the rows it cannot read. Writes nothing. */
function pos_preview(PDO $pdo, array $source, string $path, ?string $mappingKey, ?string $fileName): array
{
    $r = pos_read_file($pdo, $source, $path, $mappingKey, $fileName);
    $rows = [];
    $dates = [];
    $plus = [];
    foreach ($r['checks'] as $c) {
        foreach ($c['lines'] as $l) {
            if (count($rows) < 10) {
                $rows[] = ['business_date' => $c['business_date'], 'external_id' => $c['external_id'], 'plu' => $l['plu'], 'name' => $l['name'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'],
                           'line_total' => $l['line_total'], 'voided' => $l['voided'], 'comp' => $l['comp'], 'modifiers' => array_map(static fn ($m) => $m['code'] . ((float) $m['qty'] !== 1.0 ? ' ×' . $m['qty'] : ''), $l['modifiers'])];
            }
            $dates[$c['business_date']] = ($dates[$c['business_date']] ?? 0) + 1;
            $plus[$l['plu']] ??= $l['name'] ?? $l['plu'];
        }
    }
    ksort($dates);
    $known = [];
    if ($plus !== []) {
        $st = $pdo->prepare('SELECT plu FROM menu_items WHERE pos_source_id = :s AND plu = ANY (:p::text[])');
        $st->execute(['s' => $source['id'], 'p' => pg_array_literal(array_map('strval', array_keys($plus)))]);
        $known = $st->fetchAll(PDO::FETCH_COLUMN);
    }
    $unknown = [];
    foreach ($plus as $p => $n) {
        if (!in_array((string) $p, $known, true)) {
            $unknown[] = ['plu' => (string) $p, 'name' => (string) $n];
        }
    }
    $runs = [];
    if ($dates !== []) {
        $st = $pdo->prepare("SELECT business_date::text, status, run_no FROM depletion_runs WHERE scope_id = :s AND status <> 'void' AND business_date = ANY (:d::date[])");
        $st->execute(['s' => $source['scope_id'], 'd' => pg_array_literal(array_keys($dates))]);
        foreach ($st->fetchAll() as $x) {
            $runs[$x['business_date']] = $x;
        }
    }
    $dateRows = [];
    foreach ($dates as $d => $n) {
        $dateRows[] = ['date' => $d, 'lines' => $n, 'run' => $runs[$d]['status'] ?? null, 'run_no' => isset($runs[$d]) ? (int) $runs[$d]['run_no'] : null];
    }
    return ['mapping_key' => $r['mapping_key'], 'kind' => $r['kind'], 'missing' => $r['missing'], 'rows_read' => $r['rows'], 'checks' => count($r['checks']), 'lines' => array_sum($dates),
            'preview' => $rows, 'dates' => $dateRows, 'unknown_plus' => $unknown, 'bad' => array_slice($r['bad'], 0, 50), 'bad_count' => count($r['bad'])];
}

/**
 * A file into a source as one pull (upload, email, or a file an SFTP pull fetched): kept as an attachment of the pull when the source
 * keeps files (by default), read through the mapping, imported; depleted when asked. Answers the pull's counts + `pull_id`, `runs`,
 * `status`, `error`. A file the mapping cannot read closes the pull `failed` with the reason (and answers it — the handler says 422).
 */
function pos_import_file(PDO $pdo, array $source, string $path, string $fileName, ?string $mappingKey, string $kind, ?int $by, bool $deplete): array
{
    $pullId = pos_pull_open($pdo, (int) $source['id'], $kind, $fileName, $by);
    $attachmentId = pos_keep_file($pdo, $source, $pullId, $path, $fileName, $by);
    try {
        $r = pos_read_file($pdo, $source, $path, $mappingKey, $fileName);
    } catch (DomainException $e) {
        pos_pull_close($pdo, $pullId, (int) $source['id'], 'failed', [], $e->getMessage(), $attachmentId);
        return ['pull_id' => $pullId, 'status' => 'failed', 'error' => $e->getMessage(), 'dates_seen' => [], 'sales_new' => 0, 'lines_seen' => 0, 'plus_new' => 0, 'runs' => []];
    }
    if ($r['missing'] !== []) {
        $err = 'The file lacks the column' . (count($r['missing']) === 1 ? ' ' : 's ') . implode(', ', $r['missing']) . ' that the mapping ' . $r['mapping_key'] . ' needs.';
        pos_pull_close($pdo, $pullId, (int) $source['id'], 'failed', [], $err, $attachmentId);
        return ['pull_id' => $pullId, 'status' => 'failed', 'error' => $err, 'dates_seen' => [], 'sales_new' => 0, 'lines_seen' => 0, 'plus_new' => 0, 'runs' => []];
    }
    return pos_finish_import($pdo, $source, $pullId, $r['checks'], count($r['bad']), $by, $deplete, $attachmentId, $kind, $fileName);
}

/** The shared end of a pull: import the checks, close the pull, log, notify the unmapped once a day, deplete what is due. */
function pos_finish_import(PDO $pdo, array $source, int $pullId, array $checks, int $badRows, ?int $by, bool $deplete, ?int $attachmentId, string $kind, ?string $fileName): array
{
    $c = pos_import_checks($pdo, $source, $pullId, $checks);
    $status = ($c['errors'] !== [] || $badRows > 0) ? ($c['sales_seen'] > 0 || $c['dates_seen'] !== [] ? 'partial' : 'failed') : 'ok';
    $error = $c['errors'] !== [] ? implode('; ', $c['errors']) : ($badRows > 0 ? $badRows . ' row' . ($badRows === 1 ? '' : 's') . ' could not be read' : null);
    pos_pull_close($pdo, $pullId, (int) $source['id'], $status, $c, $error, $attachmentId);
    $after = ['source_id' => (int) $source['id'], 'pull_id' => $pullId, 'kind' => $kind, 'status' => $status, 'dates_seen' => $c['dates_seen'], 'sales_seen' => $c['sales_seen'],
              'sales_new' => $c['sales_new'], 'sales_changed' => $c['sales_changed'], 'lines_seen' => $c['lines_seen'], 'plus_new' => $c['plus_new'], 'bad_rows' => $badRows];
    log_activity($pdo, 'sale.import', 'source_pull', $pullId, ['scope_id' => (int) $source['scope_id'], 'after' => $after] + ($by === null ? ['actor_member_id' => null] : []));
    if ($c['plus_new'] > 0) {
        pos_notify_manager($pdo, (int) $source['scope_id'], 'unmapped', 'source', (int) $source['id'], $c['plus_new'] . ' new PLU' . ($c['plus_new'] === 1 ? '' : 's') . ' to map',
            'The POS import from ' . $source['name'] . ' brought PLUs no recipe maps yet — map them so their sales deplete stock.', 'unmapped:' . $source['scope_id'] . ':' . date('Y-m-d'));
    }
    $runs = $deplete ? pos_deplete_due($pdo, (int) $source['scope_id'], $c['dates_seen'], $by) : [];
    return ['pull_id' => $pullId, 'status' => $status, 'error' => $error, 'runs' => $runs, 'bad_rows' => $badRows, 'file_name' => $fileName] + $c;
}

/**
 * Keep a file when the source keeps files — best effort: a file the store refuses is not kept, the import still runs. The attachment's
 * record is the SOURCE (db/006's attachments.record_type admits `source`, not `source_pull`) and the pull's `attachment_id` names it: the
 * file is listed on the source's files and linked from its pull row.
 */
function pos_keep_file(PDO $pdo, array $source, int $pullId, string $path, string $fileName, ?int $by): ?int
{
    $settings = is_array($source['settings']) ? $source['settings'] : (json_decode((string) $source['settings'], true) ?: []);
    if (array_key_exists('keep_files', $settings) && !$settings['keep_files']) {
        return null;
    }
    $copy = tempnam(sys_get_temp_dir(), 'rikeep');
    copy($path, $copy);
    try {
        return attachment_store($pdo, 'source', (int) $source['id'], ['tmp_name' => $copy, 'name' => $fileName, 'size' => filesize($copy)], (int) ($by ?? 0) ?: (int) ($source['created_by'] ?? 1));
    } catch (Throwable $e) {
        @unlink($copy);
        return null;
    }
}

/**
 * Deplete the dates given that are DUE (ri_depletion_due(): sales newer than their live run) — a day already run and unchanged is not
 * run again. Each run in its own statement; a failure (a site with no storage area) is answered with its sentence and the day left due.
 */
function pos_deplete_due(PDO $pdo, int $scopeId, array $dates, ?int $by): array
{
    if ($dates === []) {
        return [];
    }
    $st = $pdo->prepare('SELECT business_date::text FROM ri_depletion_due() WHERE scope_id = :s AND business_date = ANY (:d::date[]) ORDER BY 1');
    $st->execute(['s' => $scopeId, 'd' => pg_array_literal($dates)]);
    $runs = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) {
        $runs[] = pos_run_day($pdo, $scopeId, (string) $d, $by);
    }
    return $runs;
}

/** One depletion run, logged `depletion.run` with the card's counts; a refusal answered as ['error'] (and notified once a day when the worker ran it). */
function pos_run_day(PDO $pdo, int $scopeId, string $date, ?int $by): array
{
    try {
        $st = $pdo->prepare('SELECT * FROM ri_depletion_run(:s, :d::date, :by)');
        $st->execute(['s' => $scopeId, 'd' => $date, 'by' => $by]);
        $r = $st->fetch();
        $card = run_card($r);
        log_activity($pdo, 'depletion.run', 'depletion_run', (int) $r['id'], ['scope_id' => $scopeId, 'after' => $card] + ($by === null ? ['actor_member_id' => null] : []));
        return $card;
    } catch (PDOException $e) {
        $msg = db_message($e, 'The depletion could not run.');
        log_activity($pdo, 'depletion.run', 'pos_day', null, ['scope_id' => $scopeId, 'after' => ['business_date' => $date, 'error' => $msg]] + ($by === null ? ['actor_member_id' => null] : []));
        if ($by === null) {
            pos_notify_manager($pdo, $scopeId, 'pull_failed', 'pos_day', null, 'The depletion of ' . $date . ' could not run', $msg, 'depletion:' . $scopeId . ':' . $date);
        }
        return ['business_date' => $date, 'error' => $msg];
    }
}

/** The run's card (what a screen, the JSON and the log show). */
function run_card(array $r): array
{
    return ['run_id' => (int) $r['id'], 'business_date' => (string) $r['business_date'], 'status' => (string) $r['status'], 'run_no' => (int) $r['run_no'],
            'lines_total' => (int) $r['lines_total'], 'lines_depleted' => (int) $r['lines_depleted'], 'lines_unmapped' => (int) $r['lines_unmapped'], 'lines_voided' => (int) $r['lines_voided'],
            'modifiers_unmapped' => (int) $r['modifiers_unmapped'], 'ingredients' => (int) $r['ingredients'], 'value' => $r['value'] === null ? null : (string) $r['value']];
}

/** The site's Manager: the site's setting, else the business's, else the first member holding the manager role there, else an admin there. */
function pos_site_manager(PDO $pdo, int $scopeId): ?int
{
    $id = one_value($pdo, "SELECT COALESCE((SELECT manager_member_id FROM site_settings WHERE scope_id = :s), (SELECT manager_member_id FROM ri_settings WHERE id = 1),
        (SELECT r.member_id FROM member_site_roles r JOIN members m ON m.id = r.member_id WHERE r.scope_id = :s2 AND 'manager' = ANY (r.roles) AND m.status = 'active' AND m.member_kind = 'human' ORDER BY r.member_id LIMIT 1),
        (SELECT r.member_id FROM member_site_roles r JOIN members m ON m.id = r.member_id WHERE r.scope_id = :s3 AND 'admin' = ANY (r.roles) AND m.status = 'active' AND m.member_kind = 'human' ORDER BY r.member_id LIMIT 1))",
        ['s' => $scopeId, 's2' => $scopeId, 's3' => $scopeId]);
    return $id === null ? null : (int) $id;
}

function pos_notify_manager(PDO $pdo, int $scopeId, string $kind, string $recordType, ?int $recordId, string $title, string $body, string $dedupe): void
{
    $m = pos_site_manager($pdo, $scopeId);
    if ($m === null) {
        return;
    }
    if ((int) one_value($pdo, 'SELECT count(*) FROM notifications WHERE member_id = :m AND kind = :k AND title = :t AND created_at::date = current_date', ['m' => $m, 'k' => $kind, 't' => $title]) > 0) {
        return;                                                                     // once a day (the outbox dedupes the mail; this the bell)
    }
    $pdo->prepare('SELECT ri_notify(:m, :k, :rt, :rid, :t, :b, :d, false, :s)')->execute(['m' => $m, 'k' => $kind, 'rt' => $recordType, 'rid' => $recordId, 't' => $title, 'b' => $body, 'd' => $dedupe, 's' => $scopeId]);
}

/**
 * A pull through the source's connector (Pull now, the worker's schedule): Square answers checks, SFTP answers files. Counts answered;
 * a failure closes the pull `failed`, grows the backoff, notifies the site's Manager on the third failure in a row (once a day) and is
 * RE-THROWN as a DomainException with its sentence (the handler's 422, the worker's report).
 */
function pos_run_pull(PDO $pdo, array $source, string $kind, ?int $by, bool $deplete): array
{
    if ($source['paused_at'] !== null) {
        throw new DomainException($source['name'] . ' is paused' . ($source['paused_reason'] ? ' (' . $source['paused_reason'] . ')' : '') . ' — resume it first.');
    }
    $connector = ri_pos_connector((string) $source['connector']);
    $settings = json_decode((string) $source['settings'], true) ?: [];
    if ($source['connector'] === 'file' && ($settings['intake'] ?? 'upload') !== 'sftp') {
        throw new DomainException('This source is fed by ' . (($settings['intake'] ?? '') === 'email' ? 'email' : 'upload') . ' — drop a file.');
    }
    if ($source['connector'] === 'manual') {
        throw new DomainException('This source is fed by upload — drop a file.');
    }
    $pullId = pos_pull_open($pdo, (int) $source['id'], $kind, null, $by);
    log_activity($pdo, 'source.pull_start', 'source', (int) $source['id'], ['scope_id' => (int) $source['scope_id'], 'after' => ['pull_id' => $pullId, 'kind' => $kind]] + ($by === null ? ['actor_member_id' => null] : []));
    try {
        $cs = pos_connector_source($pdo, $source);
        $lookback = max(1, (int) ($settings['lookback_days'] ?? 3));
        $since = ($source['last_ok_at'] === null ? new DateTimeImmutable('-' . $lookback . ' days') : (new DateTimeImmutable((string) $source['last_ok_at']))->modify('-' . $lookback . ' days'));
        $res = $connector->pull($cs, $since, new DateTimeImmutable());
        $checks = $res['checks'];
        $bad = count($res['bad']);
        $names = [];
        $attachmentId = null;
        foreach ($res['files'] as $f) {
            $names[] = $f['name'];
            $attachmentId ??= pos_keep_file($pdo, $source, $pullId, $f['path'], $f['name'], $by);
            $r = pos_read_file($pdo, $source, $f['path'], null, $f['name']);
            if ($r['missing'] !== []) {
                throw new DomainException($f['name'] . ' lacks the columns ' . implode(', ', $r['missing']) . '.');
            }
            $checks = array_merge($checks, $r['checks']);
            $bad += count($r['bad']);
            @unlink($f['path']);
        }
        if ($names !== []) {
            $pdo->prepare('UPDATE source_pulls SET file_name = :f WHERE id = :id')->execute(['f' => mb_substr(implode(', ', $names), 0, 200), 'id' => $pullId]);
        }
        if (($res['next_state'] ?? []) !== [] && isset($res['next_state']['seen_files'])) {
            $pdo->prepare('UPDATE sources SET settings = settings || :s::jsonb WHERE id = :id')->execute(['s' => json_encode(['seen_files' => $res['next_state']['seen_files']]), 'id' => $source['id']]);
        }
        $out = pos_finish_import($pdo, $source, $pullId, $checks, $bad, $by, $deplete, $attachmentId, $kind, $names === [] ? null : implode(', ', $names));
        log_activity($pdo, 'source.pull_done', 'source', (int) $source['id'], ['scope_id' => (int) $source['scope_id'], 'after' => ['pull_id' => $pullId, 'status' => $out['status'], 'sales_new' => $out['sales_new'], 'sales_changed' => $out['sales_changed'], 'dates_seen' => $out['dates_seen'], 'files' => count($names)]] + ($by === null ? ['actor_member_id' => null] : []));
        return $out;
    } catch (RiPosPullError | DomainException | RiCredentialError $e) {
        $msg = $e instanceof RiCredentialError ? 'The credential could not be opened.' : $e->getMessage();
        pos_pull_close($pdo, $pullId, (int) $source['id'], 'failed', [], $msg);
        if ($e instanceof RiPosPullError && $e->status === 429 && $e->retryAfter !== null) {
            $pdo->prepare("UPDATE sources SET backoff_until = greatest(backoff_until, now() + make_interval(secs => :r)) WHERE id = :s")->execute(['r' => $e->retryAfter, 's' => $source['id']]);
        }
        log_activity($pdo, 'source.pull_fail', 'source', (int) $source['id'], ['scope_id' => (int) $source['scope_id'], 'after' => ['pull_id' => $pullId, 'kind' => $kind, 'error' => mb_substr($msg, 0, 200)]] + ($by === null ? ['actor_member_id' => null] : []));
        $fails = (int) one_value($pdo, 'SELECT consecutive_failures FROM sources WHERE id = :s', ['s' => $source['id']]);
        if ($fails >= 3) {
            pos_notify_manager($pdo, (int) $source['scope_id'], 'pull_failed', 'source', (int) $source['id'], $source['name'] . ' failed ' . $fails . ' times in a row', $msg, 'pull_failed:' . $source['id'] . ':' . date('Y-m-d'));
        }
        throw new DomainException($msg);
    }
}
