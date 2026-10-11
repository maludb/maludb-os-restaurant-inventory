<?php
declare(strict_types=1);
/** /suppliers/{id}/guide — the order guide at THIS site (screen `order-guide-edit` on GET; action `order_guide_set` on POST — log order_guide.set with scope_id). purchasing.draft. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    ri_handler_begin();
    require_right('purchasing.draft');
    $pdo = db();
    $site = (int) current_site_id();
    $s = supplier_param_or_404($pdo);
    $lines = [];
    if (req_has('lines')) {
        $raw = (string) req_val('lines');
        $dec = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($dec)) { ri_refuse_fields(['lines' => 'The lines are a JSON list of supplier_item, seq and preferred.']); }
        foreach ($dec as $k => $l) { if (!is_array($l)) { continue; } $lines[] = ['supplier_item_id' => (int) ($l['supplier_item'] ?? $l['supplier_item_id'] ?? 0), 'seq' => (int) ($l['seq'] ?? $k + 1), 'preferred' => in_array((string) ($l['preferred'] ?? 'yes'), ['1', 'yes', 'true'], true)]; }
    } elseif (isset($_POST['line']) && is_array($_POST['line'])) {
        foreach ($_POST['line'] as $k => $l) { if (!is_array($l) || empty($l['supplier_item'])) { continue; } $lines[] = ['supplier_item_id' => (int) $l['supplier_item'], 'seq' => (int) ($l['seq'] ?? $k + 1), 'preferred' => !empty($l['preferred']) && $l['preferred'] !== 'no']; }
    }
    $own = array_column(supplier_items($pdo, $s['supplier_id'], null, false), 'supplier_item_id');
    foreach ($lines as $l) { if (!in_array($l['supplier_item_id'], $own, true)) { ri_refuse_fields(['lines' => 'Item #' . $l['supplier_item_id'] . ' is not one of ' . $s['name'] . "'s."]); } }
    usort($lines, static fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
    foreach ($lines as $k => &$l) { $l['seq'] = $k + 1; }
    unset($l);
    $errors = [];
    $name = ri_text('name', 'Order guide', 80, 'The name', $errors) ?? 'Order guide';
    if ($errors !== []) { ri_refuse_fields($errors); }
    $gid = ri_guard($pdo, static function () use ($pdo, $s, $site, $name, $lines): int {
        $pdo->beginTransaction();
        $gid = set_order_guide($pdo, $s['supplier_id'], $site, $name, $lines);
        catalog_log($pdo, 'order_guide.set', 'order_guide', $gid, ['scope_id' => $site, 'after' => ['supplier_id' => $s['supplier_id'], 'lines' => count($lines), 'supplier_item_ids' => array_column($lines, 'supplier_item_id')]]);
        $pdo->commit();
        return $gid;
    });
    ri_done('Saved ' . $s['name'] . "'s order guide: " . count($lines) . ' item' . (count($lines) === 1 ? '' : 's'), $gid, ri_land('/suppliers/' . $s['supplier_id'] . '/guide', 'guide_saved'), 'supplierChanged', ['supplier_id' => $s['supplier_id'], 'lines' => count($lines)]);
}
require_login();
require_right('purchasing.draft');
$pdo = db();
$site = (int) current_site_id();
$s = catalog_supplier_or_404($pdo, request_integer('id') ?? request_integer('supplier'));
$guide = order_guide($pdo, $s['supplier_id'], $site);
log_screen_view($pdo, 'order-guide-edit');
if (wants_json()) { respond_screen(['supplier' => present_supplier($s), 'guide' => $guide, 'items' => array_map('present_supplier_item', supplier_items($pdo, $s['supplier_id']))]); }
$notices = ['guide_saved' => ['success', 'The order guide is saved.']];
render_screen($s['name'] . ' · order guide', view('catalog/order-guide.php', ['s' => $s, 'guide' => $guide, 'items' => supplier_items($pdo, $s['supplier_id']), 'site' => current_site(), 'seesCost' => sees_cost(), 'notice' => ri_notice($_GET['notice'] ?? null, $notices)]),
    ['activeNav' => 'supplier-list', 'screen' => 'order-guide-edit', 'entity' => 'supplier', 'recordId' => (string) $s['supplier_id']]);
