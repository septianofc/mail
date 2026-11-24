<?php
/**
 * Skyzz Mail — Halaman Login (Superadmin & User + Pilih Akun Email Aktif)
 * Path: /mail/support/login.php
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
$mailboxes = get_selectable_mailboxes(false);
$allowReg  = get_setting('allow_public_registration', '1') === '1';

$error      = '';
$identifier = '';
$selectedMb = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    $password   = (string) ($_POST['password'] ?? '');
    $selectedMb = (int) ($_POST['mailbox_id'] ?? 0);

    $result = attempt_admin_login($identifier, $password, $selectedMb > 0 ? $selectedMb : null);
    if ($result['ok']) {
        set_flash('success', 'Selamat datang di ' . $appName . '.');
        redirect_to('dashboard.php');
    }
    $error = $result['message'];
}

$flashes      = pull_flashes();
$logoUrl      = safe_http_url((string) ($settings['logo_url'] ?: DEFAULT_LOGO_URL), base_url('assets/images/logo.png'));
$localLogoUrl = base_url('assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk — <?= e($appName) ?></title>
    <link rel="icon" type="image/png" href="<?= e($localLogoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="auth-body">
<main class="auth-container">
    <div class="auth-card">
        <div class="auth-brand">
            <img src="<?= e($logoUrl) ?>" onerror="this.onerror=null;this.src='<?= e($localLogoUrl) ?>';" alt="<?= e($appName) ?>" width="48" height="48">
            <div>
                <h1><?= e($appName) ?></h1>
                <p>Layanan Email Multi-User &amp; API Pengiriman</p>
            </div>
        </div>

        <?php foreach ($flashes as $fl): ?>
            <div class="alert alert-<?= e($fl['type']) ?>"><?= e($fl['message']) ?></div>
        <?php endforeach; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('login.php')) ?>" class="auth-form" autocomplete="on">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="identifier">Username atau Email Akun</label>
                <input type="text" id="identifier" name="identifier" value="<?= e($identifier) ?>" placeholder="username atau email Anda" required autofocus>
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi</label>
                <input type="password" id="password" name="password" placeholder="Masukkan kata sandi" required>
            </div>

            <?php if (!empty($mailboxes)): ?>
                <div class="form-group">
                    <label for="mailbox_id">Pilih Akun Email Layanan (Disediakan Admin)</label>
                    <select id="mailbox_id" name="mailbox_id">
                        <?php foreach ($mailboxes as $mb): ?>
                            <option value="<?= (int) $mb['id'] ?>" <?= ($selectedMb === (int) $mb['id'] || ($selectedMb === 0 && (int) $mb['is_default'] === 1)) ? 'selected' : '' ?>>
                                <?= e((string) $mb['email']) ?> — <?= e((string) $mb['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-hint">Anda bebas memilih alamat email layanan yang ingin digunakan (dapat diganti kapan saja setelah login).</small>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-primary btn-block">Masuk ke <?= e($appName) ?></button>
        </form>

        <?php if ($allowReg): ?>
            <div class="auth-switch-link">
                Belum punya akun? <a href="<?= e(base_url('register.php')) ?>">Daftar Gratis (10 Email/Hari Selamanya)</a>
            </div>
        <?php endif; ?>

        <div class="auth-footer">
            <div class="auth-legal-links">
                <a href="<?= e(base_url('legal.php?doc=privacy')) ?>">Privacy</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=terms')) ?>">Terms</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=aup')) ?>">AUP</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=data-safety')) ?>">Data Safety</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=account-deletion')) ?>">Hapus Akun</a> &middot;
                <a href="<?= e(base_url('legal.php?doc=contact')) ?>">Support</a>
            </div>
        </div>
    </div>
</main>
</body>
</html>
