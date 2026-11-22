<?php
/**
 * Support Septian — Manajemen Template Email Profesional
 * Path: /mail/support/templates.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email-renderer.php';

$currentAdmin = require_admin();
$pageTitle    = 'Manajemen Template Email';
$activePage   = 'templates';

$filterCat = trim((string) ($_GET['category'] ?? 'all'));
$templates = get_templates($filterCat);
$variables = get_template_variables_catalog();

$categoryLabels = [
    'reply'     => 'Balas Pesan',
    'reply_all' => 'Balas Semua',
    'compose'   => 'Pesan Baru',
    'forward'   => 'Teruskan Pesan',
    'draft'     => 'Draft',
];

$extraScripts = ['templates.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Manajemen Template Email</h1>
        <p class="page-subtitle">Kelola seluruh template balasan, pesan baru, teruskan, dan draft resmi Support Septian F. R.</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('template-preview.php')) ?>" class="btn btn-secondary">Preview Template</a>
        <a href="<?= e(base_url('settings.php#branding')) ?>" class="btn btn-secondary">Atur Logo &amp; Footer</a>
        <button type="button" class="btn btn-primary" id="btnNewTemplate">+ Buat Template Baru</button>
    </div>
</div>

<!-- Category Filter Tabs -->
<div class="filter-bar" role="tablist">
    <a href="<?= e(base_url('templates.php?category=all')) ?>" class="filter-tab <?= $filterCat === 'all' ? 'is-active' : '' ?>">Semua</a>
    <?php foreach ($categoryLabels as $key => $label): ?>
        <a href="<?= e(base_url('templates.php?category=' . $key)) ?>" class="filter-tab <?= $filterCat === $key ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="templates-workspace">
    <!-- List Column -->
    <section class="card">
        <div class="card-header">
            <h2 class="card-title">Daftar Template (<?= count($templates) ?>)</h2>
        </div>

        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <p class="muted">Belum ada template pada kategori ini.</p>
            </div>
        <?php else: ?>
            <div class="template-list" id="templateList">
                <?php foreach ($templates as $tpl): ?>
                    <div class="template-row" data-tpl-id="<?= (int) $tpl['id'] ?>">
                        <div class="template-row-main">
                            <div class="template-row-title">
                                <strong><?= e($tpl['name']) ?></strong>
                                <?php if ((int) $tpl['is_default'] === 1): ?>
                                    <span class="default-marker">Default</span>
                                <?php endif; ?>
                            </div>
                            <div class="template-row-meta">
                                <?= e($categoryLabels[$tpl['category']] ?? $tpl['category']) ?>
                                &middot; Subjek: <?= e($tpl['subject_template'] ?: '-') ?>
                            </div>
                            <?php if (!empty($tpl['description'])): ?>
                                <div class="template-row-desc"><?= e($tpl['description']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="template-row-actions">
                            <button type="button" class="btn btn-secondary btn-sm" data-edit-template="<?= (int) $tpl['id'] ?>">Edit</button>
                            <a href="<?= e(base_url('template-preview.php?id=' . (int) $tpl['id'])) ?>" class="btn btn-secondary btn-sm">Preview</a>
                            <button type="button" class="btn btn-ghost btn-sm" data-duplicate-template="<?= (int) $tpl['id'] ?>">Gandakan</button>
                            <?php if ((int) $tpl['is_default'] !== 1): ?>
                                <button type="button" class="btn btn-ghost btn-sm" data-default-template="<?= (int) $tpl['id'] ?>">Jadikan Default</button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-ghost btn-sm text-danger" data-delete-template="<?= (int) $tpl['id'] ?>" data-tpl-name="<?= e($tpl['name']) ?>">Hapus</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Editor Form Column -->
    <section class="card" id="templateEditorCard">
        <div class="card-header">
            <div>
                <h2 class="card-title" id="templateFormHeading">Buat Template Baru</h2>
                <p class="card-subtitle">Desain header logo &amp; footer identitas resmi Support Septian F. R. otomatis membungkus konten ini.</p>
            </div>
        </div>

        <form id="templateForm">
            <input type="hidden" name="id" id="tplId" value="">
            <input type="hidden" name="action" id="tplAction" value="create">

            <div class="form-group">
                <label for="tplName">Nama Template</label>
                <input type="text" id="tplName" name="name" placeholder="Contoh: Konfirmasi Pembayaran" required>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="tplCategory">Kategori Aktivitas</label>
                    <select id="tplCategory" name="category">
                        <?php foreach ($categoryLabels as $key => $label): ?>
                            <option value="<?= e($key) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="tplSubject">Template Subjek</label>
                    <input type="text" id="tplSubject" name="subject_template" value="Re: {subjek}" placeholder="Re: {subjek}">
                </div>
            </div>

            <div class="form-group">
                <label for="tplDescription">Keterangan Singkat</label>
                <input type="text" id="tplDescription" name="description" placeholder="Kapan template ini digunakan">
            </div>

            <div class="var-insert-bar">
                <span class="var-label">Variabel Dinamis:</span>
                <?php foreach ($variables as $token => $desc): ?>
                    <button type="button" class="var-btn" data-insert-tpl-var="<?= e($token) ?>" title="<?= e($desc) ?>"><?= e($token) ?></button>
                <?php endforeach; ?>
            </div>

            <div class="form-group">
                <label for="tplBodyHtml">Isi HTML Template</label>
                <textarea id="tplBodyHtml" name="body_html" rows="10" class="code-textarea" required><p>Halo <strong>{nama_penerima}</strong>,</p>
<p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>.</p>
<p>{isi_pesan}</p>
<p>Apabila masih ada pertanyaan lain, silakan balas langsung email ini.</p></textarea>
            </div>

            <div class="form-group">
                <label class="inline-check">
                    <input type="checkbox" id="tplIsDefault" name="is_default" value="1">
                    <span>Tetapkan sebagai template default untuk kategori ini</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary" id="btnSaveTemplate">Simpan Template</button>
                <button type="button" class="btn btn-secondary" id="btnLivePreviewTemplate">Preview Hasil Email</button>
                <button type="button" class="btn btn-ghost" id="btnResetTemplateForm">Reset Form</button>
            </div>
        </form>
    </section>
</div>

<!-- Preview Modal -->
<div class="modal-backdrop" id="tplPreviewModal" hidden>
    <div class="modal-dialog modal-lg" role="dialog" aria-modal="true">
        <div class="modal-header">
            <h3 id="tplPreviewTitle">Preview Template Email</h3>
            <button type="button" class="icon-btn" id="closeTplPreviewModal">&times;</button>
        </div>
        <div class="modal-body" style="padding:0;">
            <iframe id="tplPreviewFrame" class="preview-iframe" title="Preview Template" sandbox="allow-same-origin"></iframe>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="closeTplPreviewBtn">Tutup</button>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
