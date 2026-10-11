<?php
declare(strict_types=1);
/**
 * GET /files/{id} — the gated attachment door (sso-shell.md): a session (an anonymous request is 401, never a redirect: a file URL sits
 * in an <img>), and the record's visibility as ri_can_see_attachment() decides it. Streams the file from the store with the stored MIME
 * type, `inline` for images and PDFs, `attachment` otherwise. Logs nothing — a file view is not an event.
 */
require_once dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/attachments.php';
if (!is_logged_in()) {
    http_response_code(401);
    header('Cache-Control: no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('Sign in required.');
}
$id = request_integer('id');
$pdo = db();
$row = $id === null ? null : ri_can_see_attachment($pdo, $id);
$full = $row === null ? '' : attachment_path($row);
if ($row === null || $full === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found.');
}
$inline = str_starts_with((string) $row['mime_type'], 'image/') || $row['mime_type'] === 'application/pdf';
header('Content-Type: ' . $row['mime_type']);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', (string) $row['filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($full);
