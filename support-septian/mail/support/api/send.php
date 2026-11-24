<?php
/**
 * Support Septian — API: Send New Email
 * POST /mail/support/api/send.php (multipart/form-data atau JSON)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

$admin = require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = $_POST;
if (empty($data)) {
    $data = read_json_input();
}

require_csrf(true);

if (empty($data['confirmed']) || (string) $data['confirmed'] !== '1') {
    json_response(['ok' => false, 'message' => 'Pengiriman email memerlukan konfirmasi admin terlebih dahulu.'], 422);
}

try {
    $attachments = !empty($_FILES['attachments']) ? validate_uploaded_attachments($_FILES['attachments']) : [];

    $to            = trim((string) ($data['to'] ?? ''));
    $cc            = trim((string) ($data['cc'] ?? ''));
    $bcc           = trim((string) ($data['bcc'] ?? ''));
    $subject       = trim((string) ($data['subject'] ?? ''));
    $bodyHtml      = (string) ($data['body_html'] ?? '');
    $recipientName = trim((string) ($data['recipient_name'] ?? ''));
    $wrapTemplate  = !isset($data['wrap_template']) || (string) $data['wrap_template'] === '1';

    $result = mail_send_email([
        'to'            => $to,
        'cc'            => $cc,
        'bcc'           => $bcc,
        'subject'       => $subject,
        'body_html'     => $bodyHtml,
        'wrap_template' => $wrapTemplate,
        'attachments'   => $attachments,
        'variables'     => [
            'nama_penerima' => $recipientName !== '' ? $recipientName : 'Pelanggan',
            'nama_pengirim' => $admin['name'] ?: DEFAULT_OFFICIAL_NAME,
            'subjek'        => $subject,
        ],
    ]);

    $fromLabel = $result['from_email'] ?? get_effective_mailbox($admin)['email'];
    json_response([
        'ok'      => true,
        'message' => 'Email berhasil dikirim dari ' . $fromLabel . ' dan tercatat di folder Terkirim.',
        'result'  => $result,
    ]);
} catch (Throwable $e) {
    app_log('error', 'API send.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
