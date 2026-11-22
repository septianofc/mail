<?php
/**
 * Support Septian — PDO MySQL Database Layer & Settings Repository
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';

/**
 * Mengembalikan koneksi PDO singleton dengan prepared statements asli.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host    = (string) app_config('db.host', 'localhost');
    $port    = (string) app_config('db.port', '3306');
    $name    = (string) app_config('db.name', '');
    $user    = (string) app_config('db.user', '');
    $pass    = (string) app_config('db.pass', '');
    $charset = (string) app_config('db.charset', 'utf8mb4');

    if ($name === '' || $user === '') {
        throw new RuntimeException('Konfigurasi database belum lengkap. Silakan buka halaman install.php.');
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

/**
 * Ambil seluruh pengaturan dari tabel app_settings (di-cache per request).
 */
function get_all_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    $defaults = [
        'app_name'                  => DEFAULT_APP_NAME,
        'official_name'             => DEFAULT_OFFICIAL_NAME,
        'support_email'             => DEFAULT_OFFICIAL_GMAIL,
        'website_url'               => DEFAULT_WEBSITE_URL,
        'app_url'                   => DEFAULT_APP_URL,
        'logo_url'                  => DEFAULT_LOGO_URL,
        'official_address'          => DEFAULT_ADDRESS,
        'mail_connection_mode'      => 'oauth', // 'oauth' atau 'app_password'
        'gmail_oauth_client_id'     => '',
        'gmail_oauth_client_secret' => '',
        'gmail_app_password_email'  => DEFAULT_OFFICIAL_GMAIL,
        'gmail_app_password_secret' => '',
        'gmail_smtp_host'           => 'smtp.gmail.com',
        'gmail_smtp_port'           => '465',
        'gmail_smtp_encryption'     => 'ssl',
        'gmail_imap_host'           => 'imap.gmail.com',
        'gmail_imap_port'           => '993',
        'email_signature'           => '<p style="margin:0 0 6px 0;font-weight:600;color:#111827;">Hormat kami,</p><p style="margin:0 0 2px 0;font-weight:600;color:#1a73e8;">Tim Support Septian F. R.</p><p style="margin:0;font-size:13px;color:#5f6368;">Layanan Bantuan Resmi Septian F. R.</p>',
        'footer_note'               => 'Email ini dikirim melalui sistem layanan resmi Support Septian F. R.',
        'items_per_page'            => '20',
        'timezone'                  => 'Asia/Jakarta',
    ];

    try {
        $stmt = db()->query('SELECT setting_key, setting_value, is_encrypted FROM app_settings');
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $val = (string) ($row['setting_value'] ?? '');
            if ((int) ($row['is_encrypted'] ?? 0) === 1 && $val !== '') {
                $val = crypto_decrypt($val);
            }
            $defaults[$row['setting_key']] = $val;
        }
    } catch (Throwable $e) {
        // Fallback ke default jika tabel belum dibuat saat installer berjalan
    }

    // Override opsional dari environment server
    if (getenv('GMAIL_CLIENT_ID')) {
        $defaults['gmail_oauth_client_id'] = (string) getenv('GMAIL_CLIENT_ID');
    }
    if (getenv('GMAIL_CLIENT_SECRET')) {
        $defaults['gmail_oauth_client_secret'] = (string) getenv('GMAIL_CLIENT_SECRET');
    }
    if (getenv('GMAIL_APP_PASSWORD')) {
        $defaults['gmail_app_password_secret'] = (string) getenv('GMAIL_APP_PASSWORD');
    }

    $cache = $defaults;
    return $cache;
}

/**
 * Ambil satu pengaturan berdasarkan key.
 */
function get_setting(string $key, string $default = ''): string
{
    $all = get_all_settings();
    return (string) ($all[$key] ?? $default);
}

/**
 * Simpan satu pengaturan ke app_settings (mendukung enkripsi AES-256-GCM).
 */
function save_setting(string $key, string $value, bool $encrypt = false, ?int $adminId = null): void
{
    $storedValue = ($encrypt && $value !== '') ? crypto_encrypt($value) : $value;

    $stmt = db()->prepare(
        'INSERT INTO app_settings (setting_key, setting_value, is_encrypted, updated_by)
         VALUES (:k, :v, :enc, :uid)
         ON DUPLICATE KEY UPDATE
           setting_value = VALUES(setting_value),
           is_encrypted  = VALUES(is_encrypted),
           updated_by    = VALUES(updated_by)'
    );
    $stmt->execute([
        ':k'   => $key,
        ':v'   => $storedValue,
        ':enc' => $encrypt ? 1 : 0,
        ':uid' => $adminId,
    ]);

    get_all_settings(true);
}

/**
 * Catat log aktivitas admin ke tabel activity_logs (tanpa data sensitif).
 */
function log_activity(
    string $action,
    string $description,
    ?string $entityType = null,
    ?string $entityId = null,
    string $status = 'success',
    ?int $adminId = null
): void {
    try {
        $uid = $adminId ?? ($_SESSION['admin_id'] ?? null);
        $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua  = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250);

        // Redaksi otomatis agar token/password tidak pernah tercatat
        $safeDescription = redact_sensitive_text($description);

        $stmt = db()->prepare(
            'INSERT INTO activity_logs (admin_id, action, entity_type, entity_id, description, ip_address, user_agent, status)
             VALUES (:uid, :action, :etype, :eid, :desc, :ip, :ua, :status)'
        );
        $stmt->execute([
            ':uid'    => $uid,
            ':action' => substr($action, 0, 80),
            ':etype'  => $entityType ? substr($entityType, 0, 60) : null,
            ':eid'    => $entityId ? substr($entityId, 0, 160) : null,
            ':desc'   => substr($safeDescription, 0, 495),
            ':ip'     => $ip,
            ':ua'     => $ua,
            ':status' => in_array($status, ['success', 'warning', 'error'], true) ? $status : 'success',
        ]);
    } catch (Throwable $e) {
        // Jangan menggagalkan alur utama jika logging gagal
    }
}
