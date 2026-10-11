<?php /** The phone's page for the header's dropdown (screen `site-switch`): the held sites as buttons, one form each. Data: sites, current */ ?>
<?= view('shared/header.php', ['id' => 'site-switch', 'title' => 'Switch restaurant', 'crumbs' => [['Home', '/'], ['Switch restaurant', null]]]) ?>
<div class="main-content" id="site-switch-content">
    <div class="card"><div class="card-body" id="site-switch-list">
        <?php foreach ($sites as $s): ?>
            <form method="post" action="/switch-site.php" class="mb-2" id="site-switch-form-<?= (int) $s['scope_id'] ?>"><?= csrf_field() ?><input type="hidden" name="site" value="<?= (int) $s['scope_id'] ?>">
                <button type="submit" class="btn btn-touch w-100 <?= $s['scope_id'] === $current ? 'btn-primary' : 'btn-outline-secondary' ?>" id="site-switcher-<?= (int) $s['scope_id'] ?>"><i class="feather-map-pin me-1"></i><?= e($s['name']) ?><?= $s['scope_id'] === $current ? ' · here' : '' ?></button></form>
        <?php endforeach; ?>
    </div></div>
</div>
