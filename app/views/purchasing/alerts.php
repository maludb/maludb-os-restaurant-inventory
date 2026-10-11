<?php /** The cost moves over the threshold (screen `price-alert-list`). Data: rows, total, open, page, filters, seesCost, thresholds, maySee, mayThreshold, notice */
$tz = site_timezone();
$qs = static fn (array $over): string => '/purchasing/alerts?' . http_build_query(array_filter($over + $filters, static fn ($v): bool => $v !== '' && $v !== null));
$action = $seesCost && $maySee && $open > 0
    ? '<form method="post" action="/purchasing/alerts/seen.php" hx-post="/purchasing/alerts/seen.php" hx-target="#flash" class="d-inline">' . csrf_field() . '<button type="submit" class="btn btn-light btn-touch" id="alert-all-seen-btn"><i class="feather-check-circle me-1"></i>Mark all seen</button></form>' : '';
?>
<?= view('shared/header.php', ['id' => 'price-alert-list', 'title' => 'Price alerts', 'crumbs' => [['Home', '/'], ['Purchasing', null], ['Price alerts', null]], 'action' => $action]) ?>
<div class="main-content" id="price-alert-list-content">
    <?= view('stock/_assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if (!$seesCost): ?>
        <div class="card" id="alerts-withheld"><div class="card-body text-center text-muted py-5">Price alerts are about cost, which your role does not see here.</div></div>
    <?php else: ?>
    <?php if ($mayThreshold): ?><?= view('purchasing/_threshold-form.php', ['thresholds' => $thresholds]) ?><?php endif; ?>
    <div class="d-flex flex-wrap gap-2 mb-3" id="alert-filters">
        <?= hx_link($qs(['seen' => '']), 'All', 'btn btn-sm ' . ($filters['seen'] === '' ? 'btn-primary' : 'btn-light'), 'id="alert-filter-all"') ?>
        <?= hx_link($qs(['seen' => '0']), 'Open (' . (int) $open . ')', 'btn btn-sm ' . ($filters['seen'] === '0' ? 'btn-primary' : 'btn-light'), 'id="alert-filter-open"') ?>
        <?= hx_link($qs(['seen' => '1']), 'Seen', 'btn btn-sm ' . ($filters['seen'] === '1' ? 'btn-primary' : 'btn-light'), 'id="alert-filter-seen"') ?>
    </div>
    <?php if ($rows === []): ?><div class="card" id="alerts-empty"><div class="card-body text-center text-muted py-5">No price alerts<?= $filters['seen'] === '0' ? ' are open' : '' ?>. A cost that moves past its threshold appears here.</div></div>
    <?php else: ?>
    <div class="card"><div class="stock-table-wrap"><table class="table table-hover align-middle mb-0" id="alerts-table"><thead><tr><th>Raised</th><th>Ingredient</th><th>Supplier</th><th class="text-end">Per purchase unit</th><th class="text-end">Per recipe unit</th><th class="text-end">%</th><th>Document</th><th>Seen</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $a): $aid = (int) $a['price_alert_id']; $up = (float) $a['pct'] > 0; ?>
        <tr id="alert-row-<?= $aid ?>">
            <td class="text-nowrap fs-12"><?= e(format_ts($a['raised_at'], $tz, 'M j g:i A')) ?></td>
            <td><?= hx_link('/ingredients/' . $a['ingredient_id'], e($a['ingredient'])) ?></td>
            <td><?= e((string) ($a['supplier'] ?? '')) ?></td>
            <td class="text-end text-nowrap"><?= e(ri_money($a['old_per_unit'], '$', 2)) ?> → <span class="fw-semibold"><?= e(ri_money($a['new_per_unit'], '$', 2)) ?></span> <span class="fs-11 text-muted">/ <?= e($a['unit']) ?></span></td>
            <td class="text-end text-nowrap"><?= $a['old_per_recipe'] === null ? '—' : e(ri_money($a['old_per_recipe'], '$', 4)) . ' → ' . e(ri_money($a['new_per_recipe'], '$', 4)) . ' <span class="fs-11 text-muted">/ ' . e($a['recipe_unit_code']) . '</span>' ?></td>
            <td class="text-end"><span class="badge bg-soft-<?= $up ? 'danger' : 'success' ?> text-<?= $up ? 'danger' : 'success' ?>"><?= ($up ? '+' : '') . e(fmt_num($a['pct'], 2)) ?> %</span></td>
            <td><?php if ($a['document']): ?><?= hx_link($a['reference_kind'] === 'invoice' ? '/invoices/' . $a['reference_id'] : '/receipts/' . $a['reference_id'], e($a['document'])) ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
            <td class="fs-12"><?= $a['seen_at'] ? e((string) ($a['seen_by_name'] ?? '')) . '<div class="text-muted">' . e(format_ts($a['seen_at'], $tz, 'M j')) . '</div>' : '<span class="text-muted">open</span>' ?></td>
            <td class="text-end"><?php if ($maySee && $a['seen_at'] === null): ?>
                <form method="post" action="/purchasing/alerts/seen.php" hx-post="/purchasing/alerts/seen.php" hx-target="#flash" class="d-inline"><?= csrf_field() ?><input type="hidden" name="alert" value="<?= $aid ?>"><button type="submit" class="btn btn-light btn-sm btn-touch" id="alert-seen-<?= $aid ?>">Seen</button></form><?php endif; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
    <?php if ($total > PO_PAGE): ?><div class="d-flex justify-content-end gap-1 mt-2" id="alerts-pager"><?= $page > 1 ? hx_link($qs(['page' => $page - 1]), 'Previous', 'btn btn-light btn-touch') : '' ?><?= $page * PO_PAGE < $total ? hx_link($qs(['page' => $page + 1]), 'Next', 'btn btn-light btn-touch') : '' ?></div><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</div>
