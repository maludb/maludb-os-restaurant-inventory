<?php
declare(strict_types=1);
/** /sales/sources/{source}/pulls?page= — screen pull-list: the source's pulls in full (kind, started, finished, status, file — a link to the kept attachment —, dates seen, sales seen / new, lines, PLUs new, error, by). */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$src = source_or_404($pdo, (string) (request_integer('id') ?? ''));
$page = max(1, request_integer('page') ?? 1);
$pulls = source_pulls($pdo, (int) $src['id'], $page, 50);
$total = (int) ($pulls[0]['total'] ?? 0);
log_screen_view($pdo, 'pull-list', ['scope_id' => (int) $src['scope_id']]);
if (wants_json()) { respond_screen(['source_id' => (int) $src['id'], 'page' => $page, 'total' => $total, 'pulls' => array_map('present_pull', $pulls)]); }
render_screen('Pulls · ' . $src['name'], view('pos/sources/pulls.php', ['src' => $src, 'pulls' => $pulls, 'page' => $page, 'total' => $total, 'tz' => site_timezone((int) $src['scope_id']), 'back' => back_link()]),
    ['activeNav' => 'source-list', 'screen' => 'pull-list', 'entity' => 'source', 'recordId' => (string) $src['id']]);
