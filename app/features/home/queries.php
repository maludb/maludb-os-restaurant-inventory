<?php
declare(strict_types=1);

/**
 * The home's regions (screen `home`, sso-shell.md "Home"; reports-admin.md fills the keys): one function, one call per region, each
 * honouring its right at the current site and answering null until its slice fills the key. Home writes nothing.
 *   stockouts     projected stock-outs before the next delivery (slice 8)        suggested   suggested orders due today (slice 8)
 *   unmapped      unmapped PLUs (slice 5)                                          count       the last count's variance (slice 6)
 *   prices        price movers (slice 4)                                           purchasing  open POs and deliveries due (slice 4)
 *   forecast      the forecast's thin items (slice 8)                              period      the period's status (slice 7)
 *   kitchen       to receive today, the open count (slices 3, 6)                   admin       dispatches and the agents' proposals (slices 8, 9)
 *   bell          the five newest unread notifications (this phase)
 * Every region: ['slice' => 'slice N', 'count' => int|null, 'rows' => [...]|null] — null means "its slice has not filled it".
 */
const HOME_REGIONS = [
    'stockouts' => ['Stock-outs projected', 'slice 8', 'feather-alert-triangle', '/projection/stock?stockouts_only=1', 'ri.read'],
    'suggested' => ['Suggested orders due', 'slice 8', 'feather-shopping-cart', '/purchasing/suggested', 'purchasing.draft'],
    'unmapped' => ['Unmapped PLUs', 'slice 5', 'feather-help-circle', '/menu/?unmapped=1', 'recipes.write'],
    'count' => ['The last count', 'slice 6', 'feather-check-square', '/counts/', 'stock.count'],
    'prices' => ['Price movers', 'slice 4', 'feather-trending-up', '/purchasing/alerts', 'purchasing.draft'],
    'purchasing' => ['Open orders and deliveries', 'slice 4', 'feather-truck', '/purchasing/', 'ri.read'],
    'forecast' => ['Thin forecasts', 'slice 8', 'feather-bar-chart', '/projection/', 'reports.read'],
    'period' => ['The period', 'slice 7', 'feather-calendar', '/periods/', 'reports.read'],
    'kitchen' => ['Today in the kitchen', 'slice 3', 'feather-coffee', '/receipts/', 'stock.receive|stock.count'],
    'admin' => ['The agents', 'slice 9', 'feather-cpu', '/admin/agents', 'agents.settings|settings.manage'],
];

function home_summary(PDO $pdo, int $memberId, int $scopeId): array
{
    $out = ['site_id' => $scopeId, 'regions' => [], 'bell' => null, 'may' => ['cost' => sees_cost()]];
    foreach (HOME_REGIONS as $key => [$title, $slice, $icon, $url, $right]) {
        if (!nav_has_right($right)) {
            continue;
        }
        $out['regions'][$key] = ['title' => $title, 'slice' => $slice, 'icon' => $icon, 'url' => $url, 'count' => null, 'rows' => null];
    }
    $st = $pdo->prepare('SELECT notification_id, kind, record_type, record_id, title, body, created_at FROM mcp_notifications WHERE member_id = :m AND read_at IS NULL ORDER BY created_at DESC LIMIT 5');
    $st->execute(['m' => $memberId]);
    $out['bell'] = $st->fetchAll();
    return $out;
}
