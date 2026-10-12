<?php
declare(strict_types=1);

/**
 * The shell's menu — ONE table (sso-shell.md "Restaurant Inventory is not Inventory"): the sidebar and the phone's tab bar read it, so a
 * menu item and the right that opens its screen can never disagree. An item shows only when the person holds its right AT THE CURRENT
 * SITE (several joined by "|": any one). The ids ARE the manifest's screen ids (Phase 1); until a screen is built its controller calls
 * render_nav_stub() and the item opens a page naming the slice that builds it.
 *   [id, url, icon, label, right]
 */
function nav_groups(): array
{
    return [
        'Kitchen' => [
            ['home',   '/',       'feather-home',   'Home',   'ri.read'],
            ['search', '/search', 'feather-search', 'Search', 'ri.read'],
        ],
        'Ingredients' => [
            ['ingredient-list', '/ingredients/', 'feather-box',           'Ingredients',   'ri.read'],
            ['supplier-list',   '/suppliers/',   'feather-truck',         'Suppliers',     'ri.read'],
            ['area-list',       '/areas/',       'feather-map-pin',       'Storage areas', 'ri.read'],
            ['category-list',   '/categories/',  'feather-grid',          'Categories',    'ingredients.write'],
            ['unit-list',       '/units/',       'feather-divide-square', 'Units',         'settings.manage'],
        ],
        'Recipes' => [
            ['recipe-list',        '/recipes/',         'feather-book-open',   'Recipes',          'ri.read'],
            ['menu-item-list',     '/menu/',            'feather-list',        'Menu items',       'ri.read'],
            ['modifier-list',      '/modifiers/',       'feather-sliders',     'Modifiers',        'recipes.write'],
            ['menu-engineering',   '/menu/engineering', 'feather-bar-chart-2', 'Menu engineering', 'reports.read'],
            ['menu-category-list', '/menu/categories',  'feather-tag',         'Menu categories',  'recipes.write'],
        ],
        'Stock' => [
            ['stock-levels',    '/stock/',          'feather-layers',      'On hand',      'ri.read'],
            ['stock-movements', '/stock/movements', 'feather-activity',    'Movements',    'ri.read'],
            ['stock-value',     '/stock/value',     'feather-dollar-sign', 'Stock value',  'reports.read'],
            ['receipt-list',    '/receipts/',       'feather-download',    'Receive',      'stock.receive'],
            ['transfer-list',   '/transfers/',      'feather-repeat',      'Transfers',    'stock.transfer'],
            ['adjustment-list', '/adjustments/',    'feather-edit-3',      'Adjustments',  'counts.approve'],
            ['production-list', '/production/',     'feather-coffee',      'Prep batches', 'stock.produce'],
            ['waste-list',      '/waste/',          'feather-trash-2',     'Waste',        'stock.waste'],
        ],
        'Count' => [
            ['count-list',       '/counts/',            'feather-check-square', 'Counts',           'stock.count'],
            ['count-sheet-list', '/counts/sheets/',     'feather-clipboard',    'Count sheets',     'ingredients.write'],
            ['container-list',   '/counts/containers/', 'feather-droplet',      'Bottles and kegs', 'ingredients.write'],
        ],
        'Purchasing' => [
            ['suggested-order-list', '/purchasing/suggested', 'feather-shopping-cart', 'Suggested orders', 'purchasing.draft'],
            ['purchase-order-list',  '/purchasing/',          'feather-file-text',     'Purchase orders',  'ri.read'],
            ['deliveries-due',       '/purchasing/due',       'feather-calendar',      'Deliveries due',   'stock.receive'],
            ['invoice-list',         '/invoices/',            'feather-file',          'Invoices',         'invoices.write'],
            ['price-alert-list',     '/purchasing/alerts',    'feather-trending-up',   'Price alerts',     'purchasing.draft'],
        ],
        'Sales' => [
            ['sales-day-list',     '/sales/',           'feather-pie-chart', 'Sales by day',      'ri.read'],
            ['source-list',        '/sales/sources/',   'feather-rss',       'POS sources',       'settings.manage'],
            ['sales-upload',       '/sales/upload',     'feather-upload',    'Upload a POS file', 'stock.receive'],
            ['depletion-run-list', '/sales/runs/',      'feather-zap',       'Depletion runs',    'stock.receive'],
            ['mapping-list',       '/sales/mappings/',  'feather-columns',   'File mappings',     'settings.manage'],
        ],
        'Usage' => [
            ['usage-report',  '/usage/',            'feather-bar-chart', 'Actual vs theoretical', 'reports.read'],
            ['period-list',   '/periods/',          'feather-calendar',  'Periods',               'reports.read'],
            ['cogs-report',   '/usage/cogs',        'feather-percent',   'COGS',                  'reports.read'],
            ['calendar-list', '/periods/calendars', 'feather-clock',     'Costing calendars',     'settings.manage'],
        ],
        'Projection' => [
            ['forecast-list',   '/projection/',          'feather-trending-up',      'Forecast',          'reports.read'],
            ['projected-stock', '/projection/stock',     'feather-arrow-down-right', 'Projected stock',   'reports.read'],
            ['demand-report',   '/projection/demand',    'feather-layers',           'Ingredient demand', 'reports.read'],
            ['calendar-days',   '/projection/calendar',  'feather-calendar',         'Trading calendar',  'forecast.adjust'],
            ['special-check',   '/projection/special',   'feather-star',             'Can we run it?',    'reports.read'],
            ['proposal-list',   '/projection/proposals', 'feather-inbox',            'Purchasing agent',  'purchasing.draft'],
            ['watch-list',      '/projection/watches',   'feather-eye',              'Watches',           'ri.read'],
        ],
        'Reports' => [
            ['report-list', '/reports/',        'feather-bar-chart-2',    'Reports',             'reports.read'],
            ['export-list', '/reports/exports', 'feather-download-cloud', 'Exports',             'exports.all'],
            ['multi-site',  '/reports/sites',   'feather-globe',          'Sites side by side',  'reports.read'],
        ],
        'Me' => [
            ['my-settings',      '/me/settings',      'feather-settings', 'My settings',   'ri.read'],
            ['my-notifications', '/me/notifications', 'feather-bell',     'Notifications', 'ri.read'],
            ['my-tokens',        '/me/tokens',        'feather-key',      'Tokens',        'ri.read'],
            ['my-trail',         '/me/trail',         'feather-activity', 'My trail',      'ri.read'],
        ],
        'Admin' => [
            ['admin-settings',  '/admin/settings',  'feather-sliders',  'Settings',     'settings.manage'],
            ['admin-sequences', '/admin/sequences', 'feather-hash',     'Sequences',    'sequences.manage'],
            ['admin-tax-rates', '/admin/tax-rates', 'feather-percent',  'Tax rates',    'settings.manage'],
            ['admin-reasons',   '/admin/reasons',   'feather-bookmark', 'Reason codes', 'settings.manage'],
            ['admin-agents',    '/admin/agents',    'feather-cpu',      'Agents',       'agents.settings'],
            ['admin-tokens',    '/admin/tokens',    'feather-key',      'Tokens',       'settings.manage'],
            ['admin-trail',     '/admin/trail',     'feather-activity', 'Trail',        'settings.manage'],
        ],
    ];
}

