<?php /** One ingredient at this site (screen `ingredient-view`). Data: full, tab, site, may (write, delete, prices, notes), deletable, seesCost, tz, here, me, isAdmin, cost_history, ledger, trail, notice */
$i = $full['ingredient'];
$id = (int) $i['ingredient_id'];
$pdo = db();
$tabs = ['on_hand' => 'On hand', 'units' => 'Units', 'suppliers' => 'Suppliers', 'pars' => 'Pars', 'cost' => 'Cost', 'recipes' => 'Recipes', 'ledger' => 'Ledger', 'yield' => 'Yield tests', 'containers' => 'Containers', 'notes' => 'Notes', 'trail' => 'Trail'];
if (!isset($tabs[$tab])) { $tab = 'on_hand'; }
$actions = '';
if ($may['write']) {
    $actions .= hx_link(with_back('/ingredients/' . $id . '/edit', $here), '<i class="feather-edit-2 me-1"></i>Edit', 'btn btn-light btn-touch me-1', 'id="ingredient-view-edit-btn"');
    $actions .= hx_link(with_back('/ingredients/' . $id . '/units', $here), 'Units', 'btn btn-light btn-touch me-1', 'id="ingredient-view-units-btn"');
    $actions .= hx_link(with_back('/ingredients/' . $id . '/sites', $here), 'Sites', 'btn btn-light btn-touch me-1', 'id="ingredient-view-sites-btn"');
    $actions .= hx_link(with_back('/ingredients/' . $id . '/yield-tests/new', $here), 'Yield test', 'btn btn-light btn-touch me-1', 'id="ingredient-view-yield-btn"');
    $actions .= '<form method="post" action="/ingredients/archive.php" hx-post="/ingredients/archive.php" hx-target="#flash" class="d-inline" hx-confirm="' . ($i['active'] ? 'Archive ' : 'Restore ') . e($i['name']) . '?">' . csrf_field()
        . '<input type="hidden" name="ingredient" value="' . $id . '"><input type="hidden" name="archived" value="' . ($i['active'] ? 'yes' : 'no') . '"><button type="submit" class="btn btn-light btn-touch me-1" id="ingredient-view-archive-btn">' . ($i['active'] ? 'Archive' : 'Restore') . '</button></form>';
}
if ($may['delete'] && $deletable === null) {
    $actions .= '<form method="post" action="/ingredients/delete.php" hx-post="/ingredients/delete.php" hx-target="#flash" class="d-inline" hx-confirm="Delete ' . e($i['name']) . '? This cannot be undone.">' . csrf_field() . '<input type="hidden" name="ingredient" value="' . $id . '"><button type="submit" class="btn btn-outline-danger btn-touch" id="ingredient-view-delete-btn">Delete</button></form>';
}
$tabUrl = static fn (string $t): string => '/ingredients/' . $id . ($t === 'on_hand' ? '' : '?tab=' . $t);
$cost = $full['cost'];
$count = $full['on_hand']['count_unit_code'];
$appliedTest = null;
foreach ($full['yield_tests'] as $y) { if ($y['applied'] && (string) $y['yield_pct'] === (string) $i['yield_pct']) { $appliedTest = $y; break; } }
?>
<?= view('shared/header.php', ['id' => 'ingredient-view', 'title' => $i['name'], 'crumbs' => [['Home', '/'], ['Ingredients', '/ingredients/'], [$i['name'], null]], 'back' => back_link() ?? ['/ingredients/', 'Ingredients'], 'action' => $actions]) ?>
<div class="main-content" id="ingredient-view-content">
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="card mb-3" id="ingredient-view-header"><div class="card-body d-flex gap-3 align-items-start flex-wrap">
        <span class="avatar-text avatar-lg rounded" title="<?= e(MEASURE_CLASSES[$i['measure_class']]) ?>"><i class="<?= e(class_icon($i['measure_class'])) ?>"></i></span>
        <div class="min-w-0 flex-grow-1">
            <h4 class="mb-1" id="ingredient-view-name"><?= e($i['name']) ?> <code class="fs-12" id="ingredient-view-code"><?= e($i['code']) ?></code></h4>
            <div class="chip-row mb-2"><?= chip($i['category'], 'light', 'ingredient-view-category') ?><?= chip(MEASURE_CLASSES[$i['measure_class']] . ' · base ' . $i['base_unit_code'], 'secondary') ?><?= chip('recipe unit ' . $i['recipe_unit_code'], 'info', 'ingredient-view-recipe-unit') ?>
                <?php if ($i['is_prep']): ?><?= chip('prep', 'info') ?><?php endif; ?><?php if ($i['catch_weight']): ?><?= chip('catch weight', 'warning') ?><?php endif; ?><?php if (!$i['active']): ?><?= chip('archived', 'dark', 'ingredient-view-archived') ?><?php endif; ?></div>
            <div class="fs-12 text-muted">Yield <strong id="ingredient-view-yield"><?= e(fmt_num($i['yield_pct'], 2)) ?> %</strong><?= $appliedTest !== null ? ' (from a test on ' . e(format_ts($appliedTest['tested_at'], $tz, 'M j, Y')) . ')' : '' ?>
                <?= $i['shelf_life_days'] !== null ? ' · keeps ' . (int) $i['shelf_life_days'] . ' days' : '' ?><?= $i['density_g_per_ml'] !== null ? ' · density ' . e(fmt_num($i['density_g_per_ml'], 3)) . ' g/ml' : '' ?><?= $i['each_weight_g'] !== null ? ' · each ' . e(fmt_num($i['each_weight_g'], 2)) . ' g' : '' ?><?= $i['each_volume_ml'] !== null ? ' · each ' . e(fmt_num($i['each_volume_ml'], 2)) . ' ml' : '' ?>
                <?= $i['allergens'] !== [] ? ' · allergens: ' . e(implode(', ', array_map(static fn (string $a): string => ALLERGENS[$a] ?? $a, $i['allergens']))) : '' ?></div>
            <div class="fs-12 mt-2" id="ingredient-view-summary"><strong><?= e($full['on_hand']['total_base'] === '0.0000' ? 'Nothing on hand here' : format_qty($pdo, $id, $full['on_hand']['total_base'], $count) . ' on hand') ?></strong>
                · <span id="ingredient-view-cost"><?= $seesCost ? ($cost === null || (float) $cost['per_base'] === 0.0 ? 'no cost yet' : e($cost['sentence'])) : '<span class="text-muted" title="cost withheld">cost —</span>' ?></span></div>
            <?php if ($deletable !== null && $deletable !== 'n/a' && $may['delete']): ?><div class="fs-12 text-muted mt-1" id="ingredient-view-not-deletable"><?= e($deletable) ?></div><?php endif; ?>
        </div>
    </div></div>
    <div class="d-flex flex-wrap gap-1 mb-3" id="ingredient-view-tabs"><?php foreach ($tabs as $k => $label): ?><?= hx_link($tabUrl($k), e($label), 'btn btn-touch ' . ($tab === $k ? 'btn-primary' : 'btn-light'), 'id="ingredient-view-tab-' . $k . '"') ?><?php endforeach; ?></div>
    <?php if ($tab === 'on_hand'): $par = null; foreach ($full['site_settings'] as $s) { if ($s['scope_id'] === $site) { $par = $s; } } ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-on-hand-table"><thead class="thead-light"><tr><th>Storage area</th><th>On hand</th><th>In <?= e($i['base_unit_code']) ?></th></tr></thead><tbody>
            <?php if ($full['on_hand']['rows'] === []): ?><tr><td colspan="3" class="text-center text-muted py-4" id="on-hand-empty">Nothing on hand at this site.</td></tr><?php endif; ?>
            <?php foreach ($full['on_hand']['rows'] as $r): ?><tr id="on-hand-row-<?= (int) $r['storage_area_id'] ?>"><td><?= e($r['name']) ?> <?= chip(AREA_KINDS[$r['kind']] ?? $r['kind'], 'light') ?></td><td><?= e(format_qty($pdo, $id, $r['qty_base'], $count)) ?></td><td><?= e(fmt_num($r['qty_base'], 2)) ?></td></tr><?php endforeach; ?>
            <?php if ($full['on_hand']['rows'] !== []): ?><tr class="fw-semibold" id="on-hand-total"><td>Total<?php if ($par !== null && $par['par_base'] !== null && (float) $full['on_hand']['total_base'] + (float) $par['on_order_base'] < (float) $par['par_base']): ?> <?= chip('below par', 'danger', 'on-hand-below-par') ?><?php endif; ?></td><td><?= e(format_qty($pdo, $id, $full['on_hand']['total_base'], $count)) ?></td><td><?= e(fmt_num($full['on_hand']['total_base'], 2)) ?></td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'units'): ?>
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Units</h5><?= $may['write'] ? hx_link(with_back('/ingredients/' . $id . '/units', $here), 'Change the units', 'btn btn-light btn-sm btn-touch', 'id="ingredient-units-link"') : '' ?></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-units-table"><thead class="thead-light"><tr><th>Unit</th><th>Kind</th><th>Holds</th><th></th></tr></thead><tbody>
            <?php foreach ($full['units'] as $u): ?><tr id="ingredient-unit-row-<?= e((string) ($u['ingredient_unit_id'] ?? $u['unit_code'])) ?>" class="<?= $u['active'] ? '' : 'text-muted' ?>"><td><code><?= e($u['unit_code']) ?></code> <?= e($u['name']) ?></td><td><?= unit_kind_chip($u['kind']) ?></td><td><?= e($u['sentence']) ?></td><td><?= $u['is_default_count'] ? '★ default count' : '' ?><?= !$u['active'] ? ' retired' : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'suppliers'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-suppliers-table"><thead class="thead-light"><tr><th>Supplier</th><th>SKU</th><th>Pack</th><th>Last price</th><th>Per <?= e($i['recipe_unit_code']) ?></th><th>Contract until</th><th>MOQ</th><th></th></tr></thead><tbody>
            <?php if ($full['supplier_items'] === []): ?><tr><td colspan="8" class="text-center text-muted py-4" id="ingredient-suppliers-empty">No supplier sells it yet.</td></tr><?php endif; ?>
            <?php foreach ($full['supplier_items'] as $si): ?><tr id="ingredient-supplier-row-<?= $si['supplier_item_id'] ?>"><td><?= hx_link(with_back('/suppliers/' . $si['supplier_id'], $here), e($si['supplier'])) ?></td><td><code><?= e($si['supplier_sku'] ?? '') ?></code></td><td><?= e($si['pack'] ?? $si['purchase_unit_code']) ?></td>
                <td><?= $si['last_price'] !== null ? '$' . e(fmt_num($si['last_price'], 2)) . ' / ' . e($si['purchase_unit_code']) : ($seesCost ? '<span class="text-muted">—</span>' : '<span class="text-muted" title="cost withheld">—</span>') ?></td><td><?= $si['per_recipe_unit'] !== null ? '$' . e(fmt_num($si['per_recipe_unit'], 4)) : '<span class="text-muted" title="cost withheld">—</span>' ?></td>
                <td><?= e($si['contract_until'] ?? '') ?></td><td><?= e(fmt_num($si['moq'], 2)) ?></td><td><?= $seesCost && $si['cheapest'] ? chip('cheapest', 'success', 'ingredient-cheapest') : '' ?><?= $si['catch_weight'] ? ' ' . chip('catch weight', 'warning') : '' ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'pars'): ?>
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Per site</h5><?= $may['write'] ? hx_link(with_back('/ingredients/' . $id . '/sites', $here), 'Change', 'btn btn-light btn-sm btn-touch', 'id="ingredient-sites-link"') : '' ?></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-pars-table"><thead class="thead-light"><tr><th>Site</th><th>Par</th><th>Mode</th><th>On hand</th><th>On order</th><th>Service level</th><th>Depletes from</th></tr></thead><tbody>
            <?php foreach ($full['site_settings'] as $s): ?><tr id="par-row-<?= $s['scope_id'] ?>"><td><?= e($s['name']) ?></td><td><?= $s['par_base'] === null ? '<span class="text-muted">none</span>' : e(format_qty($pdo, $id, $s['par_base'], $count)) ?></td><td><?= e($s['par_mode']) ?></td><td><?= e(format_qty($pdo, $id, $s['on_hand_base'], $count)) ?></td><td><?= e(format_qty($pdo, $id, $s['on_order_base'], $count)) ?></td><td><?= e(fmt_num((float) $s['service_level'] * 100, 0)) ?> %</td><td><?= e((string) ($s['default_area_id'] ?? '')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'cost'): ?>
        <?= view('catalog/partials/cost-chart.php', ['i' => $i, 'rows' => $cost_history, 'tz' => $tz, 'seesCost' => $seesCost]) ?>
    <?php elseif ($tab === 'recipes'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-recipes-table"><thead class="thead-light"><tr><th>Recipe</th><th>Kind</th><th>Per batch</th></tr></thead><tbody>
            <?php if ($full['recipes_using'] === []): ?><tr><td colspan="3" class="text-center text-muted py-4" id="ingredient-recipes-empty">No recipe uses it yet.</td></tr><?php endif; ?>
            <?php foreach ($full['recipes_using'] as $r): ?><tr id="ingredient-recipe-row-<?= (int) $r['recipe_id'] ?>"><td><?= hx_link(with_back('/recipes/' . (int) $r['recipe_id'], $here), e($r['name'])) ?></td><td><?= chip($r['kind'], 'light') ?></td><td><?= e(fmt_num($r['qty'], 2)) ?> <?= e($r['unit_code']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'ledger'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-ledger-table"><thead class="thead-light"><tr><th>When</th><th>Type</th><th>Area</th><th>Quantity</th><th>Cost</th><th>Document</th></tr></thead><tbody>
            <?php if ($ledger === []): ?><tr><td colspan="6" class="text-center text-muted py-4" id="ingredient-ledger-empty">No movement at this site yet.</td></tr><?php endif; ?>
            <?php foreach ($ledger as $t): ?><tr id="ledger-row-<?= (int) $t['transaction_id'] ?>"><td><?= e(format_ts($t['occurred_at'], $tz)) ?></td><td><?= chip(str_replace('_', ' ', $t['txn_type']), 'light') ?></td><td><?= e($t['area']) ?></td><td class="<?= (float) $t['qty_base'] < 0 ? 'text-danger' : '' ?>"><?= e(format_qty($pdo, $id, $t['qty_base'], $count)) ?></td><td><?= $t['unit_cost_per_base'] === null ? '<span class="text-muted" title="cost withheld">—</span>' : '$' . e(fmt_num($t['unit_cost_per_base'], 6)) ?></td><td><?= e($t['reference_kind'] . ' #' . $t['reference_id']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'yield'): ?>
        <div class="card"><div class="card-header d-flex justify-content-between align-items-center"><h5 class="card-title mb-0">Yield tests</h5><?= $may['write'] ? hx_link(with_back('/ingredients/' . $id . '/yield-tests/new', $here), 'New test', 'btn btn-light btn-sm btn-touch', 'id="ingredient-yield-new"') : '' ?></div>
            <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-yield-table"><thead class="thead-light"><tr><th>When</th><th>AP</th><th>EP</th><th>Yield</th><th>Trim</th><th>Cook loss</th><th>Applied</th><th>By</th></tr></thead><tbody>
            <?php if ($full['yield_tests'] === []): ?><tr><td colspan="8" class="text-center text-muted py-4" id="ingredient-yield-empty">No yield test yet.</td></tr><?php endif; ?>
            <?php foreach ($full['yield_tests'] as $y): ?><tr id="yield-row-<?= (int) $y['yield_test_id'] ?>"><td><?= e(format_ts($y['tested_at'], $tz)) ?></td><td><?= e(fmt_num($y['ap_qty_base'], 2)) ?> <?= e($i['base_unit_code']) ?></td><td><?= e(fmt_num($y['ep_qty_base'], 2)) ?> <?= e($i['base_unit_code']) ?></td><td><strong><?= e(fmt_num($y['yield_pct'], 2)) ?> %</strong></td><td><?= e($y['trim_loss_pct'] !== null ? fmt_num($y['trim_loss_pct'], 2) . ' %' : '') ?></td><td><?= e($y['cook_loss_pct'] !== null ? fmt_num($y['cook_loss_pct'], 2) . ' %' : '') ?></td><td><?= $y['applied'] ? '✓' : '' ?></td><td><?= e($y['tested_by_name'] ?? '') ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'containers'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-containers-table"><thead class="thead-light"><tr><th>Container</th><th>Full</th><th>Empty</th><th>Pourer</th><th>Holds</th></tr></thead><tbody>
            <?php if ($full['containers'] === []): ?><tr><td colspan="5" class="text-center text-muted py-4" id="ingredient-containers-empty">No bottle or keg weights yet (the count slice adds them).</td></tr><?php endif; ?>
            <?php foreach ($full['containers'] as $c): ?><tr><td><?= e($c['name']) ?><?= $c['is_default'] ? ' ★' : '' ?></td><td><?= e(fmt_num($c['full_weight_g'], 0)) ?> g</td><td><?= e(fmt_num($c['empty_weight_g'], 0)) ?> g</td><td><?= e(fmt_num($c['pourer_tare_g'], 0)) ?> g</td><td><?= e(fmt_num($c['volume_ml'], 0)) ?> ml</td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php elseif ($tab === 'notes'): ?>
        <?= view('catalog/partials/notes-tab.php', ['type' => 'ingredient', 'id' => $id, 'notes' => $full['notes'], 'attachments' => $full['attachments'], 'mayNote' => $may['notes'], 'me' => $me, 'isAdmin' => $isAdmin, 'tz' => $tz, 'back' => '/ingredients/' . $id . '?tab=notes']) ?>
    <?php elseif ($tab === 'trail'): ?>
        <div class="card"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 fs-12" id="ingredient-trail-table"><thead class="thead-light"><tr><th>When</th><th>Who</th><th>What</th></tr></thead><tbody>
            <?php if ($trail === []): ?><tr><td colspan="3" class="text-center text-muted py-4">Nothing yet.</td></tr><?php endif; ?>
            <?php foreach ($trail as $r): ?><tr id="trail-row-<?= (int) $r['activity_id'] ?>"><td><?= e(format_ts($r['occurred_at'], $tz)) ?></td><td><?= e($r['actor_name'] ?? 'system') ?></td><td><?= e(activity_sentence($r)) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>
