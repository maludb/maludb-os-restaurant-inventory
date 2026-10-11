<?php /** One supplier at this site (screen `supplier-view`). Data: full, tab, site, may (write, delete, draft, notes), deletable, seesCost, tz, here, me, isAdmin, purchase_orders, invoices, alerts, trail, notice */
$s = $full['supplier'];
$id = (int) $s['supplier_id'];
$pdo = db();
$tabs = ['schedules' => 'Schedules', 'items' => 'Items', 'guide' => 'Order guide', 'purchase_orders' => 'Purchase orders', 'invoices' => 'Invoices', 'alerts' => 'Price alerts', 'notes' => 'Notes', 'trail' => 'Trail'];
if (!isset($tabs[$tab])) { $tab = 'schedules'; }
$actions = '';
if ($may['write']) {
    $actions .= hx_link(with_back('/suppliers/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch me-1', 'id="supplier-view-edit-btn"');
    $actions .= hx_link(with_back('/suppliers/' . $id . '/schedules', $here), 'Schedules', 'btn btn-light btn-touch me-1', 'id="supplier-view-schedules-btn"');
    $actions .= hx_link(with_back('/suppliers/' . $id . '/items', $here), 'Items', 'btn btn-light btn-touch me-1', 'id="supplier-view-items-btn"');
    $actions .= '<form method="post" action="/suppliers/archive.php" hx-post="/suppliers/archive.php" hx-target="#flash" class="d-inline" hx-confirm="' . ($s['active'] ? 'Archive ' : 'Restore ') . e($s['name']) . '?">' . csrf_field() . '<input type="hidden" name="supplier" value="' . $id . '"><input type="hidden" name="archived" value="' . ($s['active'] ? 'yes' : 'no') . '"><button type="submit" class="btn btn-light btn-touch me-1" id="supplier-view-archive-btn">' . ($s['active'] ? 'Archive' : 'Restore') . '</button></form>';
}
if ($may['draft']) { $actions .= hx_link(with_back('/suppliers/' . $id . '/guide', $here), 'Order guide', 'btn btn-light btn-touch me-1', 'id="supplier-view-guide-btn"') . hx_link(with_back('/purchasing/new?supplier=' . $id, $here), '<i class="feather-shopping-cart me-1"></i>New purchase order', 'btn btn-primary btn-touch me-1', 'id="supplier-view-po-btn"'); }
if ($may['delete'] && $deletable === null) { $actions .= '<form method="post" action="/suppliers/delete.php" hx-post="/suppliers/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Delete ' . e($s['name']) . '? This cannot be undone.">' . csrf_field() . '<input type="hidden" name="supplier" value="' . $id . '"><button type="submit" class="btn btn-outline-danger btn-touch" id="supplier-view-delete-btn">Delete</button></form>'; }
$tabUrl = static fn (string $t): string => '/suppliers/' . $id . ($t === 'schedules' ? '' : '?tab=' . $t);
$mayWrite = $may['write'];
?>
<?= view('shared/header.php', ['id' => 'supplier-view', 'title' => $s['name'], 'crumbs' => [['Home', '/'], ['Suppliers', '/suppliers/'], [$s['name'], null]], 'back' => back_link() ?? ['/suppliers/', 'Suppliers'], 'action' => $actions]) ?>
<div class="main-content" id="supplier-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="supplier-view-header"><div class="card-body">
        <h4 class="mb-1" id="supplier-view-name"><?= e($s['name']) ?></h4>
        <div class="chip-row mb-2"><?= supplier_kind_chip($s['kind']) ?><?= order_method_chip($s['order_method']) ?><?php if (!$s['active']): ?><?= chip('archived', 'dark', 'supplier-view-archived') ?><?php endif; ?><?php if ($s['allow_split_case']): ?><?= chip('split cases', 'light') ?><?php endif; ?></div>
        <div class="fs-12 text-muted"><?= e($s['contact_name'] ?? '') ?><?= $s['email'] ? ' · ' . e($s['email']) : '' ?><?= $s['phone'] ? ' · ' . e($s['phone']) : '' ?><?= $s['order_email'] ? ' · orders to ' . e($s['order_email']) : '' ?><?= $s['portal_url'] ? ' · <a href="' . e($s['portal_url']) . '" target="_blank" rel="noopener">portal</a>' : '' ?></div>
        <div class="fs-12 text-muted mt-1">Lead time <?= (int) $s['lead_time_days'] ?> day<?= (int) $s['lead_time_days'] === 1 ? '' : 's' ?><?= $s['min_order_value'] !== null ? ' · minimum order $' . e(fmt_num($s['min_order_value'], 2)) : '' ?> · invoice tolerance <?= e(fmt_num($s['match_tolerance_pct'], 2)) ?> % / $<?= e(fmt_num($s['match_tolerance_value'], 2)) ?>
            <?= $s['rep_name'] ? ' · rep ' . e($s['rep_name']) . ($s['rep_phone'] ? ' ' . e($s['rep_phone']) : '') : '' ?><?= $mayWrite && $s['account_number'] ? ' · account <span id="supplier-view-account">' . e($s['account_number']) . '</span>' : '' ?><?= $s['kind'] === 'commissary' && $s['commissary_site'] ? ' · the site ' . e($s['commissary_site']) : '' ?></div>
        <?php if ($deletable !== null && $deletable !== 'n/a' && $may['delete']): ?><div class="fs-12 text-muted mt-1" id="supplier-view-not-deletable"><?= e($deletable) ?></div><?php endif; ?>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="supplier-view-tabs"><?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="supplier-view-tab-' . $k . '"') ?><?php endforeach; ?></div>
    <?php if ($tab === 'schedules'): ?>
        <div class="card" id="supplier-schedules-card"><div class="card-body">
            <?php if ($full['schedules'] === []): ?><div class="text-muted fs-12" id="supplier-schedules-empty">No schedule at this site yet — the suggested order uses the lead time alone.</div><?php endif; ?>
            <ul class="list-unstyled mb-2"><?php foreach ($full['schedules'] as $r): ?><li id="schedule-row-<?= $r['supplier_schedule_id'] ?>" class="fs-12 mb-1"><i class="feather-clock me-1"></i><?= e($r['words']) ?></li><?php endforeach; ?></ul>
            <?php if ($full['next_windows'] !== []): ?><div class="fs-12 text-muted" id="schedule-windows">Next: <?php foreach ($full['next_windows'] as $w): ?><span class="me-2"><?= e(window_words($w)) ?></span><?php endforeach; ?></div><?php endif; ?>
        </div></div>
    <?php elseif ($tab === 'items'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="supplier-items-table"><thead class="thead-light"><tr><th>SKU</th><th>Ingredient</th><th>Unit · pack</th><th>Last price</th><th>Per recipe unit</th><th>Contract</th><th>MOQ</th><th></th></tr></thead><tbody>
            <?php if ($full['items'] === []): ?><tr><td colspan="8" class="text-center text-muted py-4" id="supplier-items-empty">No items yet.</td></tr><?php endif; ?>
            <?php foreach ($full['items'] as $si): ?><tr id="supplier-item-row-<?= $si['supplier_item_id'] ?>" class="<?= $si['active'] ? '' : 'text-muted' ?>"><td><code><?= e($si['supplier_sku'] ?? '') ?></code></td><td><?= hx_link(with_back('/ingredients/' . $si['ingredient_id'], $here), e($si['ingredient'])) ?></td><td><?= e($si['purchase_unit_code']) ?><?= $si['pack'] ? ' · ' . e($si['pack']) : '' ?></td>
                <td><?= $si['last_price'] !== null ? '$' . e(fmt_num($si['last_price'], 2)) . ($si['last_price_at'] ? ' <span class="text-muted">' . e(format_ts($si['last_price_at'], $tz, 'M j')) . '</span>' : '') : '<span class="text-muted" title="cost withheld">—</span>' ?></td><td><?= $si['per_recipe_unit'] !== null ? '$' . e(fmt_num($si['per_recipe_unit'], 4)) . ' / ' . e($si['recipe_unit_code']) : '<span class="text-muted" title="cost withheld">—</span>' ?></td>
                <td><?= $si['contract_price'] !== null ? '$' . e(fmt_num($si['contract_price'], 2)) . ($si['contract_until'] ? ' until ' . e($si['contract_until']) : '') : '' ?></td><td><?= e(fmt_num($si['moq'], 2)) ?></td><td><?= $si['catch_weight'] ? chip('catch weight', 'warning') : '' ?><?= !$si['active'] ? ' ' . chip('retired', 'dark') : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'guide'): ?>
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Order guide at this site</h5><?= $may['draft'] ? hx_link(with_back('/suppliers/' . $id . '/guide', $here), 'Edit', 'btn btn-light btn-sm btn-touch') : '' ?></div><div class="card-body">
            <?php if ($full['guide'] === null || $full['guide']['lines'] === []): ?><div class="text-muted fs-12" id="supplier-guide-empty">No order guide at this site yet.</div><?php else: ?>
            <ol class="mb-0 fs-12" id="supplier-guide-list"><?php foreach ($full['guide']['lines'] as $l): ?><li id="supplier-guide-row-<?= $l['supplier_item_id'] ?>"><?= e($l['ingredient']) ?> <span class="text-muted"><?= e($l['supplier_sku'] ?? '') ?> · <?= e($l['pack'] ?? $l['purchase_unit_code']) ?></span><?= $l['preferred'] ? ' ★' : '' ?></li><?php endforeach; ?></ol><?php endif; ?>
        </div></div>
    <?php elseif ($tab === 'purchase_orders'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="supplier-pos-table"><thead class="thead-light"><tr><th>Number</th><th>Status</th><th>Ordered</th><th>Expected</th><th>Total</th></tr></thead><tbody>
            <?php if ($purchase_orders === []): ?><tr><td colspan="5" class="text-center text-muted py-4" id="supplier-pos-empty">No purchase orders at this site yet.</td></tr><?php endif; ?>
            <?php foreach ($purchase_orders as $p): ?><tr><td><?= hx_link(with_back('/purchasing/' . (int) $p['purchase_order_id'], $here), e($p['number'])) ?></td><td><?= chip($p['status'], 'light') ?></td><td><?= e($p['ordered_on']) ?></td><td><?= e($p['expected_on'] ?? '') ?></td><td><?= money($p['total']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'invoices'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="supplier-invoices-table"><thead class="thead-light"><tr><th>Invoice</th><th>Date</th><th>Status</th><th>Total</th></tr></thead><tbody>
            <?php if (!$seesCost): ?><tr><td colspan="4" class="text-center text-muted py-4" id="supplier-invoices-withheld">Cost is withheld from you here.</td></tr><?php elseif ($invoices === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="supplier-invoices-empty">No invoices at this site yet.</td></tr><?php endif; ?>
            <?php foreach ($invoices as $inv): ?><tr><td><?= hx_link(with_back('/invoices/' . (int) $inv['invoice_id'], $here), e($inv['invoice_no'])) ?></td><td><?= e($inv['invoice_date']) ?></td><td><?= chip($inv['status'], 'light') ?></td><td><?= money($inv['total']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'alerts'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="supplier-alerts-table"><thead class="thead-light"><tr><th>When</th><th>Ingredient</th><th>Old → new</th><th>%</th></tr></thead><tbody>
            <?php if ($alerts === []): ?><tr><td colspan="4" class="text-center text-muted py-4" id="supplier-alerts-empty">No price alerts.</td></tr><?php endif; ?>
            <?php foreach ($alerts as $a): ?><tr><td><?= e(format_ts($a['raised_at'], $tz)) ?></td><td><?= e($a['ingredient']) ?></td><td>$<?= e(fmt_num($a['old_per_base'], 6)) ?> → $<?= e(fmt_num($a['new_per_base'], 6)) ?></td><td><?= e(fmt_num($a['pct'], 1)) ?> %</td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'notes'): ?>
        <?= view('catalog/partials/notes-tab.php', ['type' => 'supplier', 'id' => $id, 'notes' => $full['notes'], 'attachments' => $full['attachments'], 'mayNote' => $may['notes'], 'me' => $me, 'isAdmin' => $isAdmin, 'tz' => $tz, 'back' => '/suppliers/' . $id . '?tab=notes']) ?>
    <?php elseif ($tab === 'trail'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="supplier-trail-table"><thead class="thead-light"><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
            <?php if ($trail === []): ?><tr><td colspan="3" class="text-center text-muted py-4">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($trail as $r): ?><tr><td><?= e(format_ts($r['occurred_at'], $tz)) ?></td><td><?= e($r['actor_name'] ?? 'system') ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>
