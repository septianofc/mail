<?php
/**
 * Support Septian — API: Forward Email
 * POST /mail/support/api/forward.php
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
    json_response(['ok' => false, 'message' => 'Konfirmasi admin wajib dilakukan sebelum meneruskan email.'], 422);
}

try {
    $attachments   = !empty($_FILES['attachments']) ? validate_uploaded_attachments($_FILES['attachments']) : [];
    $to            = trim((string) ($data['to'] ?? ''));
    $cc            = trim((string) ($data['cc'] ?? ''));
    $subject       = trim((string) ($data['subject'] ?? ''));
    $bodyHtml      = (string) ($data['body_html'] ?? '');
    $quotedHtml    = (string) ($data['quoted_html'] ?? '');
    $recipientName = trim((string) ($data['recipient_name'] ?? ''));

    if ($subject !== '' && !preg_match('/^fwd:/i', $subject)) {
        $subject = 'Fwd: ' . $subject;
    }

    $result = mail_send_email([
        'to'            => $to,
        'cc'            => $cc,
        'subject'       => $subject,
        'body_html'     => $bodyHtml,
        'quoted_html'   => $quotedHtml,
        'wrap_template' => true,
        'attachments'   => $attachments,
        'variables'     => [
            'nama_penerima' => $recipientName !== '' ? $recipientName : 'Rekan',
            'nama_pengirim' => $admin['name'] ?: DEFAULT_OFFICIAL_NAME,
            'subjek'        => preg_replace('/^fwd:\s*/i', '', $subject),
        ],
    ]);

    json_response([
        'ok'      => true,
        'message' => 'Email berhasil diteruskan.',
        'result'  => $result,
    ]);
} catch (Throwable $e) {
    app_log('error', 'API forward.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
