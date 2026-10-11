<?php
declare(strict_types=1);
/** Action mapping_save (settings.manage): `mapping_key` names an existing mapping to change (a builtin is refused: "Copy it to change it"), else `key` makes a new one (is_builtin false). The mapping is the JSON `mapping` or the form's fields (name, delimiter, date format, one column per field, the void and comp words, the modifier split); the required columns present or 422. Log mapping.save. */
require_once dirname(__DIR__, 3) . '/app/features/pos/handler.php';
ri_handler_begin();
$pdo = db();
require_right('settings.manage');
$errors = [];
$target = req_val('mapping_key') ?: req_val('key');
if ($target === null || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $target)) {
    ri_refuse_fields(['key' => 'A mapping key is lower case letters, digits and _, starting with a letter (up to 40).']);
}
$cur = mapping_for($pdo, $target);
if ($cur !== null && $cur['is_builtin']) {
    refuse(422, 'Copy it to change it — ' . $target . ' is a built-in mapping.');
}
if (req_val('mapping_key') !== null && req_val('mapping_key') !== '' && $cur === null) {
    refuse(404, 'That mapping is not here.');
}
$name = ri_text('name', $cur['name'] ?? null, 80, 'name', $errors, $cur === null);
$m = $cur['mapping'] ?? [];
$raw = req_val('mapping');
if ($raw !== null && $raw !== '') {
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        $errors['mapping'] = 'The mapping is not JSON.';
    } else {
        $m = $j;
    }
}
foreach (['delimiter', 'date_format', 'modifier_split', 'comp_rule'] as $k) {
    if (req_has($k)) { $m[$k] = (string) req_val($k); }
}
foreach (['void_values', 'comp_values'] as $k) {
    if (req_has($k)) { $m[$k] = array_values(array_filter(array_map('trim', explode(',', mb_strtolower((string) req_val($k)))), 'strlen')); }
}
foreach (RiPosNormalizer::FIELDS as $f) {
    if (req_has('col_' . $f)) { $m['columns'][$f] = req_val('col_' . $f) === '' ? null : req_val('col_' . $f); }
}
$cols = (array) ($m['columns'] ?? []);
$has = static fn (string $f): bool => isset($cols[$f]) && $cols[$f] !== null && $cols[$f] !== '';
foreach (['external_id' => 'the check id', 'qty' => 'the quantity'] as $f => $label) {
    if (!$has($f)) { $errors['col_' . $f] = 'The mapping names the column of ' . $label . '.'; }
}
if (!$has('plu') && !$has('item_name')) { $errors['col_plu'] = 'The mapping names the PLU column or the item name column.'; }
if (!$has('business_date') && !$has('closed_at')) { $errors['col_business_date'] = 'The mapping names the date column or the close time column.'; }
if ($errors !== []) {
    ri_refuse_fields($errors);
}
ri_guard($pdo, static function () use ($pdo, $cur, $target, $name, $m): void {
    $pdo->beginTransaction();
    if ($cur === null) {
        $pdo->prepare('INSERT INTO sales_file_mappings (key, name, mapping, is_builtin) VALUES (:k, :n, :m::jsonb, false)')->execute(['k' => $target, 'n' => $name, 'm' => json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } else {
        $pdo->prepare('UPDATE sales_file_mappings SET name = :n, mapping = :m::jsonb, updated_at = now() WHERE key = :k AND NOT is_builtin')->execute(['k' => $target, 'n' => $name, 'm' => json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
    log_activity($pdo, 'mapping.save', 'sales_file_mapping', null, ['after' => ['key' => $target, 'name' => $name, 'columns' => $m['columns'] ?? [], 'created' => $cur === null]]);
    $pdo->commit();
});
ri_done(($cur === null ? 'Made the mapping ' : 'Saved the mapping ') . $name, $target, ri_land(return_path('/sales/mappings/' . $target), 'saved'), 'mappingChanged', ['key' => $target]);
