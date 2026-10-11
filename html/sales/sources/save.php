<?php
declare(strict_types=1);
/** Actions source_create (no source) / source_update (with): settings.manage at the site. The connector's settings validated (a missing required key → 422 naming it); a credential is never part of this form (source-credential). The connector is fixed once a pull exists; `_partial=1` keeps the untouched settings. Log source.create / source.update. Location /sales/sources/{id}; refresh sourceChanged. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
$me = (int) current_member_id();
$idRaw = req_val('source');
$cur = ($idRaw === null || $idRaw === '') ? null : source_or_404($pdo, $idRaw, 'settings.manage');
$scope = $cur === null ? (int) current_site_id() : (int) $cur['scope_id'];
require_right('settings.manage', $scope);
$errors = [];
$partial = $cur !== null && ri_yes('_partial', false);
$name = ri_text('name', $cur['name'] ?? null, 80, 'name', $errors, $cur === null);
$connector = $cur === null ? (string) (req_val('connector') ?? '') : (req_has('connector') && req_val('connector') !== '' ? (string) req_val('connector') : (string) $cur['connector']);
if ($connector === '') {
    $errors['connector'] = 'Choose the connector: a file or Square.';
} elseif (!isset(ri_pos_connectors()[$connector])) {
    $errors['connector'] = isset(RI_POS_EXTENDED[$connector]) ? RI_POS_EXTENDED[$connector] . ' is an Extended connector — not built in version 1. Use its file export with a mapping.' : 'There is no connector called ' . $connector . '.';
}
if ($cur !== null && $connector !== $cur['connector'] && (int) one_value($pdo, 'SELECT count(*) FROM source_pulls WHERE source_id = :s', ['s' => $cur['id']]) > 0) {
    $errors['connector'] = 'The connector is fixed once a pull exists.';
}
$keep = $cur === null ? [] : (json_decode((string) $cur['settings'], true) ?: []);
$settings = isset($errors['connector']) ? $keep : source_settings_from_request($pdo, $connector, $keep, $errors, $partial || $cur === null ? true : false);
$baseUrl = req_has('base_url') ? (req_val('base_url') ?: null) : ($cur['base_url'] ?? null);
if ($baseUrl !== null && !preg_match('#^https?://[^\s]+$#', $baseUrl)) {
    $errors['base_url'] = 'The base URL is an http(s) address.';
}
$defaultSchedule = ($connector === 'square' || ($settings['intake'] ?? '') === 'sftp') ? 60 : 0;
$schedule = ri_int('schedule_minutes', $cur === null ? $defaultSchedule : (int) $cur['schedule_minutes'], 0, 10080, 'schedule', $errors);
if ($errors !== []) {
    ri_refuse_fields($errors);
}
$id = ri_guard($pdo, static function () use ($pdo, $me, $cur, $scope, $name, $connector, $settings, $baseUrl, $schedule): int {
    $pdo->beginTransaction();
    $json = json_encode((object) $settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    try {
        if ($cur === null) {
            $st = $pdo->prepare("INSERT INTO sources (scope_id, name, connector, role, base_url, settings, schedule_minutes, created_by) VALUES (:s, :n, :c, 'pos', :b, :set::jsonb, :sch, :by) RETURNING id");
            $st->execute(['s' => $scope, 'n' => $name, 'c' => $connector, 'b' => $baseUrl, 'set' => $json, 'sch' => $schedule, 'by' => $me]);
            $id = (int) $st->fetchColumn();
        } else {
            $id = (int) $cur['id'];
            $pdo->prepare('UPDATE sources SET name = :n, connector = :c, base_url = :b, settings = :set::jsonb, schedule_minutes = :sch WHERE id = :id')
                ->execute(['n' => $name, 'c' => $connector, 'b' => $baseUrl, 'set' => $json, 'sch' => $schedule, 'id' => $id]);
        }
    } catch (PDOException $e) {
        if ((string) $e->getCode() === '23505') {
            throw new DomainException('This site already has a source called ' . $name . '.');
        }
        throw $e;
    }
    $after = source_loggable(['name' => $name, 'connector' => $connector, 'schedule_minutes' => $schedule, 'settings' => $settings, 'base_url' => $baseUrl]);
    if ($cur === null) {
        source_log($pdo, 'source.create', $id, $scope, ['after' => $after]);
    } else {
        $d = ri_diff(source_loggable($cur), $after);
        if ($d['after'] !== []) {
            source_log($pdo, 'source.update', $id, $scope, $d);
        }
    }
    $pdo->commit();
    return $id;
});
ri_done(($cur === null ? 'Added the source ' : 'Saved ') . $name, $id, ri_land(return_path('/sales/sources/' . $id), $cur === null ? 'created' : 'saved'), 'sourceChanged', ['source_id' => $id]);
