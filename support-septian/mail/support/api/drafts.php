<?php
/**
 * Support Septian — API: Save / Update Draft in Gmail
 * POST /mail/support/api/drafts.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

$admin = require_admin(true);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pageToken = isset($_GET['pageToken']) && $_GET['pageToken'] !== '' ? trim((string) $_GET['pageToken']) : null;
        $result = mail_list_messages('drafts', 20, $pageToken);
        json_response(['ok' => true, 'data' => $result]);
    } catch (Throwable $e) {
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

try {
    $attachments = !empty($_FILES['attachments']) ? validate_uploaded_attachments($_FILES['attachments']) : [];

    $saved = mail_save_draft([
        'draft_id'    => (string) ($data['draft_id'] ?? ''),
        'thread_id'   => (string) ($data['thread_id'] ?? ''),
        'to'          => (string) ($data['to'] ?? ''),
        'cc'          => (string) ($data['cc'] ?? ''),
        'bcc'         => (string) ($data['bcc'] ?? ''),
        'subject'     => (string) ($data['subject'] ?? '(Tanpa Subjek)'),
        'body_html'   => (string) ($data['body_html'] ?? ''),
        'attachments' => $attachments,
        'variables'   => [
            'nama_penerima' => trim((string) ($data['recipient_name'] ?? '')) ?: 'Pelanggan',
            'nama_pengirim' => $admin['name'] ?: DEFAULT_OFFICIAL_NAME,
            'subjek'        => (string) ($data['subject'] ?? ''),
        ],
    ]);

    json_response([
        'ok'      => true,
        'message' => 'Draft berhasil disimpan ke Gmail.',
        'draft'   => $saved,
    ]);
} catch (Throwable $e) {
    app_log('error', 'API drafts.php error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
