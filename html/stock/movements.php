<?php
declare(strict_types=1);
/** /stock/movements — screen `stock-movements` (slice 3): a placeholder until its slice builds it (sso-shell.md). The right is checked at the current site; a POST or JSON answers 501 not_built. */
require_once dirname(__DIR__, 2) . '/app/bootstrap.php';
render_nav_stub('stock-movements', 'slice 3', 'ri.read');
