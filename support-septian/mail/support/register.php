<?php
/**
 * Skyzz Mail — Halaman Pendaftaran Pengguna Baru (Free 10 Email/Hari Selamanya)
 * Path: /mail/support/register.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!is_app_installed()) {
    redirect_to('install.php');
}

if (current_admin() !== null) {
    redirect_to('dashboard.php');
}

ensure_skyzz_schema();
$settings  = get_all_settings();
$appName   = $settings['app_name'] ?: DEFAULT_APP_NAME;
$allowReg  = get_setting('allow_public_registration', '1') === '1';
$mailboxes = get_selectable_mailboxes(false);

$error    = '';
$name     = '';
$email    = '';
$username = '';
$mbId     = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $name      = trim((string) ($_POST['name'] ?? ''));
    $email     = trim((string) ($_POST['email'] ?? ''));
    $username  = trim((string) ($_POST['username'] ?? ''));
    $password  = (string) ($_POST['password'] ?? '');
    $password2 = (string) ($_POST['password_confirm'] ?? '');
    $mbId      = (int) ($_POST['mailbox_id'] ?? 0);
    $agree     = !empty($_POST['agree_terms']);

    if (!$agree) {
        $error = 'Anda harus menyetujui Terms & Conditions, Privacy Policy, dan Acceptable Use Policy.';
    } elseif ($password !== $password2) {
        $error = 'Konfirmasi kata sandi tidak cocok.';
    } else {
        $res = register_new_user($name, $email, $username, $password, $mbId > 0 ? $mbId : null);
        if ($res['ok']) {
            set_flash('success', $res['message']);
            redirect_to('dashboard.php');
        }
        $error = $res['message'];
    }
}

$logoUrl      = safe_http_url((string) ($settings['logo_url'] ?: DEFAULT_LOGO_URL), base_url('assets/images/logo.png'));
$localLogoUrl = base_url('assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun — <?= e($appName) ?></title>
    <link rel="icon" type="image/png" href="<?= e($localLogoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-container">
    <div class="auth-card auth-card-wide">
        <div class="auth-brand">
            <img src="<?= e($logoUrl) ?>" onerror="this.onerror=null;this.src='<?= e($localLogoUrl) ?>';" alt="<?= e($appName) ?>" width="48" height="48">
            <div>
                <h1>Daftar <?= e($appName) ?></h1>
                <p>Paket Free: 10 kirim email/hari (selamanya) + Terima pesan tanpa batas + Support API</p>
            </div>
        </div>

        <?php if (!$allowReg): ?>
            <div class="alert alert-warning">Pendaftaran akun baru saat ini sedang ditutup oleh Superadmin.</div>
            <a href="<?= e(base_url('login.php')) ?>" class="btn btn-secondary btn-block">Kembali ke Halaman Login</a>
        <?php else: ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
            <?php endif; ?>

            <form method="post" action="<?= e(base_url('register.php')) ?>" class="auth-form">
                <?= csrf_field() ?>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="name">Nama Lengkap / Nama Pengirim</label>
                        <input type="text" id="name" name="name" value="<?= e($name) ?>" placeholder="Contoh: Raka Pratama" required>
                    </div>
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" value="<?= e($username) ?>" placeholder="rakapratama" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="email">Email Pribadi (untuk Login &amp; Identitas Akun)</label>
                    <input type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="nama@email.com" required>
                </div>

                <?php if (!empty($mailboxes)): ?>
                    <div class="form-group">
                        <label for="mailbox_id">Pilih Alamat Email Layanan yang Ingin Dipakai</label>
                        <select id="mailbox_id" name="mailbox_id">
                            <?php foreach ($mailboxes as $mb): ?>
                                <option value="<?= (int) $mb['id'] ?>" <?= ($mbId === (int) $mb['id'] || ($mbId === 0 && (int) $mb['is_default'] === 1)) ? 'selected' : '' ?>>
                                    <?= e((string) $mb['email']) ?> — <?= e((string) $mb['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Disediakan dan dikonfigurasi oleh Superadmin. Anda juga bisa mengganti akun email kapan saja di dalam aplikasi.</small>
                    </div>
                <?php endif; ?>

                <div class="form-row-2">
                    <div class="form-group">
                        <label for="password">Kata Sandi (Min. 8 karakter)</label>
                        <input type="password" id="password" name="password" minlength="8" required>
                    </div>
                    <div class="form-group">
                        <label for="password_confirm">Ulangi Kata Sandi</label>
                        <input type="password" id="password_confirm" name="password_confirm" minlength="8" required>
                    </div>
                </div>

                <label class="inline-check" style="align-items:flex-start;margin-bottom:14px;">
                    <input type="checkbox" name="agree_terms" value="1" required style="margin-top:3px;">
                    <span style="font-size:13px;color:var(--text-muted);">
                        Saya menyetujui <a href="<?= e(base_url('legal.php?doc=terms')) ?>" target="_blank">Terms &amp; Conditions</a>,
                        <a href="<?= e(base_url('legal.php?doc=privacy')) ?>" target="_blank">Privacy Policy</a>, dan
                        <a href="<?= e(base_url('legal.php?doc=aup')) ?>" target="_blank">Acceptable Use Policy</a> (dilarang spam &amp; phishing).
                    </span>
                </label>

                <button type="submit" class="btn btn-primary btn-block">Buat Akun Gratis Sekarang</button>
            </form>

            <div class="auth-switch-link">
                Sudah punya akun? <a href="<?= e(base_url('login.php')) ?>">Masuk di sini</a>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <div class="auth-legal-links">
                <a href="<?= e(base_url('legal.php?doc=privacy')) ?>">Privacy Policy</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=data-safety')) ?>">Data Safety</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=account-deletion')) ?>">Account Deletion</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=contact')) ?>">Contact &amp; Support</a>
            </div>
        </div>
    </div>
</main>
</body>
</html>
