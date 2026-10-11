<?php
declare(strict_types=1);
/** Action `note_remove` (log note.remove): the note's own author, or the admin. */
require_once dirname(__DIR__, 2) . '/app/features/catalog/handler.php';
ri_handler_begin();
$pdo = db();
$id = request_integer('note');
$st = $pdo->prepare('SELECT * FROM mcp_notes WHERE note_id = :id');
$st->execute(['id' => $id ?? 0]);
$n = $st->fetch();
if ($n === false) { respond_not_found('That note is not here.'); }
if ((int) $n['member_id'] !== (int) current_member_id() && !is_ri_admin()) { emit_action_status(false, ['error' => 'Only the note\'s author or an admin removes it.']); refuse(403, 'Only the note\'s author or an admin removes it.'); }
ri_guard($pdo, static function () use ($pdo, $n, $id): void {
    $gone = remove_note($pdo, (int) $id);
    log_activity($pdo, 'note.remove', 'note', (int) $id, ['before' => ['record_type' => $n['record_type'], 'record_id' => (int) $n['record_id'], 'length' => (int) ($gone['length'] ?? 0)]]);
});
ri_done('Removed the note', $id, ri_land(return_path((record_url($n['record_type'], (int) $n['record_id']) ?? '/') . '?tab=notes')), 'noteChanged');
