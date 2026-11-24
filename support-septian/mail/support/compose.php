<?php
/**
 * Skyzz Mail — Tulis Email Baru (Compose)
 * Path: /mail/support/compose.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin  = require_admin();
$isSuper       = is_superadmin($currentAdmin);
$pageTitle     = 'Tulis Email Baru';
$activePage    = 'compose';
$templatesAll  = get_templates('all', (int) $currentAdmin['id'], $isSuper);
$activeMailbox = get_effective_mailbox($currentAdmin);
$quota         = get_user_quota_status($currentAdmin);
$plan          = $quota['plan'];
$officialEmail = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$senderName    = trim((string) ($currentAdmin['custom_sender_name'] ?? ''))
    ?: (trim((string) ($activeMailbox['display_name'] ?? '')) ?: get_setting('official_name', DEFAULT_OFFICIAL_NAME));
$variables     = get_template_variables_catalog();
$appName       = get_setting('app_name', DEFAULT_APP_NAME);

$prefillTo      = trim((string) ($_GET['to'] ?? ''));
$prefillSubject = trim((string) ($_GET['subject'] ?? ''));
$prefillDraftId = trim((string) ($_GET['draft_id'] ?? ''));

$extraScripts = ['editor.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Tulis Email Baru</h1>
        <p class="page-subtitle">Gunakan template kustom Anda atau tulis bebas &middot; Sisa kuota kirim hari ini: <strong class="tabular-nums"><?= !empty($quota['is_unlimited']) ? 'Tanpa Batas' : ((int) $quota['remaining'] . ' / ' . (int) $quota['daily_limit']) ?></strong> (Paket <?= e((string) ($plan['name'] ?? 'Free')) ?>)</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('templates.php')) ?>" class="btn btn-secondary btn-sm">Kelola Template</a>
        <a href="<?= e(base_url('billing.php')) ?>" class="btn btn-secondary btn-sm">Upgrade Kuota</a>
    </div>
</div>

<?php if (empty($quota['is_unlimited']) && $quota['remaining'] <= 0 && !$isSuper): ?>
    <div class="alert alert-warning" style="margin-bottom:16px;">
        <span>Kuota kirim harian Anda hari ini (<?= (int) $quota['daily_limit'] ?> email) telah habis. Kuota akan direset otomatis pukul 00:00 WIB atau <a href="<?= e(base_url('billing.php')) ?>"><strong>upgrade paket Anda sekarang</strong></a>.</span>
    </div>
<?php elseif (!empty($plan['has_watermark']) && !$isSuper): ?>
    <div class="alert alert-info" style="margin-bottom:16px;">
        <span>Paket <strong><?= e((string) ($plan['name'] ?? 'Free')) ?></strong> menyertakan watermark kecil <em>"<?= e((string) ($plan['watermark_text'] ?: get_setting('free_watermark_text', FREE_WATERMARK_DEFAULT))) ?>"</em>. Pengiriman gagal tidak akan mengurangi limit harian Anda.</span>
    </div>
<?php endif; ?>

<section class="card">
    <form id="emailEditorForm"
          class="editor-form"
          enctype="multipart/form-data"
          data-mode="compose"
          data-endpoint="<?= e(base_url('api/send.php')) ?>"
          data-admin-name="<?= e($senderName) ?>">

        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="draft_id" id="draftIdInput" value="<?= e($prefillDraftId) ?>">

        <div class="editor-meta-grid">
            <div class="form-group">
                <label for="editorTo">Kepada (Wajib)</label>
                <input type="text" id="editorTo" name="to" value="<?= e($prefillTo) ?>" placeholder="nama@domain.com (pisahkan koma jika lebih dari satu)" required>
            </div>
            <div class="form-group">
                <label for="recipientNameInput">Nama Penerima (untuk variabel {nama_penerima})</label>
                <input type="text" id="recipientNameInput" name="recipient_name" value="" placeholder="Contoh: Bapak Budi Santoso">
            </div>
        </div>

        <div class="editor-meta-grid">
            <div class="form-group">
                <label for="editorCc">Cc (Opsional)</label>
                <input type="text" id="editorCc" name="cc" placeholder="salinan@domain.com">
            </div>
            <div class="form-group">
                <label for="editorBcc">Bcc (Opsional)</label>
                <input type="text" id="editorBcc" name="bcc" placeholder="salinan-tersembunyi@domain.com">
            </div>
        </div>

        <div class="editor-meta-grid">
            <div class="form-group">
                <label for="editorSubject">Subjek Email (Wajib)</label>
                <input type="text" id="editorSubject" name="subject" value="<?= e($prefillSubject) ?>" placeholder="Masukkan subjek email" required>
            </div>
            <div class="form-group">
                <label for="templateSelector">Template Email Kustom</label>
                <select id="templateSelector">
                    <option value="">-- Pilih Template --</option>
                    <?php foreach ($templatesAll as $t): ?>
                        <option value="<?= (int) $t['id'] ?>"
                                data-category="<?= e((string) ($t['category'] ?? '')) ?>"
                                data-default="<?= (int) ($t['is_default'] ?? 0) ?>"
                                data-subject="<?= e((string) ($t['subject_template'] ?? '')) ?>"
                                data-body="<?= e((string) ($t['body_html'] ?? '')) ?>">
                            [<?= e(strtoupper((string) ($t['category'] ?? ''))) ?>] <?= e((string) ($t['name'] ?? '')) ?><?= (int) ($t['is_default'] ?? 0) === 1 ? ' (Default)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Dynamic Variables Quick Insert -->
        <div class="var-insert-bar">
            <span class="var-label">Sisipkan Variabel:</span>
            <?php foreach ($variables as $token => $desc): ?>
                <button type="button" class="var-btn" data-insert-var="<?= e($token) ?>" title="<?= e($desc) ?>"><?= e($token) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Rich Editor Toolbar -->
        <div class="editor-toolbar">
            <div class="toolbar-btn-group">
                <button type="button" class="tool-btn" data-cmd="bold" title="Tebal"><strong>B</strong></button>
                <button type="button" class="tool-btn" data-cmd="italic" title="Miring"><em>I</em></button>
                <button type="button" class="tool-btn" data-cmd="underline" title="Garis bawah"><u>U</u></button>
                <button type="button" class="tool-btn" data-cmd="insertUnorderedList" title="Daftar poin">&bull; Daftar</button>
                <button type="button" class="tool-btn" data-cmd="insertOrderedList" title="Daftar angka">1. Nomor</button>
                <button type="button" class="tool-btn" id="btnInsertLink" title="Sisipkan tautan">Tautan</button>
                <button type="button" class="tool-btn" data-cmd="removeFormat" title="Bersihkan format">Reset Format</button>
            </div>
            <div class="toolbar-btn-group">
                <label class="inline-check">
                    <input type="checkbox" name="wrap_template" id="wrapTemplateCheck" value="1" checked>
                    <span>Gunakan Tata Letak Email Rapi (Header &amp; Tanda Tangan)</span>
                </label>
                <button type="button" class="btn btn-secondary btn-sm" id="btnPreviewEmail">Preview Email</button>
            </div>
        </div>

        <div id="richEditor" class="rich-editor rich-editor-lg" contenteditable="true" aria-label="Isi pesan email baru"></div>
        <textarea name="body_html" id="bodyHtmlHidden" hidden></textarea>

        <div class="form-group" style="margin-top:14px;">
            <label for="editorAttachments">Lampiran (Opsional — PDF, Office, Gambar, ZIP — maks. 15 MB/file)</label>
            <input type="file" id="editorAttachments" name="attachments[]" multiple>
            <div id="attachmentPreviewList" class="file-list-preview"></div>
        </div>

        <div class="editor-footer-actions">
            <div class="editor-footer-left">
                <button type="submit" class="btn btn-primary" id="btnSendEmail">Kirim Email Sekarang</button>
                <button type="button" class="btn btn-secondary" id="btnSaveDraft">Simpan sebagai Draft</button>
            </div>
            <div class="editor-identity-note">
                Pengirim aktif: <strong><?= e($senderName) ?></strong> &lt;<?= e($officialEmail) ?>&gt;
            </div>
        </div>
    </form>
</section>

<!-- Live Email Preview Modal -->
<div class="modal-backdrop" id="emailPreviewModal" hidden>
    <div class="modal-dialog modal-lg" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h3>Preview Desain Email <?= e($appName) ?></h3>
            <button type="button" class="icon-btn" id="closePreviewModal">&times;</button>
        </div>
        <div class="modal-body" style="padding:0;">
            <iframe id="emailPreviewFrame" class="preview-iframe" title="Preview Email HTML" sandbox="allow-same-origin"></iframe>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="closePreviewModalBtn">Tutup</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
