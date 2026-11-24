<?php
/**
 * Skyzz Mail — Role-Aware Shared Sidebar Navigation (Superadmin & User)
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$isSuper = is_superadmin($currentAdmin ?? null);
$pendingPaymentsCount = 0;
if ($isSuper) {
    try {
        $pendingPaymentsCount = (int) db()->query("SELECT COUNT(*) FROM payment_orders WHERE status = 'pending'")->fetchColumn();
    } catch (Throwable $e) {
    }
}

$navMail = [
    ['key' => 'dashboard', 'label' => 'Dashboard',   'href' => 'dashboard.php', 'icon' => 'grid'],
    ['key' => 'inbox',     'label' => 'Kotak Masuk', 'href' => 'inbox.php',     'icon' => 'inbox'],
    ['key' => 'starred',   'label' => 'Berbintang',  'href' => 'starred.php',   'icon' => 'star'],
    ['key' => 'sent',      'label' => 'Terkirim',    'href' => 'sent.php',      'icon' => 'send'],
    ['key' => 'drafts',    'label' => 'Draft',       'href' => 'drafts.php',    'icon' => 'file'],
    ['key' => 'spam',      'label' => 'Spam',        'href' => 'spam.php',      'icon' => 'alert'],
    ['key' => 'trash',     'label' => 'Sampah',      'href' => 'trash.php',     'icon' => 'trash'],
];

$navUserTools = [
    ['key' => 'templates',        'label' => 'Template Kustom',  'href' => 'templates.php',        'icon' => 'layout'],
    ['key' => 'template-preview', 'label' => 'Preview Template', 'href' => 'template-preview.php', 'icon' => 'eye'],
    ['key' => 'billing',          'label' => 'Paket & QRIS',     'href' => 'billing.php',          'icon' => 'credit'],
    ['key' => 'api-keys',         'label' => 'API Pengiriman',   'href' => 'api-keys.php',         'icon' => 'code'],
    ['key' => 'profile',          'label' => 'Profil & Pengirim','href' => 'profile.php',          'icon' => 'user'],
    ['key' => 'legal',            'label' => 'Legal & Support',  'href' => 'legal.php',            'icon' => 'book'],
    ['key' => 'delete-account',   'label' => 'Hapus Akun',       'href' => 'delete-account.php',   'icon' => 'user-x', 'danger' => true],
];

$navSuperadmin = [
    ['key' => 'mailboxes',        'label' => 'Kelola Email',         'href' => 'mailboxes.php',        'icon' => 'mail'],
    ['key' => 'gmail-connection', 'label' => 'Koneksi & Diagnostik', 'href' => 'gmail-connection.php', 'icon' => 'link'],
    ['key' => 'admin-packages',   'label' => 'Paket & QRIS Admin',   'href' => 'admin-packages.php',   'icon' => 'tag'],
    ['key' => 'admin-payments',   'label' => 'Konfirmasi Bayar',     'href' => 'admin-payments.php',   'icon' => 'check', 'badge' => $pendingPaymentsCount],
    ['key' => 'admin-users',      'label' => 'Kelola Pengguna',      'href' => 'admin-users.php',      'icon' => 'users'],
    ['key' => 'settings',         'label' => 'Pengaturan Aplikasi',  'href' => 'settings.php',         'icon' => 'settings'],
    ['key' => 'security',         'label' => 'Keamanan & Log Error', 'href' => 'security.php',         'icon' => 'shield'],
];

if (!function_exists('render_nav_icon')) {
    function render_nav_icon(string $name): string
    {
        return match ($name) {
            'grid'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/></svg>',
            'inbox'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>',
            'star'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
            'send'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
            'file'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
            'alert'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
            'trash'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>',
            'layout'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg>',
            'eye'      => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>',
            'link'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
            'settings' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.26.6.85 1 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
            'user'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
            'users'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
            'shield'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
            'credit'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
            'code'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
            'book'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
            'mail'     => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
            'tag'      => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
            'check'    => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
            'user-x'   => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="18" y1="8" x2="23" y2="13"/><line x1="23" y1="8" x2="18" y2="13"/></svg>',
            default    => '',
        };
    }
}
?>
<aside class="app-sidebar" id="appSidebar" aria-label="Navigasi Utama">
    <div class="sidebar-top">
        <a href="<?= e(base_url('compose.php')) ?>" class="btn-compose">
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            <span>Tulis Email</span>
        </a>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-group">
            <div class="nav-group-label">Kotak Surat</div>
            <?php foreach ($navMail as $item): ?>
                <a href="<?= e(base_url($item['href'])) ?>"
                   class="nav-item <?= $activePage === $item['key'] ? 'is-active' : '' ?>"
                   data-nav-key="<?= e($item['key']) ?>">
                    <span class="nav-icon"><?= render_nav_icon($item['icon']) ?></span>
                    <span class="nav-label"><?= e($item['label']) ?></span>
                    <?php if ($item['key'] === 'inbox'): ?>
                        <span class="nav-count" id="sidebarInboxUnread"></span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="nav-group">
            <div class="nav-group-label">Akun &amp; Layanan</div>
            <?php foreach ($navUserTools as $item): ?>
                <a href="<?= e(base_url($item['href'])) ?>"
                   class="nav-item <?= $activePage === $item['key'] ? 'is-active' : '' ?> <?= !empty($item['danger']) ? 'nav-item-danger' : '' ?>">
                    <span class="nav-icon"><?= render_nav_icon($item['icon']) ?></span>
                    <span class="nav-label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <?php if ($isSuper): ?>
            <div class="nav-group">
                <div class="nav-group-label">Panel Superadmin</div>
                <?php foreach ($navSuperadmin as $item): ?>
                    <a href="<?= e(base_url($item['href'])) ?>"
                       class="nav-item <?= $activePage === $item['key'] ? 'is-active' : '' ?>">
                        <span class="nav-icon"><?= render_nav_icon($item['icon']) ?></span>
                        <span class="nav-label"><?= e($item['label']) ?></span>
                        <?php if (!empty($item['badge']) && (int) $item['badge'] > 0): ?>
                            <span class="nav-count nav-count-alert"><?= (int) $item['badge'] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-identity">
            <div class="sidebar-id-name">
                <?= e((string) ($currentAdmin['name'] ?? 'Pengguna')) ?>
                <span class="role-mini-tag"><?= $isSuper ? 'Superadmin' : e((string) ($userQuota['plan']['name'] ?? 'Free')) ?></span>
            </div>
            <div class="sidebar-id-email"><?= e((string) ($activeMailbox['email'] ?? '')) ?></div>
        </div>
        <form method="post" action="<?= e(base_url('logout.php')) ?>" id="logoutForm">
            <?= csrf_field() ?>
            <button type="submit" class="btn-logout">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Keluar</span>
            </button>
        </form>
    </div>
</aside>
