<?php
/**
 * Skyzz Mail — Superadmin: Kelola Akun Email / Mailbox yang Dapat Dipilih User
 * Path: /mail/support/mailboxes.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/smtp-imap-service.php';

$currentAdmin = require_superadmin();
$pageTitle    = 'Kelola Akun Email';
$activePage   = 'mailboxes';

$testResult = null;
$editId     = (int) ($_GET['edit'] ?? 0);
$editMb     = $editId > 0 ? get_mailbox_by_id($editId) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $action = trim((string) ($_POST['action'] ?? 'save'));

    try {
        if ($action === 'save') {
            $savedId = save_mailbox([
                'id'                => (int) ($_POST['id'] ?? 0),
                'label'             => (string) ($_POST['label'] ?? ''),
                'email'             => (string) ($_POST['email'] ?? ''),
                'display_name'      => (string) ($_POST['display_name'] ?? ''),
                'provider_mode'     => (string) ($_POST['provider_mode'] ?? 'app_password'),
                'smtp_host'         => (string) ($_POST['smtp_host'] ?? 'smtp.gmail.com'),
                'smtp_port'         => (int) ($_POST['smtp_port'] ?? 465),
                'smtp_encryption'   => (string) ($_POST['smtp_encryption'] ?? 'ssl'),
                'smtp_username'     => (string) ($_POST['smtp_username'] ?? ''),
                'smtp_password'     => (string) ($_POST['smtp_password'] ?? ''),
                'imap_host'         => (string) ($_POST['imap_host'] ?? 'imap.gmail.com'),
                'imap_port'         => (int) ($_POST['imap_port'] ?? 993),
                'is_active'         => !empty($_POST['is_active']),
                'is_default'        => !empty($_POST['is_default']),
                'allow_user_select' => !empty($_POST['allow_user_select']),
            ], (int) $currentAdmin['id']);

            log_activity('mailbox_saved', 'Superadmin menyimpan konfigurasi akun email #' . $savedId . ' (' . ($_POST['email'] ?? '') . ').', 'mailbox', (string) $savedId);
            set_flash('success', 'Konfigurasi akun email berhasil disimpan dan langsung tersedia bagi pengguna.');
            redirect_to('mailboxes.php');
        } elseif ($action === 'delete') {
            $delId = (int) ($_POST['id'] ?? 0);
            if ($delId > 0) {
                delete_mailbox($delId);
                log_activity('mailbox_deleted', 'Superadmin menghapus akun email #' . $delId, 'mailbox', (string) $delId);
                set_flash('success', 'Akun email berhasil dihapus.');
            }
            redirect_to('mailboxes.php');
        } elseif ($action === 'test') {
            $testId = (int) ($_POST['id'] ?? 0);
            $mb = get_mailbox_by_id($testId);
            if (!$mb) {
                throw new RuntimeException('Akun email tidak ditemukan.');
            }
            $testResult = run_smtp_diagnostics(null, null, $mb);
        }
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }
}

$mailboxes = get_all_mailboxes();

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Kelola Akun Email (Mailbox)</h1>
        <p class="page-subtitle">Tambahkan &amp; konfigurasi akun email (Gmail App Password, SMTP/IMAP, atau OAuth) yang bebas dipilih pengguna saat login maupun di dashboard</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-secondary btn-sm">Diagnostik SMTP &amp; OAuth</a>
    </div>
</div>

<?php if ($testResult !== null): ?>
    <div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?>" role="alert">
        <div>
            <strong>Hasil Tes Koneksi (<?= e((string) $testResult['account_email']) ?>):</strong>
            <?= e((string) $testResult['message']) ?>
        </div>
        <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
    </div>
<?php endif; ?>

<div class="dashboard-columns" style="grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);">
    <!-- Daftar Mailbox -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Daftar Email Layanan (<?= count($mailboxes) ?>)</h2>
                <p class="card-subtitle">User dapat memilih akun bertanda &ldquo;Bisa Dipilih User&rdquo;</p>
            </div>
        </div>

        <?php if (empty($mailboxes)): ?>
            <div class="empty-state">
                <p class="muted">Belum ada akun email dikonfigurasi. Tambahkan akun email pertama di formulir sebelah kanan.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Email &amp; Label</th>
                            <th>SMTP / IMAP</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mailboxes as $m): ?>
                            <tr>
                                <td>
                                    <strong><?= e((string) $m['email']) ?></strong>
                                    <?php if ((int) $m['is_default'] === 1): ?>
                                        <span class="badge-pill badge-primary">Default</span>
                                    <?php endif; ?>
                                    <div class="muted" style="font-size:12px;"><?= e((string) $m['label']) ?> &middot; Pengirim: <?= e((string) $m['display_name']) ?></div>
                                </td>
                                <td style="font-size:12px;">
                                    <code><?= e((string) $m['smtp_host']) ?>:<?= (int) $m['smtp_port'] ?></code> (<?= e(strtoupper((string) $m['smtp_encryption'])) ?>)<br>
                                    <span class="muted">IMAP: <?= e((string) $m['imap_host']) ?>:<?= (int) $m['imap_port'] ?> &middot; Sandi: <?= !empty($m['smtp_password_enc']) ? 'Tersimpan' : 'Kosong' ?></span>
                                </td>
                                <td>
                                    <?php if ((int) $m['is_active'] === 1): ?>
                                        <span class="badge-pill badge-success">Aktif</span>
                                    <?php else: ?>
                                        <span class="badge-pill badge-danger">Nonaktif</span>
                                    <?php endif; ?>
                                    <?php if ((int) $m['allow_user_select'] === 1): ?>
                                        <div style="font-size:11.5px;color:var(--primary);margin-top:3px;">Bisa dipilih User</div>
                                    <?php else: ?>
                                        <div style="font-size:11.5px;color:var(--text-muted);margin-top:3px;">Khusus Superadmin</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <a href="<?= e(base_url('mailboxes.php?edit=' . (int) $m['id'])) ?>" class="btn btn-secondary btn-sm">Edit</a>
                                        <form method="post" action="<?= e(base_url('mailboxes.php')) ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="test">
                                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                            <button type="submit" class="btn btn-secondary btn-sm">Tes</button>
                                        </form>
                                        <form method="post" action="<?= e(base_url('mailboxes.php')) ?>" onsubmit="return confirm('Hapus akun email ini?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <!-- Form Tambah / Edit Mailbox -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title"><?= $editMb ? 'Edit Akun Email #' . (int) $editMb['id'] : 'Tambah Akun Email Baru' ?></h2>
                <p class="card-subtitle">Sandi SMTP / Google App Password disimpan terenkripsi AES-256-GCM</p>
            </div>
            <?php if ($editMb): ?>
                <a href="<?= e(base_url('mailboxes.php')) ?>" class="btn btn-secondary btn-sm">Batal Edit</a>
            <?php endif; ?>
        </div>

        <form method="post" action="<?= e(base_url('mailboxes.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editMb['id'] ?? 0) ?>">

            <div class="form-row-2">
                <div class="form-group">
                    <label for="mbLabel">Label Tampilan (Pilihan User)</label>
                    <input type="text" id="mbLabel" name="label" value="<?= e((string) ($editMb['label'] ?? '')) ?>" placeholder="Contoh: Email Bisnis 1" required>
                </div>
                <div class="form-group">
                    <label for="mbDisplayName">Nama Pengirim Default</label>
                    <input type="text" id="mbDisplayName" name="display_name" value="<?= e((string) ($editMb['display_name'] ?? get_setting('app_name', DEFAULT_APP_NAME))) ?>" required>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="mbEmail">Alamat Email</label>
                    <input type="email" id="mbEmail" name="email" value="<?= e((string) ($editMb['email'] ?? '')) ?>" placeholder="akun@gmail.com" required>
                </div>
                <div class="form-group">
                    <label for="mbMode">Mode Koneksi</label>
                    <select id="mbMode" name="provider_mode">
                        <option value="app_password" <?= ($editMb['provider_mode'] ?? 'app_password') === 'app_password' ? 'selected' : '' ?>>SMTP / Google App Password + IMAP</option>
                        <option value="oauth" <?= ($editMb['provider_mode'] ?? '') === 'oauth' ? 'selected' : '' ?>>Google Gmail API (OAuth 2.0)</option>
                    </select>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="mbSmtpUser">Username SMTP / IMAP</label>
                    <input type="text" id="mbSmtpUser" name="smtp_username" value="<?= e((string) ($editMb['smtp_username'] ?? '')) ?>" placeholder="Kosongkan agar sama dengan Email">
                </div>
                <div class="form-group">
                    <label for="mbSmtpPass">Sandi SMTP / 16 Digit App Password</label>
                    <input type="password" id="mbSmtpPass" name="smtp_password" placeholder="<?= $editMb ? 'Kosongkan jika tidak ingin mengubah' : 'Masukkan App Password / Sandi SMTP' ?>" autocomplete="new-password">
                </div>
            </div>

            <div class="form-row-3">
                <div class="form-group">
                    <label for="mbSmtpHost">SMTP Host</label>
                    <input type="text" id="mbSmtpHost" name="smtp_host" value="<?= e((string) ($editMb['smtp_host'] ?? 'smtp.gmail.com')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="mbSmtpPort">SMTP Port</label>
                    <input type="number" id="mbSmtpPort" name="smtp_port" value="<?= (int) ($editMb['smtp_port'] ?? 465) ?>" required>
                </div>
                <div class="form-group">
                    <label for="mbSmtpEnc">Enkripsi</label>
                    <select id="mbSmtpEnc" name="smtp_encryption">
                        <option value="ssl" <?= ($editMb['smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                        <option value="tls" <?= ($editMb['smtp_encryption'] ?? '') === 'tls' ? 'selected' : '' ?>>TLS / STARTTLS (587)</option>
                        <option value="none" <?= ($editMb['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label for="mbImapHost">IMAP Host (Kotak Masuk &amp; Sinkron Terkirim)</label>
                    <input type="text" id="mbImapHost" name="imap_host" value="<?= e((string) ($editMb['imap_host'] ?? 'imap.gmail.com')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="mbImapPort">IMAP Port</label>
                    <input type="number" id="mbImapPort" name="imap_port" value="<?= (int) ($editMb['imap_port'] ?? 993) ?>" required>
                </div>
            </div>

            <div style="display:flex;flex-wrap:wrap;gap:16px;margin:12px 0 18px;">
                <label class="inline-check">
                    <input type="checkbox" name="is_active" value="1" <?= !$editMb || (int) $editMb['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Aktif</span>
                </label>
                <label class="inline-check">
                    <input type="checkbox" name="allow_user_select" value="1" <?= !$editMb || (int) $editMb['allow_user_select'] === 1 ? 'checked' : '' ?>>
                    <span>Bisa Dipilih User</span>
                </label>
                <label class="inline-check">
                    <input type="checkbox" name="is_default" value="1" <?= $editMb && (int) $editMb['is_default'] === 1 ? 'checked' : '' ?>>
                    <span>Jadikan Default Utama</span>
                </label>
            </div>

            <button type="submit" class="btn btn-primary btn-block"><?= $editMb ? 'Simpan Perubahan Akun Email' : 'Tambah Akun Email' ?></button>
        </form>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
