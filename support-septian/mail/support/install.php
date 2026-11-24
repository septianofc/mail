<?php
/**
 * Skyzz Mail — Guided Web Installer
 * Path: /mail/support/install.php
 *
 * Fitur Installer:
 * 1. Menguji koneksi MySQL PDO & menjalankan seluruh skema Skyzz Mail (multi-user, multi-mailbox, paket, QRIS, API key, template)
 * 2. Membuat akun Superadmin pertama (password di-hash dengan password_hash)
 * 3. Mengisi Google OAuth 2.0 Client ID/Secret (opsional) dan/atau Google App Password (opsional)
 * 4. Mengatur identitas aplikasi Skyzz Mail dan mendaftarkan akun email pertama
 * 5. Menghasilkan kunci enkripsi AES-256-GCM 256-bit & menulis storage/app-config.php (chmod 0600)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crypto.php';

if (is_app_installed()) {
    set_flash('info', 'Skyzz Mail sudah terinstal. Silakan masuk.');
    redirect_to('login.php');
}

$errors   = [];
$success  = false;
$formData = [
    'db_host'            => 'localhost',
    'db_port'            => '3306',
    'db_name'            => '',
    'db_user'            => '',
    'db_pass'            => '',
    'admin_name'         => 'Superadmin Skyzz Mail',
    'admin_username'     => 'admin',
    'admin_email'        => DEFAULT_OFFICIAL_GMAIL,
    'app_name'           => DEFAULT_APP_NAME,
    'official_name'      => DEFAULT_OFFICIAL_NAME,
    'support_email'      => DEFAULT_OFFICIAL_GMAIL,
    'website_url'        => DEFAULT_WEBSITE_URL,
    'logo_url'           => DEFAULT_LOGO_URL,
    'official_address'   => DEFAULT_ADDRESS,
    'mail_mode'          => 'app_password',
    'oauth_client_id'    => '',
    'oauth_client_secret'=> '',
    'app_password'       => '',
];

// Cek prasyarat PHP hosting
$checks = [
    'PHP >= 8.0 (' . PHP_VERSION . ')'     => version_compare(PHP_VERSION, '8.0.0', '>='),
    'Ekstensi PDO MySQL'                   => extension_loaded('pdo_mysql'),
    'Ekstensi OpenSSL (AES-256-GCM)'       => extension_loaded('openssl'),
    'Ekstensi DOM & Fileinfo'              => extension_loaded('dom') && extension_loaded('fileinfo'),
    'Folder storage/ dapat ditulis'        => is_dir(STORAGE_PATH) && is_writable(STORAGE_PATH),
    'Google API PHP Client (vendor/)'      => class_exists(\Google\Client::class),
    'PHPMailer SMTP (Composer / Manual)'   => class_exists(\PHPMailer\PHPMailer\PHPMailer::class),
    'Ekstensi PHP IMAP (Inbox & Sent)'     => extension_loaded('imap'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errors[] = 'Token keamanan CSRF tidak valid. Muat ulang halaman lalu coba kembali.';
    } else {
        foreach ($formData as $k => $v) {
            if (isset($_POST[$k])) {
                $formData[$k] = trim((string) $_POST[$k]);
            }
        }
        $adminPass        = (string) ($_POST['admin_password'] ?? '');
        $adminPassConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

        if ($formData['db_name'] === '' || $formData['db_user'] === '') {
            $errors[] = 'Nama database dan username MySQL wajib diisi.';
        }
        if ($formData['admin_name'] === '' || $formData['admin_username'] === '' || !filter_var($formData['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Nama Superadmin, username, dan email yang valid wajib diisi.';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = 'Kata sandi Superadmin minimal 8 karakter.';
        } elseif ($adminPass !== $adminPassConfirm) {
            $errors[] = 'Konfirmasi kata sandi Superadmin tidak cocok.';
        }
        if (!filter_var($formData['support_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Alamat email utama tidak valid.';
        }

        if (empty($errors)) {
            try {
                // 1. Uji koneksi PDO MySQL
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    $formData['db_host'],
                    $formData['db_port'] ?: '3306',
                    $formData['db_name']
                );
                $pdo = new PDO($dsn, $formData['db_user'], $formData['db_pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);

                // 2. Jalankan SQL schema dari database.sql
                $sqlPaths = [
                    APP_ROOT . '/database.sql',
                    dirname(APP_ROOT, 2) . '/database.sql',
                ];
                $sqlFile = null;
                foreach ($sqlPaths as $candidate) {
                    if (is_file($candidate) && is_readable($candidate)) {
                        $sqlFile = $candidate;
                        break;
                    }
                }
                if (!$sqlFile) {
                    throw new RuntimeException('File database.sql tidak ditemukan. Pastikan database.sql diunggah ke folder aplikasi atau impor manual via phpMyAdmin.');
                }

                $sqlRaw = (string) file_get_contents($sqlFile);
                $sqlClean = preg_replace('/^\s*--.*$/m', '', $sqlRaw) ?? $sqlRaw;
                $statements = array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sqlClean))));
                foreach ($statements as $stmtSql) {
                    if ($stmtSql !== '') {
                        $pdo->exec($stmtSql);
                    }
                }

                // 3. Hasilkan kunci enkripsi AES-256-GCM 64-hex (32 byte)
                $encryptionKey = bin2hex(random_bytes(32));
                $GLOBALS['APP_CONFIG']['encryption_key'] = $encryptionKey;

                // 4. Buat akun Superadmin pertama dengan password_hash()
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $insAdmin = $pdo->prepare(
                    'INSERT INTO admins (name, email, username, password_hash, role, job_title, plan_slug, is_active)
                     VALUES (:name, :email, :user, :hash, "superadmin", "Superadmin Skyzz Mail", "business", 1)
                     ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), role = "superadmin", is_active = 1'
                );
                $insAdmin->execute([
                    ':name'  => $formData['admin_name'],
                    ':email' => strtolower($formData['admin_email']),
                    ':user'  => $formData['admin_username'],
                    ':hash'  => $hash,
                ]);
                $adminId = (int) $pdo->lastInsertId() ?: 1;

                // 5. Simpan seluruh pengaturan identitas & kredensial terenkripsi
                $upsertSetting = $pdo->prepare(
                    'INSERT INTO app_settings (setting_key, setting_value, is_encrypted, updated_by)
                     VALUES (:k, :v, :enc, :uid)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), is_encrypted = VALUES(is_encrypted), updated_by = VALUES(updated_by)'
                );

                $appName       = $formData['app_name'] ?: DEFAULT_APP_NAME;
                $officialName  = $formData['official_name'] ?: DEFAULT_OFFICIAL_NAME;
                $primaryEmail  = strtolower($formData['support_email']);
                $mailMode      = in_array($formData['mail_mode'], ['oauth', 'app_password'], true) ? $formData['mail_mode'] : 'app_password';

                $plainSettings = [
                    'app_name'                  => $appName,
                    'official_name'             => $officialName,
                    'support_email'             => $primaryEmail,
                    'contact_email'             => $primaryEmail,
                    'developer_name'            => $appName,
                    'website_url'               => safe_http_url($formData['website_url'], DEFAULT_WEBSITE_URL),
                    'app_url'                   => DEFAULT_APP_URL,
                    'logo_url'                  => safe_http_url($formData['logo_url'], DEFAULT_LOGO_URL),
                    'official_address'          => $formData['official_address'],
                    'mail_connection_mode'      => $mailMode,
                    'gmail_oauth_client_id'     => $formData['oauth_client_id'],
                    'gmail_app_password_email'  => $primaryEmail,
                    'gmail_smtp_host'           => 'smtp.gmail.com',
                    'gmail_smtp_port'           => '465',
                    'gmail_smtp_encryption'     => 'ssl',
                    'imap_append_sent'          => '1',
                    'allow_public_registration' => '1',
                    'free_watermark_text'       => FREE_WATERMARK_DEFAULT,
                ];

                foreach ($plainSettings as $sk => $sv) {
                    $upsertSetting->execute([':k' => $sk, ':v' => $sv, ':enc' => 0, ':uid' => $adminId]);
                }

                if ($formData['oauth_client_secret'] !== '') {
                    $upsertSetting->execute([
                        ':k'   => 'gmail_oauth_client_secret',
                        ':v'   => crypto_encrypt($formData['oauth_client_secret']),
                        ':enc' => 1,
                        ':uid' => $adminId,
                    ]);
                }

                $appPassEnc = null;
                if ($formData['app_password'] !== '') {
                    $cleanAppPass = str_replace(' ', '', $formData['app_password']);
                    $appPassEnc   = crypto_encrypt($cleanAppPass);
                    $upsertSetting->execute([
                        ':k'   => 'gmail_app_password_secret',
                        ':v'   => $appPassEnc,
                        ':enc' => 1,
                        ':uid' => $adminId,
                    ]);
                }

                // 6. Daftarkan akun email pertama pada tabel mailboxes
                $pdo->prepare(
                    'INSERT INTO mailboxes (label, email, display_name, provider_mode, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_enc, imap_host, imap_port, is_active, is_default, allow_user_select, created_by)
                     VALUES (:lbl, :em, :dn, :mode, "smtp.gmail.com", 465, "ssl", :su, :ap, "imap.gmail.com", 993, 1, 1, 1, :uid)
                     ON DUPLICATE KEY UPDATE label = VALUES(label), display_name = VALUES(display_name), provider_mode = VALUES(provider_mode), smtp_username = VALUES(smtp_username), smtp_password_enc = COALESCE(VALUES(smtp_password_enc), smtp_password_enc), is_default = 1, is_active = 1'
                )->execute([
                    ':lbl'  => $officialName,
                    ':em'   => $primaryEmail,
                    ':dn'   => $officialName,
                    ':mode' => $mailMode,
                    ':su'   => $primaryEmail,
                    ':ap'   => $appPassEnc,
                    ':uid'  => $adminId,
                ]);

                // 7. Tulis file konfigurasi privat storage/app-config.php (dilindungi .htaccess + chmod 0600)
                $configExport = [
                    'installed' => true,
                    'db' => [
                        'host'    => $formData['db_host'],
                        'port'    => $formData['db_port'] ?: '3306',
                        'name'    => $formData['db_name'],
                        'user'    => $formData['db_user'],
                        'pass'    => $formData['db_pass'],
                        'charset' => 'utf8mb4',
                    ],
                    'encryption_key' => $encryptionKey,
                    'base_path'      => BASE_PATH,
                    'app_url'        => DEFAULT_APP_URL,
                    'official_gmail' => $primaryEmail,
                    'debug'          => false,
                ];

                $phpConfigBody = "<?php\n/**\n * Skyzz Mail — Konfigurasi Produksi (Dihasilkan Otomatis oleh Installer)\n * Dilindungi oleh .htaccess & izin 0600.\n */\ndeclare(strict_types=1);\n\nreturn " . var_export($configExport, true) . ";\n";

                foreach ([STORAGE_PATH, LOGS_PATH, CACHE_PATH, UPLOADS_PATH] as $dir) {
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0750, true);
                    }
                }
                $written = @file_put_contents(STORAGE_PATH . '/app-config.php', $phpConfigBody, LOCK_EX);
                if ($written === false) {
                    throw new RuntimeException('Gagal menulis file storage/app-config.php. Periksa izin tulis folder storage/.');
                }
                @chmod(STORAGE_PATH . '/app-config.php', 0600);

                $success = true;
            } catch (Throwable $e) {
                $errors[] = 'Instalasi gagal: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalasi — Skyzz Mail</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="auth-body">
<div class="install-wrapper">
    <div class="install-header">
        <img src="<?= e(DEFAULT_LOGO_URL) ?>" onerror="this.onerror=null;this.src='<?= e(base_url('assets/images/logo.png')) ?>';" alt="Skyzz Mail" width="44" height="44" class="brand-logo">
        <div>
            <h1>Instalasi Skyzz Mail</h1>
            <p class="muted">Konfigurasi Database MySQL, Akun Superadmin Pertama, Paket Langganan, &amp; Koneksi Email Utama</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card">
            <div class="alert alert-success">
                <span><strong>Instalasi Skyzz Mail Berhasil!</strong> Database, paket Free/Lite/Pro/Business, akun Superadmin, akun email awal, dan enkripsi AES-256-GCM telah aktif.</span>
            </div>
            <p class="muted" style="margin-bottom:16px;">Setelah masuk sebagai Superadmin, Anda dapat menambahkan banyak akun email sewaan, mengunggah gambar QRIS All Payment, dan mengatur paket sesuai kebutuhan.</p>
            <a href="<?= e(base_url('login.php')) ?>" class="btn btn-primary">Masuk ke Dashboard Skyzz Mail</a>
        </div>
    <?php else: ?>
        <div class="card" style="margin-bottom:20px;">
            <h2 class="card-title">1. Pemeriksaan Lingkungan Server PHP</h2>
            <div class="check-grid">
                <?php foreach ($checks as $label => $ok): ?>
                    <div class="check-item <?= $ok ? 'check-ok' : 'check-warn' ?>">
                        <span class="check-status"><?= $ok ? 'Siap' : 'Perlu Perhatian' ?></span>
                        <span><?= e($label) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" style="margin-bottom:20px;">
                <div>
                    <strong>Periksa kembali data instalasi:</strong>
                    <ul style="margin:6px 0 0 18px;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <form method="post" class="card">
            <?= csrf_field() ?>

            <h2 class="card-title">2. Koneksi Database MySQL (PDO)</h2>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="db_host">Host Database</label>
                    <input type="text" id="db_host" name="db_host" value="<?= e($formData['db_host']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_port">Port MySQL</label>
                    <input type="text" id="db_port" name="db_port" value="<?= e($formData['db_port']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="db_name">Nama Database</label>
                    <input type="text" id="db_name" name="db_name" value="<?= e($formData['db_name']) ?>" placeholder="contoh: skyzz_mail_db" required>
                </div>
                <div class="form-group">
                    <label for="db_user">Username Database</label>
                    <input type="text" id="db_user" name="db_user" value="<?= e($formData['db_user']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label for="db_pass">Password Database</label>
                <input type="password" id="db_pass" name="db_pass" autocomplete="new-password">
            </div>

            <hr class="section-divider">

            <h2 class="card-title">3. Akun Superadmin Pertama</h2>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="admin_name">Nama Lengkap Superadmin</label>
                    <input type="text" id="admin_name" name="admin_name" value="<?= e($formData['admin_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_username">Username Login</label>
                    <input type="text" id="admin_username" name="admin_username" value="<?= e($formData['admin_username']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_email">Email Superadmin</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?= e($formData['admin_email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_password">Kata Sandi Superadmin (min. 8 karakter)</label>
                    <input type="password" id="admin_password" name="admin_password" minlength="8" required autocomplete="new-password">
                </div>
            </div>
            <div class="form-group">
                <label for="admin_password_confirm">Ulangi Kata Sandi Superadmin</label>
                <input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="8" required autocomplete="new-password">
            </div>

            <hr class="section-divider">

            <h2 class="card-title">4. Identitas Aplikasi &amp; Akun Email Utama</h2>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="app_name">Nama Aplikasi</label>
                    <input type="text" id="app_name" name="app_name" value="<?= e($formData['app_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="official_name">Nama Pengirim Default (From Name)</label>
                    <input type="text" id="official_name" name="official_name" value="<?= e($formData['official_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="support_email">Email Utama Pertama (Gmail / SMTP)</label>
                    <input type="email" id="support_email" name="support_email" value="<?= e($formData['support_email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="website_url">Website Aplikasi</label>
                    <input type="url" id="website_url" name="website_url" value="<?= e($formData['website_url']) ?>" required>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="logo_url">URL Logo Aplikasi</label>
                    <input type="url" id="logo_url" name="logo_url" value="<?= e($formData['logo_url']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="official_address">Alamat Lengkap</label>
                    <input type="text" id="official_address" name="official_address" value="<?= e($formData['official_address']) ?>" required>
                </div>
            </div>

            <hr class="section-divider">

            <h2 class="card-title">5. Koneksi Google Gmail (Opsional Sekarang)</h2>
            <p class="muted" style="margin-bottom:14px;">Anda bisa menambah lebih banyak akun email nanti di menu <strong>Kelola Akun Email</strong>.</p>

            <div class="form-group">
                <label for="mail_mode">Mode Koneksi Utama</label>
                <select id="mail_mode" name="mail_mode">
                    <option value="app_password" <?= $formData['mail_mode'] === 'app_password' ? 'selected' : '' ?>>Google App Password 16 Digit (SMTP SSL + IMAP) — Cepat &amp; Mudah</option>
                    <option value="oauth" <?= $formData['mail_mode'] === 'oauth' ? 'selected' : '' ?>>Google Gmail API (OAuth 2.0 Web Application)</option>
                </select>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="oauth_client_id">Google OAuth Client ID (Opsional)</label>
                    <input type="text" id="oauth_client_id" name="oauth_client_id" value="<?= e($formData['oauth_client_id']) ?>" placeholder="xxxx.apps.googleusercontent.com">
                </div>
                <div class="form-group">
                    <label for="oauth_client_secret">Google OAuth Client Secret (Opsional)</label>
                    <input type="password" id="oauth_client_secret" name="oauth_client_secret" placeholder="GOCSPX-..." autocomplete="new-password">
                </div>
            </div>

            <div class="form-group">
                <label for="app_password">Google App Password 16 Karakter (Opsional — Terenkripsi AES-256-GCM)</label>
                <input type="password" id="app_password" name="app_password" placeholder="xxxx xxxx xxxx xxxx" autocomplete="new-password">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Selesaikan Instalasi Skyzz Mail</button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
