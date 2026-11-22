<?php
/**
 * Support Septian — Google OAuth 2.0 & Gmail API Client
 *
 * Redirect URI Wajib:
 * https://septianfr.web.id/mail/support/oauth/callback.php
 *
 * Scopes:
 * - https://www.googleapis.com/auth/gmail.readonly
 * - https://www.googleapis.com/auth/gmail.send
 * - https://www.googleapis.com/auth/gmail.modify
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Daftar scope Gmail API wajib.
 */
function gmail_required_scopes(): array
{
    return [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/gmail.modify',
    ];
}

/**
 * Cek apakah Google API PHP Client (Composer) sudah terpasang.
 */
function is_google_sdk_installed(): bool
{
    return class_exists(\Google\Client::class) && class_exists(\Google\Service\Gmail::class);
}

/**
 * Buat instance Google\Client terkonfigurasi dengan Client ID & Client Secret dari server.
 */
function create_google_oauth_client(): \Google\Client
{
    if (!is_google_sdk_installed()) {
        throw new RuntimeException(
            'Library Google API PHP Client belum terpasang. Jalankan "composer install" atau unggah folder vendor/ ke /mail/support/vendor/.'
        );
    }

    $clientId     = trim(get_setting('gmail_oauth_client_id', ''));
    $clientSecret = trim(get_setting('gmail_oauth_client_secret', ''));

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException(
            'Google OAuth Client ID atau Client Secret belum diisi. Silakan lengkapi di halaman Pengaturan.'
        );
    }

    $client = new \Google\Client();
    $client->setApplicationName('Support Septian');
    $client->setClientId($clientId);
    $client->setClientSecret($clientSecret);
    $client->setRedirectUri(OAUTH_REDIRECT_URI);
    $client->setScopes(gmail_required_scopes());
    $client->setAccessType('offline');
    $client->setPrompt('consent select_account');
    $client->setIncludeGrantedScopes(true);

    return $client;
}

/**
 * Ambil data token OAuth resmi dari database (terdekripsi di memori server saja).
 */
