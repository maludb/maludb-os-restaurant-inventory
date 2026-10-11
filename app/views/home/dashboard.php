<?php /** The home (screen `home`, sso-shell.md): one card per region, each with its count that opens its list, and an empty state naming the slice that fills it; the bell's five newest. Data: s (home_summary), tz, seesCost, site */ ?>
<?= view('shared/header.php', ['id' => 'home', 'title' => $site['name'] ?? 'Home', 'crumbs' => [['Home', null]]]) ?>
<div class="main-content" id="home-content">
    <div class="row g-3">
        <?php foreach ($s['regions'] as $key => $r): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 mb-0<?= $r['count'] === null ? ' coming-card' : '' ?>" id="home-<?= e($key) ?>">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3">
                            <span class="avatar-text avatar-lg rounded"><i class="<?= e($r['icon']) ?>"></i></span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-semibold"><?= hx_link($r['url'], e($r['title']), 'text-dark', 'id="home-' . e($key) . '-link"') ?></div>
                                <?php if ($r['count'] === null): ?>
                                    <div class="fs-12 text-muted" id="home-<?= e($key) ?>-empty">Nothing here yet — <?= e($r['slice']) ?> fills this card.</div>
                                <?php else: ?>
                                    <div class="fs-24 fw-bold" id="home-<?= e($key) ?>-count"><?= (int) $r['count'] ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 mb-0" id="home-bell">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-3">
                        <span class="avatar-text avatar-lg rounded"><i class="feather-bell"></i></span>
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-semibold"><?= hx_link('/me/notifications', 'Unread', 'text-dark', 'id="home-bell-link"') ?></div>
                            <?php if ($s['bell'] === []): ?>
                                <div class="fs-12 text-muted" id="home-bell-empty">Nothing unread.</div>
                            <?php else: ?>
                                <ul class="list-unstyled fs-12 mb-0" id="home-bell-list">
                                    <?php foreach ($s['bell'] as $n): ?><li class="text-truncate"><?= e($n['title']) ?></li><?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
