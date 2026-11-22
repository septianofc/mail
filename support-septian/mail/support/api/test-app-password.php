<?php
/**
 * Support Septian — API: Test Google App Password (SMTP + IMAP) Connection
 * POST /mail/support/api/test-app-password.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/smtp-imap-service.php';

$admin = require_admin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

try {
    $email    = isset($data['email']) && trim((string) $data['email']) !== '' ? trim((string) $data['email']) : null;
    $password = isset($data['app_password']) && trim((string) $data['app_password']) !== '' ? trim((string) $data['app_password']) : null;

    $result = test_google_app_password_connection($email, $password);

    log_activity(
        'test_app_password',
        'Pengujian koneksi Google App Password: ' . ($result['ok'] ? 'Berhasil' : 'Gagal'),
        'settings',
        null,
        $result['ok'] ? 'success' : 'warning',
        (int) $admin['id']
    );

    json_response($result, $result['ok'] ? 200 : 400);
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => $e->getMessage()], 400);
}
