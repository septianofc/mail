<?php
/**
 * Support Septian — Guided Web Installer
 * Path: /mail/support/install.php
 *
 * Fitur Installer:
 * 1. Menguji koneksi MySQL PDO & menjalankan seluruh skema tabel + seed 8 template awal
 * 2. Membuat akun Admin pertama (password di-hash dengan password_hash)
 * 3. Mengisi Google OAuth 2.0 Client ID & Client Secret (opsional) dan/atau Google App Password (opsional)
 * 4. Mengatur identitas resmi Support Septian F. R.
 * 5. Menghasilkan kunci enkripsi AES-256-GCM 256-bit & menulis storage/app-config.php (chmod 0600)
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/crypto.php';

if (is_app_installed()) {
    set_flash('info', 'Support Septian sudah terinstal. Silakan masuk sebagai admin.');
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
    'admin_name'         => 'Septian F. R.',
    'admin_username'     => 'admin',
    'admin_email'        => DEFAULT_OFFICIAL_GMAIL,
    'official_name'      => DEFAULT_OFFICIAL_NAME,
    'support_email'      => DEFAULT_OFFICIAL_GMAIL,
    'website_url'        => DEFAULT_WEBSITE_URL,
    'logo_url'           => DEFAULT_LOGO_URL,
    'official_address'   => DEFAULT_ADDRESS,
    'mail_mode'          => 'oauth',
    'oauth_client_id'    => '',
    'oauth_client_secret'=> '',
    'app_password'       => '',
];

// Cek prasyarat PHP hosting
$checks = [
    'PHP >= 8.1 (' . PHP_VERSION . ')'     => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Ekstensi PDO MySQL'                   => extension_loaded('pdo_mysql'),
    'Ekstensi OpenSSL (AES-256-GCM)'       => extension_loaded('openssl'),
    'Ekstensi DOM & Fileinfo'              => extension_loaded('dom') && extension_loaded('fileinfo'),
    'Folder storage/ dapat ditulis'        => is_dir(STORAGE_PATH) && is_writable(STORAGE_PATH),
    'Google API PHP Client (vendor/)'      => is_file(APP_ROOT . '/vendor/autoload.php'),
    'Ekstensi PHP IMAP (opsional App Pass)'=> extension_loaded('imap'),
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
            $errors[] = 'Nama admin, username, dan email admin yang valid wajib diisi.';
        }
        if (strlen($adminPass) < 8) {
            $errors[] = 'Kata sandi admin minimal 8 karakter.';
        } elseif ($adminPass !== $adminPassConfirm) {
            $errors[] = 'Konfirmasi kata sandi admin tidak cocok.';
        }
        if (!filter_var($formData['support_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Alamat email Support Septian tidak valid.';
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
                    dirname(APP_ROOT, 2) . '/database.sql',
                    APP_ROOT . '/database.sql',
                ];
                $sqlFile = null;
                foreach ($sqlPaths as $candidate) {
                    if (is_file($candidate) && is_readable($candidate)) {
                        $sqlFile = $candidate;
                        break;
                    }
                }
                if (!$sqlFile) {
                    throw new RuntimeException('File database.sql tidak ditemukan. Pastikan database.sql diunggah atau impor manual via phpMyAdmin.');
                }

                $sqlRaw = (string) file_get_contents($sqlFile);
                // Hapus komentar baris -- dan jalankan statement
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

                // 4. Buat akun Admin pertama dengan password_hash()
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $insAdmin = $pdo->prepare(
                    'INSERT INTO admins (name, email, username, password_hash, role, job_title, is_active)
                     VALUES (:name, :email, :user, :hash, "superadmin", "Customer Support Administrator", 1)
                     ON DUPLICATE KEY UPDATE name = VALUES(name), password_hash = VALUES(password_hash), is_active = 1'
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

                $plainSettings = [
                    'app_name'                 => DEFAULT_APP_NAME,
                    'official_name'            => $formData['official_name'],
                    'support_email'            => strtolower($formData['support_email']),
                    'website_url'              => safe_http_url($formData['website_url'], DEFAULT_WEBSITE_URL),
                    'app_url'                  => DEFAULT_APP_URL,
                    'logo_url'                 => safe_http_url($formData['logo_url'], DEFAULT_LOGO_URL),
                    'official_address'         => $formData['official_address'],
                    'mail_connection_mode'     => in_array($formData['mail_mode'], ['oauth', 'app_password'], true) ? $formData['mail_mode'] : 'oauth',
                    'gmail_oauth_client_id'    => $formData['oauth_client_id'],
                    'gmail_app_password_email' => strtolower($formData['support_email']),
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

                if ($formData['app_password'] !== '') {
                    $cleanAppPass = str_replace(' ', '', $formData['app_password']);
                    $upsertSetting->execute([
                        ':k'   => 'gmail_app_password_secret',
                        ':v'   => crypto_encrypt($cleanAppPass),
                        ':enc' => 1,
                        ':uid' => $adminId,
                    ]);
                }

                // 6. Tulis file konfigurasi privat storage/app-config.php (dilindungi .htaccess + chmod 0600)
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
                    'official_gmail' => strtolower($formData['support_email']),
                    'debug'          => false,
                ];

                $phpConfigBody = "<?php\n/**\n * Support Septian — Konfigurasi Produksi (Dihasilkan Otomatis oleh Installer)\n * Dilindungi oleh .htaccess & izin 0600. Disarankan memindahkan ke luar public_html jika hosting mendukung.\n */\ndeclare(strict_types=1);\n\nreturn " . var_export($configExport, true) . ";\n";

                if (!is_dir(STORAGE_PATH)) {
                    @mkdir(STORAGE_PATH, 0750, true);
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
    <title>Instalasi — Support Septian</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="auth-body">
<div class="install-wrapper">
    <div class="install-header">
        <img src="<?= e(DEFAULT_LOGO_URL) ?>" onerror="this.onerror=null;this.src='<?= e(base_url('assets/images/logo.png')) ?>';" alt="Support Septian" width="44" height="44" class="brand-logo">
        <div>
            <h1>Instalasi Support Septian</h1>
            <p class="muted">Konfigurasi Database MySQL, Akun Admin Pertama, Identitas Resmi, &amp; Koneksi Gmail</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card">
            <div class="alert alert-success">
                <span><strong>Instalasi Berhasil!</strong> Database, 8 template profesional awal, akun admin, dan enkripsi AES-256-GCM telah dikonfigurasi.</span>
            </div>
            <p class="muted" style="margin-bottom:16px;">Demi keamanan produksi, disarankan memindahkan <code>storage/app-config.php</code> ke luar <code>public_html</code> (ke <code>support-septian-private/config.php</code>) atau memastikan izin file tetap <code>0600</code>.</p>
            <a href="<?= e(base_url('login.php')) ?>" class="btn btn-primary">Masuk ke Dashboard Admin</a>
        </div>
    <?php else: ?>
        <!-- System Requirements Check -->
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
                    <input type="text" id="db_name" name="db_name" value="<?= e($formData['db_name']) ?>" placeholder="contoh: septianf_support" required>
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

            <h2 class="card-title">3. Akun Administrator Pertama</h2>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="admin_name">Nama Lengkap Admin</label>
                    <input type="text" id="admin_name" name="admin_name" value="<?= e($formData['admin_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_username">Username Login</label>
                    <input type="text" id="admin_username" name="admin_username" value="<?= e($formData['admin_username']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_email">Email Admin</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?= e($formData['admin_email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="admin_password">Kata Sandi Admin (min. 8 karakter)</label>
                    <input type="password" id="admin_password" name="admin_password" minlength="8" required autocomplete="new-password">
                </div>
            </div>
            <div class="form-group">
                <label for="admin_password_confirm">Ulangi Kata Sandi Admin</label>
                <input type="password" id="admin_password_confirm" name="admin_password_confirm" minlength="8" required autocomplete="new-password">
            </div>

            <hr class="section-divider">

            <h2 class="card-title">4. Identitas Resmi Support Septian F. R.</h2>
            <div class="form-grid-2">
                <div class="form-group">
                    <label for="official_name">Nama Identitas Resmi</label>
                    <input type="text" id="official_name" name="official_name" value="<?= e($formData['official_name']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="support_email">Email Gmail Resmi</label>
                    <input type="email" id="support_email" name="support_email" value="<?= e($formData['support_email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="website_url">Website Resmi</label>
                    <input type="url" id="website_url" name="website_url" value="<?= e($formData['website_url']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="logo_url">URL Logo Resmi</label>
                    <input type="url" id="logo_url" name="logo_url" value="<?= e($formData['logo_url']) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label for="official_address">Alamat Lengkap</label>
                <textarea id="official_address" name="official_address" rows="2" required><?= e($formData['official_address']) ?></textarea>
            </div>

            <hr class="section-divider">

            <h2 class="card-title">5. Koneksi Google Gmail (OAuth 2.0 &amp; Google App Password)</h2>
            <p class="muted" style="margin-bottom:14px;">Bisa diisi sekarang atau nanti melalui halaman <strong>Koneksi Gmail / Pengaturan</strong>. Redirect URI OAuth wajib: <code><?= e(OAUTH_REDIRECT_URI) ?></code></p>

            <div class="form-group">
                <label for="mail_mode">Mode Koneksi Utama</label>
                <select id="mail_mode" name="mail_mode">
                    <option value="oauth" <?= $formData['mail_mode'] === 'oauth' ? 'selected' : '' ?>>Google Gmail API (OAuth 2.0 Web Application) — Direkomendasikan</option>
                    <option value="app_password" <?= $formData['mail_mode'] === 'app_password' ? 'selected' : '' ?>>Google App Password 16 Digit (SMTP + IMAP)</option>
                </select>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="oauth_client_id">Google OAuth Client ID (Opsional sekarang)</label>
                    <input type="text" id="oauth_client_id" name="oauth_client_id" value="<?= e($formData['oauth_client_id']) ?>" placeholder="xxxx.apps.googleusercontent.com">
                </div>
                <div class="form-group">
                    <label for="oauth_client_secret">Google OAuth Client Secret (Terenkripsi AES-256-GCM)</label>
                    <input type="password" id="oauth_client_secret" name="oauth_client_secret" placeholder="GOCSPX-..." autocomplete="new-password">
                </div>
            </div>

            <div class="form-group">
                <label for="app_password">Google App Password 16 Karakter (Opsional — Terenkripsi AES-256-GCM)</label>
                <input type="password" id="app_password" name="app_password" placeholder="xxxx xxxx xxxx xxxx" autocomplete="new-password">
                <small class="form-hint">Buat di Google Account &rarr; Security &rarr; 2-Step Verification &rarr; App Passwords untuk akun support.septianfr@gmail.com.</small>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary btn-lg">Selesaikan Instalasi Support Septian</button>
            </div>
        </form>
    <?php endif; ?>
</div>
</body>
</html>
