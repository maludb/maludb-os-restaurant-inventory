<?php
declare(strict_types=1);

/** The JSON shapes of a person's corner — whitelists, never a raw row; never a token's value or hash — and the trail's sentences. */

function present_prefs(array $p): array
{
    return ['email_enabled' => (bool) $p['email_enabled'], 'text_enabled' => (bool) $p['text_enabled'], 'kinds' => array_values($p['kinds']),
            'text_kinds' => array_values($p['text_kinds']), 'display_units' => (string) $p['display_units'], 'default_area_id' => $p['default_area_id'], 'saved' => (bool) ($p['saved'] ?? true)];
}

function present_token(array $t): array
{
    return ['token_id' => (int) $t['id'], 'label' => $t['label'], 'last4' => $t['last4'] ?? null, 'created_at' => json_ts($t['created_at']),
            'last_used_at' => json_ts($t['last_used_at'] ?? null), 'expires_at' => json_ts($t['expires_at'] ?? null), 'revoked' => ($t['revoked_at'] ?? null) !== null, 'state' => token_state($t)];
}

/** live | revoked | expired (sso-shell.md "Status vocabulary"). */
function token_state(array $t): string
{
    if (($t['revoked_at'] ?? null) !== null) { return 'revoked'; }
    if (($t['expires_at'] ?? null) !== null && strtotime((string) $t['expires_at']) < time()) { return 'expired'; }
    return 'live';
}

function present_notification(array $n): array
{
    return ['notification_id' => (int) $n['notification_id'], 'scope_id' => $n['scope_id'] === null ? null : (int) $n['scope_id'], 'site' => $n['site_name'] ?? null, 'kind' => $n['kind'],
            'record_type' => $n['record_type'], 'record_id' => $n['record_id'] !== null ? (int) $n['record_id'] : null, 'title' => $n['title'], 'body' => $n['body'],
            'read' => $n['read_at'] !== null, 'created_at' => json_ts($n['created_at']), 'url' => record_url($n['record_type'], $n['record_id']), 'label' => notification_kind((string) $n['kind'])['label']];
}

/** A notification kind's chip: label, colour, icon (sso-shell.md "Status vocabulary"). */
function notification_kind(string $kind): array
{
    $map = [
        'watch' => ['A watch', 'danger', 'feather-eye'], 'stockout' => ['Stock-out', 'danger', 'feather-alert-triangle'], 'price_alert' => ['Price', 'warning', 'feather-trending-up'],
        'pull_failed' => ['POS', 'warning', 'feather-rss'], 'unmapped' => ['Unmapped PLU', 'warning', 'feather-help-circle'], 'po_ack' => ['Acknowledged', 'info', 'feather-check'],
        'po_decline' => ['Declined', 'danger', 'feather-x'], 'po_date' => ['Delivery date', 'info', 'feather-calendar'], 'delivery_due' => ['Delivery due', 'info', 'feather-truck'],
        'count_review' => ['Count', 'primary', 'feather-check-square'], 'count_recount' => ['Recount', 'primary', 'feather-refresh-cw'], 'variance' => ['Variance', 'warning', 'feather-bar-chart'],
        'morning_note' => ['Morning note', 'dark', 'feather-sunrise'], 'mention' => ['Mention', 'primary', 'feather-at-sign'], 'agent_drafted' => ['Drafted', 'secondary', 'feather-edit'],
        'period_due' => ['Period', 'info', 'feather-calendar'], 'invoice_dispute' => ['Invoice', 'warning', 'feather-file'],
    ];
    [$label, $color, $icon] = $map[$kind] ?? [ucfirst(str_replace('_', ' ', $kind)), 'secondary', 'feather-bell'];
    return ['label' => $label, 'color' => $color, 'icon' => $icon];
}

// ---- the trail's sentences ---------------------------------------------------------------------------------------------
/** A row's JSON: the actor, the sentence, the keys. */
function present_activity_row(array $r): array
{
    $link = activity_record_link($r);
    return ['activity_id' => (int) $r['activity_id'], 'occurred_at' => json_ts($r['occurred_at']),
            'actor' => ['member_id' => $r['actor_member_id'] === null ? null : (int) $r['actor_member_id'], 'display_name' => $r['actor_name'], 'is_agent' => (bool) $r['actor_is_agent']],
            'source' => $r['source'], 'action' => $r['action'], 'sentence' => activity_sentence($r), 'entity_type' => $r['entity_type'],
            'entity_id' => $r['entity_id'] === null ? null : (int) $r['entity_id'], 'scope_id' => $r['scope_id'] === null ? null : (int) $r['scope_id'], 'site' => $r['site_name'] ?? null,
            'ingredient_id' => $r['ingredient_id'] === null ? null : (int) $r['ingredient_id'], 'recipe_id' => $r['recipe_id'] === null ? null : (int) $r['recipe_id'],
            'purchase_order_id' => $r['purchase_order_id'] === null ? null : (int) $r['purchase_order_id'], 'count_id' => $r['count_id'] === null ? null : (int) $r['count_id'],
            'agent_run_id' => $r['agent_run_id'] === null ? null : (int) $r['agent_run_id'], 'url' => $link['href'] ?? null];
}

