<?php
declare(strict_types=1);
/** Action `attachment_remove` (log attachment.remove): the uploader, or the admin. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
$pdo = db();
$id = request_integer('attachment');
$a = $id === null ? null : ri_can_see_attachment($pdo, $id);
if ($a === null) { respond_not_found('That file is not here.'); }
if ((int) $a['uploaded_by'] !== (int) current_member_id() && !is_ri_admin()) { emit_action_status(false, ['error' => 'Only the uploader or an admin removes a file.']); refuse(403, 'Only the uploader or an admin removes a file.'); }
ri_guard($pdo, static function () use ($pdo, $a, $id): void {
    remove_attachment($pdo, (int) $id);
    log_activity($pdo, 'attachment.remove', 'attachment', (int) $id, ['before' => ['record_type' => $a['record_type'], 'record_id' => (int) $a['record_id'], 'filename' => $a['filename'], 'bytes' => (int) $a['byte_size']]]);
});
ri_done('Removed the file', $id, ri_land(return_path((record_url($a['record_type'], (int) $a['record_id']) ?? '/') . '?tab=notes')), 'attachmentChanged');
