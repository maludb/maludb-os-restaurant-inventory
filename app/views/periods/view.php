<?php /** One period (screen `period-view`). Data: p, f, tab, seesCost, marks, undepleted, closingCounts, mayClose, mayReopen, mayExport, notice + the tab's (usage | counts | late | trail) */
$id = (int) $p['period_id'];
$tz = site_timezone((int) $p['scope_id']);
$closesNext = in_array($id, $marks['closes_next'], true);
$canReopen = $mayReopen && in_array($id, $marks['reopenable'], true);
$exportQ = http_build_query(['from' => $p['starts_on'], 'to' => $p['ends_on']]);
?>
<?= view('shared/header.php', ['id' => 'period-view', 'title' => ucfirst($p['label']), 'crumbs' => [['Home', '/'], ['Periods', '/periods/?year=' . (int) $p['fiscal_year']], [ucfirst($p['label']), null]], 'back' => back_link() ?? ['/periods/?year=' . (int) $p['fiscal_year'], 'Periods']]) ?>
<div class="main-content" id="period-view-content">
    <?= view('usage/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3 fs-12" id="period-header">
        <?= period_status_chip($p['status'], 'period-status') ?><?= $closesNext ? ' ' . chip('closes next', 'primary') : '' ?>
        <span id="period-dates"><?= e(format_date($p['starts_on'])) ?> – <?= e(format_date($p['ends_on'])) ?></span><span class="text-muted">· <?= e($p['calendar_name']) ?> · fiscal year <?= (int) $p['fiscal_year'] ?></span>
        <?php if ($p['status'] === 'closed' && $p['closed_at']): ?><span class="text-muted" id="period-closed-by">· closed by <?= e($p['closed_by_name'] ?? 'a person') ?> <?= e(format_ts($p['closed_at'], $tz, 'M j g:i A')) ?></span><?php endif; ?>
        <?php if (!empty($p['reopened_at'])): ?><span class="text-muted" id="period-reopened-at">· reopened <?= e(format_ts($p['reopened_at'], $tz, 'M j g:i A')) ?></span><?php endif; ?>
    </div>
    <?= view('periods/partials/figures.php', ['f' => $f, 'seesCost' => $seesCost]) ?>
    <?php if ($closesNext && $mayClose): ?><?= view('periods/partials/close-form.php', ['p' => $p, 'closingCounts' => $closingCounts, 'undepleted' => $undepleted]) ?>
    <?php elseif ($closesNext && $undepleted !== []): ?><div class="alert alert-warning" id="period-undepleted" role="status"><?= e(implode(' and ', array_map(static fn (string $d): string => format_date($d), $undepleted))) ?> not depleted yet. <?= hx_link('/sales/', 'Sales by day', 'alert-link') ?></div><?php endif; ?>
    <?php if ($canReopen): ?><div class="card mb-3" id="period-reopen-card"><div class="card-body"><?= view('periods/partials/reopen-form.php', ['p' => $p, 'suffix' => '']) ?></div></div><?php endif; ?>
    <ul class="nav nav-tabs mb-3" id="period-tabs">
        <?php foreach (PERIOD_TABS as $k => $label): ?><li class="nav-item"><?= hx_link('/periods/' . $id . '?tab=' . $k, e($label), 'nav-link ' . ($tab === $k ? 'active' : ''), 'id="period-tab-' . e($k) . '"') ?></li><?php endforeach; ?>
        <?php if ($mayExport): ?><li class="nav-item ms-auto"><a class="nav-link" href="/usage/export.php?<?= e($exportQ) ?>&amp;format=csv" id="period-export-csv"><i class="feather-download me-1"></i>CSV</a></li><li class="nav-item"><a class="nav-link" href="/usage/export.php?<?= e($exportQ) ?>&amp;format=json" id="period-export-json">JSON</a></li><?php endif; ?>
    </ul>
    <?php if ($tab === 'usage'): ?>
        <?php if ($usage['rows'] === []): ?><div class="card"><div class="card-body text-muted text-center py-4">Nothing moved in this period.</div></div>
        <?php else: ?>
        <div id="period-usage">
            <?php foreach ($usage['categories'] as $c): $rows = array_values(array_filter($usage['rows'], static fn (array $r): bool => $r['category_key'] === $c['category_key'])); ?>
            <div class="card mb-2" id="period-usage-cat-<?= e($c['category_key']) ?>"><div class="card-body py-2">
                <a href="#period-usage-<?= e($c['category_key']) ?>" data-bs-toggle="collapse" class="d-flex justify-content-between fw-semibold text-reset"><span><?= e($c['category']) ?> <span class="text-muted fw-normal">(<?= (int) $c['ingredients'] ?>)</span></span><span>COGS <?= money($c['actual_value']) ?> · unexplained <?= money($c['unexplained_value']) ?></span></a>
                <div class="collapse mt-2" id="period-usage-<?= e($c['category_key']) ?>"><table class="table table-sm mb-0 fs-12"><thead><tr><th>Ingredient</th><th class="text-end">Actual</th><th class="text-end">Theoretical</th><th class="text-end">Unexplained</th></tr></thead><tbody>
                <?php foreach ($rows as $r): ?><tr id="period-usage-row-<?= (int) $r['ingredient_id'] ?>"><td><?= hx_link(with_back('/usage/' . (int) $r['ingredient_id'] . '?' . http_build_query(['from' => $p['starts_on'], 'to' => $p['ends_on']]), here_url()), e($r['name'])) ?></td>
                    <td class="text-end"><?= e(fmt_num_adaptive($r['actual_count']) . ' ' . $r['count_unit']) ?></td><td class="text-end"><?= e(fmt_num_adaptive($r['theoretical_count']) . ' ' . $r['count_unit']) ?></td><td class="text-end"><?= e(fmt_num_adaptive($r['unexplained_count']) . ' ' . $r['count_unit']) ?> <span class="text-muted"><?= money($r['unexplained_value']) ?></span></td></tr><?php endforeach; ?>
                </tbody></table></div>
            </div></div>
            <?php endforeach; ?>
            <div class="fs-12 mt-2"><?= hx_link(with_back('/usage/?' . http_build_query(['from' => $p['starts_on'], 'to' => $p['ends_on']]), here_url()), 'The full table, every column') ?></div>
        </div>
        <?php endif; ?>
    <?php elseif ($tab === 'counts'): ?><?= view('periods/partials/counts.php', ['counts' => $counts, 'p' => $p, 'seesCost' => $seesCost]) ?>
    <?php elseif ($tab === 'late'): ?><?= view('periods/partials/late-rows.php', ['late' => $late, 'p' => $p, 'seesCost' => $seesCost]) ?>
    <?php else: ?><?= view('stock/_trail.php', ['id' => 'period-trail', 'rows' => $trail]) ?><?php endif; ?>
</div>
