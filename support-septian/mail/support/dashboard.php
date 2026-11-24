<?php
/**
 * Skyzz Mail — Dashboard Utama (Superadmin & User)
 * Path: /mail/support/dashboard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin  = require_admin();
$isSuper       = is_superadmin($currentAdmin);
$pageTitle     = 'Dashboard';
$activePage    = 'dashboard';
$settings      = get_all_settings();
$activeMailbox = get_effective_mailbox($currentAdmin);
$connStatus    = get_mail_connection_status($activeMailbox);
$quota         = get_user_quota_status($currentAdmin);
$plan          = $quota['plan'];

// Ambil aktivitas terbaru dari database lokal (User hanya melihat aktivitas miliknya sendiri)
$recentLogs = [];
try {
    if ($isSuper) {
        $recentLogs = db()->query(
            'SELECT a.action, a.description, a.status, a.created_at, ad.name AS admin_name
             FROM activity_logs a
             LEFT JOIN admins ad ON ad.id = a.admin_id
             ORDER BY a.id DESC
             LIMIT 8'
        )->fetchAll();
    } else {
        $stmt = db()->prepare(
            'SELECT a.action, a.description, a.status, a.created_at, ad.name AS admin_name
             FROM activity_logs a
             LEFT JOIN admins ad ON ad.id = a.admin_id
             WHERE a.admin_id = :uid
             ORDER BY a.id DESC
             LIMIT 8'
        );
        $stmt->execute([':uid' => (int) $currentAdmin['id']]);
        $recentLogs = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    app_log('warning', 'dashboard.php gagal memuat activity_logs: ' . $e->getMessage());
}

// Hitung jumlah template yang dapat diakses user
$tplCount = count(get_templates('all', (int) $currentAdmin['id'], $isSuper));
$appName  = $settings['app_name'] ?: DEFAULT_APP_NAME;
$mbEmail  = (string) ($activeMailbox['email'] ?? $connStatus['official_email']);
$mbLabel  = (string) ($activeMailbox['label'] ?? ($activeMailbox['display_name'] ?? $appName));
$pctUsed  = (int) ($quota['percent_used'] ?? 0);

$extraScripts = ['inbox.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard <?= e($appName) ?></h1>
        <p class="page-subtitle">Akun Email Aktif: <strong><?= e($mbLabel) ?></strong> &lt;<?= e($mbEmail) ?>&gt;</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" id="btnRefreshDashboard">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            <span>Refresh</span>
        </button>
        <a href="<?= e(base_url('compose.php')) ?>" class="btn btn-primary">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Tulis Email</span>
        </a>
    </div>
</div>

<!-- Quota & Subscription Summary Strip -->
<section class="card quota-overview-card" style="margin-bottom:18px;">
    <div class="quota-overview-grid">
        <div>
            <div class="quota-header-row">
                <span class="badge-pill badge-primary">Paket <?= e((string) ($plan['name'] ?? 'Free')) ?></span>
                <?php if (!empty($plan['expires_at'])): ?>
                    <span class="muted" style="font-size:12px;">Aktif s/d <?= e(format_tanggal_id($plan['expires_at'])) ?></span>
                <?php else: ?>
                    <span class="muted" style="font-size:12px;">Aktif Selamanya</span>
                <?php endif; ?>
            </div>
            <div style="margin-top:8px;font-size:14px;">
                <?php if (!empty($quota['is_unlimited'])): ?>
                    Kuota Kirim Hari Ini: <strong class="tabular-nums"><?= (int) $quota['used_today'] ?> / Tanpa Batas</strong>
                    <span class="muted">(Mode Superadmin · Terima email tanpa batas)</span>
                <?php else: ?>
                    Kuota Kirim Hari Ini: <strong class="tabular-nums"><?= (int) $quota['used_today'] ?> / <?= (int) $quota['daily_limit'] ?></strong> email
                    <span class="muted">(Sisa <strong class="tabular-nums"><?= (int) $quota['remaining'] ?></strong> · Pengiriman gagal tidak mengurangi limit · Terima email tanpa batas)</span>
                <?php endif; ?>
            </div>
            <div class="quota-bar-track" style="margin-top:8px;">
                <div class="quota-bar-fill <?= $pctUsed >= 90 ? 'is-danger' : ($pctUsed >= 70 ? 'is-warn' : '') ?>" style="width: <?= $pctUsed ?>%;"></div>
            </div>
        </div>
        <div class="quota-actions-col">
            <a href="<?= e(base_url('billing.php')) ?>" class="btn btn-primary btn-sm">Upgrade Paket (QRIS)</a>
            <a href="<?= e(base_url('api-keys.php')) ?>" class="btn btn-secondary btn-sm">API Kirim Email</a>
        </div>
    </div>
</section>

<!-- Connection Status Strip -->
<section class="connection-banner conn-banner-<?= e($connStatus['active_badge']) ?>">
    <div class="conn-banner-info">
        <div class="conn-banner-title">Status Koneksi Email: <strong><?= e($connStatus['active_label']) ?></strong></div>
        <div class="conn-banner-desc">
            <?php if ($connStatus['active_ready']): ?>
                Akun aktif: <strong><?= e($mbEmail) ?></strong>
                <?php if ($connStatus['mode'] === 'oauth' && $connStatus['oauth_last_refresh']): ?>
                    &middot; Gmail API OAuth 2.0 (diperbarui <?= e(format_tanggal_id($connStatus['oauth_last_refresh'])) ?>)
                <?php elseif ($connStatus['mode'] === 'app_password'): ?>
                    &middot; SMTP Terautentikasi<?= $connStatus['imap_available'] ? ' + IMAP (Terkirim tersinkron)' : '' ?>
                <?php endif; ?>
            <?php else: ?>
                <?= e($connStatus['notice'] ?: 'Akun email belum terhubung. Silakan pilih akun email lain atau hubungi Superadmin.') ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="conn-banner-actions">
        <?php if ($isSuper): ?>
            <a href="<?= e(base_url('mailboxes.php')) ?>" class="btn btn-sm btn-secondary">Multi-Akun Email</a>
            <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-sm <?= $connStatus['active_ready'] ? 'btn-secondary' : 'btn-primary' ?>">
                <?= $connStatus['active_ready'] ? 'Kelola Koneksi' : 'Hubungkan Gmail' ?>
            </a>
        <?php else: ?>
            <a href="<?= e(base_url('profile.php')) ?>" class="btn btn-sm btn-secondary">Pengaturan Profil</a>
        <?php endif; ?>
    </div>
</section>

<!-- Live Gmail KPI Grid -->
<section class="stats-grid"
         id="dashboardStatsGrid"
         data-connected="<?= $connStatus['active_ready'] ? '1' : '0' ?>"
         data-mailbox-id="<?= (int) ($activeMailbox['id'] ?? 0) ?>">
    <a href="<?= e(base_url('inbox.php')) ?>" class="stat-card">
        <div class="stat-label">Total Email Masuk</div>
        <div class="stat-value tabular-nums" id="statInboxTotal">—</div>
        <div class="stat-meta">Terima pesan tanpa batas</div>
    </a>
    <a href="<?= e(base_url('inbox.php')) ?>" class="stat-card stat-accent">
        <div class="stat-label">Belum Dibaca</div>
        <div class="stat-value tabular-nums" id="statInboxUnread">—</div>
        <div class="stat-meta">Perlu ditindaklanjuti</div>
    </a>
    <a href="<?= e(base_url('sent.php')) ?>" class="stat-card">
        <div class="stat-label">Email Terkirim</div>
        <div class="stat-value tabular-nums" id="statSentTotal">—</div>
        <div class="stat-meta"><?= e($mbEmail) ?></div>
    </a>
    <a href="<?= e(base_url('drafts.php')) ?>" class="stat-card">
        <div class="stat-label">Draft</div>
        <div class="stat-value tabular-nums" id="statDraftsTotal">—</div>
        <div class="stat-meta">Tersimpan di akun email</div>
    </a>
    <a href="<?= e(base_url('starred.php')) ?>" class="stat-card">
        <div class="stat-label">Berbintang</div>
        <div class="stat-value tabular-nums" id="statStarredTotal">—</div>
        <div class="stat-meta">Prioritas &amp; ditandai</div>
    </a>
    <a href="<?= e(base_url('templates.php')) ?>" class="stat-card">
        <div class="stat-label">Template Kustom</div>
        <div class="stat-value tabular-nums"><?= $tplCount ?></div>
        <div class="stat-meta">Siap pakai di editor</div>
    </a>
</section>

<!-- Main Two-Column Grid: Recent Real Emails + Activity Log -->
<div class="dashboard-columns">
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Email Masuk Terbaru</h2>
                <p class="card-subtitle">Data langsung dari akun <?= e($mbEmail) ?></p>
            </div>
            <a href="<?= e(base_url('inbox.php')) ?>" class="link-action">Lihat Semua &rarr;</a>
        </div>

        <div id="dashboardRecentEmails" class="mail-list-compact">
            <?php if (!$connStatus['active_ready']): ?>
                <div class="empty-state">
                    <p><strong>Akun email belum terhubung.</strong></p>
                    <p class="muted">
                        <?php if ($isSuper): ?>
                            Konfigurasikan koneksi Gmail (OAuth 2.0 / App Password) untuk mulai memuat pesan sungguhan.
                        <?php else: ?>
                            Silakan pilih akun email aktif lain pada menu pemilih email di bagian atas atau hubungi Superadmin.
                        <?php endif; ?>
                    </p>
                    <?php if ($isSuper): ?>
                        <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">Buka Pengaturan Koneksi Gmail</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="loading-rows">
                    <div class="skeleton-row"></div>
                    <div class="skeleton-row"></div>
                    <div class="skeleton-row"></div>
                    <div class="skeleton-row"></div>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Aktivitas Terbaru</h2>
                <p class="card-subtitle"><?= $isSuper ? 'Riwayat aktivitas seluruh pengguna & sistem' : 'Riwayat pengiriman & tindakan akun Anda' ?></p>
            </div>
            <?php if ($isSuper): ?>
                <a href="<?= e(base_url('security.php')) ?>" class="link-action">Log Keamanan &rarr;</a>
            <?php else: ?>
                <a href="<?= e(base_url('profile.php')) ?>" class="link-action">Profil Akun &rarr;</a>
            <?php endif; ?>
        </div>

        <?php if (empty($recentLogs)): ?>
            <div class="empty-state">
                <p class="muted">Belum ada catatan aktivitas.</p>
            </div>
        <?php else: ?>
            <ul class="activity-list">
                <?php foreach ($recentLogs as $log): ?>
                    <?php $logStatus = (string) ($log['status'] ?? 'success'); ?>
                    <li class="activity-item">
                        <div class="activity-main">
                            <span class="activity-desc"><?= e((string) ($log['description'] ?? '')) ?></span>
                            <span class="activity-meta">
                                <?= e((string) ($log['admin_name'] ?: 'Sistem')) ?> &middot; <?= e(format_tanggal_id($log['created_at'] ?? null)) ?>
                            </span>
                        </div>
                        <span class="status-text status-<?= e($logStatus) ?>"><?= e(ucfirst($logStatus)) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
