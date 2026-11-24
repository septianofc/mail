<?php
/**
 * Skyzz Mail — Pengaturan Aplikasi Global, Branding, Kontak Legal & Cache (Superadmin)
 * Path: /mail/support/settings.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/includes/email-renderer.php';
require_once __DIR__ . '/includes/cache.php';

$currentAdmin = require_superadmin();
$pageTitle    = 'Pengaturan Aplikasi';
$activePage   = 'settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $formAction = (string) ($_POST['form_action'] ?? 'save_settings');

    try {
        if ($formAction === 'flush_cache') {
            $removed = cache_flush_all();
            log_activity('cache_flushed', 'Superadmin membersihkan cache server (' . $removed . ' file).', 'settings');
            set_flash('success', 'Cache server berhasil dibersihkan (' . $removed . ' file cache dihapus).');
            redirect_to('settings.php');
        }

        $appName         = trim((string) ($_POST['app_name'] ?? DEFAULT_APP_NAME)) ?: DEFAULT_APP_NAME;
        $officialName    = trim((string) ($_POST['official_name'] ?? DEFAULT_OFFICIAL_NAME)) ?: DEFAULT_OFFICIAL_NAME;
        $supportEmail    = strtolower(trim((string) ($_POST['support_email'] ?? DEFAULT_OFFICIAL_GMAIL)));
        $websiteUrl      = safe_http_url((string) ($_POST['website_url'] ?? DEFAULT_WEBSITE_URL), DEFAULT_WEBSITE_URL);
        $logoUrl         = safe_http_url((string) ($_POST['logo_url'] ?? DEFAULT_LOGO_URL), DEFAULT_LOGO_URL);
        $officialAddress = trim((string) ($_POST['official_address'] ?? DEFAULT_ADDRESS));
        $emailSignature  = sanitize_outgoing_body_html((string) ($_POST['email_signature'] ?? ''));
        $footerNote      = trim((string) ($_POST['footer_note'] ?? ''));
        $itemsPerPage    = (string) max(10, min(50, (int) ($_POST['items_per_page'] ?? 20)));

        $developerName   = trim((string) ($_POST['developer_name'] ?? 'Skyzz Mail')) ?: 'Skyzz Mail';
        $contactEmail    = strtolower(trim((string) ($_POST['contact_email'] ?? $supportEmail)));
        $contactWhatsapp = trim((string) ($_POST['contact_whatsapp'] ?? ''));
        $allowReg        = !empty($_POST['allow_public_registration']) ? '1' : '0';
        $imapAppendSent  = !empty($_POST['imap_append_sent']) ? '1' : '0';
        $freeWatermark   = trim((string) ($_POST['free_watermark_text'] ?? FREE_WATERMARK_DEFAULT)) ?: FREE_WATERMARK_DEFAULT;

        if (!filter_var($supportEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email Utama tidak valid.');
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Email Kontak Dukungan tidak valid.');
        }

        $toSave = [
            'app_name'                  => $appName,
            'official_name'             => $officialName,
            'support_email'             => $supportEmail,
            'website_url'               => $websiteUrl,
            'logo_url'                  => $logoUrl,
            'official_address'          => $officialAddress,
            'email_signature'           => $emailSignature,
            'footer_note'               => $footerNote,
            'items_per_page'            => $itemsPerPage,
            'developer_name'            => $developerName,
            'contact_email'             => $contactEmail ?: $supportEmail,
            'contact_whatsapp'          => $contactWhatsapp,
            'allow_public_registration' => $allowReg,
            'imap_append_sent'          => $imapAppendSent,
            'free_watermark_text'       => $freeWatermark,
        ];

        foreach ($toSave as $k => $v) {
            save_setting($k, $v, false, (int) $currentAdmin['id']);
        }

        cache_flush_all();
        log_activity('settings_updated', 'Superadmin memperbarui pengaturan aplikasi, identitas, watermark, dan kontak dukungan.', 'settings');
        set_flash('success', 'Seluruh pengaturan aplikasi Skyzz Mail berhasil disimpan.');
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
        <p class="page-subtitle">Superadmin bebas mengatur nama aplikasi, logo, branding default, watermark paket Free, registrasi publik, dan informasi kontak legal</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('mailboxes.php')) ?>" class="btn btn-secondary btn-sm">Kelola Akun Email</a>
        <a href="<?= e(base_url('admin-packages.php')) ?>" class="btn btn-secondary btn-sm">Paket &amp; QRIS</a>
        <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="flush_cache">
            <button type="submit" class="btn btn-secondary btn-sm">Bersihkan Cache Server</button>
        </form>
    </div>
</div>

<form method="post" class="card" id="branding">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save_settings">

    <h2 class="card-title">Identitas Aplikasi &amp; Branding Default</h2>
    <p class="card-subtitle" style="margin-bottom:16px;">Nama aplikasi, logo, dan identitas default ini digunakan sebagai bawaan sistem. User tetap dapat menyesuaikan nama pengirim, tanda tangan, dan template kustom mereka sendiri.</p>

    <div class="form-grid-2">
        <div class="form-group">
            <label for="app_name">Nama Aplikasi</label>
            <input type="text" id="app_name" name="app_name" value="<?= e($settings['app_name'] ?: DEFAULT_APP_NAME) ?>" required>
        </div>
        <div class="form-group">
            <label for="official_name">Nama Pengirim Default (From Name)</label>
            <input type="text" id="official_name" name="official_name" value="<?= e($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME) ?>" required>
        </div>
        <div class="form-group">
            <label for="support_email">Email Utama Sistem</label>
            <input type="email" id="support_email" name="support_email" value="<?= e($settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL) ?>" required>
            <small class="form-hint">Untuk menambah banyak email sewaan, gunakan halaman <a href="<?= e(base_url('mailboxes.php')) ?>">Kelola Akun Email</a>.</small>
        </div>
        <div class="form-group">
            <label for="website_url">Website Aplikasi</label>
            <input type="url" id="website_url" name="website_url" value="<?= e($settings['website_url'] ?: DEFAULT_WEBSITE_URL) ?>" required>
        </div>
    </div>

    <div class="form-grid-2">
        <div class="form-group">
            <label for="logo_url">URL Logo Aplikasi</label>
            <input type="url" id="logo_url" name="logo_url" value="<?= e($settings['logo_url'] ?: DEFAULT_LOGO_URL) ?>" required>
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
        <label for="official_address">Alamat Lengkap (Footer &amp; Halaman Legal)</label>
        <textarea id="official_address" name="official_address" rows="2" required><?= e($settings['official_address']) ?></textarea>
    </div>

    <hr class="section-divider">

    <h2 class="card-title">Pengaturan SaaS, Pendaftaran &amp; Watermark Paket Free</h2>
    <div class="form-group" style="margin-top:12px;">
        <label for="free_watermark_text">Teks Watermark Paket Free</label>
        <input type="text" id="free_watermark_text" name="free_watermark_text" value="<?= e($settings['free_watermark_text'] ?: FREE_WATERMARK_DEFAULT) ?>" required>
        <small class="form-hint">Otomatis disisipkan pada email pengguna paket Free. Pengguna paket Lite, Pro, dan Business tidak menampilkan watermark ini.</small>
    </div>

    <div class="form-grid-2">
        <div class="form-group">
            <label class="inline-check">
                <input type="checkbox" name="allow_public_registration" value="1" <?= ($settings['allow_public_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
                <span>Izinkan pendaftaran akun User baru secara publik (<code>register.php</code>)</span>
            </label>
        </div>
        <div class="form-group">
            <label class="inline-check">
                <input type="checkbox" name="imap_append_sent" value="1" <?= ($settings['imap_append_sent'] ?? '1') === '1' ? 'checked' : '' ?>>
                <span>Catat email terkirim via SMTP ke folder <strong>Terkirim (Sent)</strong> Gmail via IMAP</span>
            </label>
        </div>
    </div>

    <hr class="section-divider">

    <h2 class="card-title">Kontak Pengembang &amp; Dokumen Legal (Google Play Console)</h2>
    <p class="card-subtitle" style="margin-bottom:14px;">Informasi ini tampil otomatis pada halaman publik <a href="<?= e(base_url('legal.php')) ?>" target="_blank" rel="noopener">Dokumen Legal &amp; Bantuan</a>.</p>
    <div class="form-grid-3">
        <div class="form-group">
            <label for="developer_name">Nama Pengembang / Penyedia</label>
            <input type="text" id="developer_name" name="developer_name" value="<?= e($settings['developer_name'] ?: 'Skyzz Mail') ?>" required>
        </div>
        <div class="form-group">
            <label for="contact_email">Email Kontak &amp; Bantuan Resmi</label>
            <input type="email" id="contact_email" name="contact_email" value="<?= e($settings['contact_email'] ?: $settings['support_email']) ?>" required>
        </div>
        <div class="form-group">
            <label for="contact_whatsapp">Nomor WhatsApp Support (Opsional)</label>
            <input type="text" id="contact_whatsapp" name="contact_whatsapp" value="<?= e($settings['contact_whatsapp'] ?? '') ?>" placeholder="Contoh: +62 812-3456-7890">
        </div>
    </div>

    <hr class="section-divider">

    <h2 class="card-title">Tanda Tangan &amp; Catatan Footer Email Default</h2>
    <div class="form-group" style="margin-top:12px;">
        <label for="email_signature">HTML Tanda Tangan Email Default</label>
        <textarea id="email_signature" name="email_signature" rows="4" class="code-textarea"><?= e($settings['email_signature']) ?></textarea>
    </div>

    <div class="form-group">
        <label for="footer_note">Catatan Tambahan di Bagian Bawah Footer Default</label>
        <textarea id="footer_note" name="footer_note" rows="2"><?= e($settings['footer_note']) ?></textarea>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Simpan Semua Pengaturan</button>
        <a href="<?= e(base_url('template-preview.php')) ?>" class="btn btn-secondary">Preview Template</a>
        <a href="<?= e(base_url('legal.php')) ?>" class="btn btn-secondary">Lihat Dokumen Legal</a>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
