<?php
/**
 * Support Septian — Dashboard Utama
 * Path: /mail/support/dashboard.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin = require_admin();
$pageTitle    = 'Dashboard';
$activePage   = 'dashboard';
$settings     = get_all_settings();
$connStatus   = get_mail_connection_status();

// Ambil aktivitas terbaru dari database lokal
$recentLogs = [];
try {
    $recentLogs = db()->query(
        'SELECT a.action, a.description, a.status, a.created_at, ad.name AS admin_name
         FROM activity_logs a
         LEFT JOIN admins ad ON ad.id = a.admin_id
         ORDER BY a.id DESC
         LIMIT 8'
    )->fetchAll();
} catch (Throwable $e) {
    app_log('warning', 'dashboard.php gagal memuat activity_logs: ' . $e->getMessage());
}

// Hitung jumlah template aktif
$tplCount = 0;
try {
    $tplCount = (int) db()->query('SELECT COUNT(*) FROM email_templates WHERE is_active = 1')->fetchColumn();
} catch (Throwable $e) {
    app_log('warning', 'dashboard.php gagal menghitung email_templates: ' . $e->getMessage());
}

$extraScripts = ['inbox.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Dashboard Support Septian</h1>
        <p class="page-subtitle"><?= e($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME) ?> &middot; <?= e($settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" id="btnRefreshDashboard">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            <span>Refresh Inbox</span>
        </button>
        <a href="<?= e(base_url('compose.php')) ?>" class="btn btn-primary">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            <span>Tulis Email</span>
        </a>
    </div>
</div>

<!-- Connection Status Strip -->
<section class="connection-banner conn-banner-<?= e($connStatus['active_badge']) ?>">
    <div class="conn-banner-info">
        <div class="conn-banner-title">Status Koneksi Gmail: <strong><?= e($connStatus['active_label']) ?></strong></div>
        <div class="conn-banner-desc">
            <?php if ($connStatus['active_ready']): ?>
                Akun aktif: <strong><?= e($connStatus['official_email']) ?></strong>
                <?php if ($connStatus['mode'] === 'oauth' && $connStatus['oauth_last_refresh']): ?>
                    &middot; Token diperbarui <?= e(format_tanggal_id($connStatus['oauth_last_refresh'])) ?>
                <?php elseif ($connStatus['mode'] === 'app_password'): ?>
                    &middot; Mode Google App Password (SMTP<?= $connStatus['imap_available'] ? ' + IMAP' : '' ?>)
                <?php endif; ?>
            <?php else: ?>
                <?= e($connStatus['notice'] ?: 'Silakan hubungkan akun Gmail resmi support.septianfr@gmail.com.') ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="conn-banner-actions">
        <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-sm <?= $connStatus['active_ready'] ? 'btn-secondary' : 'btn-primary' ?>">
            <?= $connStatus['active_ready'] ? 'Kelola Koneksi' : 'Hubungkan Gmail Sekarang' ?>
        </a>
    </div>
</section>

<!-- Live Gmail KPI Grid -->
<section class="stats-grid" id="dashboardStatsGrid" data-connected="<?= $connStatus['active_ready'] ? '1' : '0' ?>">
    <a href="<?= e(base_url('inbox.php')) ?>" class="stat-card">
        <div class="stat-label">Total Email Masuk</div>
        <div class="stat-value tabular-nums" id="statInboxTotal">—</div>
        <div class="stat-meta">Kotak Masuk Gmail</div>
    </a>
    <a href="<?= e(base_url('inbox.php')) ?>" class="stat-card stat-accent">
        <div class="stat-label">Belum Dibaca</div>
        <div class="stat-value tabular-nums" id="statInboxUnread">—</div>
        <div class="stat-meta">Perlu ditindaklanjuti</div>
    </a>
    <a href="<?= e(base_url('sent.php')) ?>" class="stat-card">
        <div class="stat-label">Email Terkirim</div>
        <div class="stat-value tabular-nums" id="statSentTotal">—</div>
        <div class="stat-meta">Dari support.septianfr@gmail.com</div>
    </a>
    <a href="<?= e(base_url('drafts.php')) ?>" class="stat-card">
        <div class="stat-label">Draft</div>
        <div class="stat-value tabular-nums" id="statDraftsTotal">—</div>
        <div class="stat-meta">Tersimpan di Gmail</div>
    </a>
    <a href="<?= e(base_url('starred.php')) ?>" class="stat-card">
        <div class="stat-label">Berbintang</div>
        <div class="stat-value tabular-nums" id="statStarredTotal">—</div>
        <div class="stat-meta">Prioritas &amp; ditandai</div>
    </a>
    <a href="<?= e(base_url('templates.php')) ?>" class="stat-card">
        <div class="stat-label">Template Aktif</div>
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
                <p class="card-subtitle">Data langsung dari Gmail akun <?= e($connStatus['official_email']) ?></p>
            </div>
            <a href="<?= e(base_url('inbox.php')) ?>" class="link-action">Lihat Semua &rarr;</a>
        </div>

        <div id="dashboardRecentEmails" class="mail-list-compact">
            <?php if (!$connStatus['active_ready']): ?>
                <div class="empty-state">
                    <p><strong>Gmail belum terhubung.</strong></p>
                    <p class="muted">Hubungkan akun resmi <code>support.septianfr@gmail.com</code> menggunakan Google OAuth 2.0 atau Google App Password untuk memuat email sungguhan.</p>
                    <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">Buka Pengaturan Koneksi Gmail</a>
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
                <p class="card-subtitle">Riwayat tindakan admin &amp; pengiriman</p>
            </div>
            <a href="<?= e(base_url('security.php')) ?>" class="link-action">Log Keamanan &rarr;</a>
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
