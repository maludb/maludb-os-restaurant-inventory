<?php
declare(strict_types=1);
/** Action `attachment_add` (log attachment.add — filename, mime and bytes, never the file): notes.write; the record must be visible; the store sniffs the type. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
require_right('notes.write');
$pdo = db();
$type = (string) (req_val('record_type') ?? '');
$rid = request_integer('record_id');
$v = record_view($type);
if ($v === null || $rid === null) { ri_refuse_fields(['record_type' => 'That is not a record a file attaches to.']); }
require_visible($pdo, $v[0], $v[1], $rid, 'Record');
if (!isset($_FILES['file'])) { ri_refuse_fields(['file' => 'Choose a file.']); }
$id = ri_guard($pdo, static function () use ($pdo, $type, $rid): int {
    $id = attachment_store($pdo, $type, $rid, $_FILES['file'], (int) current_member_id());
    $st = $pdo->prepare('SELECT filename, mime_type, byte_size FROM mcp_attachments WHERE attachment_id = :id'); $st->execute(['id' => $id]); $a = $st->fetch() ?: [];
    log_activity($pdo, 'attachment.add', 'attachment', $id, ['after' => ['record_type' => $type, 'record_id' => $rid, 'filename' => $a['filename'] ?? '', 'mime' => $a['mime_type'] ?? '', 'bytes' => (int) ($a['byte_size'] ?? 0)]] + ($type === 'ingredient' ? ['ingredient_id' => $rid] : []));
    return $id;
});
ri_done('Attached the file', $id, ri_land(return_path((record_url($type, $rid) ?? '/') . '?tab=notes'), null, 'attachment-row-' . $id), 'attachmentChanged');
