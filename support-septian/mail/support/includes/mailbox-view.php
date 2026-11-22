<?php
/**
 * Support Septian — Reusable Mailbox Folder View (Inbox, Sent, Drafts, Starred, Spam, Trash)
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

$folderKey      = $folderKey ?? 'inbox';
$folderTitle    = $folderTitle ?? 'Kotak Masuk';
$folderSubtitle = $folderSubtitle ?? 'Pesan masuk akun support.septianfr@gmail.com';
$searchQuery    = trim((string) ($_GET['q'] ?? ''));
$connStatus     = get_mail_connection_status();
$extraScripts   = ['inbox.js'];

require __DIR__ . '/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($searchQuery !== '' ? 'Hasil Pencarian: "' . $searchQuery . '"' : $folderTitle) ?></h1>
        <p class="page-subtitle"><?= e($folderSubtitle) ?></p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-secondary" id="btnRefreshFolder">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
            <span>Refresh</span>
        </button>
        <a href="<?= e(base_url('compose.php')) ?>" class="btn btn-primary">Tulis Email</a>
    </div>
</div>

<section class="card mailbox-card"
         id="mailboxContainer"
         data-folder="<?= e($folderKey) ?>"
         data-query="<?= e($searchQuery) ?>"
         data-connected="<?= $connStatus['active_ready'] ? '1' : '0' ?>">

    <!-- Mailbox Bulk Toolbar -->
    <div class="mailbox-toolbar">
        <div class="toolbar-left">
            <label class="checkbox-wrap" title="Pilih semua">
                <input type="checkbox" id="selectAllMails">
                <span></span>
            </label>
            <div class="toolbar-actions" id="bulkActionsBar">
                <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="mark_read" title="Tandai sudah dibaca">Sudah Dibaca</button>
                <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="mark_unread" title="Tandai belum dibaca">Belum Dibaca</button>
                <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="star" title="Beri bintang">Bintang</button>
                <?php if ($folderKey !== 'trash'): ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="archive" title="Arsipkan">Arsipkan</button>
                    <?php if ($folderKey === 'spam'): ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="not_spam">Bukan Spam</button>
                    <?php else: ?>
                        <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="spam">Spam</button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-ghost btn-sm text-danger" data-bulk-action="trash">Hapus</button>
                <?php else: ?>
                    <button type="button" class="btn btn-ghost btn-sm" data-bulk-action="untrash">Pulihkan</button>
                    <button type="button" class="btn btn-ghost btn-sm text-danger" data-bulk-action="delete">Hapus Permanen</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="toolbar-right">
            <span class="mailbox-meta tabular-nums" id="mailboxMetaInfo">Memuat...</span>
            <div class="pagination-controls">
                <button type="button" class="btn btn-secondary btn-sm" id="btnPrevPage" disabled>&larr; Sebelumnya</button>
                <button type="button" class="btn btn-secondary btn-sm" id="btnNextPage" disabled>Berikutnya &rarr;</button>
            </div>
        </div>
    </div>

    <!-- Mail List Body -->
    <div id="mailboxList" class="mailbox-list">
        <?php if (!$connStatus['active_ready']): ?>
            <div class="empty-state">
                <p><strong>Akun Gmail belum terhubung.</strong></p>
                <p class="muted"><?= e($connStatus['notice'] ?: 'Silakan hubungkan akun support.septianfr@gmail.com di halaman Koneksi Gmail.') ?></p>
                <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-primary btn-sm" style="margin-top:12px;">Hubungkan Gmail</a>
            </div>
        <?php else: ?>
            <div class="loading-rows">
                <div class="skeleton-row"></div>
                <div class="skeleton-row"></div>
                <div class="skeleton-row"></div>
                <div class="skeleton-row"></div>
                <div class="skeleton-row"></div>
                <div class="skeleton-row"></div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require __DIR__ . '/footer.php'; ?>
