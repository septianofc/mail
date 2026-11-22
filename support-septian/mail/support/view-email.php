<?php
/**
 * Support Septian — Detail Email & Percakapan Thread + Editor Reply / Reply All / Forward
 * Path: /mail/support/view-email.php?id=...
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin = require_admin();
$messageId    = trim((string) ($_GET['id'] ?? ''));
$fromFolder   = trim((string) ($_GET['folder'] ?? 'inbox'));

if ($messageId === '') {
    set_flash('warning', 'ID email tidak ditemukan.');
    redirect_to('inbox.php');
}

$pageTitle  = 'Detail Email';
$activePage = in_array($fromFolder, ['inbox', 'sent', 'drafts', 'starred', 'spam', 'trash'], true) ? $fromFolder : 'inbox';

$threadData = null;
$loadError  = '';

try {
    $threadData = mail_get_thread_detail($messageId, true);
    $pageTitle  = $threadData['subject'] ?: 'Detail Email';
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$templatesAll = get_templates();
$templatesByCat = [
    'reply'     => [],
    'reply_all' => [],
    'forward'   => [],
    'compose'   => [],
    'draft'     => [],
];
foreach ($templatesAll as $t) {
    $cat = (string) $t['category'];
    if (isset($templatesByCat[$cat])) {
        $templatesByCat[$cat][] = $t;
    }
}

$officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
$lastMsg       = $threadData && !empty($threadData['messages']) ? end($threadData['messages']) : null;

// Siapkan penerima Reply & Reply All
$replyToEmail = '';
$replyToName  = 'Pelanggan';
$replyAllTo   = '';
$replyAllCc   = '';

if ($lastMsg) {
    $replyParsed  = parse_sender_header($lastMsg['reply_to'] ?: $lastMsg['from_raw']);
    $replyToEmail = $replyParsed['email'];
    $replyToName  = $replyParsed['name'];

    // Jika pesan terakhir dari kita sendiri, balas ke penerima pesan tersebut
    if ($replyToEmail === $officialEmail && !empty($lastMsg['to'])) {
        $toParsed     = parse_sender_header(explode(',', $lastMsg['to'])[0]);
        $replyToEmail = $toParsed['email'];
        $replyToName  = $toParsed['name'];
    }

    $allToEmails = array_values(array_filter(
        array_merge([$replyToEmail], parse_email_list($lastMsg['to'])),
        static fn(string $em): bool => $em !== '' && $em !== $officialEmail
    ));
    $allCcEmails = array_values(array_filter(
        parse_email_list($lastMsg['cc']),
        static fn(string $em): bool => $em !== '' && $em !== $officialEmail && !in_array($em, $allToEmails, true)
    ));
    $replyAllTo = implode(', ', array_unique($allToEmails));
    $replyAllCc = implode(', ', array_unique($allCcEmails));
}

$extraScripts = ['editor.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div class="header-back-row">
        <a href="<?= e(base_url($activePage . '.php')) ?>" class="btn btn-secondary btn-sm">&larr; Kembali</a>
        <div>
            <h1 class="page-title"><?= e($threadData['subject'] ?? 'Detail Email') ?></h1>
            <?php if ($threadData): ?>
                <p class="page-subtitle"><?= count($threadData['messages']) ?> pesan dalam percakapan</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lastMsg): ?>
        <div class="page-actions" id="threadActionBar" data-message-id="<?= e($lastMsg['id']) ?>">
            <button type="button" class="btn btn-secondary btn-sm" data-single-action="<?= $lastMsg['is_starred'] ? 'unstar' : 'star' ?>">
                <?= $lastMsg['is_starred'] ? 'Hapus Bintang' : 'Beri Bintang' ?>
            </button>
            <button type="button" class="btn btn-secondary btn-sm" data-single-action="mark_unread">Tandai Belum Dibaca</button>
            <button type="button" class="btn btn-secondary btn-sm" data-single-action="archive">Arsipkan</button>
            <button type="button" class="btn btn-secondary btn-sm" data-single-action="spam">Spam</button>
            <button type="button" class="btn btn-danger-outline btn-sm" data-single-action="trash">Hapus</button>
        </div>
    <?php endif; ?>
</div>

<?php if ($loadError !== ''): ?>
    <div class="card">
        <div class="alert alert-danger">
            <span><?= e($loadError) ?></span>
        </div>
        <a href="<?= e(base_url('inbox.php')) ?>" class="btn btn-secondary btn-sm">Kembali ke Kotak Masuk</a>
    </div>
<?php elseif ($threadData): ?>

    <!-- Thread Messages -->
    <div class="thread-container">
        <?php foreach ($threadData['messages'] as $index => $msg): ?>
            <article class="card thread-message">
                <div class="thread-msg-header">
                    <div class="sender-block">
                        <div class="sender-avatar"><?= e(strtoupper(mb_substr($msg['from_name'] ?: '?', 0, 1))) ?></div>
                        <div>
                            <div class="sender-name-line">
                                <strong><?= e($msg['from_name']) ?></strong>
                                <span class="sender-email">&lt;<?= e($msg['from_email']) ?>&gt;</span>
                            </div>
                            <div class="recipient-meta">
                                Kepada: <?= e($msg['to'] ?: '-') ?>
                                <?php if ($msg['cc'] !== ''): ?>
                                    &middot; Cc: <?= e($msg['cc']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="msg-date tabular-nums"><?= e($msg['date_full']) ?></div>
                </div>

                <div class="thread-msg-body email-rendered-content">
                    <?= $msg['body_html'] ?>
                </div>

                <?php if (!empty($msg['attachments'])): ?>
                    <div class="attachments-strip">
                        <div class="attachments-title">Lampiran (<?= count($msg['attachments']) ?>)</div>
                        <div class="attachments-list">
                            <?php foreach ($msg['attachments'] as $att): ?>
                                <a class="attachment-card"
                                   href="<?= e(base_url('api/attachments.php?message_id=' . urlencode($msg['id']) . '&attachment_id=' . urlencode($att['attachment_id']) . '&filename=' . urlencode($att['filename']))) ?>">
                                    <span class="att-name"><?= e($att['filename']) ?></span>
                                    <span class="att-size tabular-nums"><?= e(format_bytes((int) $att['size'])) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <!-- Action Reply / Reply All / Forward Editor -->
    <?php if ($lastMsg): ?>
        <?php
        $quotedHeader = sprintf(
            '<p style="margin:0 0 8px 0;font-weight:600;color:#3c4043;">Pada %s, %s &lt;%s&gt; menulis:</p>',
            e($lastMsg['date_full']),
            e($lastMsg['from_name']),
            e($lastMsg['from_email'])
        );
        $quotedHtmlPayload = $quotedHeader . '<div>' . $lastMsg['body_html'] . '</div>';
        ?>
        <section class="card reply-composer-card" id="threadReplySection">
            <div class="reply-mode-tabs" role="tablist">
                <button type="button" class="mode-tab is-active" data-reply-mode="reply">Balas Pesan</button>
                <button type="button" class="mode-tab" data-reply-mode="reply_all">Balas Semua (Reply All)</button>
                <button type="button" class="mode-tab" data-reply-mode="forward">Teruskan (Forward)</button>
            </div>

            <form id="emailEditorForm"
                  class="editor-form"
                  enctype="multipart/form-data"
                  data-mode="reply"
                  data-endpoint="<?= e(base_url('api/reply.php')) ?>"
                  data-reply-to="<?= e($replyToEmail) ?>"
                  data-reply-name="<?= e($replyToName) ?>"
                  data-reply-all-to="<?= e($replyAllTo) ?>"
                  data-reply-all-cc="<?= e($replyAllCc) ?>"
                  data-orig-subject="<?= e($threadData['subject']) ?>"
                  data-admin-name="<?= e($currentAdmin['name']) ?>">

                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="thread_id" value="<?= e($threadData['thread_id']) ?>">
                <input type="hidden" name="in_reply_to" value="<?= e($lastMsg['message_id_hdr']) ?>">
                <input type="hidden" name="references" value="<?= e($lastMsg['references_hdr']) ?>">
                <input type="hidden" name="recipient_name" id="recipientNameInput" value="<?= e($replyToName) ?>">
                <textarea name="quoted_html" id="quotedHtmlInput" hidden><?= e($quotedHtmlPayload) ?></textarea>

                <div class="editor-meta-grid">
                    <div class="form-group">
                        <label for="editorTo">Kepada</label>
                        <input type="text" id="editorTo" name="to" value="<?= e($replyToEmail) ?>" placeholder="email@penerima.com" required>
                    </div>
                    <div class="form-group">
                        <label for="editorCc">Cc (Opsional)</label>
                        <input type="text" id="editorCc" name="cc" value="" placeholder="Pisahkan dengan koma jika lebih dari satu">
                    </div>
                </div>

                <div class="editor-meta-grid">
                    <div class="form-group">
                        <label for="editorSubject">Subjek</label>
                        <input type="text" id="editorSubject" name="subject" value="<?= e(preg_match('/^re:/i', $threadData['subject']) ? $threadData['subject'] : 'Re: ' . $threadData['subject']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="templateSelector">Pilih Template Profesional</label>
                        <select id="templateSelector">
                            <option value="">-- Muat Template --</option>
                            <?php foreach ($templatesAll as $t): ?>
                                <option value="<?= (int) $t['id'] ?>"
                                        data-category="<?= e($t['category']) ?>"
                                        data-default="<?= (int) $t['is_default'] ?>"
                                        data-subject="<?= e($t['subject_template']) ?>"
                                        data-body="<?= e($t['body_html']) ?>">
                                    [<?= e(strtoupper($t['category'])) ?>] <?= e($t['name']) ?><?= (int) $t['is_default'] === 1 ? ' (Default)' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Rich Toolbar -->
                <div class="editor-toolbar">
                    <div class="toolbar-btn-group">
                        <button type="button" class="tool-btn" data-cmd="bold" title="Tebal"><strong>B</strong></button>
                        <button type="button" class="tool-btn" data-cmd="italic" title="Miring"><em>I</em></button>
                        <button type="button" class="tool-btn" data-cmd="underline" title="Garis bawah"><u>U</u></button>
                        <button type="button" class="tool-btn" data-cmd="insertUnorderedList" title="Daftar poin">&bull; Daftar</button>
                        <button type="button" class="tool-btn" data-cmd="insertOrderedList" title="Daftar angka">1. Nomor</button>
                        <button type="button" class="tool-btn" id="btnInsertLink" title="Sisipkan tautan">Tautan</button>
                    </div>
                    <div class="toolbar-btn-group">
                        <button type="button" class="btn btn-secondary btn-sm" id="btnPreviewEmail">Preview HTML</button>
                    </div>
                </div>

                <div id="richEditor" class="rich-editor" contenteditable="true" aria-label="Isi pesan email"></div>
                <textarea name="body_html" id="bodyHtmlHidden" hidden></textarea>

                <div class="form-group" style="margin-top:14px;">
                    <label for="editorAttachments">Lampiran (Opsional, maks. 15 MB/file)</label>
                    <input type="file" id="editorAttachments" name="attachments[]" multiple>
                    <div id="attachmentPreviewList" class="file-list-preview"></div>
                </div>

                <div class="editor-footer-actions">
                    <div class="editor-footer-left">
                        <button type="submit" class="btn btn-primary" id="btnSendEmail">Kirim Balasan</button>
                        <button type="button" class="btn btn-secondary" id="btnSaveDraft">Simpan ke Draft</button>
                    </div>
                    <div class="editor-identity-note">
                        Mengirim sebagai <strong><?= e(get_setting('official_name', DEFAULT_OFFICIAL_NAME)) ?></strong> &lt;<?= e($officialEmail) ?>&gt;
                    </div>
                </div>
            </form>
        </section>

        <!-- Live Email Preview Modal -->
        <div class="modal-backdrop" id="emailPreviewModal" hidden>
            <div class="modal-dialog modal-lg" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <h3>Preview Tampilan Email Support Septian</h3>
                    <button type="button" class="icon-btn" id="closePreviewModal">&times;</button>
                </div>
                <div class="modal-body" style="padding:0;">
                    <iframe id="emailPreviewFrame" class="preview-iframe" title="Preview Email HTML" sandbox="allow-same-origin"></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="closePreviewModalBtn">Tutup Preview</button>
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
