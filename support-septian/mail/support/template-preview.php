<?php
/**
 * Support Septian — Halaman Preview Template Email (Desktop & Mobile)
 * Path: /mail/support/template-preview.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email-renderer.php';

$currentAdmin = require_admin();
$pageTitle    = 'Preview Template Email';
$activePage   = 'template-preview';

$templates  = get_templates();
$selectedId = (int) ($_GET['id'] ?? ($templates[0]['id'] ?? 0));
$activeTpl  = null;
foreach ($templates as $t) {
    if ((int) $t['id'] === $selectedId) {
        $activeTpl = $t;
        break;
    }
}
if (!$activeTpl && !empty($templates)) {
    $activeTpl = $templates[0];
}

$sampleVars = [
    'nama_penerima'  => 'Budi Santoso',
    'email_penerima' => 'budi.santoso@contoh.id',
    'subjek'         => 'Permintaan Bantuan Aktivasi Layanan',
    'nama_pengirim'  => $currentAdmin['name'] ?: DEFAULT_OFFICIAL_NAME,
    'isi_pesan'      => 'Tim Support Septian F. R. telah menerima laporan Anda dan melakukan verifikasi pada akun terkait. Berikut detail langkah yang dapat Anda ikuti selanjutnya.',
];

$renderedSubject = $activeTpl ? replace_template_variables((string) $activeTpl['subject_template'], $sampleVars, false) : 'Support Septian';
$renderedHtml    = $activeTpl
    ? render_professional_email_html((string) $activeTpl['body_html'], $renderedSubject, $sampleVars, true)
    : '<p>Belum ada template.</p>';

$extraScripts = ['templates.js'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Preview Template Email</h1>
        <p class="page-subtitle">Uji tampilan responsif email HTML Support Septian F. R. pada layar Desktop, Android, dan iPhone</p>
    </div>
    <div class="page-actions">
        <div class="segmented-control" id="viewportToggle">
            <button type="button" class="seg-btn is-active" data-viewport="desktop">Desktop (600px)</button>
            <button type="button" class="seg-btn" data-viewport="mobile">Mobile (375px)</button>
        </div>
        <a href="<?= e(base_url('templates.php')) ?>" class="btn btn-secondary btn-sm">Kelola Template</a>
    </div>
</div>

<div class="preview-workspace">
    <section class="card preview-controls-card">
        <h2 class="card-title">Pengaturan Simulasi Variabel</h2>

        <div class="form-group">
            <label for="previewTemplateSelect">Pilih Template</label>
            <select id="previewTemplateSelect">
                <?php foreach ($templates as $t): ?>
                    <option value="<?= (int) $t['id'] ?>"
                            data-subject="<?= e($t['subject_template']) ?>"
                            data-body="<?= e($t['body_html']) ?>"
                            <?= $activeTpl && (int) $t['id'] === (int) $activeTpl['id'] ? 'selected' : '' ?>>
                        [<?= e(strtoupper($t['category'])) ?>] <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="simNamaPenerima">{nama_penerima}</label>
            <input type="text" id="simNamaPenerima" value="<?= e($sampleVars['nama_penerima']) ?>">
        </div>

        <div class="form-group">
            <label for="simEmailPenerima">{email_penerima}</label>
            <input type="email" id="simEmailPenerima" value="<?= e($sampleVars['email_penerima']) ?>">
        </div>

        <div class="form-group">
            <label for="simSubjek">{subjek}</label>
            <input type="text" id="simSubjek" value="<?= e($sampleVars['subjek']) ?>">
        </div>

        <div class="form-group">
            <label for="simIsiPesan">{isi_pesan}</label>
            <textarea id="simIsiPesan" rows="5"><?= e($sampleVars['isi_pesan']) ?></textarea>
        </div>

        <button type="button" class="btn btn-primary btn-block" id="btnRefreshPreviewPage">Perbarui Preview</button>
    </section>

    <section class="card preview-stage-card">
        <div class="preview-mail-meta">
            <div><strong>Dari:</strong> <?= e(get_setting('official_name', DEFAULT_OFFICIAL_NAME)) ?> &lt;<?= e(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)) ?>&gt;</div>
            <div><strong>Subjek:</strong> <span id="previewRenderedSubject"><?= e($renderedSubject) ?></span></div>
        </div>
        <div class="preview-stage" id="previewStage">
            <iframe id="standalonePreviewFrame"
                    class="standalone-preview-frame"
                    title="Preview Email Support Septian"
                    sandbox="allow-same-origin"
                    srcdoc="<?= e($renderedHtml) ?>"></iframe>
        </div>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