/** The record a row concerns, as a link: ['href', 'label'] (also at 0 and 1) or null. */
function activity_record_link(array $r): ?array
{
    $action = (string) ($r['action'] ?? '');
    if (str_ends_with($action, '.delete') || $action === 'screen.view') { return null; }
    $mk = static fn (string $href, string $label): array => [0 => $href, 1 => $label, 'href' => $href, 'label' => $label];
    $type = (string) ($r['entity_type'] ?? '');
    $id = $r['entity_id'] ?? null;
    if ($id !== null) {
        $own = match ($type) { 'settings', 'ri_settings' => '/admin/settings', 'sequence' => '/admin/sequences', 'tax_rate' => '/admin/tax-rates', 'reason_code' => '/admin/reasons', 'mcp_access_token' => '/me/tokens', 'member', 'site' => null, default => record_url($type, $id) };
        if ($own !== null) { return $mk($own, str_replace('_', ' ', $type)); }
    }
    foreach (['purchase_order_id' => ['/purchasing/', 'purchase order'], 'count_id' => ['/counts/', 'count'], 'recipe_id' => ['/recipes/', 'recipe'], 'ingredient_id' => ['/ingredients/', 'ingredient']] as $k => [$base, $label]) {
        if (($r[$k] ?? null) !== null) { return $mk($base . (int) $r[$k], $label); }
    }
    return null;
}

function activity_after(array $r): array
{
    return is_array($r['after'] ?? null) ? $r['after'] : (json_decode((string) ($r['after'] ?? ''), true) ?: []);
}

/** Who acted, in words: a person's name, an agent chipped "(agent)", the worker, the feed, the supplier at the door. */
function activity_actor(array $r): string
{
    $action = (string) ($r['action'] ?? '');
    if (($r['source'] ?? '') === 'cron' && ($r['actor_name'] ?? null) === null) { return 'the worker'; }
    if (($r['source'] ?? '') === 'feed') { return 'the directory'; }
    if (($r['source'] ?? '') === 'mcp' && ($r['actor_name'] ?? null) === null) { return 'a sibling application'; }
    if (($r['source'] ?? '') === 'door' || ($r['source'] ?? '') === 'portal') { return 'the supplier'; }
    $who = (string) ($r['actor_name'] ?? app_name());
    return !empty($r['actor_is_agent']) ? $who . ' (agent)' : $who;
}

