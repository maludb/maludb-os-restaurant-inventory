<?php
declare(strict_types=1);
/** /login.php — never a login form: the kernel signs people in. A visitor is sent to the launcher with ?app=restaurant_inventory. */
require_once dirname(__DIR__) . '/app/bootstrap.php';
redirect(launcher_url('app=' . rawurlencode(app_key())));
