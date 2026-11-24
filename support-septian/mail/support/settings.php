<?php
/**
 * Support Septian — Pengaturan Aplikasi, Identitas Resmi, Tanda Tangan & Footer
 * Path: /mail/support/settings.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/includes/email-renderer.php';

$currentAdmin = require_admin();
$pageTitle    = 'Pengaturan Aplikasi';
$activePage   = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);

    try {
        $appName         = trim((string) ($_POST['app_name'] ?? DEFAULT_APP_NAME)) ?: DEFAULT_APP_NAME;
        $officialName    = DEFAULT_OFFICIAL_NAME;
        $supportEmail    = strtolower(DEFAULT_OFFICIAL_GMAIL);
        $postedEmail     = strtolower(trim((string) ($_POST['support_email'] ?? DEFAULT_OFFICIAL_GMAIL)));
        $websiteUrl      = safe_http_url((string) ($_POST['website_url'] ?? DEFAULT_WEBSITE_URL), DEFAULT_WEBSITE_URL);
        $logoUrl         = safe_http_url((string) ($_POST['logo_url'] ?? DEFAULT_LOGO_URL), DEFAULT_LOGO_URL);
        $officialAddress = trim((string) ($_POST['official_address'] ?? DEFAULT_ADDRESS));
        $emailSignature  = sanitize_outgoing_body_html((string) ($_POST['email_signature'] ?? ''));
        $footerNote      = trim((string) ($_POST['footer_note'] ?? ''));
        $itemsPerPage    = (string) max(10, min(50, (int) ($_POST['items_per_page'] ?? 20)));

        if ($postedEmail !== '' && $postedEmail !== $supportEmail) {
            throw new RuntimeException('Email Gmail Resmi dikunci ke ' . $supportEmail . ' agar otentikasi DKIM Gmail selalu valid.');
        }

        $toSave = [
            'app_name'                 => $appName,
            'official_name'            => $officialName,
            'support_email'            => $supportEmail,
            'gmail_app_password_email' => $supportEmail,
            'website_url'              => $websiteUrl,
            'logo_url'                 => $logoUrl,
            'official_address'         => $officialAddress,
            'email_signature'          => $emailSignature,
            'footer_note'              => $footerNote,
            'items_per_page'           => $itemsPerPage,
        ];

        foreach ($toSave as $k => $v) {
            save_setting($k, $v, false, (int) $currentAdmin['id']);
        }

        log_activity('settings_updated', 'Pengaturan identitas resmi, logo, tanda tangan, dan footer diperbarui.', 'settings');
        set_flash('success', 'Seluruh pengaturan identitas dan email Support Septian berhasil disimpan.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }

    redirect_to('settings.php');
}

$settings = get_all_settings(true);
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Pengaturan Aplikasi</h1>
        <p class="page-subtitle">Atur identitas resmi, logo, alamat footer, tanda tangan email, dan preferensi kotak masuk</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('gmail-connection.php')) ?>" class="btn btn-secondary">Koneksi Gmail &amp; Diagnostik SMTP</a>
    </div>
</div>

<form method="post" class="card" id="branding">
    <?= csrf_field() ?>

    <h2 class="card-title">Identitas Resmi &amp; Branding Email</h2>
    <p class="card-subtitle" style="margin-bottom:16px;">Identitas ini digunakan secara konsisten pada dashboard, profil pengirim, tanda tangan, dan footer setiap email keluar.</p>

    <div class="form-grid-2">
        <div class="form-group">
            <label for="app_name">Nama Aplikasi</label>
            <input type="text" id="app_name" name="app_name" value="<?= e($settings['app_name']) ?>" required>
        </div>
        <div class="form-group">
            <label for="official_name">Nama Pengirim Resmi (From Name)</label>
            <input type="text" id="official_name" name="official_name" value="<?= e(DEFAULT_OFFICIAL_NAME) ?>" readonly required>
            <small class="form-hint">Dikunci resmi sebagai <strong><?= e(DEFAULT_OFFICIAL_NAME) ?></strong>.</small>
        </div>
        <div class="form-group">
            <label for="support_email">Email Gmail Resmi (From &amp; SMTP Username)</label>
            <input type="email" id="support_email" name="support_email" value="<?= e(DEFAULT_OFFICIAL_GMAIL) ?>" readonly required>
            <small class="form-hint">Dikunci ke <code><?= e(DEFAULT_OFFICIAL_GMAIL) ?></code> agar DKIM Google lulus (<code>DKIM=PASS</code>).</small>
        </div>
        <div class="form-group">
            <label for="website_url">Website Resmi</label>
            <input type="url" id="website_url" name="website_url" value="<?= e($settings['website_url']) ?>" required>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group">
            <label for="logo_url">URL Logo Resmi</label>
            <input type="url" id="logo_url" name="logo_url" value="<?= e($settings['logo_url']) ?>" required>
            <small class="form-hint">Default resmi: <code>https://septianfr.web.id/logo.png</code></small>
        </div>
        <div class="form-group">
            <label for="items_per_page">Jumlah Email per Halaman</label>
            <select id="items_per_page" name="items_per_page">
                <?php foreach ([10, 15, 20, 30, 50] as $num): ?>
                    <option value="<?= $num ?>" <?= (int) $settings['items_per_page'] === $num ? 'selected' : '' ?>><?= $num ?> email</option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="form-group">
        <label for="official_address">Alamat Lengkap Resmi (Footer Email)</label>
        <textarea id="official_address" name="official_address" rows="2" required><?= e($settings['official_address']) ?></textarea>
    </div>

    <hr class="section-divider">

    <h2 class="card-title">Tanda Tangan &amp; Catatan Footer Email</h2>
    <div class="form-group" style="margin-top:12px;">
        <label for="email_signature">HTML Tanda Tangan Email</label>
        <textarea id="email_signature" name="email_signature" rows="5" class="code-textarea"><?= e($settings['email_signature']) ?></textarea>
    </div>

    <div class="form-group">
        <label for="footer_note">Catatan Tambahan di Bagian Paling Bawah Footer</label>
        <textarea id="footer_note" name="footer_note" rows="2"><?= e($settings['footer_note']) ?></textarea>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan Semua Pengaturan</button>
        <a href="<?= e(base_url('template-preview.php')) ?>" class="btn btn-secondary">Lihat Hasil pada Preview Template</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
