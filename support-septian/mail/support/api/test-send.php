<?php
/**
 * Support Septian — API: Kirim 1 Email Tes Diagnostik (Verifikasi SMTP & DKIM Gmail)
 * POST /mail/support/api/test-send.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-service.php';

$admin = require_superadmin(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'message' => 'Metode tidak diizinkan.'], 405);
}

$data = !empty($_POST) ? $_POST : read_json_input();
require_csrf(true);

$testTo = trim((string) ($data['test_to'] ?? ''));

try {
    if ($testTo === '' || !filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'message' => 'Masukkan alamat email Gmail tujuan tes yang valid.'], 422);
    }

    $result = send_diagnostic_test_email($testTo, (string) ($admin['name'] ?: DEFAULT_OFFICIAL_NAME));

    json_response([
        'ok'                 => true,
        'message'            => '1 email tes berhasil dikirim ke ' . $testTo . '. Silakan cek Kotak Masuk dan pilih "Tampilkan Asli / Show Original" di Gmail untuk memverifikasi DKIM=PASS.',
        'smtp_connected'     => (bool) ($result['smtp_connected'] ?? true),
        'smtp_authenticated' => (bool) ($result['smtp_authenticated'] ?? true),
        'account_email'      => $result['account_email'] ?? DEFAULT_OFFICIAL_GMAIL,
        'from_email'         => $result['from_email'] ?? DEFAULT_OFFICIAL_GMAIL,
        'from_name'          => $result['from_name'] ?? DEFAULT_OFFICIAL_NAME,
        'transport_method'   => ($result['transport'] ?? '') === 'phpmailer_gmail_smtp'
            ? 'PHPMailer SMTP (smtp.gmail.com:465 · SSL · SMTPAuth=true)'
            : 'Google Gmail API (OAuth 2.0)',
        'message_id'         => $result['message_id'] ?? $result['id'] ?? '',
        'headers_verified'   => $result['headers_verified'] ?? [],
        'smtp_transcript'    => $result['smtp_transcript'] ?? '',
    ]);
} catch (Throwable $e) {
    $safeErr = redact_sensitive_text($e->getMessage());
    app_log('error', 'API test-send.php gagal: ' . $safeErr);
    json_response([
        'ok'                 => false,
        'smtp_connected'     => false,
        'smtp_authenticated' => false,
        'account_email'      => DEFAULT_OFFICIAL_GMAIL,
        'from_email'         => DEFAULT_OFFICIAL_GMAIL,
        'from_name'          => DEFAULT_OFFICIAL_NAME,
        'transport_method'   => 'PHPMailer SMTP (smtp.gmail.com:465 · SSL · SMTPAuth=true)',
        'smtp_error'         => $safeErr,
        'message'            => $safeErr,
    ], 400);
}
