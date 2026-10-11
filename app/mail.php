<?php
declare(strict_types=1);

/**
 * Notification mail only: one outbound send through MaluMail's REST API (`/v1/send`, bearer MALUMAIL_API_KEY — the installer's
 * notification key, the same door every sibling's bell uses; MALUMAIL_API_URL points the proofs at a stub). The bell's mail copies
 * (a purchase order to a supplier with its secure link, a price alert, the morning note's copy) go through malumail_send(). Mail is
 * optional: with no key the send raises a catchable error and the screens say so.
 */

/** Send one email. Answers ['status' => int, 'body' => array, 'message_id' => ?string]; throws on a missing key or a transport error (never on an HTTP status). */
function malumail_send(array $mail): array
{
    $key = (string) env('MALUMAIL_API_KEY', '');
    if ($key === '') {
        throw new RuntimeException('MALUMAIL_API_KEY is not configured.');
    }
    $ch = curl_init(rtrim((string) env('MALUMAIL_API_URL', 'https://api.malumail.com'), '/') . '/v1/send');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($mail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || $raw === false) {
        throw new RuntimeException('MaluMail transport error.');
    }
    $body = json_decode((string) $raw, true);
    $body = is_array($body) ? $body : [];
    return ['status' => $status, 'body' => $body, 'message_id' => isset($body['message_id']) ? (string) $body['message_id'] : null];
}
