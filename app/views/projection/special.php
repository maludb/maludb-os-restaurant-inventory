<?php /** Can we run the special? (screen `special-check`). Data: result (special_check()) or null, recipeId, date, portions, errors, mayDraft, today */
$here = here_url();
$max = (new DateTimeImmutable($today))->modify('+28 days')->format('Y-m-d');
?>
<?= view('shared/header.php', ['id' => 'special-check', 'title' => 'Can we run it?', 'crumbs' => [['Home', '/'], ['Projection', null], ['Can we run it?', null]], 'back' => back_link()]) ?>
<div class="main-content" id="special-check-content">
    <?= view('projection/partials/assets.php') ?>
    <form method="get" action="/projection/special" hx-get="/projection/special" hx-target="#page-content" hx-push-url="true" class="card mb-3" id="special-form"><div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-12 col-lg-5"><?= form_picker('special-form', 'recipe', 'Recipe', 'recipe', $recipeId, $errors, ['required' => true]) ?></div>
            <div class="col-6 col-lg-3"><label class="form-label fs-12 text-muted" for="special-form-field-date">Day</label><input type="date" name="date" id="special-form-field-date" class="form-control btn-touch<?= isset($errors['date']) ? ' is-invalid' : '' ?>" value="<?= e($date) ?>" min="<?= e($today) ?>" max="<?= e($max) ?>" required><?php if (isset($errors['date'])): ?><div class="invalid-feedback"><?= e($errors['date']) ?></div><?php endif; ?></div>
            <div class="col-6 col-lg-2"><label class="form-label fs-12 text-muted" for="special-form-field-portions">Portions</label><input type="number" name="portions" id="special-form-field-portions" class="form-control btn-touch<?= isset($errors['portions']) ? ' is-invalid' : '' ?>" min="1" max="10000" step="any" inputmode="decimal" value="<?= e($portions) ?>" required><?php if (isset($errors['portions'])): ?><div class="invalid-feedback"><?= e($errors['portions']) ?></div><?php endif; ?></div>
            <div class="col-12 col-lg-2"><button type="submit" class="btn btn-primary btn-touch w-100" id="special-check-btn">Check</button></div>
        </div>
        <div class="form-text">For a prep, the portions are in its batch unit. The check uses the recipe version in force that day and the stock projected for it.</div>
    </div></form>
    <?php if ($result !== null): ?>
        <?php if ($result['problem'] !== null): ?>
            <div class="alert alert-warning" id="special-problem" role="status"><?= e($result['problem']) ?></div>
        <?php else: ?>
            <div class="alert alert-<?= $result['short'] === [] ? 'success' : 'warning' ?>" id="special-verdict" role="status"><strong><?= e($result['verdict']) ?></strong></div>
            <div class="card"><div class="fc-table-wrap"><table class="table table-hover align-middle mb-0 fc-table" id="special-table">
                <thead><tr><th>Ingredient</th><th class="num">Needed</th><th class="num">Projected that day</th><th class="num">Short</th><th class="d-none d-md-table-cell">Who can bring it</th></tr></thead><tbody>
                <?php foreach ($result['needs'] as $n): $id = (int) $n['ingredient_id']; ?>
                    <tr id="special-row-<?= $id ?>" class="<?= $n['short'] ? 'table-warning' : '' ?>">
                        <td><?= hx_link(with_back('/projection/stock/' . $id, $here), e($n['name']), 'fw-semibold') ?></td>
                        <td class="num" title="<?= e(fmt_num($n['needed_base'], 2)) ?> <?= e($n['base_unit']) ?>"><?= e(fmt_num_adaptive($n['needed_count'])) ?> <span class="fs-11 text-muted"><?= e($n['count_unit']) ?></span></td>
                        <td class="num" title="<?= e(fmt_num($n['projected_base'], 2)) ?> <?= e($n['base_unit']) ?>"><?= $n['projected_count'] === null ? '—' : e(fmt_num_adaptive($n['projected_count'])) ?></td>
                        <td class="num fw-semibold <?= $n['short'] ? 'text-danger' : '' ?>" title="<?= e(fmt_num($n['short_base'], 2)) ?> <?= e($n['base_unit']) ?>"><?= $n['short'] ? e(fmt_num_adaptive($n['short_count'])) . ' <span class="fs-11">' . e($n['count_unit']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="d-none d-md-table-cell fs-12"><?php if (!$n['short']): ?><span class="text-muted">covered</span><?php elseif ($n['supplier'] === null): ?><span class="text-danger">no supplier delivers by then</span><?php else: ?><?= e($n['supplier']['supplier']) ?> · order by <?= e(format_ts($n['supplier']['cutoff_at'], site_timezone(), 'D H:i')) ?> for <?= e(date('D M j', (int) strtotime((string) $n['supplier']['delivery_on']))) ?><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?></tbody></table></div></div>
            <?php if ($mayDraft && $result['drafts'] !== []): ?>
            <div class="card mt-3" id="special-drafts"><div class="card-header"><h6 class="mb-0">Draft what is short</h6></div><div class="card-body d-flex flex-wrap gap-2">
                <?php $first = true; foreach ($result['drafts'] as $sid => $dr):
                    $json = json_encode(array_map(static fn (array $l): array => ['supplier_item' => $l['supplier_item'], 'qty' => $l['qty'], 'unit' => $l['unit']], $dr['lines']), JSON_UNESCAPED_SLASHES); ?>
                    <form method="post" action="/purchasing/save.php" hx-post="/purchasing/save.php" hx-target="#flash"><?= csrf_field() ?><input type="hidden" name="supplier" value="<?= (int) $sid ?>"><input type="hidden" name="lines" value="<?= e((string) $json) ?>">
                        <button type="submit" class="btn btn-primary btn-touch" id="special-draft-btn<?= $first ? '' : '-' . (int) $sid ?>"><i class="feather-file-plus me-1"></i>Draft <?= count($dr['lines']) ?> line<?= count($dr['lines']) === 1 ? '' : 's' ?> from <?= e($dr['supplier']) ?></button></form>
                <?php $first = false; endforeach; ?>
                <div class="fs-12 text-muted w-100">A draft only — a Manager sends it. The quantities are whole purchase units, at least the supplier's minimum.</div>
            </div></div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