/**
 * The phone's tabs (sso-shell.md): Home · Stock · Count · Order · Me. The third and fourth follow the role — Count for stock.count,
 * else Search; Order for purchasing.draft, else Waste for stock.waste, else Search — so a Viewer and a cook each get five useful tabs.
 */
function nav_tabs(): array
{
    $third = has_right('stock.count') ? ['count-list', '/counts/', 'feather-check-square', 'Count', 'stock.count'] : ['search', '/search', 'feather-search', 'Search', 'ri.read'];
    $fourth = has_right('purchasing.draft') ? ['suggested-order-list', '/purchasing/suggested', 'feather-shopping-cart', 'Order', 'purchasing.draft']
        : (has_right('stock.waste') ? ['waste-list', '/waste/', 'feather-trash-2', 'Waste', 'stock.waste'] : ['search', '/search', 'feather-search', 'Search', 'ri.read']);
    $tabs = [
        ['home', '/', 'feather-home', 'Home', 'ri.read'],
        ['stock-levels', '/stock/', 'feather-layers', 'Stock', 'ri.read'],
        $third,
        $fourth,
        ['my-settings', '/me/settings', 'feather-user', 'Me', 'ri.read'],
    ];
    // never the same tab twice (a Viewer's Search in both the third and fourth place)
    $seen = [];
    $out = [];
    foreach ($tabs as $t) {
        if (isset($seen[$t[0]])) { continue; }
        $seen[$t[0]] = true;
        $out[] = $t;
    }
    return $out;
}

