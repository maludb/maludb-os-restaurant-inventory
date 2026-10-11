<?php
declare(strict_types=1);

/**
 * The periods' handler prelude (periods.md "Handlers"): the gates, the 404s and period_log() (scope_id on every row, count_id on a close with a
 * closing count). Every handler: ri_handler_begin() → the right at the site → the record through its view (a period of another site is the same 404
 * as a missing one) → ri_guard() around one transaction → the schema's verb → log → ri_done(). The schema is the referee of every close.
 */
require_once dirname(__DIR__) . '/stock/handler.php';
require_once dirname(__DIR__) . '/counts/queries.php';
require_once dirname(__DIR__) . '/usage/queries.php';
require_once dirname(__DIR__) . '/usage/present.php';
require_once dirname(__DIR__) . '/usage/csv.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/write.php';

function period_begin(string $right): void
{
    stock_begin($right);
}

function period_or_404(PDO $pdo, ?int $id): array
{
    return stock_here_or_404($id === null ? null : find_period($pdo, $id), 'That period');
}

function period_log(PDO $pdo, string $action, string $entityType, int $entityId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, $opts + ['scope_id' => current_site_id()]);
}

/** The usage window of a request, or the sentence that refuses it (422; a page tells it, JSON answers it). */
function usage_window_or_refuse(PDO $pdo, int $scopeId): array
{
    $w = window_from_request($pdo, $scopeId);
    if ($w['error'] !== null) { refuse(422, $w['error']); }
    return $w;
}

/** The query string that keeps a window and its filters, for a link. */
function usage_query(array $over, array $keep): string
{
    return http_build_query(array_filter(array_merge($keep, $over), static fn ($v): bool => $v !== '' && $v !== null));
}
