<?php
/**
 * Support Septian — Google OAuth 2.0 Callback Endpoint (WAJIB)
 * Path: /mail/support/oauth/callback.php
 * URL Resmi: https://septianfr.web.id/mail/support/oauth/callback.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-client.php';

$admin = require_admin();

// 1. Cek jika pengguna membatalkan persetujuan di layar Google
if (isset($_GET['error'])) {
    $errCode = (string) $_GET['error'];
    log_activity('oauth_callback_denied', 'Otorisasi Google OAuth dibatalkan/ditolak: ' . $errCode, 'oauth', null, 'warning', (int) $admin['id']);
    set_flash('warning', 'Koneksi Google Gmail dibatalkan atau akses ditolak (' . $errCode . ').');
    redirect_to('gmail-connection.php');
}

// 2. Verifikasi parameter state untuk mencegah CSRF
$returnedState = (string) ($_GET['state'] ?? '');
$expectedState = (string) ($_SESSION['gmail_oauth_state'] ?? '');
$stateTs       = (int) ($_SESSION['gmail_oauth_state_ts'] ?? 0);

unset($_SESSION['gmail_oauth_state'], $_SESSION['gmail_oauth_state_ts']);

if (
    $returnedState === '' ||
    $expectedState === '' ||
    !hash_equals($expectedState, $returnedState) ||
    (time() - $stateTs) > 900
) {
    log_activity('oauth_state_invalid', 'Validasi state OAuth gagal (indikasi CSRF atau sesi kedaluwarsa).', 'oauth', null, 'error', (int) $admin['id']);
    set_flash('error', 'Validasi keamanan OAuth (state) tidak valid atau kedaluwarsa. Silakan ulangi proses Hubungkan Gmail.');
    redirect_to('gmail-connection.php');
}

// 3. Pastikan authorization code tersedia
$authCode = trim((string) ($_GET['code'] ?? ''));
if ($authCode === '') {
    set_flash('error', 'Kode otorisasi Google tidak ditemukan.');
    redirect_to('gmail-connection.php');
}

try {
    // 4. Tukar authorization code dengan access token & refresh token
    $client = create_google_oauth_client();
    $token  = $client->fetchAccessTokenWithAuthCode($authCode);

    if (isset($token['error'])) {
        $errDesc = (string) ($token['error_description'] ?? $token['error']);
        throw new RuntimeException('Google menolak pertukaran token: ' . $errDesc);
    }

    $client->setAccessToken($token);

    // 5. Verifikasi akun Gmail yang terhubung melalui Gmail API Profile
    $gmailService   = new \Google\Service\Gmail($client);
    $profile        = $gmailService->users->getProfile('me');
    $connectedEmail = strtolower(trim((string) $profile->getEmailAddress()));
    $officialEmail  = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));

    // 6. Tolak akun jika berbeda dari akun resmi support.septianfr@gmail.com
    if ($connectedEmail !== $officialEmail) {
        try {
            if (!empty($token['access_token'])) {
                $client->revokeToken((string) $token['access_token']);
            }
        } catch (Throwable $ignore) {
        }

        log_activity(
            'oauth_wrong_account',
            'Akun Gmail tidak sesuai (' . $connectedEmail . '). Hanya ' . $officialEmail . ' yang diizinkan.',
            'oauth',
            $connectedEmail,
            'warning',
            (int) $admin['id']
        );

        set_flash(
            'error',
            'Koneksi ditolak! Anda memilih akun "' . $connectedEmail . '". Aplikasi Support Septian hanya mengizinkan akun resmi "' . $officialEmail . '".'
        );
        redirect_to('gmail-connection.php');
    }

    // 7. Simpan access token & refresh token secara terenkripsi (AES-256-GCM)
    $existing = get_stored_oauth_token();
    save_oauth_token(
        $connectedEmail,
        $token,
        (int) $admin['id'],
        $existing['refresh_token'] ?? null
    );

    // Otomatis aktifkan mode OAuth 2.0
    save_setting('mail_connection_mode', 'oauth', false, (int) $admin['id']);

    log_activity(
        'oauth_connected',
        'Akun Gmail resmi ' . $connectedEmail . ' berhasil dihubungkan via OAuth 2.0.',
        'oauth',
        $connectedEmail,
        'success',
        (int) $admin['id']
    );

    set_flash('success', 'Gmail resmi ' . $connectedEmail . ' berhasil terhubung dan siap digunakan!');
    redirect_to('gmail-connection.php');
} catch (Throwable $e) {
    app_log('error', 'Kesalahan pada oauth/callback.php: ' . $e->getMessage());
    log_activity('oauth_callback_error', 'Gagal memproses callback OAuth: ' . $e->getMessage(), 'oauth', null, 'error', (int) $admin['id']);
    set_flash('error', 'Gagal menghubungkan Gmail: ' . $e->getMessage());
    redirect_to('gmail-connection.php');
}
