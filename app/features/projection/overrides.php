<?php
declare(strict_types=1);

/** The override form's reader, shared by forecast_override_set and forecast_override_propose (one rule, two doors): fields or the sentences in $errors. */
function override_fields(PDO $pdo, int $scopeId, array &$errors): array
{
    $mi = ri_ref($pdo, 'menu_item', null, 'SELECT 1 FROM mcp_menu_items WHERE menu_item_id = :id', 'menu item', $errors);
    $from = proj_date('from_date', null, 'The first day', $errors, true);
    $to = proj_date('to_date', null, 'The last day', $errors, true);
    $factor = proj_decimal('factor', 'The factor', $errors, 1000.0);
    $qty = proj_decimal('qty', 'The quantity', $errors, 1000000.0);
    $reason = proj_required_text('reason', 200, 'The reason', $errors);
    if ($factor !== null && $qty !== null) { $errors['factor'] = 'Give a factor or a quantity — not both.'; }
    elseif ($factor === null && $qty === null && !isset($errors['factor']) && !isset($errors['qty'])) { $errors['factor'] = 'Give a factor (1.5 is 50 % more) or a fixed quantity a day.'; }
    if ($from !== null && $to !== null) {
        if ($to < $from) { $errors['to_date'] = 'The last day is not before the first.'; }
        elseif ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 92) { $errors['to_date'] = 'An override covers up to 92 days.'; }
    }
    return ['menu_item_id' => $mi, 'from_date' => $from, 'to_date' => $to, 'factor' => $factor, 'qty' => $qty, 'reason' => $reason];
}

/** "+50 % on the Chicken Sandwich for Oct 18" — the override in words. */
function override_words(array $f, ?string $itemName): string
{
    $what = $f['factor'] !== null ? ((float) $f['factor'] >= 1 ? '+' . rtrim(rtrim(number_format(((float) $f['factor'] - 1) * 100, 1, '.', ''), '0'), '.') . ' %' : '−' . rtrim(rtrim(number_format((1 - (float) $f['factor']) * 100, 1, '.', ''), '0'), '.') . ' %') : fc_qty($f['qty']) . ' a day';
    $when = $f['from_date'] === $f['to_date'] ? date('M j', (int) strtotime((string) $f['from_date'])) : date('M j', (int) strtotime((string) $f['from_date'])) . ' – ' . date('M j', (int) strtotime((string) $f['to_date']));
    return $what . ' on ' . ($itemName ?? 'every item') . ' for ' . $when;
}
