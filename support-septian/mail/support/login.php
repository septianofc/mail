<?php
/**
 * Support Septian — Admin Login Page
 * Path: /mail/support/login.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!is_app_installed()) {
    redirect_to('install.php');
}

if (is_logged_in()) {
    redirect_to('dashboard.php');
}

$settings = get_all_settings();
$errorMsg = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) {
        $errorMsg = 'Sesi keamanan tidak valid. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $username = trim((string) ($_POST['identifier'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $result   = attempt_admin_login($username, $password);

        if ($result['ok']) {
            redirect_to('dashboard.php');
        }
        $errorMsg = $result['message'];
    }
}

$flashes      = pull_flashes();
$logoUrl      = safe_http_url($settings['logo_url'] ?: DEFAULT_LOGO_URL, base_url('assets/images/logo.png'));
$localLogoUrl = base_url('assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk Admin — <?= e($settings['app_name'] ?: DEFAULT_APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e($localLogoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="auth-body">
<div class="auth-container">
    <div class="auth-card">
        <div class="auth-brand">
            <img src="<?= e($logoUrl) ?>" onerror="this.onerror=null;this.src='<?= e($localLogoUrl) ?>';" alt="Support Septian" width="48" height="48" class="brand-logo">
            <h1><?= e($settings['app_name'] ?: DEFAULT_APP_NAME) ?></h1>
            <p class="muted"><?= e($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME) ?> &middot; <?= e($settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL) ?></p>
        </div>

        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e($f['type']) ?>" style="margin-bottom:16px;">
                <span><?= e($f['message']) ?></span>
            </div>
        <?php endforeach; ?>

        <?php if ($errorMsg !== ''): ?>
            <div class="alert alert-danger" style="margin-bottom:16px;">
                <span><?= e($errorMsg) ?></span>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e(base_url('login.php')) ?>" novalidate>
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="identifier">Username atau Email Admin</label>
                <input type="text" id="identifier" name="identifier" value="<?= e($username) ?>" placeholder="admin atau support.septianfr@gmail.com" required autofocus autocomplete="username">
            </div>

            <div class="form-group">
                <label for="password">Kata Sandi</label>
                <input type="password" id="password" name="password" placeholder="Masukkan kata sandi admin" required autocomplete="current-password">
            </div>

            <button type="submit" class="btn btn-primary btn-block btn-lg">Masuk ke Support Septian</button>
        </form>

        <div class="auth-footer">
            <div><?= e($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME) ?></div>
            <div><a href="<?= e(safe_http_url($settings['website_url'] ?: DEFAULT_WEBSITE_URL)) ?>" target="_blank" rel="noopener noreferrer"><?= e($settings['website_url'] ?: DEFAULT_WEBSITE_URL) ?></a></div>
        </div>
    </div>
</div>
</body>
</html>
