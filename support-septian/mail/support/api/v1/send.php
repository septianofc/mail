<?php
/**
 * Skyzz Mail — Public REST API v1: Send Email
 * POST /mail/support/api/v1/send.php
 *
 * Header Autentikasi:
 *   Authorization: Bearer skz_xxxxxxxxxxxxxxxxxxxxxxxx
 *   atau
 *   X-API-Key: skz_xxxxxxxxxxxxxxxxxxxxxxxx
 *
 * Body JSON:
 *   {
 *     "to": "penerima@domain.com",
 *     "cc": "",
 *     "bcc": "",
 *     "subject": "Judul Email",
 *     "body_html": "<p>Halo...</p>",
 *     "text": "Alternatif teks biasa jika tidak memakai body_html",
 *     "from_name": "Nama Pengirim Kustom (opsional)",
 *     "recipient_name": "Nama Penerima (opsional)",
 *     "wrap_template": true,
 *     "mailbox_id": 1
 *   }
 *
 * Aturan Kuota:
 *   - Free: 10 email/hari (selamanya) + watermark "Dikirim Menggunakan Aplikasi Skyzz Mail"
 *   - Lite: 50 email/hari, Pro: 100 email/hari, Business: 200 email/hari (tanpa watermark)
 *   - Jika pengiriman GAGAL, kuota harian TIDAK berkurang!
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/gmail-service.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'message' => 'Gunakan metode POST.'], 405);
}

$user = authenticate_api_key();
if (!$user) {
    json_response([
        'ok'      => false,
        'code'    => 'UNAUTHORIZED',
        'message' => 'API Key tidak valid atau telah dicabut. Kirimkan melalui header Authorization: Bearer <API_KEY> atau X-API-Key.',
    ], 401);
}

$plan = get_user_effective_plan($user);
if (empty($plan['allow_api'])) {
    json_response([
        'ok'      => false,
        'code'    => 'API_DISABLED_FOR_PLAN',
        'message' => 'Paket Anda saat ini tidak mengaktifkan akses pengiriman via API.',
    ], 403);
}

$input = read_json_input();
if (empty($input)) {
    $input = $_POST;
}

try {
    // Pilih mailbox: opsional mailbox_id dari payload (harus aktif & diizinkan), atau mailbox pilihan user
    $mailbox = null;
    if (!empty($input['mailbox_id'])) {
        $reqMbId = (int) $input['mailbox_id'];
        $cand = get_mailbox_by_id($reqMbId);
        if ($cand && (int) $cand['is_active'] === 1 && (is_superadmin($user) || (int) $cand['allow_user_select'] === 1)) {
            $mailbox = $cand;
        } else {
            json_response(['ok' => false, 'code' => 'INVALID_MAILBOX', 'message' => 'mailbox_id tidak tersedia atau tidak aktif.'], 422);
        }
    } else {
        $mailbox = get_effective_mailbox($user);
    }

    $to = trim((string) ($input['to'] ?? ''));
    $cc = trim((string) ($input['cc'] ?? ''));
    $bcc = trim((string) ($input['bcc'] ?? ''));
    $subject = trim((string) ($input['subject'] ?? ''));
    $bodyHtml = (string) ($input['body_html'] ?? '');
    if (trim($bodyHtml) === '' && isset($input['text'])) {
        $bodyHtml = '<p>' . nl2br(e((string) $input['text'])) . '</p>';
    }
    if (trim($bodyHtml) === '' && isset($input['message'])) {
        $bodyHtml = '<p>' . nl2br(e((string) $input['message'])) . '</p>';
    }

    if ($to === '' || $subject === '' || trim($bodyHtml) === '') {
        json_response([
            'ok'      => false,
            'code'    => 'VALIDATION_ERROR',
            'message' => 'Parameter "to", "subject", dan "body_html" (atau "text") wajib diisi.',
        ], 422);
    }

    $wrapTemplate = !array_key_exists('wrap_template', $input) || filter_var($input['wrap_template'], FILTER_VALIDATE_BOOLEAN);
    $fromName = trim((string) ($input['from_name'] ?? ''));
    $recipientName = trim((string) ($input['recipient_name'] ?? ''));

    $result = mail_send_email([
        'actor'         => $user,
        'mailbox'       => $mailbox,
        'sent_via'      => 'api',
        'to'            => $to,
        'cc'            => $cc,
        'bcc'           => $bcc,
        'subject'       => $subject,
        'body_html'     => $bodyHtml,
        'from_name'     => $fromName,
        'wrap_template' => $wrapTemplate,
        'variables'     => [
            'nama_penerima' => $recipientName !== '' ? $recipientName : explode('@', parse_email_list($to)[0] ?? 'Pelanggan')[0],
            'nama_pengirim' => $fromName !== '' ? $fromName : ((string) ($user['custom_sender_name'] ?: $user['name'])),
            'subjek'        => $subject,
        ],
    ]);

    json_response([
        'ok'               => true,
        'message'          => 'Email berhasil dikirim melalui Skyzz Mail API.',
        'message_id'       => $result['message_id'] ?? $result['id'] ?? '',
        'from_email'       => $result['from_email'] ?? '',
        'from_name'        => $result['from_name'] ?? '',
        'imap_sent_synced' => (bool) ($result['imap_sent_synced'] ?? false),
        'quota'            => $result['quota'] ?? null,
    ], 200);
} catch (Throwable $e) {
    $quotaNow = get_user_quota_status($user);
    $code = str_contains($e->getMessage(), 'Batas pengiriman harian') ? 'QUOTA_EXCEEDED' : 'SEND_FAILED';
    $httpStatus = $code === 'QUOTA_EXCEEDED' ? 429 : 400;

    json_response([
        'ok'      => false,
        'code'    => $code,
        'message' => redact_sensitive_text($e->getMessage()),
        'quota'   => [
            'plan'        => $quotaNow['plan']['name'],
            'used_today'  => $quotaNow['used_today'],
            'daily_limit' => $quotaNow['daily_limit'],
            'remaining'   => $quotaNow['remaining'],
            'unlimited'   => $quotaNow['is_unlimited'],
        ],
    ], $httpStatus);
}