function get_stored_oauth_token(): ?array
{
    $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
    $stmt = db()->prepare(
        'SELECT * FROM oauth_tokens WHERE provider = :prov AND LOWER(account_email) = LOWER(:email) LIMIT 1'
    );
    $stmt->execute([
        ':prov'  => 'google_gmail',
        ':email' => $officialEmail,
    ]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $accessToken  = crypto_decrypt((string) $row['access_token_enc']);
    $refreshToken = crypto_decrypt((string) ($row['refresh_token_enc'] ?? ''));

    return [
        'id'              => (int) $row['id'],
        'account_email'   => (string) $row['account_email'],
        'access_token'    => $accessToken,
        'refresh_token'   => $refreshToken,
        'token_type'      => (string) ($row['token_type'] ?: 'Bearer'),
        'scope'           => (string) ($row['scope'] ?? ''),
        'expires_at'      => (string) $row['expires_at'],
        'status'          => (string) $row['status'],
        'last_refreshed_at' => $row['last_refreshed_at'],
        'last_error'      => $row['last_error'],
        'updated_at'      => $row['updated_at'],
    ];
}

/**
 * Simpan token OAuth terenkripsi ke database dan backup terenkripsi di storage/tokens/.
 */
function save_oauth_token(
    string $accountEmail,
    array $tokenArray,
    ?int $adminId = null,
    ?string $existingRefreshToken = null
): void {
    $accessToken = (string) ($tokenArray['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('Access token kosong dari Google OAuth.');
    }

    $refreshToken = (string) ($tokenArray['refresh_token'] ?? '');
    if ($refreshToken === '' && $existingRefreshToken !== null && $existingRefreshToken !== '') {
        $refreshToken = $existingRefreshToken;
    }

    $expiresIn = (int) ($tokenArray['expires_in'] ?? 3500);
    $createdAt = (int) ($tokenArray['created'] ?? time());
    $expiresAt = date('Y-m-d H:i:s', $createdAt + max(60, $expiresIn - 60));

    $accessEnc  = crypto_encrypt($accessToken);
    $refreshEnc = $refreshToken !== '' ? crypto_encrypt($refreshToken) : null;

    $stmt = db()->prepare(
        'INSERT INTO oauth_tokens
            (provider, account_email, access_token_enc, refresh_token_enc, token_type, scope, expires_at, status, last_refreshed_at, last_error, connected_by)
         VALUES
            (:prov, :email, :acc, :ref, :type, :scope, :exp, "connected", NOW(), NULL, :uid)
         ON DUPLICATE KEY UPDATE
            access_token_enc  = VALUES(access_token_enc),
            refresh_token_enc = COALESCE(VALUES(refresh_token_enc), refresh_token_enc),
            token_type        = VALUES(token_type),
            scope             = VALUES(scope),
            expires_at        = VALUES(expires_at),
            status            = "connected",
            last_refreshed_at = NOW(),
            last_error        = NULL,
            connected_by      = COALESCE(VALUES(connected_by), connected_by)'
    );

    $stmt->execute([
        ':prov'  => 'google_gmail',
        ':email' => strtolower($accountEmail),
        ':acc'   => $accessEnc,
        ':ref'   => $refreshEnc,
        ':type'  => (string) ($tokenArray['token_type'] ?? 'Bearer'),
        ':scope' => (string) ($tokenArray['scope'] ?? implode(' ', gmail_required_scopes())),
        ':exp'   => $expiresAt,
        ':uid'   => $adminId,
    ]);

    // Simpan salinan terenkripsi juga pada storage/tokens (chmod 0600)
    if (!is_dir(TOKENS_PATH)) {
        @mkdir(TOKENS_PATH, 0750, true);
    }
    $backupFile = TOKENS_PATH . '/gmail_oauth_token.enc';
    $backupData = json_encode([
        'account_email' => strtolower($accountEmail),
        'expires_at'    => $expiresAt,
        'updated_at'    => date('c'),
        'payload'       => $accessEnc,
    ]);
    @file_put_contents($backupFile, (string) $backupData, LOCK_EX);
    @chmod($backupFile, 0600);
}

/**
 * Tandai token sebagai revoked / error saat Google menolak refresh token.
 */
function mark_oauth_token_status(string $status, ?string $errorMessage = null): void
{
    $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
    $stmt = db()->prepare(
        'UPDATE oauth_tokens SET status = :st, last_error = :err WHERE provider = :prov AND LOWER(account_email) = LOWER(:email)'
    );
    $stmt->execute([
        ':st'    => in_array($status, ['connected', 'expired', 'revoked', 'disconnected'], true) ? $status : 'revoked',
        ':err'   => $errorMessage ? substr(redact_sensitive_text($errorMessage), 0, 490) : null,
        ':prov'  => 'google_gmail',
        ':email' => $officialEmail,
    ]);
}

/**
 * Hapus & cabut koneksi OAuth secara aman.
 */
function disconnect_oauth_account(?int $adminId = null): void
{
    $stored = get_stored_oauth_token();
    if ($stored && is_google_sdk_installed()) {
        try {
            $client = create_google_oauth_client();
            if ($stored['access_token'] !== '') {
                $client->revokeToken($stored['access_token']);
            }
        } catch (Throwable $e) {
            app_log('warning', 'Gagal revoke token di sisi Google saat disconnect: ' . $e->getMessage());
        }
    }

    $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
    $stmt = db()->prepare('DELETE FROM oauth_tokens WHERE provider = :prov AND LOWER(account_email) = LOWER(:email)');
    $stmt->execute([
        ':prov'  => 'google_gmail',
        ':email' => $officialEmail,
    ]);

    $backupFile = TOKENS_PATH . '/gmail_oauth_token.enc';
    if (is_file($backupFile)) {
        @unlink($backupFile);
    }

    log_activity('gmail_disconnected', 'Koneksi Google Gmail OAuth 2.0 diputuskan oleh admin.', 'oauth', $officialEmail, 'success', $adminId);
}

/**
 * Mengembalikan Google\Client terautentikasi dengan auto-refresh token.
 * Melempar RuntimeException jika token belum ada atau dicabut.
 */
function get_authenticated_google_client(): \Google\Client
{
    $stored = get_stored_oauth_token();
    if (!$stored || $stored['status'] === 'disconnected' || $stored['access_token'] === '') {
        throw new RuntimeException('Akun Gmail belum terhubung. Silakan hubungkan Gmail melalui halaman Koneksi Gmail.');
    }

    if ($stored['status'] === 'revoked') {
        throw new RuntimeException('Akses Gmail telah dicabut atau kedaluwarsa. Silakan tekan tombol Hubungkan Ulang Gmail.');
    }

    $client = create_google_oauth_client();
    $expiresTs = strtotime($stored['expires_at']) ?: 0;
    $remaining = max(0, $expiresTs - time());

    $client->setAccessToken([
        'access_token'  => $stored['access_token'],
        'refresh_token' => $stored['refresh_token'],
        'token_type'    => $stored['token_type'],
        'expires_in'    => $remaining,
        'created'       => time(),
    ]);

    // Refresh otomatis jika access token kedaluwarsa atau sisa < 2 menit
    if ($client->isAccessTokenExpired() || $remaining < 120) {
        if ($stored['refresh_token'] === '') {
            mark_oauth_token_status('revoked', 'Refresh token tidak tersedia. Diperlukan otorisasi ulang.');
            throw new RuntimeException('Token Gmail kedaluwarsa dan refresh token tidak ditemukan. Silakan hubungkan ulang Gmail.');
        }

        try {
            $newToken = $client->fetchAccessTokenWithRefreshToken($stored['refresh_token']);
            if (isset($newToken['error'])) {
                $errDesc = (string) ($newToken['error_description'] ?? $newToken['error']);
                mark_oauth_token_status('revoked', 'Refresh token ditolak: ' . $errDesc);
                app_log('error', 'Google OAuth refresh token ditolak: ' . $errDesc);
                throw new RuntimeException('Akses Gmail telah dicabut oleh Google (' . $errDesc . '). Silakan hubungkan ulang Gmail.');
            }

            save_oauth_token($stored['account_email'], $newToken, null, $stored['refresh_token']);
            app_log('info', 'Access token Gmail berhasil diperbarui otomatis.');
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'hubungkan ulang')) {
                mark_oauth_token_status('revoked', $e->getMessage());
            }
            throw new RuntimeException('Gagal memperbarui token Gmail secara otomatis. Silakan hubungkan ulang Gmail.');
        }
    }

    return $client;
}

