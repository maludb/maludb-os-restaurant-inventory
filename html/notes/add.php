<?php
declare(strict_types=1);
/** Action `note_add` (log note.add — the length, never the body): notes.write; the record must be visible to the caller. Location the record's page ?tab=notes. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('notes.write');
$pdo = db();
$type = (string) (req_val('record_type') ?? '');
$rid = request_integer('record_id');
$v = record_view($type);
if ($v === null || $rid === null) { ri_refuse_fields(['record_type' => 'That is not a record a note goes on.']); }
require_visible($pdo, $v[0], $v[1], $rid, 'Record');
$body = trim((string) (req_val('body') ?? ''));
if ($body === '' || mb_strlen($body) > 4000) { ri_refuse_fields(['body' => 'A note is up to 4,000 characters.']); }
$id = ri_guard($pdo, static function () use ($pdo, $type, $rid, $body): int {
    $id = add_note($pdo, $type, $rid, $body, (int) current_member_id());
    log_activity($pdo, 'note.add', 'note', $id, ['after' => ['record_type' => $type, 'record_id' => $rid, 'length' => mb_strlen($body)]] + ($type === 'ingredient' ? ['ingredient_id' => $rid] : []));
    return $id;
});
ri_done('Added a note', $id, ri_land(return_path((record_url($type, $rid) ?? '/') . '?tab=notes'), null, 'note-row-' . $id), 'noteChanged');
