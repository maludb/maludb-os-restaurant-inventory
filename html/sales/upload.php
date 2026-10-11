<?php
declare(strict_types=1);
/**
 * /sales/upload — GET: screen sales-upload (stock.receive): a file source of this site (the only one preselected), the file, the mapping.
 * POST with preview=1: the first ten lines as the mapping reads them, the dates seen with their line counts and whether a run exists, the
 * unknown PLUs it will create, the rows it cannot read — NOTHING written (no pull row). POST: action sales_upload — a pull `upload`, the file
 * kept on it, the checks imported, the days depleted when `deplete` (yes by default) and due; the reply {dates_seen, sales_new, lines_seen,
 * plus_new, runs[]}; a file the mapping cannot read → the pull `failed` and 422 with the reason. Log sale.import (counts only).
 */
require_once dirname(__DIR__, 2) . '/app/features/pos/handler.php';
$pdo = db();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    require_login();
    require_right('stock.receive');
    $site = (int) current_site_id();
    $sources = file_sources_for_site($pdo, $site);
    $chosen = request_integer('source') ?? (count($sources) === 1 ? (int) $sources[0]['source_id'] : null);
    log_screen_view($pdo, 'sales-upload');
    if (wants_json()) { respond_screen(['sources' => array_map(static fn ($s) => ['source_id' => (int) $s['source_id'], 'name' => $s['name'], 'paused' => $s['paused_at'] !== null], $sources), 'source_id' => $chosen, 'mappings' => array_map(static fn ($m) => ['key' => $m['key'], 'name' => $m['name']], mapping_list($pdo))]); }
    render_screen('Upload a POS file', view('pos/upload.php', ['sources' => $sources, 'chosen' => $chosen, 'mappings' => mapping_list($pdo), 'mayCreate' => has_right('settings.manage'), 'back' => back_link()]),
        ['activeNav' => 'sales-upload', 'screen' => 'sales-upload', 'entity' => 'pos_upload']);
    return;
}
ri_handler_begin();
$me = (int) current_member_id();
$site = (int) current_site_id();
require_right('stock.receive', $site);
$preview = ri_yes('preview', false) || ($_GET['preview'] ?? '') === '1';
$srcRaw = req_val('source');
if ($srcRaw === 'new') {                                                     // "a new manual source": a file source fed by upload (the admin's)
    require_right('settings.manage', $site);
    $mapKey = req_val('mapping') ?: 'generic';
    if (mapping_for($pdo, $mapKey) === null) {
        ri_refuse_fields(['mapping' => 'There is no mapping called ' . $mapKey . '.']);
    }
    $id = (int) ri_guard($pdo, static function () use ($pdo, $site, $mapKey, $me): int {
        $name = 'POS uploads';
        $n = 1;
        while ((int) one_value($pdo, 'SELECT count(*) FROM sources WHERE scope_id = :s AND name = :n', ['s' => $site, 'n' => $name]) > 0) { $name = 'POS uploads ' . (++$n); }
        $st = $pdo->prepare("INSERT INTO sources (scope_id, name, connector, role, settings, created_by) VALUES (:s, :n, 'file', 'pos', :set::jsonb, :by) RETURNING id");
        $st->execute(['s' => $site, 'n' => $name, 'set' => json_encode(['mapping_key' => $mapKey, 'intake' => 'upload']), 'by' => $me]);
        $id = (int) $st->fetchColumn();
        source_log($pdo, 'source.create', $id, $site, ['after' => ['name' => $name, 'connector' => 'file', 'settings' => ['mapping_key' => $mapKey, 'intake' => 'upload'], 'from' => 'upload']]);
        return $id;
    });
    $srcRaw = (string) $id;
}
if ($srcRaw === null || $srcRaw === '') {
    ri_refuse_fields(['source' => 'Choose the source the file comes from.']);
}
$src = source_or_404($pdo, $srcRaw, 'stock.receive');
if ($src['connector'] === 'square') {
    refuse(422, 'This source is fed by its API — use Pull now, not a file.');
}
$settings = json_decode((string) $src['settings'], true) ?: [];
if ($src['connector'] === 'file' && in_array($settings['intake'] ?? 'upload', ['sftp'], true) && !ri_yes('force', false)) {
    // an SFTP source may still take a hand-dropped file (a missed night) — allowed; nothing to refuse
}
if ($src['paused_at'] !== null) {
    refuse(422, $src['name'] . ' is paused — resume it first.');
}
[$tmp, $fileName] = pos_uploaded_file('file');
$mapping = req_val('mapping') ?: null;
if ($mapping !== null && mapping_for($pdo, $mapping) === null) {
    ri_refuse_fields(['mapping' => 'There is no mapping called ' . $mapping . '.']);
}
if ($preview) {
    try {
        $p = pos_preview($pdo, $src, $tmp, $mapping, $fileName);
    } catch (DomainException $e) {
        refuse(422, $e->getMessage());
    }
    emit_action_status(true, ['did' => 'Previewed ' . $fileName]);
    if (wants_json()) { respond_saved(['preview' => $p, 'source_id' => (int) $src['id'], 'file_name' => $fileName]); }
    if (!is_htmx_request()) {                                                // JavaScript off: the screen again, the preview under the form
        render_screen('Upload a POS file', view('pos/upload.php', ['sources' => file_sources_for_site($pdo, $site), 'chosen' => (int) $src['id'], 'mappings' => mapping_list($pdo), 'mayCreate' => has_right('settings.manage'),
            'back' => null, 'preview' => $p, 'fileName' => $fileName, 'src' => $src]), ['activeNav' => 'sales-upload', 'screen' => 'sales-upload', 'entity' => 'pos_upload']);
        return;
    }
    echo view('pos/_upload-preview.php', ['p' => $p, 'fileName' => $fileName, 'src' => $src]);
    return;
}
$deplete = ri_yes('deplete', true);
$r = pos_import_file($pdo, $src, $tmp, $fileName, $mapping, 'upload', $me, $deplete);
if ($r['status'] === 'failed') {
    emit_action_status(false, ['error' => $r['error'], 'record_id' => $r['pull_id']]);
    refuse(422, (string) $r['error']);
}
$runs = array_values(array_filter($r['runs'], static fn ($x) => !isset($x['error'])));
$did = 'Imported ' . count($r['dates_seen']) . ' day' . (count($r['dates_seen']) === 1 ? '' : 's') . ': ' . $r['lines_seen'] . ' line' . ($r['lines_seen'] === 1 ? '' : 's') . ', ' . $r['plus_new'] . ' new PLU' . ($r['plus_new'] === 1 ? '' : 's') . '; ' . count($runs) . ' run' . (count($runs) === 1 ? '' : 's') . ' posted';
$dates = $r['dates_seen'];
$land = '/sales/?' . http_build_query(['from' => $dates === [] ? date('Y-m-d', strtotime('-30 days')) : min($dates), 'to' => $dates === [] ? date('Y-m-d') : max($dates), 'highlight' => implode(',', $dates), 'did' => $did]);
ri_done($did, $r['pull_id'], ri_land($land), 'salesChanged', ['pull_id' => $r['pull_id'], 'status' => $r['status'], 'dates_seen' => $r['dates_seen'], 'sales_new' => $r['sales_new'], 'sales_changed' => $r['sales_changed'],
    'lines_seen' => $r['lines_seen'], 'plus_new' => $r['plus_new'], 'bad_rows' => $r['bad_rows'], 'runs' => $r['runs']]);
