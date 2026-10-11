<?php
declare(strict_types=1);
/** /sales/runs — screen `depletion-run-list` (slice 5): a placeholder until its slice builds it (sso-shell.md). The right is checked at the current site; a POST or JSON answers 501 not_built. */
require_once dirname(__DIR__, 3) . '/app/bootstrap.php';
render_nav_stub('depletion-run-list', 'slice 5', 'stock.receive');
