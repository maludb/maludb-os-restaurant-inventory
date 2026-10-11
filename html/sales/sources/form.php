<?php
declare(strict_types=1);
/** /sales/sources/new?connector= and /sales/sources/{source}/edit — screens source-add / source-edit (settings.manage): name, connector (file / square; Toast, Clover, Lightspeed listed disabled "Extended"), the connector's sub-form, the schedule; the connector fixed once a pull exists. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
$pdo = db();
$id = request_integer('id');
$cur = $id === null ? null : source_or_404($pdo, (string) $id, 'settings.manage');
require_right('settings.manage', $cur === null ? null : (int) $cur['scope_id']);
$connector = $cur['connector'] ?? request_string('connector', 'file');
$locked = $cur !== null && (int) one_value($pdo, 'SELECT count(*) FROM source_pulls WHERE source_id = :s', ['s' => $cur['id']]) > 0;
log_screen_view($pdo, $cur === null ? 'source-add' : 'source-edit', $cur === null ? [] : ['scope_id' => (int) $cur['scope_id']]);
if (wants_json()) { respond_screen(['source' => $cur === null ? null : present_source(source_for($pdo, (int) $cur['id']), true), 'connectors' => ri_pos_connectors(), 'extended' => RI_POS_EXTENDED, 'mappings' => array_column(mapping_list($pdo), 'name', 'key'), 'locked' => $locked]); }
render_screen($cur === null ? 'New POS source' : 'Edit ' . $cur['name'], view('pos/sources/form.php', ['cur' => $cur, 'settings' => $cur === null ? ['intake' => 'upload', 'mapping_key' => 'generic'] : (json_decode((string) $cur['settings'], true) ?: []),
    'connector' => $connector, 'locked' => $locked, 'mappings' => mapping_list($pdo), 'back' => back_link()]), ['activeNav' => 'source-list', 'screen' => $cur === null ? 'source-add' : 'source-edit', 'entity' => 'source', 'recordId' => $cur === null ? '' : (string) $cur['id']]);
