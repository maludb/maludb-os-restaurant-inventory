<?php /** Phase 0's home: the design-system's minimal layout (the auth-minimal card), one card saying where the build stands. Data: me, roles, site, sites */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Home · <?= e(app_name()) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
</head>
<body>
    <main class="auth-minimal-wrapper">
        <div class="auth-minimal-inner">
            <div class="minimal-card-wrapper">
                <div class="card mb-4 mt-5 mx-4 mx-sm-0 position-relative">
                    <div class="wd-50 bg-white p-2 rounded-circle shadow-lg position-absolute translate-middle top-0 start-50">
                        <img src="/assets/images/logo-abbr.png" alt="" class="img-fluid">
                    </div>
                    <div class="card-body p-sm-5 text-center" id="home-placeholder">
                        <h4 class="fw-bold mb-2"><?= e(app_name()) ?></h4>
                        <p class="text-muted mb-1">Signed in as <strong id="home-member"><?= e($me['display_name']) ?></strong>
                            at <strong id="home-site"><?= e($site['name'] ?? '—') ?></strong>
                            <?php if ($roles !== []): ?>· <span id="home-roles"><?= e(implode(', ', array_column($roles, 'name'))) ?></span><?php endif; ?>
                            · <span id="home-cost"><?= sees_cost() ? 'sees cost' : 'no cost' ?></span></p>
                        <?php if (count($sites) > 1): ?>
                        <div class="mb-2" id="home-sites">
                            <?php foreach ($sites as $s): ?>
                                <form method="post" action="/switch-site.php" class="d-inline"><?= csrf_field() ?><input type="hidden" name="site" value="<?= (int) $s['scope_id'] ?>">
                                    <button type="submit" class="btn btn-sm <?= $s['scope_id'] === current_site_id() ? 'btn-primary' : 'btn-outline-secondary' ?>" id="site-switch-<?= (int) $s['scope_id'] ?>"><?= e($s['name']) ?></button></form>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <p class="text-muted">This is Phase 0: the kit and the schema. The shell — the home, ingredients, recipes, stock, counts, purchasing, sales, usage and projection — is Phase 2 and the slices after it.</p>
                        <form method="post" action="/logout.php" class="mt-3"><?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-secondary btn-touch" id="home-signout-btn">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="/assets/vendors/js/vendors.min.js"></script>
    <script src="/assets/js/common-init.min.js"></script>
    <script src="/assets/js/theme-customizer-init.min.js"></script>
</body>
</html>
