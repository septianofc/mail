<?php
/**
 * Support Septian — Shared Application Header Layout
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$pageTitle    = $pageTitle ?? 'Support Septian';
$activePage   = $activePage ?? 'dashboard';
$currentAdmin = $currentAdmin ?? current_admin();
$settings     = get_all_settings();
$connStatus   = get_mail_connection_status();
$flashes      = pull_flashes();
$searchValue  = trim((string) ($_GET['q'] ?? ''));
$logoUrl      = safe_http_url($settings['logo_url'] ?: DEFAULT_LOGO_URL, base_url('assets/images/logo.png'));
$localLogoUrl = base_url('assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-path" content="<?= e(rtrim(base_url(), '/')) ?>">
    <title><?= e($pageTitle) ?> — <?= e($settings['app_name'] ?: DEFAULT_APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e($localLogoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/email.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="app-body" data-page="<?= e($activePage) ?>">
<div class="app-shell">
    <!-- Top Header -->
    <header class="app-header">
        <div class="header-left">
            <button type="button" class="icon-btn mobile-menu-btn" id="mobileMenuBtn" aria-label="Buka navigasi">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <a href="<?= e(base_url('dashboard.php')) ?>" class="brand-lockup">
                <img src="<?= e($logoUrl) ?>" onerror="this.onerror=null;this.src='<?= e($localLogoUrl) ?>';" alt="Support Septian" class="brand-logo" width="32" height="32">
                <span class="brand-name"><?= e($settings['app_name'] ?: DEFAULT_APP_NAME) ?></span>
            </a>
        </div>

        <form class="header-search" action="<?= e(base_url('inbox.php')) ?>" method="get" role="search">
            <svg class="search-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="search" name="q" value="<?= e($searchValue) ?>" placeholder="Cari pengirim, alamat email, subjek, atau isi pesan..." autocomplete="off">
            <?php if ($searchValue !== ''): ?>
                <a href="<?= e(base_url('inbox.php')) ?>" class="search-clear" title="Hapus pencarian">&times;</a>
            <?php endif; ?>
        </form>

        <div class="header-right">
            <a href="<?= e(base_url('gmail-connection.php')) ?>" class="conn-indicator conn-<?= e($connStatus['active_badge']) ?>" title="Status Koneksi Gmail">
                <span class="conn-text"><?= e($connStatus['active_ready'] ? ($connStatus['mode'] === 'app_password' ? 'App Password Aktif' : 'Gmail API Terhubung') : 'Perlu Koneksi') ?></span>
            </a>
            <a href="<?= e(base_url('profile.php')) ?>" class="admin-chip" title="Profil Admin">
                <span class="admin-avatar"><?= e(strtoupper(mb_substr((string) ($currentAdmin['name'] ?? 'A'), 0, 1))) ?></span>
                <span class="admin-chip-name"><?= e($currentAdmin['name'] ?? 'Admin') ?></span>
            </a>
        </div>
    </header>

    <div class="app-layout">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <main class="app-main" id="appMain">
            <?php if ($connStatus['oauth_revoked'] && $connStatus['mode'] === 'oauth'): ?>
                <div class="alert alert-danger" role="alert">
                    <div>
                        <strong>Token Gmail Dicabut atau Kedaluwarsa.</strong>
                        <span><?= e($connStatus['oauth_last_error'] ?: 'Akses Google Gmail perlu diotorisasi ulang agar email dapat disinkronkan.') ?></span>
                    </div>
                    <a href="<?= e(base_url('oauth/connect.php')) ?>" class="btn btn-sm btn-primary">Hubungkan Ulang Gmail</a>
                </div>
            <?php endif; ?>

            <?php foreach ($flashes as $flash): ?>
                <div class="alert alert-<?= e($flash['type']) ?>" role="alert">
                    <span><?= e($flash['message']) ?></span>
                    <button type="button" class="alert-close" onclick="this.parentElement.remove()" aria-label="Tutup">&times;</button>
                </div>
            <?php endforeach; ?>
