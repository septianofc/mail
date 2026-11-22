<?php
/**
 * Support Septian — API: Secure Attachment Download
 * GET /mail/support/api/attachments.php?message_id=...&attachment_id=...&filename=...
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

require_admin(false);

$messageId    = trim((string) ($_GET['message_id'] ?? ''));
$attachmentId = trim((string) ($_GET['attachment_id'] ?? ''));
$filename     = trim((string) ($_GET['filename'] ?? 'lampiran'));

if ($messageId === '' || $attachmentId === '') {
    http_response_code(400);
    exit('Parameter lampiran tidak lengkap.');
}

try {
    $file = mail_download_attachment($messageId, $attachmentId, $filename);
    $safeName = preg_replace('/[^A-Za-z0-9._\- ]/', '_', basename($file['filename'])) ?: 'lampiran';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safeName . '"');
    header('Content-Length: ' . strlen($file['data']));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');

    echo $file['data'];
    exit;
} catch (Throwable $e) {
    app_log('error', 'API attachments.php error: ' . $e->getMessage());
    http_response_code(500);
    exit('Gagal mengunduh lampiran: ' . e($e->getMessage()));
}
