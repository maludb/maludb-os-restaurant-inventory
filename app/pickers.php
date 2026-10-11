<?php
declare(strict_types=1);

/**
 * app/pickers.php — the registry of what a form can pick (app/picker.php has the contract; the design system's reference is
 * record-picker.md). One entry per kind of record a form chooses; every key is a bigint. Phase 2 ships the picker with the shell and
 * registers nothing: slice 1 adds `ingredient`, `supplier`, `supplier_item` (param `supplier`), `category`, `area`; slice 2 adds
 * `recipe` (params `kind`, `exclude`), `menu_item`, `modifier`. The proofs register a `member` source of their own (tests/phase2/picker.php).
 *
 * Every `gate` is the authorization the screens that use the field apply (null = allowed, else the refusal's words) — asked AT THE
 * CURRENT SITE through has_right(); every row comes from the mcp_* views. `search` reuses the feature's own query function and only
 * filters and slices what it returns; `label` names one record for a form's first render and a 422 re-render.
 */
$sources = [];
foreach (glob(__DIR__ . '/pickers/*.php') ?: [] as $file) {                 // each slice's own sources (app/pickers/<slice>.php); the first definition of a key wins
    $sources += (array) require $file;
}
$extra = getenv('RI_PICKER_EXTRA');                       // the proofs' own sources (never set in production)
if ($extra !== false && $extra !== '' && getenv('APP_ENV') !== 'prod' && is_file($extra)) {
    $sources += (array) require $extra;
}
return $sources;
