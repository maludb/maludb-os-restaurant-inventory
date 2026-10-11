<?php
declare(strict_types=1);
/** GET /transfers/area-options?site= — a held site's active areas as <option>s (the to-area select re-listed on the site's change, Pattern A). */
require_once dirname(__DIR__, 2) . '/app/features/stock/handler.php';
require_login();
require_right('stock.transfer');
$s = request_integer('site') ?? (int) current_site_id();
if (!in_array($s, array_column(held_sites(), 'scope_id'), true)) { respond_not_found('That site is not one you hold.'); }
$areas = find_storage_areas(db(), $s);
if (wants_json()) { respond_screen(['areas' => array_map('present_area', $areas)]); }
echo view('transfers/_area-options.php', ['areas' => $areas, 'selected' => '']);