/** The words of every event of this phase and of the manifest (the slices extend it): the verb phrase (the record's number or name follows). */
const ACTIVITY_WORDS = [
    'member.sign_on' => 'signed on', 'member.sign_on.refused' => 'was refused at sign-on', 'member.sign_out' => 'signed out', 'member.refused' => 'was refused', 'member.sign_out.refused' => 'sent a sign-out notice that was refused',
    'member.site_switch' => 'switched to the restaurant', 'member.site_switch.refused' => 'was refused a restaurant', 'member.site_moved' => 'was moved to another restaurant', 'member.preferences_save' => 'changed their settings',
    'member.notification_read' => 'read their notifications', 'directory.sync' => 'refreshed the directory', 'directory.sync.failed' => 'could not refresh the directory',
    'token.mint' => 'made an access token', 'token.revoke' => 'revoked an access token', 'assistant.ask' => 'asked the expert', 'worker.pass' => 'ran a pass', 'share.read' => 'read shared data',
    'notification.send' => 'sent a notice', 'notification.skip' => 'skipped a notice', 'notification.fail' => 'could not send a notice',
    'attachment.add' => 'attached a file', 'attachment.remove' => 'removed an attachment', 'note.add' => 'added a note', 'note.remove' => 'removed a note',
    'ingredient.create' => 'created the ingredient', 'ingredient.update' => 'changed the ingredient', 'ingredient.archive' => 'archived the ingredient', 'ingredient.delete' => 'deleted the ingredient',
    'ingredient.unit_set' => 'set a unit of the ingredient', 'ingredient.par_set' => 'set the par of the ingredient', 'ingredient.cost_override' => 'overrode the cost of the ingredient', 'ingredient.yield_test' => 'recorded a yield test of the ingredient',
    'unit.save' => 'saved the unit', 'category.save' => 'saved the category', 'supplier.create' => 'added the supplier', 'supplier.update' => 'changed the supplier', 'supplier.archive' => 'archived the supplier', 'supplier.delete' => 'deleted the supplier',
    'supplier.schedule_set' => 'set a schedule of the supplier', 'supplier.schedule_remove' => 'removed a schedule of the supplier', 'supplier_item.create' => 'added a supplier item', 'supplier_item.update' => 'changed a supplier item', 'supplier_item.retire' => 'retired a supplier item',
    'order_guide.set' => 'set the order guide', 'area.create' => 'created the storage area', 'area.update' => 'changed the storage area', 'area.archive' => 'archived the storage area', 'area.reorder' => 'reordered the storage areas',
    'recipe.create' => 'created the recipe', 'recipe.update' => 'changed the recipe', 'recipe.archive' => 'archived the recipe', 'recipe.delete' => 'deleted the recipe', 'recipe.version_draft' => 'drafted a version of the recipe',
    'recipe.version_update' => 'changed a draft of the recipe', 'recipe.line_set' => 'set a line of the recipe', 'recipe.line_remove' => 'removed a line of the recipe', 'recipe.version_activate' => 'activated a version of the recipe',
    'recipe.version_discard' => 'discarded a draft of the recipe', 'recipe.version_retire' => 'retired the recipe', 'menu_item.create' => 'added the menu item', 'menu_item.map' => 'mapped the menu item', 'menu_item.price_set' => 'priced the menu item',
    'menu_item.archive' => 'archived the menu item', 'modifier.map' => 'mapped the modifier', 'menu_category.save' => 'saved the menu category',
    'receipt.draft' => 'drafted the receipt', 'receipt.update' => 'changed the receipt', 'receipt.line_add' => 'set a line of the receipt', 'receipt.line_remove' => 'removed a line of the receipt', 'receipt.post' => 'posted the receipt', 'receipt.cancel' => 'cancelled the receipt',
    'stock.reverse' => 'reversed a movement', 'transfer.draft' => 'drafted the transfer', 'transfer.update' => 'changed the transfer', 'transfer.line_set' => 'set a line of the transfer', 'transfer.line_remove' => 'removed a line of the transfer',
    'transfer.send' => 'sent the transfer', 'transfer.receive' => 'received the transfer', 'transfer.cancel' => 'cancelled the transfer', 'adjustment.draft' => 'drafted the adjustment', 'adjustment.line_set' => 'set a line of the adjustment',
    'adjustment.line_remove' => 'removed a line of the adjustment', 'adjustment.post' => 'posted the adjustment', 'adjustment.cancel' => 'cancelled the adjustment', 'production.post' => 'posted the prep batch', 'production.cancel' => 'cancelled the prep batch',
    'waste.log' => 'logged waste', 'waste.review' => 'reviewed the waste log', 'waste.void' => 'voided the waste log',
    'purchase_order.draft' => 'drafted purchase order', 'purchase_order.update' => 'changed purchase order', 'purchase_order.line_set' => 'set a line of purchase order', 'purchase_order.line_remove' => 'removed a line of purchase order',
    'purchase_order.send' => 'sent purchase order', 'purchase_order.place' => 'placed purchase order', 'purchase_order.acknowledge' => 'recorded the acknowledgment of purchase order', 'purchase_order.line_decline' => 'declined a line of purchase order',
    'purchase_order.close' => 'closed purchase order', 'purchase_order.cancel' => 'cancelled purchase order', 'purchase_order.link_rotate' => "renewed the supplier's link for purchase order", 'purchase_order.suggest' => 'drafted the suggested order',
    'purchase_order.supplier_view' => 'opened their page for purchase order', 'purchase_order.supplier_ack' => 'acknowledged purchase order', 'purchase_order.supplier_decline' => 'declined a line of purchase order', 'purchase_order.supplier_date' => 'set the delivery date of purchase order',
    'supplier.message' => 'sent a message to the supplier', 'invoice.create' => 'entered the invoice', 'invoice.update' => 'changed the invoice', 'invoice.line_set' => 'set a line of the invoice', 'invoice.line_remove' => 'removed a line of the invoice',
    'invoice.match' => 'matched the invoice', 'invoice.settle' => 'settled the invoice', 'invoice.void' => 'voided the invoice', 'price_alert.seen' => 'saw a price alert', 'price_alert.threshold_set' => 'set the price-alert threshold',
    'source.create' => 'added the POS source', 'source.update' => 'changed the POS source', 'source.credential_set' => 'set the credential of the POS source', 'source.schedule_set' => 'set the schedule of the POS source', 'source.pause' => 'paused the POS source',
    'source.pull_start' => 'started a pull of the POS source', 'source.pull_done' => 'finished a pull of the POS source', 'source.pull_fail' => 'failed a pull of the POS source', 'source.delete' => 'removed the POS source', 'sale.import' => 'imported a POS file',
    'mapping.save' => 'saved the file mapping', 'depletion.run' => 'ran the depletion', 'depletion.void' => 'voided the depletion run', 'plu.map' => 'mapped a PLU', 'plu.queue' => 'queued a PLU',
    'count_sheet.save' => 'saved the count sheet', 'count_sheet.line_set' => 'set a line of the count sheet', 'count_sheet.line_remove' => 'removed a line of the count sheet', 'count_sheet.reorder' => 'reordered the count sheet', 'container.save' => 'saved the bottle or keg',
    'count.start' => 'started the count', 'count.line_set' => 'counted a line of', 'count.line_add' => 'added a line to the count', 'count.review' => 'reviewed the count', 'count.recount' => 'recounted a line of', 'count.approve' => 'approved the count', 'count.discard' => 'discarded the count',
    'calendar.set' => 'set the costing calendar', 'period.ensure' => "made the year's periods", 'period.close' => 'closed the period', 'period.reopen' => 'reopened the period', 'export.download' => 'downloaded an export', 'report.run' => 'ran the report',
    'forecast.run' => 'ran the forecast', 'forecast.override' => 'set a forecast override', 'forecast.override_propose' => 'proposed a forecast override', 'forecast.override_decide' => 'decided a forecast override', 'forecast.override_remove' => 'removed a forecast override',
    'forecast.covers_sync' => 'read the booked covers', 'forecast.accuracy' => "computed the forecast's accuracy", 'calendar.day_set' => 'set a day of the trading calendar', 'watch.set' => 'set a watch', 'watch.clear' => 'cleared a watch', 'watch.fire' => 'fired a watch',
    'buyer.propose' => 'made a proposal', 'buyer.decide' => 'decided a proposal', 'buyer.note' => 'sent the morning note', 'agent.dispatch' => 'handed a task to an agent', 'agent.reply' => "received an agent's reply", 'agent.fail' => 'could not reach an agent',
    'settings.save' => 'saved the settings', 'settings.site_save' => "saved the restaurant's settings", 'settings.day_part_save' => 'saved a day part', 'settings.sequence_set' => 'set a sequence', 'settings.tax_rate_save' => 'saved a tax rate', 'settings.reason_save' => 'saved a reason code',
    'agent.settings_save' => "saved an agent's settings",
];

