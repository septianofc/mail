<?php
/**
 * Support Septian — API: Reply Email
 * POST /mail/support/api/reply.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

$admin = require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

if (empty($data['confirmed']) || (string) $data['confirmed'] !== '1') {
    json_response(['ok' => false, 'message' => 'Konfirmasi admin wajib dilakukan sebelum mengirim balasan.'], 422);
}

try {
    $attachments = !empty($_FILES['attachments']) ? validate_uploaded_attachments($_FILES['attachments']) : [];

    $to            = trim((string) ($data['to'] ?? ''));
    $cc            = trim((string) ($data['cc'] ?? ''));
    $subject       = trim((string) ($data['subject'] ?? ''));
    $bodyHtml      = (string) ($data['body_html'] ?? '');
    $quotedHtml    = (string) ($data['quoted_html'] ?? '');
    $threadId      = trim((string) ($data['thread_id'] ?? ''));
    $inReplyTo     = trim((string) ($data['in_reply_to'] ?? ''));
    $references    = trim((string) ($data['references'] ?? ''));
    $recipientName = trim((string) ($data['recipient_name'] ?? ''));

    if ($subject !== '' && !preg_match('/^re:/i', $subject)) {
        $subject = 'Re: ' . $subject;
    }

    $result = mail_send_email([
        'to'            => $to,
        'cc'            => $cc,
        'subject'       => $subject,
        'body_html'     => $bodyHtml,
        'quoted_html'   => $quotedHtml,
        'wrap_template' => true,
        'thread_id'     => $threadId,
        'in_reply_to'   => $inReplyTo,
        'references'    => trim($references . ' ' . $inReplyTo),
        'attachments'   => $attachments,
        'variables'     => [
            'nama_penerima' => $recipientName !== '' ? $recipientName : 'Pelanggan',
            'nama_pengirim' => $admin['name'] ?: DEFAULT_OFFICIAL_NAME,
            'subjek'        => preg_replace('/^re:\s*/i', '', $subject),
        ],
    ]);

    json_response([
        'ok'      => true,
        'message' => 'Balasan email berhasil dikirim.',
        'result'  => $result,
    ]);
} catch (Throwable $e) {
    app_log('error', 'API reply.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
