<?php
/**
 * Skyzz Mail — Shared Application Header Layout
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/gmail-client.php';

$settings      = get_all_settings();
$appName       = $settings['app_name'] ?: DEFAULT_APP_NAME;
$pageTitle     = $pageTitle ?? $appName;
$activePage    = $activePage ?? 'dashboard';
$currentAdmin  = $currentAdmin ?? current_admin();
$isSuper       = is_superadmin($currentAdmin);
$activeMailbox = get_effective_mailbox($currentAdmin);
$availMailboxes = get_selectable_mailboxes($isSuper);
$userQuota     = $currentAdmin ? get_user_quota_status($currentAdmin) : null;

try {
    $connStatus = get_mail_connection_status($activeMailbox);
} catch (Throwable $e) {
    app_log('error', 'Header get_mail_connection_status error: ' . $e->getMessage());
    $connStatus = [
        'mailbox_id'       => (int) ($activeMailbox['id'] ?? 0),
        'mode'             => 'app_password',
        'official_email'   => (string) ($activeMailbox['email'] ?? DEFAULT_OFFICIAL_GMAIL),
        'active_ready'     => false,
        'active_label'     => 'Status Koneksi Tidak Tersedia',
        'active_badge'     => 'warning',
        'notice'           => 'Gagal memeriksa status koneksi email.',
        'oauth_revoked'    => false,
        'oauth_last_error' => null,
    ];
}
$flashes      = pull_flashes();
$searchValue  = trim((string) ($_GET['q'] ?? ''));
$logoUrl      = safe_http_url((string) ($settings['logo_url'] ?: DEFAULT_LOGO_URL), base_url('assets/images/logo.png'));
$localLogoUrl = base_url('assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <meta name="base-path" content="<?= e(rtrim(base_url(), '/')) ?>">
    <meta name="active-mailbox-id" content="<?= (int) ($activeMailbox['id'] ?? 0) ?>">
    <title><?= e($pageTitle) ?> — <?= e($appName) ?></title>
    <link rel="icon" type="image/png" href="<?= e($localLogoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/email.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="app-body" data-page="<?= e($activePage) ?>" data-role="<?= e((string) ($currentAdmin['role'] ?? 'user')) ?>">
<div class="app-shell">
    <!-- Top Header -->
    <header class="app-header">
        <div class="header-left">
            <button type="button" class="icon-btn mobile-menu-btn" id="mobileMenuBtn" aria-label="Buka navigasi">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <a href="<?= e(base_url('dashboard.php')) ?>" class="brand-lockup">
                <img src="<?= e($logoUrl) ?>" onerror="this.onerror=null;this.src='<?= e($localLogoUrl) ?>';" alt="<?= e($appName) ?>" class="brand-logo" width="32" height="32">
                <span class="brand-name"><?= e($appName) ?></span>
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
            <?php if (count($availMailboxes) > 1): ?>
                <div class="mailbox-switcher-wrap" title="Pilih akun email aktif">
                    <select id="headerMailboxSwitcher" class="mailbox-switcher-select" aria-label="Pilih akun email aktif">
                        <?php foreach ($availMailboxes as $mbOpt): ?>
                            <option value="<?= (int) $mbOpt['id'] ?>" <?= (int) $mbOpt['id'] === (int) ($activeMailbox['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= e((string) $mbOpt['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <span class="mailbox-active-label" title="Akun email aktif"><?= e((string) ($activeMailbox['email'] ?? '')) ?></span>
            <?php endif; ?>

            <?php if ($isSuper): ?>
                <a href="<?= e(base_url('gmail-connection.php')) ?>" class="conn-indicator conn-<?= e($connStatus['active_badge']) ?>" title="Status Koneksi Email">
                    <span class="conn-text"><?= e($connStatus['active_ready'] ? 'Koneksi Aktif' : 'Perlu Koneksi') ?></span>
                </a>
            <?php elseif ($userQuota): ?>
                <a href="<?= e(base_url('billing.php')) ?>" class="plan-chip plan-<?= e((string) $userQuota['plan']['slug']) ?>" title="Paket & Kuota Kirim Hari Ini">
                    <span><?= e((string) $userQuota['plan']['name']) ?></span>
                    <span class="tabular-nums"><?= (int) $userQuota['used_today'] ?>/<?= (int) $userQuota['daily_limit'] ?></span>
                </a>
            <?php endif; ?>

            <a href="<?= e(base_url('profile.php')) ?>" class="admin-chip" title="Profil & Pengaturan Akun">
                <span class="admin-avatar"><?= e(strtoupper(safe_substr((string) ($currentAdmin['name'] ?? 'U'), 0, 1))) ?></span>
                <span class="admin-chip-name"><?= e((string) ($currentAdmin['name'] ?? 'Pengguna')) ?></span>
            </a>
        </div>
    </header>

    <div class="app-layout">
        <?php require __DIR__ . '/sidebar.php'; ?>
        <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

        <main class="app-main" id="appMain">
            <?php if ($isSuper && $connStatus['oauth_revoked'] && $connStatus['mode'] === 'oauth'): ?>
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