/** The record's name from the payload: its number, code, name, title or label; else an empty string. */
function activity_subject(array $after): string
{
    foreach (['number', 'code', 'plu', 'name', 'title', 'label', 'filename', 'kind'] as $k) {
        if (isset($after[$k]) && is_scalar($after[$k]) && trim((string) $after[$k]) !== '') {
            return mb_substr((string) $after[$k], 0, 80);
        }
    }
    return '';
}

/** One line for a row, in words: "Dana activated a version of the recipe Chicken sandwich". */
function activity_sentence(array $r): string
{
    $action = (string) ($r['action'] ?? '');
    $after = activity_after($r);
    $who = activity_actor($r);
    if ($action === 'screen.view') {
        return $who . ' opened ' . str_replace(['-', '_'], ' ', (string) ($r['screen'] ?? $after['screen'] ?? 'a screen'));
    }
    $words = ACTIVITY_WORDS[$action] ?? null;
    if ($words === null) {
        return $who . ' did ' . $action . (($r['entity_type'] ?? null) !== null ? ' on ' . str_replace('_', ' ', (string) $r['entity_type']) . (isset($r['entity_id']) ? ' #' . (int) $r['entity_id'] : '') : '');
    }
    $subject = activity_subject($after);
    return trim($who . ' ' . $words . ($subject !== '' ? ' ' . $subject : ''));
}