function nav_item(string $id): ?array
{
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[0] === $id) {
                return $i;
            }
        }
    }
    return null;
}

/** A menu right may name several joined by "|": any one of them opens the item — at the current site. */
function nav_has_right(string $spec): bool
{
    foreach (explode('|', $spec) as $right) {
        if (has_right($right)) {
            return true;
        }
    }
    return false;
}

/**
 * The highest role the member holds AT THE CURRENT SITE, in words — the header's badge (db/004): Restaurant Inventory admin › Manager ›
 * Chef › Kitchen › Viewer; a super-admin "Super-admin". Read through ri_member_roles_at() — the EFFECTIVE roles there.
 */
function role_badge(): string
{
    $m = current_member();
    if ($m === null) {
        return '';
    }
    if (($m['business_role'] ?? '') === 'super_admin') {
        return 'Super-admin';
    }
    $site = current_site_id();
    $held = $site === null ? [] : array_column(my_roles(db(), (int) $m['id'], $site), 'role_key');
    foreach (['admin' => 'Restaurant Inventory admin', 'manager' => 'Manager', 'chef' => 'Chef', 'user' => 'Kitchen', 'viewer' => 'Viewer'] as $key => $label) {
        if (in_array($key, $held, true)) {
            return $label;
        }
    }
    $cap = current_site()['capability'] ?? ($m['capability'] ?? '');
    return match ($cap) { 'admin' => 'Restaurant Inventory admin', 'write' => 'Kitchen', default => 'Viewer' };
}

/** The badge's colour (sso-shell.md "Status vocabulary"). */
function role_badge_kind(string $badge): string
{
    return match ($badge) { 'Super-admin', 'Restaurant Inventory admin' => 'danger', 'Manager' => 'primary', 'Chef' => 'info', 'Kitchen' => 'secondary', default => 'light' };
}

/** A link that navigates by HTMX into #page-content and still works as a plain link (progressive enhancement). $html is already escaped. */
function hx_link(string $url, string $html, string $class = '', string $extra = ''): string
{
    return '<a href="' . e($url) . '"' . ($class !== '' ? ' class="' . e($class) . '"' : '') . ($extra !== '' ? ' ' . $extra : '')
        . ' hx-get="' . e($url) . '" hx-target="#page-content" hx-swap="innerHTML" hx-push-url="' . e($url) . '">' . $html . '</a>';
}

