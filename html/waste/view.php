<?php
declare(strict_types=1);
/** /waste/{id} — one waste log (screen `waste-view`): the facts, the photo, the movements (a plate's waste shows every ingredient), cost walled. */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('ri.read');
$pdo = db();
$site = (int) current_site_id();
$w = waste_or_404($pdo, request_integer('id') ?? request_integer('waste'));
$sees = sees_cost($site);
log_activity($pdo, 'screen.view', 'waste_log', $w['waste_log_id'], ['screen' => 'waste-view', 'ingredient_id' => $w['ingredient_id'], 'recipe_id' => $w['recipe_id']]);
if (wants_json()) { respond_screen(['waste' => present_waste($w, $sees), 'movements' => stock_doc_txns($pdo, 'waste', $w['waste_log_id'])]); }
$photo = $w['photo_attachment_id'] ? ri_can_see_attachment($pdo, (int) $w['photo_attachment_id']) : null;
$notices = ['reviewed' => ['success', 'Reviewed.'], 'voided' => ['success', 'Voided — its movements are reversed.']];
render_screen($w['number'], view('waste/view.php', ['waste' => $w, 'movements' => document_movements($pdo, $site, 'waste', $w['waste_log_id']), 'seesCost' => $sees, 'mayReview' => has_right('waste.review'), 'photo' => $photo,
    'trail' => find_record_activity($pdo, 'waste_log', $w['waste_log_id']), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]), ['activeNav' => 'waste-list', 'screen' => 'waste-view', 'entity' => 'waste_log', 'recordId' => (string) $w['waste_log_id']]);
