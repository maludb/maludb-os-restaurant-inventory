<?php
declare(strict_types=1);

/**
 * The projection's handler prelude (projection.md "Handlers"): the gates, the 404s and the date readers. Every handler: ri_handler_begin() → the right at the site → the record
 * (a hidden record is the same 404 as a missing one) → ri_guard() around one transaction → the database's verb → log_activity with scope_id → ri_done(). The screens' gate is
 * reports.read (projection is the Chef's and the Manager's — a Kitchen member is told so); the suggested orders and the proposals are purchasing.draft's; watches are ri.read.
 */
require_once dirname(__DIR__) . '/stock/handler.php';
require_once __DIR__ . '/queries.php';
require_once __DIR__ . '/present.php';
require_once __DIR__ . '/charts.php';
require_once __DIR__ . '/write.php';
require_once __DIR__ . '/overrides.php';
require_once dirname(__DIR__) . '/ordering/queries.php';
require_once dirname(__DIR__) . '/ordering/write.php';
require_once dirname(__DIR__) . '/agents/queries.php';
require_once dirname(__DIR__) . '/agents/note.php';
require_once dirname(__DIR__) . '/agents/write.php';
require_once dirname(__DIR__) . '/watches/queries.php';
require_once dirname(__DIR__) . '/watches/write.php';

/** A projection screen: signed in, and reports.read at the site; a member without it is told whose it is. */
function projection_screen_gate(): void
{
    require_login();
    // a read-only screen of quantities opens to every reader at the site (worker-brief.md's gate rule; design §3) — the suggested orders and the
    // proposals, which carry cost and draft orders, keep purchasing.draft in their own controllers; the menu still shows these to who works with them
    if (!has_right('ri.read')) { refuse(403, right_refusal('ri.read')); }
}

function proj_begin(string $right): void
{
    stock_begin($right);
}

function proj_log(PDO $pdo, string $action, string $entityType, int $entityId, array $opts = []): void
{
    log_activity($pdo, $action, $entityType, $entityId, $opts + ['scope_id' => current_site_id()]);
}

/** A date from the request ('Y-m-d'): $keep when left out; an error in $errors for a value that is not a date. */
function proj_date(string $name, ?string $keep, string $label, array &$errors, bool $required = false): ?string
{
    if (!req_has($name)) {
        if ($required && $keep === null) { $errors[$name] = $label . ' is required.'; }
        return $keep;
    }
    $v = (string) req_val($name);
    if ($v === '') {
        if ($required) { $errors[$name] = $label . ' is required.'; }
        return $required ? $keep : null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) { $errors[$name] = $label . ' is a date (year-month-day).'; return $keep; }
    return $v;
}

/** A date from the query string, or $default when absent or not a date. */
function proj_query_date(string $name, string $default): string
{
    $v = request_string($name);
    $d = $v === '' ? false : DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    return $d !== false && $d->format('Y-m-d') === $v ? $v : $default;
}

/** A positive-or-zero number from a field: null when empty; adds the sentence when it is not a number or is out of bounds. */
function proj_decimal(string $name, string $label, array &$errors, float $max = 100000.0): ?string
{
    if (!req_has($name) || (string) req_val($name) === '') { return null; }
    $v = str_replace([',', ' '], '', (string) req_val($name));
    if (!is_numeric($v) || (float) $v < 0) { $errors[$name] = $label . ' is a number, zero or more.'; return null; }
    if ((float) $v > $max) { $errors[$name] = $label . ' is up to ' . fmt_num($max, 0) . '.'; return null; }
    return $v;
}

/** A required text field that may have been left out altogether (ri_text() keeps $keep when the field is absent — for a create that is a missing field, said in words). */
function proj_required_text(string $name, int $max, string $label, array &$errors): ?string
{
    $v = ri_text($name, null, $max, $label, $errors, true);
    if ($v === null && !isset($errors[$name])) { $errors[$name] = $label . ' is required.'; }
    return $v;
}