/** "Back to …" from the ?back= a link carried (click-around rule): [url, label] or null. */
function back_link(): ?array
{
    $back = safe_local_path($_GET['back'] ?? null);
    if ($back === null) {
        return null;
    }
    $path = parse_url($back, PHP_URL_PATH) ?: '/';
    foreach (nav_groups() as $items) {
        foreach ($items as $i) {
            if ($i[1] === $path) {
                return [$back, $i[3]];
            }
        }
    }
    if (preg_match('#^/counts/sheets/[0-9]+$#', $path)) {
        return [$back, 'the sheet'];
    }
    if (preg_match('#^/projection/items/[0-9]+$#', $path)) {
        return [$back, 'the item\'s forecast'];
    }
    if (preg_match('#^/projection/stock/[0-9]+$#', $path)) {
        return [$back, 'the projection'];
    }
    foreach (['ingredients' => 'the ingredient', 'suppliers' => 'the supplier', 'areas' => 'the storage area', 'recipes' => 'the recipe', 'menu' => 'the menu item', 'modifiers' => 'the modifier',
              'receipts' => 'the receipt', 'transfers' => 'the transfer', 'adjustments' => 'the adjustment', 'production' => 'the batch', 'waste' => 'the waste log',
              'purchasing' => 'the purchase order', 'invoices' => 'the invoice', 'sales' => 'the day', 'counts' => 'the count', 'periods' => 'the period', 'usage' => 'the ingredient\'s week',
              'projection' => 'the projection'] as $dir => $label) {
        if (preg_match('#^/' . $dir . '/[0-9a-z-]+(/[a-z_-]+)?$#', $path)) {
            return [$back, $label];
        }
    }
    return null;
}

/** The URL of this same page, for a ?back= (path and query as requested). */
function here_url(): string
{
    return (string) ($_SERVER['REQUEST_URI'] ?? '/');
}

function with_back(string $url, string $here): string
{
    $frag = '';
    if (($hash = strpos($url, '#')) !== false) { $frag = substr($url, $hash); $url = substr($url, 0, $hash); }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'back=' . rawurlencode($here) . $frag;
}

