<?php
declare(strict_types=1);
/** /production/{id} — one batch (screen `production-view`): what it used and made, cost walled. JSON: present_batch() + its movements. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$b = batch_or_404($pdo, request_integer('id') ?? request_integer('batch'));
$sees = sees_cost($site);
log_activity($pdo, 'screen.view', 'production_batch', $b['production_batch_id'], ['screen' => 'production-view', 'recipe_id' => $b['recipe_id']]);
if (wants_json()) { respond_screen(['batch' => present_batch($b, $sees), 'movements' => stock_doc_txns($pdo, 'production', $b['production_batch_id'])]); }
$notices = ['posted' => ['success', 'Posted — the inputs left the shelf and the output landed at their cost.'], 'drafted' => ['success', 'Saved as a draft — post it when it is made.']];
render_screen($b['number'], view('production/view.php', ['batch' => $b, 'movements' => document_movements($pdo, $site, 'production', $b['production_batch_id']), 'seesCost' => $sees, 'mayProduce' => has_right('stock.produce'),
    'trail' => find_record_activity($pdo, 'production_batch', $b['production_batch_id']), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'production-list', 'screen' => 'production-view', 'entity' => 'production_batch', 'recordId' => (string) $b['production_batch_id']]);
