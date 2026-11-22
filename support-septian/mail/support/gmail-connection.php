<?php
/**
 * Support Septian — Pusat Koneksi Gmail (Google OAuth 2.0 & Google App Password)
 * Path: /mail/support/gmail-connection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/includes/smtp-imap-service.php';

$currentAdmin = require_admin();
$pageTitle    = 'Koneksi Gmail';
$activePage   = 'gmail-connection';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $formType = (string) ($_POST['form_type'] ?? '');

    try {
        if ($formType === 'save_oauth') {
            $clientId     = trim((string) ($_POST['gmail_oauth_client_id'] ?? ''));
            $clientSecret = trim((string) ($_POST['gmail_oauth_client_secret'] ?? ''));

            save_setting('gmail_oauth_client_id', $clientId, false, (int) $currentAdmin['id']);
            if ($clientSecret !== '') {
                save_setting('gmail_oauth_client_secret', $clientSecret, true, (int) $currentAdmin['id']);
            }
            log_activity('oauth_config_updated', 'Konfigurasi Google OAuth Client ID/Secret diperbarui.', 'settings');
            set_flash('success', 'Konfigurasi Google OAuth 2.0 berhasil disimpan dengan enkripsi AES-256-GCM.');
        } elseif ($formType === 'save_app_password') {
            $officialEmail = strtolower(trim(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)));
            $appEmail      = strtolower(trim((string) ($_POST['gmail_app_password_email'] ?? $officialEmail)));
            $appPass       = str_replace(' ', '', trim((string) ($_POST['gmail_app_password_secret'] ?? '')));
            $smtpHost      = trim((string) ($_POST['gmail_smtp_host'] ?? 'smtp.gmail.com')) ?: 'smtp.gmail.com';
            $smtpPort      = (string) max(1, (int) ($_POST['gmail_smtp_port'] ?? 465));
            $smtpEnc       = in_array($_POST['gmail_smtp_encryption'] ?? 'ssl', ['ssl', 'tls'], true) ? $_POST['gmail_smtp_encryption'] : 'ssl';
            $imapHost      = trim((string) ($_POST['gmail_imap_host'] ?? 'imap.gmail.com')) ?: 'imap.gmail.com';
            $imapPort      = (string) max(1, (int) ($_POST['gmail_imap_port'] ?? 993));
            $activateNow   = !empty($_POST['activate_app_password']);

            if ($appEmail !== $officialEmail) {
                throw new RuntimeException('Email App Password harus sama dengan akun resmi aplikasi: ' . $officialEmail);
            }

            save_setting('gmail_app_password_email', $appEmail, false, (int) $currentAdmin['id']);
            if ($appPass !== '') {
                save_setting('gmail_app_password_secret', $appPass, true, (int) $currentAdmin['id']);
            }
            save_setting('gmail_smtp_host', $smtpHost, false, (int) $currentAdmin['id']);
            save_setting('gmail_smtp_port', $smtpPort, false, (int) $currentAdmin['id']);
            save_setting('gmail_smtp_encryption', $smtpEnc, false, (int) $currentAdmin['id']);
            save_setting('gmail_imap_host', $imapHost, false, (int) $currentAdmin['id']);
            save_setting('gmail_imap_port', $imapPort, false, (int) $currentAdmin['id']);

            if ($activateNow) {
                save_setting('mail_connection_mode', 'app_password', false, (int) $currentAdmin['id']);
            }

            log_activity('app_password_updated', 'Konfigurasi Google App Password SMTP/IMAP diperbarui.', 'settings');
            set_flash('success', 'Pengaturan Google App Password berhasil disimpan secara terenkripsi.');
        } elseif ($formType === 'switch_mode') {
            $mode = ($_POST['mail_connection_mode'] ?? 'oauth') === 'app_password' ? 'app_password' : 'oauth';
            save_setting('mail_connection_mode', $mode, false, (int) $currentAdmin['id']);
            log_activity('mail_mode_switched', 'Mode koneksi email diubah ke: ' . $mode, 'settings');
            set_flash('success', 'Mode koneksi aktif diubah ke: ' . ($mode === 'oauth' ? 'Google Gmail API (OAuth 2.0)' : 'Google App Password (SMTP/IMAP)') . '.');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }

    redirect_to('gmail-connection.php');
}

$settings   = get_all_settings(true);
$connStatus = get_mail_connection_status();
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Koneksi Google Gmail</h1>
        <p class="page-subtitle">Hubungkan akun resmi <strong><?= e($connStatus['official_email']) ?></strong> menggunakan Google Gmail API (OAuth 2.0) atau Google App Password</p>
    </div>
</div>

<!-- Active Transport Switcher -->
<section class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Status &amp; Metode Koneksi Aktif</h2>
            <p class="card-subtitle"><?= e($connStatus['active_label']) ?></p>
        </div>
        <form method="post" class="inline-mode-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="switch_mode">
            <select name="mail_connection_mode" aria-label="Pilih mode koneksi">
                <option value="oauth" <?= $connStatus['mode'] === 'oauth' ? 'selected' : '' ?>>Google Gmail API (OAuth 2.0)</option>
                <option value="app_password" <?= $connStatus['mode'] === 'app_password' ? 'selected' : '' ?>>Google App Password (SMTP + IMAP)</option>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm">Terapkan Mode</button>
        </form>
    </div>

    <?php if ($connStatus['notice'] !== ''): ?>
        <div class="alert alert-warning" style="margin-top:12px;">
            <span><?= e($connStatus['notice']) ?></span>
        </div>
    <?php endif; ?>
</section>

<div class="two-col-grid">
    <!-- Panel 1: Google Gmail API OAuth 2.0 -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">1. Google Gmail API (OAuth 2.0 Web Application)</h2>
                <p class="card-subtitle">Integrasi penuh Gmail API dengan refresh token otomatis &amp; verifikasi akun resmi</p>
            </div>
        </div>

        <div class="spec-box">
            <div class="spec-row">
                <span class="spec-label">Akun Resmi Wajib</span>
                <code class="spec-val"><?= e($connStatus['official_email']) ?></code>
            </div>
            <div class="spec-row">
                <span class="spec-label">Authorized JavaScript Origin</span>
                <code class="spec-val">https://septianfr.web.id</code>
            </div>
            <div class="spec-row">
                <span class="spec-label">Authorized Redirect URI</span>
                <code class="spec-val"><?= e(OAUTH_REDIRECT_URI) ?></code>
            </div>
            <div class="spec-row">
                <span class="spec-label">Scopes Gmail</span>
                <span class="spec-val">gmail.readonly, gmail.send, gmail.modify</span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Google API PHP Client</span>
                <span class="spec-val"><?= $connStatus['sdk_installed'] ? 'Terpasang (vendor/ siap)' : 'Belum terpasang (jalankan composer install)' ?></span>
            </div>
            <?php if ($connStatus['oauth_connected']): ?>
                <div class="spec-row">
                    <span class="spec-label">Status OAuth</span>
                    <span class="spec-val text-success"><strong>Terhubung</strong> &middot; Refresh terakhir <?= e(format_tanggal_id($connStatus['oauth_last_refresh'])) ?></span>
                </div>
            <?php endif; ?>
        </div>

        <form method="post" style="margin-top:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="save_oauth">

            <div class="form-group">
                <label for="gmail_oauth_client_id">Google OAuth Client ID</label>
                <input type="text" id="gmail_oauth_client_id" name="gmail_oauth_client_id" value="<?= e($settings['gmail_oauth_client_id']) ?>" placeholder="1234567890-xxxx.apps.googleusercontent.com">
            </div>

            <div class="form-group">
                <label for="gmail_oauth_client_secret">
                    Google OAuth Client Secret
                    <?= $connStatus['oauth_configured'] ? '<span class="muted">(Tersimpan terenkripsi — kosongkan jika tidak diubah)</span>' : '' ?>
                </label>
                <input type="password" id="gmail_oauth_client_secret" name="gmail_oauth_client_secret" placeholder="<?= $connStatus['oauth_configured'] ? '•••••••••••••••• (Terenkripsi di server)' : 'GOCSPX-...' ?>" autocomplete="new-password">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-secondary">Simpan Kredensial OAuth</button>
                <?php if ($connStatus['oauth_configured'] && $connStatus['sdk_installed']): ?>
                    <a href="<?= e(base_url('oauth/connect.php')) ?>" class="btn btn-primary">
                        <?= $connStatus['oauth_connected'] ? 'Hubungkan Ulang Gmail' : 'Hubungkan Gmail' ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>

        <?php if ($connStatus['oauth_connected'] || $connStatus['oauth_revoked']): ?>
            <form method="post" action="<?= e(base_url('oauth/disconnect.php')) ?>" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border-color);" onsubmit="return confirm('Putuskan koneksi Google Gmail OAuth 2.0 dan hapus token tersimpan?');">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-danger-outline btn-sm">Putuskan Koneksi OAuth</button>
            </form>
        <?php endif; ?>
    </section>

    <!-- Panel 2: Google App Password (SMTP + IMAP) -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">2. Google App Password (SMTP + IMAP)</h2>
                <p class="card-subtitle">Gunakan Sandi Aplikasi Google 16 digit untuk akun resmi tanpa perlu verifikasi layar OAuth</p>
            </div>
        </div>

        <div class="spec-box">
            <div class="spec-row">
                <span class="spec-label">Status App Password</span>
                <span class="spec-val"><?= $connStatus['app_password_set'] ? '<strong class="text-success">Tersimpan Terenkripsi</strong>' : 'Belum dikonfigurasi' ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Ekstensi PHP IMAP</span>
                <span class="spec-val"><?= $connStatus['imap_available'] ? 'Aktif (Inbox &amp; Folder siap dibaca)' : 'Tidak aktif (hanya SMTP kirim yang aktif)' ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Cara Buat App Password</span>
                <span class="spec-val">Google Account &rarr; Security &rarr; 2-Step Verification &rarr; App Passwords</span>
            </div>
        </div>

        <form method="post" id="appPasswordForm" style="margin-top:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="save_app_password">

            <div class="form-group">
                <label for="gmail_app_password_email">Email Gmail Resmi</label>
                <input type="email" id="gmail_app_password_email" name="gmail_app_password_email" value="<?= e($settings['gmail_app_password_email'] ?: $connStatus['official_email']) ?>" required>
            </div>

            <div class="form-group">
                <label for="gmail_app_password_secret">
                    Google App Password (16 Karakter)
                    <?= $connStatus['app_password_set'] ? '<span class="muted">(Tersimpan — kosongkan jika tidak diubah)</span>' : '' ?>
                </label>
                <input type="password" id="gmail_app_password_secret" name="gmail_app_password_secret" placeholder="<?= $connStatus['app_password_set'] ? '•••• •••• •••• •••• (Terenkripsi)' : 'abcd efgh ijkl mnop' ?>" autocomplete="new-password">
            </div>

            <div class="form-grid-3">
                <div class="form-group">
                    <label for="gmail_smtp_host">SMTP Host</label>
                    <input type="text" id="gmail_smtp_host" name="gmail_smtp_host" value="<?= e($settings['gmail_smtp_host']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="gmail_smtp_port">SMTP Port</label>
                    <input type="number" id="gmail_smtp_port" name="gmail_smtp_port" value="<?= e($settings['gmail_smtp_port']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="gmail_smtp_encryption">Enkripsi</label>
                    <select id="gmail_smtp_encryption" name="gmail_smtp_encryption">
                        <option value="ssl" <?= $settings['gmail_smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (465)</option>
                        <option value="tls" <?= $settings['gmail_smtp_encryption'] === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    </select>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="gmail_imap_host">IMAP Host</label>
                    <input type="text" id="gmail_imap_host" name="gmail_imap_host" value="<?= e($settings['gmail_imap_host']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="gmail_imap_port">IMAP Port (SSL)</label>
                    <input type="number" id="gmail_imap_port" name="gmail_imap_port" value="<?= e($settings['gmail_imap_port']) ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="inline-check">
                    <input type="checkbox" name="activate_app_password" value="1" <?= $connStatus['mode'] === 'app_password' ? 'checked' : '' ?>>
                    <span>Aktifkan Google App Password sebagai metode koneksi utama</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Simpan Google App Password</button>
                <button type="button" class="btn btn-secondary" id="btnTestAppPassword">Uji Koneksi SMTP &amp; IMAP</button>
            </div>
            <div id="testAppPasswordResult" class="test-result-box" hidden></div>
        </form>
    </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnTest = document.getElementById('btnTestAppPassword');
    const resultBox = document.getElementById('testAppPasswordResult');
    if (!btnTest) return;

    btnTest.addEventListener('click', async function () {
        btnTest.disabled = true;
        btnTest.textContent = 'Menguji koneksi...';
        resultBox.hidden = false;
        resultBox.className = 'test-result-box';
        resultBox.textContent = 'Menghubungi server SMTP & IMAP Gmail...';

        try {
            const payload = {
                email: document.getElementById('gmail_app_password_email').value,
                app_password: document.getElementById('gmail_app_password_secret').value
            };
            const res = await window.SupportApp.apiPost('api/test-app-password.php', payload);
            resultBox.className = 'test-result-box ' + (res.ok ? 'is-ok' : 'is-err');
            resultBox.textContent = res.message || (res.ok ? 'Koneksi berhasil!' : 'Koneksi gagal.');
        } catch (err) {
            resultBox.className = 'test-result-box is-err';
            resultBox.textContent = err.message || 'Gagal menguji koneksi.';
        } finally {
            btnTest.disabled = false;
            btnTest.textContent = 'Uji Koneksi SMTP & IMAP';
        }
    });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
