<?php
/** Slice 1's proof helpers on the Phase 2 library: a POST as a signed-on person with the CSRF token, a JSON POST under an action token, the world. */
require dirname(__DIR__, 2) . '/phase2/lib.php';
function post(string $jar, string $path, array $form, array $headers = []): array
{
    static $csrf = [];
    $csrf[$jar] ??= page_csrf($jar);
    return req('POST', $path, ['jar' => $jar, 'form' => $form + ['csrf_token' => $csrf[$jar]], 'headers' => $headers]);
}
/** A JSON answer of a POST as a person (HX-less): the location header and the decoded body when any. */
function post_json(string $jar, string $path, array $form): array
{
    $r = post($jar, $path, $form, ['Accept: application/json']);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
function agent_post(string $tok, string $path, array $form, int $site = DOWNTOWN): array
{
    $r = req('POST', $path, ['form' => $form, 'headers' => as_agent($tok) + [] ]);
    $r['json'] = json_decode($r['body'], true) ?: [];
    return $r;
}
function ingredient_id(string $code): int { return (int) one('SELECT id FROM ingredients WHERE code = :c', ['c' => $code]); }
function supplier_id(string $name): int { return (int) one('SELECT id FROM suppliers WHERE name = :n', ['n' => $name]); }
function area_id(int $scope, string $name): int { return (int) one('SELECT id FROM storage_areas WHERE scope_id = :s AND name = :n', ['s' => $scope, 'n' => $name]); }
function supplier_item_id(string $sku): int { return (int) one('SELECT id FROM supplier_items WHERE supplier_sku = :s', ['s' => $sku]); }
function near(float|string|null $a, float $b, float $tol = 0.01): bool { return $a !== null && abs((float) $a - $b) <= $tol; }