/**
 * Mengembalikan ringkasan status koneksi email aktif (OAuth 2.0 & Google App Password)
 * Aman untuk ditampilkan di UI (tidak pernah mengekspos secret atau token).
 */
function get_mail_connection_status(): array
{
    $mode          = get_setting('mail_connection_mode', 'oauth');
    $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
    $oauthToken    = get_stored_oauth_token();
    $oauthConfigured = trim(get_setting('gmail_oauth_client_id', '')) !== ''
        && trim(get_setting('gmail_oauth_client_secret', '')) !== '';
    $sdkInstalled  = is_google_sdk_installed();

    $appPassEmail  = strtolower(trim(get_setting('gmail_app_password_email', $officialEmail)));
    $appPassSet    = trim(get_setting('gmail_app_password_secret', '')) !== '';
    $imapAvailable = function_exists('imap_open');

    $oauthConnected = $oauthToken !== null && $oauthToken['status'] === 'connected';
    $oauthRevoked   = $oauthToken !== null && $oauthToken['status'] === 'revoked';

    $activeReady = false;
    $activeLabel = 'Belum Terhubung';
    $activeBadge = 'disconnected';
    $notice      = '';

    if ($mode === 'app_password') {
        if ($appPassSet && $appPassEmail === $officialEmail) {
            $activeReady = true;
            $activeLabel = 'Terhubung via Google App Password';
            $activeBadge = 'connected';
            if (!$imapAvailable) {
                $notice = 'SMTP siap mengirim email. Ekstensi PHP IMAP belum aktif di server untuk membaca kotak masuk via App Password (disarankan aktifkan php-imap atau gunakan mode OAuth 2.0).';
            }
        } else {
            $notice = 'Google App Password belum dikonfigurasi untuk akun resmi ' . $officialEmail . '.';
        }
    } else {
        if ($oauthConnected && $sdkInstalled) {
            $activeReady = true;
            $activeLabel = 'Terhubung via Gmail API (OAuth 2.0)';
            $activeBadge = 'connected';
        } elseif ($oauthRevoked) {
            $activeLabel = 'Token Dicabut — Perlu Hubungkan Ulang';
            $activeBadge = 'revoked';
            $notice      = (string) ($oauthToken['last_error'] ?: 'Akses OAuth Gmail telah dicabut. Klik Hubungkan Ulang Gmail.');
        } elseif (!$sdkInstalled) {
            $activeLabel = 'Google API Client Belum Terpasang';
            $activeBadge = 'warning';
            $notice      = 'Folder vendor/ (google/apiclient) belum ditemukan. Instal melalui Composer atau gunakan mode Google App Password.';
        } elseif (!$oauthConfigured) {
            $activeLabel = 'OAuth Client ID/Secret Belum Diisi';
            $activeBadge = 'warning';
            $notice      = 'Isi Google OAuth Client ID & Client Secret di halaman Pengaturan atau Koneksi Gmail.';
        } else {
            $notice = 'Klik "Hubungkan Gmail" untuk mengotorisasi ' . $officialEmail . '.';
        }
    }

    return [
        'mode'               => $mode,
        'official_email'     => $officialEmail,
        'active_ready'       => $activeReady,
        'active_label'       => $activeLabel,
        'active_badge'       => $activeBadge,
        'notice'             => $notice,
        'sdk_installed'      => $sdkInstalled,
        'oauth_configured'   => $oauthConfigured,
        'oauth_connected'    => $oauthConnected,
        'oauth_revoked'      => $oauthRevoked,
        'oauth_expires_at'   => $oauthToken['expires_at'] ?? null,
        'oauth_last_refresh' => $oauthToken['last_refreshed_at'] ?? null,
        'oauth_last_error'   => $oauthToken['last_error'] ?? null,
        'app_password_set'   => $appPassSet,
        'app_password_email' => $appPassEmail,
        'imap_available'     => $imapAvailable,
    ];
}
