<?php
/**
 * Skyzz Mail — PDO MySQL Database Layer, Settings Repository & Auto-Schema Migrator
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/cache.php';

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
 * Migrasi otomatis & aman (idempotent) agar instalasi lama Support Septian
 * langsung kompatibel dengan seluruh fitur Skyzz Mail tanpa error SQL 500.
 */
function ensure_skyzz_schema(bool $force = false): void
{
    static $ran = false;
    if ($ran && !$force) {
        return;
    }
    $ran = true;

    if (!is_app_installed()) {
        return;
    }

    $schemaFlag = 'skyzz_schema_v2';
    if (!$force && cache_get($schemaFlag) === 'ok') {
        return;
    }

    try {
        $pdo = db();

        // 1. Pastikan kolom baru di tabel admins tersedia & role mendukung 'user'
        $cols = [];
        $resCols = $pdo->query('SHOW COLUMNS FROM admins')->fetchAll();
        foreach ($resCols as $c) {
            $cols[strtolower((string) $c['Field'])] = $c;
        }

        if (isset($cols['role']) && !str_contains(strtolower((string) ($cols['role']['Type'] ?? '')), "'user'")) {
            $pdo->exec("ALTER TABLE `admins` MODIFY `role` ENUM('superadmin', 'admin', 'user') NOT NULL DEFAULT 'user'");
        }
        if (!isset($cols['selected_mailbox_id'])) {
            $pdo->exec('ALTER TABLE `admins` ADD COLUMN `selected_mailbox_id` INT UNSIGNED DEFAULT NULL AFTER `avatar_url`');
        }
        if (!isset($cols['plan_slug'])) {
            $pdo->exec("ALTER TABLE `admins` ADD COLUMN `plan_slug` VARCHAR(40) NOT NULL DEFAULT 'free' AFTER `selected_mailbox_id`");
        }
        if (!isset($cols['plan_expires_at'])) {
            $pdo->exec('ALTER TABLE `admins` ADD COLUMN `plan_expires_at` DATETIME DEFAULT NULL AFTER `plan_slug`');
        }
        if (!isset($cols['custom_sender_name'])) {
            $pdo->exec('ALTER TABLE `admins` ADD COLUMN `custom_sender_name` VARCHAR(120) DEFAULT NULL AFTER `plan_expires_at`');
        }
        if (!isset($cols['custom_signature'])) {
            $pdo->exec('ALTER TABLE `admins` ADD COLUMN `custom_signature` MEDIUMTEXT DEFAULT NULL AFTER `custom_sender_name`');
        }
        if (!isset($cols['custom_footer_note'])) {
            $pdo->exec('ALTER TABLE `admins` ADD COLUMN `custom_footer_note` VARCHAR(500) DEFAULT NULL AFTER `custom_signature`');
        }

        // 2. Pastikan owner_id tersedia di email_templates
        $tplCols = [];
        foreach ($pdo->query('SHOW COLUMNS FROM email_templates')->fetchAll() as $tc) {
            $tplCols[strtolower((string) $tc['Field'])] = true;
        }
        if (!isset($tplCols['owner_id'])) {
            $pdo->exec('ALTER TABLE `email_templates` ADD COLUMN `owner_id` INT UNSIGNED DEFAULT NULL AFTER `id`');
        }

        // 3. Buat tabel baru jika belum ada
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `mailboxes` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `label` VARCHAR(120) NOT NULL,
                `email` VARCHAR(190) NOT NULL,
                `display_name` VARCHAR(120) NOT NULL,
                `provider_mode` ENUM('app_password', 'oauth') NOT NULL DEFAULT 'app_password',
                `smtp_host` VARCHAR(160) NOT NULL DEFAULT 'smtp.gmail.com',
                `smtp_port` INT UNSIGNED NOT NULL DEFAULT 465,
                `smtp_encryption` ENUM('ssl', 'tls', 'none') NOT NULL DEFAULT 'ssl',
                `smtp_username` VARCHAR(190) NOT NULL,
                `smtp_password_enc` TEXT DEFAULT NULL,
                `imap_host` VARCHAR(160) NOT NULL DEFAULT 'imap.gmail.com',
                `imap_port` INT UNSIGNED NOT NULL DEFAULT 993,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `allow_user_select` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT UNSIGNED DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_mailboxes_email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `packages` (
                `slug` VARCHAR(40) NOT NULL,
                `name` VARCHAR(80) NOT NULL,
                `price_monthly` INT UNSIGNED NOT NULL DEFAULT 0,
                `daily_send_limit` INT UNSIGNED NOT NULL DEFAULT 10,
                `allow_api` TINYINT(1) NOT NULL DEFAULT 1,
                `has_watermark` TINYINT(1) NOT NULL DEFAULT 0,
                `watermark_text` VARCHAR(255) NOT NULL DEFAULT '',
                `description` VARCHAR(255) DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `sort_order` INT NOT NULL DEFAULT 0,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `payment_orders` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_code` VARCHAR(40) NOT NULL,
                `user_id` INT UNSIGNED NOT NULL,
                `package_slug` VARCHAR(40) NOT NULL,
                `duration_months` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                `amount_total` INT UNSIGNED NOT NULL DEFAULT 0,
                `proof_image_path` VARCHAR(255) NOT NULL,
                `payer_note` VARCHAR(300) DEFAULT NULL,
                `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                `admin_note` VARCHAR(300) DEFAULT NULL,
                `reviewed_by` INT UNSIGNED DEFAULT NULL,
                `reviewed_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_payment_order_code` (`order_code`),
                KEY `idx_payment_user` (`user_id`, `created_at`),
                KEY `idx_payment_status` (`status`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `user_api_keys` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `key_prefix` VARCHAR(24) NOT NULL,
                `key_hash` CHAR(64) NOT NULL,
                `last_used_at` DATETIME DEFAULT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_user_api_keys_hash` (`key_hash`),
                KEY `idx_user_api_keys_user` (`user_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `sent_email_logs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED DEFAULT NULL,
                `mailbox_id` INT UNSIGNED DEFAULT NULL,
                `from_email` VARCHAR(190) NOT NULL,
                `to_emails` VARCHAR(500) NOT NULL,
                `cc_emails` VARCHAR(500) DEFAULT NULL,
                `subject` VARCHAR(255) NOT NULL,
                `body_preview` VARCHAR(320) DEFAULT NULL,
                `body_html` MEDIUMTEXT DEFAULT NULL,
                `transport` VARCHAR(40) NOT NULL DEFAULT 'phpmailer_gmail_smtp',
                `message_id` VARCHAR(255) DEFAULT NULL,
                `imap_sent_synced` TINYINT(1) NOT NULL DEFAULT 0,
                `sent_via` ENUM('web', 'api') NOT NULL DEFAULT 'web',
                `status` ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
                `error_message` VARCHAR(400) DEFAULT NULL,
                `sent_date` DATE NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_sent_quota` (`user_id`, `sent_date`, `status`),
                KEY `idx_sent_mailbox` (`mailbox_id`, `created_at`),
                KEY `idx_sent_created` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4. Seed paket default jika tabel packages masih kosong
        $pkgCount = (int) $pdo->query('SELECT COUNT(*) FROM packages')->fetchColumn();
        if ($pkgCount === 0) {
            $pdo->exec("
                INSERT INTO `packages` (`slug`, `name`, `price_monthly`, `daily_send_limit`, `allow_api`, `has_watermark`, `watermark_text`, `description`, `is_active`, `sort_order`) VALUES
                ('free', 'Free', 0, 10, 1, 1, 'Dikirim Menggunakan Aplikasi Skyzz Mail', 'Gratis selamanya · Maks. 10 kirim email/hari · Terima email tanpa batas · Support API · Template kustom bebas (dengan watermark).', 1, 1),
                ('lite', 'Lite', 5000, 50, 1, 0, '', 'Rp 5.000/bulan · Maks. 50 kirim email/hari · Terima email tanpa batas · Support API pengiriman · Bebas watermark.', 1, 2),
                ('pro', 'Pro', 10000, 100, 1, 0, '', 'Rp 10.000/bulan · Maks. 100 kirim email/hari · Terima email tanpa batas · Support API pengiriman · Bebas watermark.', 1, 3),
                ('business', 'Business', 20000, 200, 1, 0, '', 'Rp 20.000/bulan · Maks. 200 kirim email/hari · Terima email tanpa batas · Support API pengiriman · Bebas watermark.', 1, 4)
            ");
        }

        // 5. Migrasi otomatis akun email default dari app_settings ke tabel mailboxes jika mailboxes masih kosong
        $mbCount = (int) $pdo->query('SELECT COUNT(*) FROM mailboxes')->fetchColumn();
        if ($mbCount === 0) {
            $stMap = [];
            foreach ($pdo->query('SELECT setting_key, setting_value FROM app_settings')->fetchAll() as $r) {
                $stMap[$r['setting_key']] = (string) ($r['setting_value'] ?? '');
            }
            $seedEmail = strtolower(trim($stMap['gmail_app_password_email'] ?? $stMap['support_email'] ?? DEFAULT_OFFICIAL_GMAIL));
            if (!filter_var($seedEmail, FILTER_VALIDATE_EMAIL)) {
                $seedEmail = DEFAULT_OFFICIAL_GMAIL;
            }
            $seedMode  = ($stMap['mail_connection_mode'] ?? 'app_password') === 'oauth' ? 'oauth' : 'app_password';
            $seedName  = trim($stMap['official_name'] ?? '') ?: DEFAULT_OFFICIAL_NAME;
            $seedEncPw = (string) ($stMap['gmail_app_password_secret'] ?? '');

            $ins = $pdo->prepare(
                'INSERT INTO mailboxes
                    (label, email, display_name, provider_mode, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_enc, imap_host, imap_port, is_active, is_default, allow_user_select)
                 VALUES
                    (:lbl, :em, :dn, :pm, :sh, :sp, :se, :su, :pw, :ih, :ip, 1, 1, 1)'
            );
            $ins->execute([
                ':lbl' => 'Email Utama (' . $seedEmail . ')',
                ':em'  => $seedEmail,
                ':dn'  => $seedName,
                ':pm'  => $seedMode,
                ':sh'  => ($stMap['gmail_smtp_host'] ?? '') ?: 'smtp.gmail.com',
                ':sp'  => (int) (($stMap['gmail_smtp_port'] ?? '') ?: 465),
                ':se'  => in_array(($stMap['gmail_smtp_encryption'] ?? 'ssl'), ['ssl', 'tls', 'none'], true) ? $stMap['gmail_smtp_encryption'] : 'ssl',
                ':su'  => $seedEmail,
                ':pw'  => $seedEncPw !== '' ? $seedEncPw : null,
                ':ih'  => ($stMap['gmail_imap_host'] ?? '') ?: 'imap.gmail.com',
                ':ip'  => (int) (($stMap['gmail_imap_port'] ?? '') ?: 993),
            ]);
        }

        // 6. Perbarui nama aplikasi lama jika masih default lama
        $pdo->exec("UPDATE app_settings SET setting_value = 'Skyzz Mail' WHERE setting_key = 'app_name' AND setting_value IN ('Support Septian', 'Mail Septian Support')");

        cache_set($schemaFlag, 'ok', 86400);
    } catch (Throwable $e) {
        if (function_exists('app_log')) {
            app_log('warning', 'ensure_skyzz_schema notice: ' . $e->getMessage());
        }
    }
}

/**
 * Ambil seluruh pengaturan dari tabel app_settings (di-cache per request & file cache 5 menit).
 */
function get_all_settings(bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }

    ensure_skyzz_schema();

    $defaults = [
        'app_name'                  => DEFAULT_APP_NAME,
        'official_name'             => DEFAULT_OFFICIAL_NAME,
        'support_email'             => DEFAULT_OFFICIAL_GMAIL,
        'website_url'               => DEFAULT_WEBSITE_URL,
        'app_url'                   => DEFAULT_APP_URL,
        'logo_url'                  => DEFAULT_LOGO_URL,
        'official_address'          => DEFAULT_ADDRESS,
        'developer_name'            => 'Tim Pengembang Skyzz Mail',
        'contact_email'             => DEFAULT_OFFICIAL_GMAIL,
        'contact_whatsapp'          => '',
        'mail_connection_mode'      => 'app_password',
        'imap_append_sent'          => '1',
        'allow_public_registration' => '1',
        'qris_merchant_name'        => 'Skyzz Mail Official (QRIS All Payment)',
        'qris_image_path'           => '',
        'qris_image_url'            => '',
        'qris_instructions'         => 'Scan QRIS All Payment di atas menggunakan Dana, OVO, GoPay, ShopeePay, LinkAja, atau Mobile Banking sesuai nominal total, lalu unggah tangkapan layar/foto bukti pembayaran untuk konfirmasi manual oleh Admin.',
        'gmail_oauth_client_id'     => '',
        'gmail_oauth_client_secret' => '',
        'gmail_app_password_email'  => DEFAULT_OFFICIAL_GMAIL,
        'gmail_app_password_secret' => '',
        'gmail_smtp_host'           => 'smtp.gmail.com',
        'gmail_smtp_port'           => '465',
        'gmail_smtp_encryption'     => 'ssl',
        'gmail_imap_host'           => 'imap.gmail.com',
        'gmail_imap_port'           => '993',
        'email_signature'           => '<p style="margin:0 0 6px 0;font-weight:600;color:#111827;">Hormat kami,</p><p style="margin:0 0 2px 0;font-weight:600;color:#1a73e8;">{nama_pengirim}</p><p style="margin:0;font-size:13px;color:#5f6368;">{nama_perusahaan}</p>',
        'footer_note'               => 'Mohon tidak membagikan informasi sensitif seperti kata sandi atau kode OTP melalui email.',
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
 * Catat log aktivitas ke tabel activity_logs (tanpa data sensitif).
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