/** The path of the current request, for the sidebar's highlight. */
function current_path(): string
{
    return (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');
}

/**
 * The screen a notification or a trail row points at, by the record it names (null when none). The ONE route map of the click-around
 * rule (sso-shell.md "Query functions"). Later slices build the pages; the links are right from the start.
 */
function record_url(?string $type, int|string|null $id): ?string
{
    if ($type === null || $id === null || $id === '') {
        return null;
    }
    $id = (int) $id;
    return match ($type) {
        'ingredient' => '/ingredients/' . $id,
        'recipe' => '/recipes/' . $id,
        'recipe_version' => '/recipes/' . $id,
        'menu_item' => '/menu/' . $id,
        'modifier' => '/modifiers/' . $id . '/map',
        'supplier' => '/suppliers/' . $id,
        'supplier_item' => '/suppliers/' . $id . '/items',
        'storage_area', 'area' => '/areas/' . $id . '/edit',
        'goods_receipt', 'receipt' => '/receipts/' . $id,
        'inventory_transfer', 'transfer' => '/transfers/' . $id,
        'inventory_adjustment', 'adjustment' => '/adjustments/' . $id,
        'production_batch', 'batch' => '/production/' . $id,
        'waste_log', 'waste' => '/waste/' . $id,
        'purchase_order' => '/purchasing/' . $id,
        'supplier_invoice', 'invoice' => '/invoices/' . $id,
        'source' => '/sales/sources/' . $id,
        'depletion_run' => '/sales/runs/' . $id,
        'inventory_count', 'count' => '/counts/' . $id,
        'count_sheet' => '/counts/sheets/' . $id,
        'container' => '/counts/containers/?edit=' . $id . '#container-form',
        'period' => '/periods/' . $id,
        'costing_calendar', 'calendar' => '/periods/calendars#calendar-row-' . $id,
        'forecast_override' => '/projection/',
        'watch' => '/projection/watches#watch-row-' . $id,
        'buyer_proposal', 'proposal' => '/projection/proposals#proposal-card-' . $id,
        'price_alert' => '/purchasing/alerts',
        'member' => '/me/trail',
        'site' => '/',
        default => null,
    };
}

/** The slice that builds each not-yet-built menu item, in words — the placeholders and the registry read it (sso-shell.md). */
const NAV_SLICES = [
    'ingredient-list' => 'slice 1', 'supplier-list' => 'slice 1', 'area-list' => 'slice 1', 'category-list' => 'slice 1', 'unit-list' => 'slice 1',
    'recipe-list' => 'slice 2', 'menu-item-list' => 'slice 2', 'modifier-list' => 'slice 2', 'menu-engineering' => 'slice 2', 'menu-category-list' => 'slice 2',
    'stock-levels' => 'slice 3', 'stock-movements' => 'slice 3', 'stock-value' => 'slice 3', 'receipt-list' => 'slice 3', 'transfer-list' => 'slice 3',
    'adjustment-list' => 'slice 3', 'production-list' => 'slice 3', 'waste-list' => 'slice 3',
    'purchase-order-list' => 'slice 4', 'deliveries-due' => 'slice 4', 'invoice-list' => 'slice 4', 'price-alert-list' => 'slice 4',
    'sales-day-list' => 'slice 5', 'source-list' => 'slice 5', 'sales-upload' => 'slice 5', 'depletion-run-list' => 'slice 5', 'mapping-list' => 'slice 5',
    'usage-report' => 'slice 7', 'period-list' => 'slice 7', 'cogs-report' => 'slice 7', 'calendar-list' => 'slice 7',
    'suggested-order-list' => 'slice 8', 'forecast-list' => 'slice 8', 'projected-stock' => 'slice 8', 'demand-report' => 'slice 8', 'calendar-days' => 'slice 8',
    'special-check' => 'slice 8', 'proposal-list' => 'slice 8', 'watch-list' => 'slice 8',
    'report-list' => 'slice 9', 'export-list' => 'slice 9', 'multi-site' => 'slice 9', 'admin-settings' => 'slice 9', 'admin-sequences' => 'slice 9',
    'admin-tax-rates' => 'slice 9', 'admin-reasons' => 'slice 9', 'admin-agents' => 'slice 9', 'admin-tokens' => 'slice 9', 'admin-trail' => 'slice 9',
];

/**
 * A screen of the manifest that its slice has not built yet (Phase 2): the shell, the page header, one card saying which slice
 * builds it — 200 after the right has been checked at the current site; 403 in the right's words; 501 `not_built` to JSON and to a
 * POST. bin/build_action_registry.php reads a controller that calls this as unbuilt, so the voice surface never offers the screen.
 * Removing the call is the slice's first step.
 */
function render_nav_stub(string $navId, string $slice, string $right): void
{
    $item = nav_item($navId);
    $title = $item[3] ?? ucfirst(str_replace('-', ' ', $navId));
    require_login();
    if (!nav_has_right($right)) {
        require_right(explode('|', $right)[0]);        // refuses in that right's sentence
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        emit_action_status(false, ['error' => 'This is built by ' . $slice . '.']);
        if (wants_json()) {
            json_error('not_built', 'This is built by ' . $slice . '.', 501);
        }
        http_response_code(501);
        echo view('shared/message.php', ['title' => 'Not built yet', 'message' => 'This is built by ' . $slice . '.']);
        exit;
    }
    log_screen_view(db(), $navId);
    if (wants_json()) {
        json_error('not_built', 'This screen is built by ' . $slice . '.', 501);
    }
    $html = view('shared/header.php', ['id' => $navId, 'title' => $title, 'crumbs' => [['Home', '/'], [$title, null]]])
        . '<div class="main-content" id="' . e($navId) . '-content"><div class="card coming-card" id="' . e($navId) . '-coming"><div class="card-body">'
        . '<div class="empty-state"><span class="avatar-text avatar-lg rounded"><i class="' . e($item[2] ?? 'feather-box') . '"></i></span>'
        . '<div><div class="fw-semibold">' . e($title) . ' is not built yet</div><div class="fs-12 text-muted">' . e($slice) . ' builds this screen. Nothing is lost: what belongs here will appear when it ships.</div></div></div>'
        . '</div></div></div>';
    render_screen($title, $html, ['activeNav' => $navId, 'screen' => $navId]);
}
