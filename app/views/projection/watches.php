<?php /** Watches (screen `watch-list`). Data: rows, admin, me, edit, prefill, agents, notice */
$tz = site_timezone();
$here = here_url();
?>
<?= view('shared/header.php', ['id' => 'watch-list', 'title' => 'Watches', 'crumbs' => [['Home', '/'], ['Projection', null], ['Watches', null]], 'back' => back_link()]) ?>
<div class="main-content" id="watch-list-content">
    <?= view('projection/partials/assets.php') ?>
    <?= view('shared/notice.php', ['notice' => $notice]) ?>
    <?php if ($rows === []): ?>
        <div class="card mb-3" id="watch-empty"><div class="card-body text-muted">No watches yet. A watch tells you — once — when an ingredient goes below its par, is projected to run out, or its cost moves.</div></div>
    <?php else: ?>
    <div class="card mb-3"><div class="fc-table-wrap"><table class="table table-hover align-middle mb-0 fc-table" id="watch-table">
        <thead><tr><th>Ingredient</th><th>Watching for</th><th class="d-none d-md-table-cell">Threshold</th><th class="d-none d-md-table-cell">Agent</th><th>Last fired</th><th class="num">Times</th><th></th></tr></thead>
        <tbody><?php foreach ($rows as $w): ?><?= view('projection/partials/watch-row.php', ['w' => $w, 'admin' => $admin, 'me' => $me, 'tz' => $tz, 'here' => $here]) ?><?php endforeach; ?></tbody>
    </table></div></div>
    <?php endif; ?>
    <?= view('projection/partials/watch-form.php', ['edit' => $edit, 'prefill' => $prefill, 'agents' => $agents]) ?>
</div>
