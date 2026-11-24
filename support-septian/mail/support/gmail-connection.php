<?php
/**
 * Skyzz Mail — Pusat Koneksi Gmail Utama & Diagnostik SMTP/IMAP (Superadmin)
 * Path: /mail/support/gmail-connection.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/includes/smtp-imap-service.php';
require_once __DIR__ . '/includes/cache.php';

$currentAdmin = require_superadmin();
$pageTitle    = 'Koneksi Gmail & Diagnostik';
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
            $appEmail    = strtolower(trim((string) ($_POST['gmail_app_password_email'] ?? '')));
            $fromName    = trim((string) ($_POST['official_name'] ?? '')) ?: get_setting('official_name', DEFAULT_OFFICIAL_NAME);
            $appPass     = str_replace(' ', '', trim((string) ($_POST['gmail_app_password_secret'] ?? '')));
            $smtpHost    = trim((string) ($_POST['gmail_smtp_host'] ?? 'smtp.gmail.com')) ?: 'smtp.gmail.com';
            $smtpPort    = (string) max(1, (int) ($_POST['gmail_smtp_port'] ?? 465));
            $smtpEnc     = in_array(strtolower((string) ($_POST['gmail_smtp_encryption'] ?? 'ssl')), ['ssl', 'tls'], true)
                ? strtolower((string) ($_POST['gmail_smtp_encryption'] ?? 'ssl'))
                : 'ssl';
            $imapHost    = trim((string) ($_POST['gmail_imap_host'] ?? 'imap.gmail.com')) ?: 'imap.gmail.com';
            $imapPort    = (string) max(1, (int) ($_POST['gmail_imap_port'] ?? 993));
            $activateNow = !empty($_POST['activate_app_password']);
            $imapSent    = !empty($_POST['imap_append_sent']) ? '1' : '0';

            if (!filter_var($appEmail, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Alamat email akun SMTP tidak valid.');
            }

            save_setting('support_email', $appEmail, false, (int) $currentAdmin['id']);
            save_setting('official_name', $fromName, false, (int) $currentAdmin['id']);
            save_setting('gmail_app_password_email', $appEmail, false, (int) $currentAdmin['id']);
            if ($appPass !== '') {
                save_setting('gmail_app_password_secret', $appPass, true, (int) $currentAdmin['id']);
            }
            save_setting('gmail_smtp_host', $smtpHost, false, (int) $currentAdmin['id']);
            save_setting('gmail_smtp_port', $smtpPort, false, (int) $currentAdmin['id']);
            save_setting('gmail_smtp_encryption', $smtpEnc, false, (int) $currentAdmin['id']);
            save_setting('gmail_imap_host', $imapHost, false, (int) $currentAdmin['id']);
            save_setting('gmail_imap_port', $imapPort, false, (int) $currentAdmin['id']);
            save_setting('imap_append_sent', $imapSent, false, (int) $currentAdmin['id']);

            if ($activateNow) {
                save_setting('mail_connection_mode', 'app_password', false, (int) $currentAdmin['id']);
            }

            // Sinkronkan juga ke mailbox default agar pengguna yang memilih akun default otomatis memakai kredensial terbaru
            try {
                $defaultMb = db()->query('SELECT id FROM mailboxes WHERE is_default = 1 LIMIT 1')->fetch();
                if ($defaultMb) {
                    $params = [
                        ':em'    => $appEmail,
                        ':dn'    => $fromName,
                        ':su'    => $appEmail,
                        ':sh'    => $smtpHost,
                        ':sp'    => (int) $smtpPort,
                        ':se'    => $smtpEnc,
                        ':ih'    => $imapHost,
                        ':ip'    => (int) $imapPort,
                        ':id'    => (int) $defaultMb['id'],
                    ];
                    $sql = 'UPDATE mailboxes SET email = :em, display_name = :dn, smtp_username = :su, smtp_host = :sh, smtp_port = :sp, smtp_encryption = :se, imap_host = :ih, imap_port = :ip';
                    if ($appPass !== '') {
                        $sql .= ', smtp_password_enc = :ap';
                        $params[':ap'] = crypto_encrypt($appPass);
                    }
                    if ($activateNow) {
                        $sql .= ', provider_mode = "app_password"';
                    }
                    $sql .= ' WHERE id = :id';
                    db()->prepare($sql)->execute($params);
                }
            } catch (Throwable $mbErr) {
                app_log('warning', 'Sinkronisasi mailbox default gagal: ' . $mbErr->getMessage());
            }

            cache_flush_all();
            log_activity('app_password_updated', 'Konfigurasi Google App Password (' . $smtpHost . ':' . $smtpPort . ' ' . strtoupper($smtpEnc) . ') diperbarui.', 'settings');
            set_flash('success', 'Pengaturan Google App Password berhasil disimpan & disinkronkan ke akun email utama.');
        } elseif ($formType === 'switch_mode') {
            $mode = ($_POST['mail_connection_mode'] ?? 'oauth') === 'app_password' ? 'app_password' : 'oauth';
            save_setting('mail_connection_mode', $mode, false, (int) $currentAdmin['id']);
            cache_flush_all();
            log_activity('mail_mode_switched', 'Mode koneksi email utama diubah ke: ' . $mode, 'settings');
            set_flash('success', 'Mode koneksi aktif diubah ke: ' . ($mode === 'oauth' ? 'Google Gmail API (OAuth 2.0)' : 'Google App Password (SMTP/IMAP)') . '.');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }

    redirect_to('gmail-connection.php');
}

$settings   = get_all_settings(true);
$activeMb   = get_effective_mailbox($currentAdmin);
$connStatus = get_mail_connection_status($activeMb);
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Koneksi Gmail &amp; Diagnostik</h1>
        <p class="page-subtitle">Konfigurasi Gmail API (OAuth 2.0), Google App Password (SMTP/IMAP), dan sinkronisasi folder Terkirim Gmail untuk <strong><?= e($connStatus['official_email']) ?></strong></p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('mailboxes.php')) ?>" class="btn btn-primary btn-sm">Kelola Multi-Akun Email</a>
    </div>
</div>

<!-- Active Transport Switcher -->
<section class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Status &amp; Metode Koneksi Aktif</h2>
            <p class="card-subtitle"><?= e($connStatus['active_label']) ?> &middot; Akun aktif: <strong><?= e($activeMb['email'] ?? $connStatus['official_email']) ?></strong></p>
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
                <h2 class="card-title">1. Google Gmail API (OAuth 2.0)</h2>
                <p class="card-subtitle">Integrasi penuh Gmail API dengan refresh token otomatis &amp; pencatatan otomatis ke Terkirim Gmail</p>
            </div>
        </div>

        <div class="spec-box">
            <div class="spec-row">
                <span class="spec-label">Akun Email Utama</span>
                <code class="spec-val"><?= e($connStatus['official_email']) ?></code>
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

    <!-- Panel 2: Google App Password (PHPMailer SMTP SSL:465 + IMAP) -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">2. Google App Password (PHPMailer SMTP + IMAP)</h2>
                <p class="card-subtitle">Pengiriman terautentikasi via <code>smtp.gmail.com:465</code> (SSL) + pencatatan ke folder <strong>Terkirim / Sent</strong> Gmail</p>
            </div>
        </div>

        <div class="spec-box">
            <div class="spec-row">
                <span class="spec-label">Library PHPMailer</span>
                <span class="spec-val"><?= $connStatus['phpmailer_installed'] ? '<strong class="text-success">Terpasang &amp; Siap Digunakan</strong>' : '<strong class="text-danger">Belum terpasang (unggah ke includes/phpmailer/src/ atau Composer vendor/)</strong>' ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Status App Password</span>
                <span class="spec-val"><?= $connStatus['app_password_set'] ? '<strong class="text-success">Tersimpan Terenkripsi</strong>' : 'Belum dikonfigurasi' ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Ekstensi PHP IMAP</span>
                <span class="spec-val"><?= $connStatus['imap_available'] ? '<strong class="text-success">Aktif</strong> (Inbox &amp; sinkronisasi Terkirim Gmail siap)' : 'Tidak aktif (SMTP tetap jalan, riwayat terkirim dicatat lokal)' ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Multi-Akun Email</span>
                <span class="spec-val">Untuk menambah akun email sewaan lainnya, gunakan menu <a href="<?= e(base_url('mailboxes.php')) ?>"><strong>Kelola Akun Email</strong></a>.</span>
            </div>
        </div>

        <form method="post" id="appPasswordForm" style="margin-top:16px;">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="save_app_password">

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="gmail_app_password_email">Username SMTP &amp; Email Utama</label>
                    <input type="email" id="gmail_app_password_email" name="gmail_app_password_email" value="<?= e($settings['gmail_app_password_email'] ?: $settings['support_email']) ?>" required>
                </div>
                <div class="form-group">
                    <label for="official_name">Nama Pengirim Default (From Name)</label>
                    <input type="text" id="official_name" name="official_name" value="<?= e($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME) ?>" required>
                </div>
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
                    <input type="text" id="gmail_smtp_host" name="gmail_smtp_host" value="<?= e($settings['gmail_smtp_host'] ?: 'smtp.gmail.com') ?>" required>
                </div>
                <div class="form-group">
                    <label for="gmail_smtp_port">SMTP Port</label>
                    <input type="number" id="gmail_smtp_port" name="gmail_smtp_port" value="<?= e($settings['gmail_smtp_port'] ?: '465') ?>" required>
                </div>
                <div class="form-group">
                    <label for="gmail_smtp_encryption">Enkripsi</label>
                    <select id="gmail_smtp_encryption" name="gmail_smtp_encryption">
                        <option value="ssl" <?= ($settings['gmail_smtp_encryption'] ?? 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (465 · Disarankan)</option>
                        <option value="tls" <?= ($settings['gmail_smtp_encryption'] ?? 'ssl') === 'tls' ? 'selected' : '' ?>>STARTTLS (587)</option>
                    </select>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="gmail_imap_host">IMAP Host</label>
                    <input type="text" id="gmail_imap_host" name="gmail_imap_host" value="<?= e($settings['gmail_imap_host'] ?: 'imap.gmail.com') ?>" required>
                </div>
                <div class="form-group">
                    <label for="gmail_imap_port">IMAP Port (SSL)</label>
                    <input type="number" id="gmail_imap_port" name="gmail_imap_port" value="<?= e($settings['gmail_imap_port'] ?: '993') ?>" required>
                </div>
            </div>

            <div class="form-group">
                <label class="inline-check">
                    <input type="checkbox" name="activate_app_password" value="1" <?= $connStatus['mode'] === 'app_password' ? 'checked' : '' ?>>
                    <span>Aktifkan Google App Password (PHPMailer SMTP) sebagai metode utama</span>
                </label>
            </div>

            <div class="form-group">
                <label class="inline-check">
                    <input type="checkbox" name="imap_append_sent" value="1" <?= ($settings['imap_append_sent'] ?? '1') === '1' ? 'checked' : '' ?>>
                    <span>Pastikan salinan email terkirim selalu tercatat di folder <strong>Terkirim (Sent)</strong> Gmail (cek anti-duplikat via IMAP)</span>
                </label>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Simpan Google App Password</button>
                <button type="button" class="btn btn-secondary" id="btnTestAppPassword">Jalankan Diagnostik SMTP &amp; IMAP</button>
            </div>
            <div id="testAppPasswordResult" class="test-result-box" hidden></div>
        </form>
    </section>
</div>

<!-- Panel 3: Diagnostik Pengiriman Email & Tes Pengiriman Tunggal -->
<section class="card" style="margin-top:20px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">3. Diagnostik Pengiriman SMTP &amp; Tes Kirim Email</h2>
            <p class="card-subtitle">Verifikasi koneksi SMTP terautentikasi, header pengirim aktual, error SMTP (tanpa password), dan uji kirim 1 email percobaan</p>
        </div>
    </div>

    <div class="two-col-grid">
        <div>
            <div class="spec-box">
                <div class="spec-row">
                    <span class="spec-label">Status Koneksi SMTP</span>
                    <span class="spec-val" id="diagSmtpConnected">Belum diuji (klik Jalankan Diagnostik)</span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Status Autentikasi SMTP</span>
                    <span class="spec-val" id="diagSmtpAuth">Belum diuji (<code>SMTPAuth = true</code>)</span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Email Akun Aktif</span>
                    <code class="spec-val" id="diagAccountEmail"><?= e($activeMb['email'] ?? $connStatus['official_email']) ?></code>
                </div>
                <div class="spec-row">
                    <span class="spec-label">From Email Aktual</span>
                    <code class="spec-val" id="diagFromEmail"><?= e(($activeMb['display_name'] ?? DEFAULT_OFFICIAL_NAME) . ' <' . ($activeMb['email'] ?? $connStatus['official_email']) . '>') ?></code>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Metode Pengiriman Aktual</span>
                    <span class="spec-val" id="diagTransportMethod"><?= $connStatus['mode'] === 'app_password' ? 'PHPMailer SMTP (SSL · SMTPAuth=true)' : 'Google Gmail API (OAuth 2.0)' ?></span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Header Wajib Terverifikasi</span>
                    <span class="spec-val" id="diagHeaders"><code>From</code>, <code>Sender</code>, <code>Reply-To</code>, <code>Return-Path</code>, <code>Message-ID</code>, <code>MIME-Version: 1.0</code>, <code>Content-Type</code></span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Detail Error SMTP (Aman)</span>
                    <span class="spec-val" id="diagSmtpError">-</span>
                </div>
            </div>

            <div class="form-actions" style="margin-top:12px;">
                <button type="button" class="btn btn-secondary btn-sm" id="btnRunDiagnostics">Jalankan Diagnostik SMTP</button>
            </div>
            <pre id="diagTranscript" class="code-textarea" style="margin-top:12px;padding:12px;background:var(--bg-canvas);border:1px solid var(--border-color);border-radius:var(--radius-sm);max-height:200px;overflow:auto;white-space:pre-wrap;" hidden></pre>
        </div>

        <div>
            <h3 class="card-title" style="font-size:15px;margin-bottom:6px;">Tes Pengiriman Email (1 Email Percobaan)</h3>
            <p class="card-subtitle" style="margin-bottom:14px;">Kirim tepat 1 email tes ke alamat Gmail lain milik Anda untuk memverifikasi kelulusan DKIM dan pencatatan di folder Terkirim Gmail.</p>

            <div class="form-group">
                <label for="diagTestTo">Alamat Email Tujuan Tes</label>
                <input type="email" id="diagTestTo" placeholder="contoh: admin.pribadi@gmail.com" autocomplete="email">
            </div>

            <div class="form-actions">
                <button type="button" class="btn btn-primary" id="btnSendTestEmail">Kirim 1 Email Tes</button>
            </div>
            <div id="testSendResult" class="test-result-box" hidden></div>

            <div class="spec-box" style="margin-top:16px;">
                <div class="spec-row">
                    <span class="spec-label">DKIM @gmail.com</span>
                    <span class="spec-val">Ditangani otomatis oleh Google via <code>smtp.gmail.com:465</code> (tidak perlu DNS DKIM untuk <code>gmail.com</code>).</span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Cek Show Original</span>
                    <span class="spec-val">Di Gmail penerima, buka email tes &rarr; klik ikon <strong>&#8942;</strong> &rarr; <strong>Tampilkan Asli / Show Original</strong> &rarr; pastikan <code>DKIM: PASS</code>.</span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Cek Terkirim Gmail</span>
                    <span class="spec-val">Buka folder <strong>Terkirim (Sent)</strong> di Gmail atau halaman <strong>Email Terkirim</strong> Skyzz Mail untuk memastikan salinan tercatat tanpa duplikat.</span>
                </div>
            </div>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const btnTest       = document.getElementById('btnTestAppPassword');
    const btnRunDiag    = document.getElementById('btnRunDiagnostics');
    const resultBox     = document.getElementById('testAppPasswordResult');
    const diagConn      = document.getElementById('diagSmtpConnected');
    const diagAuth      = document.getElementById('diagSmtpAuth');
    const diagAccount   = document.getElementById('diagAccountEmail');
    const diagFrom      = document.getElementById('diagFromEmail');
    const diagMethod    = document.getElementById('diagTransportMethod');
    const diagErr       = document.getElementById('diagSmtpError');
    const diagHeaders   = document.getElementById('diagHeaders');
    const diagTrace     = document.getElementById('diagTranscript');

    const btnSendTest   = document.getElementById('btnSendTestEmail');
    const testToInput   = document.getElementById('diagTestTo');
    const testSendBox   = document.getElementById('testSendResult');

    function updateDiagView(res) {
        if (!res) return;
        diagConn.innerHTML = res.smtp_connected
            ? '<strong class="text-success">Terhubung (' + window.SupportApp.escapeHtml(res.smtp_host || 'smtp.gmail.com') + ':' + window.SupportApp.escapeHtml(res.smtp_port || '465') + ')</strong>'
            : '<strong class="text-danger">Gagal Terhubung</strong>';
        diagAuth.innerHTML = res.smtp_authenticated
            ? '<strong class="text-success">Terautentikasi (SMTPAuth=true)</strong>'
            : '<strong class="text-danger">Gagal Autentikasi</strong>';
        if (res.account_email) diagAccount.textContent = res.account_email;
        if (res.from_email) {
            diagFrom.textContent = (res.from_name || 'Skyzz Mail') + ' <' + res.from_email + '>';
        }
        if (res.transport_method) diagMethod.textContent = res.transport_method;
        diagErr.textContent = res.smtp_error ? res.smtp_error : '-';
        diagErr.className = 'spec-val ' + (res.smtp_error ? 'text-danger' : 'text-success');

        if (res.headers_verified && Object.keys(res.headers_verified).length > 0) {
            diagHeaders.textContent = Object.entries(res.headers_verified)
                .map(([k, v]) => k + ': ' + v)
                .join(' | ');
        }

        if (res.smtp_transcript) {
            diagTrace.hidden = false;
            diagTrace.textContent = res.smtp_transcript;
        }
    }

    async function runDiagnostics(triggerBtn, originalLabel) {
        triggerBtn.disabled = true;
        triggerBtn.textContent = 'Menguji SMTP...';
        resultBox.hidden = false;
        resultBox.className = 'test-result-box';
        resultBox.textContent = 'Menghubungi server SMTP via PHPMailer...';

        try {
            const payload = {
                email: document.getElementById('gmail_app_password_email').value,
                app_password: document.getElementById('gmail_app_password_secret').value
            };
            const res = await window.SupportApp.apiPost('api/test-app-password.php', payload);
            updateDiagView(res);
            resultBox.className = 'test-result-box ' + (res.ok ? 'is-ok' : 'is-err');
            resultBox.textContent = res.message || (res.ok ? 'Diagnostik SMTP berhasil!' : 'Diagnostik SMTP gagal.');
        } catch (err) {
            resultBox.className = 'test-result-box is-err';
            resultBox.textContent = err.message || 'Gagal menjalankan diagnostik.';
            diagConn.innerHTML = '<strong class="text-danger">Gagal</strong>';
            diagAuth.innerHTML = '<strong class="text-danger">Gagal</strong>';
            diagErr.textContent = err.message || 'Gagal menguji koneksi.';
            diagErr.className = 'spec-val text-danger';
        } finally {
            triggerBtn.disabled = false;
            triggerBtn.textContent = originalLabel;
        }
    }

    if (btnTest) {
        btnTest.addEventListener('click', () => runDiagnostics(btnTest, 'Jalankan Diagnostik SMTP & IMAP'));
    }
    if (btnRunDiag) {
        btnRunDiag.addEventListener('click', () => runDiagnostics(btnRunDiag, 'Jalankan Diagnostik SMTP'));
    }

    if (btnSendTest && testToInput) {
        btnSendTest.addEventListener('click', async function () {
            const target = testToInput.value.trim();
            if (!target || !target.includes('@')) {
                window.SupportApp.showToast('Masukkan alamat email tujuan tes yang valid.', 'error');
                testToInput.focus();
                return;
            }

            const confirmed = await window.SupportApp.confirmModal({
                title: 'Konfirmasi Kirim 1 Email Tes',
                body: 'Sistem akan mengirim <strong>1 email percobaan</strong> dari akun aktif <code>' +
                    window.SupportApp.escapeHtml(diagAccount.textContent) + '</code> ke <strong>' +
                    window.SupportApp.escapeHtml(target) + '</strong>.',
                okText: 'Kirim 1 Email Tes'
            });
            if (!confirmed) return;

            btnSendTest.disabled = true;
            btnSendTest.textContent = 'Mengirim 1 email tes...';
            testSendBox.hidden = false;
            testSendBox.className = 'test-result-box';
            testSendBox.textContent = 'Mengirim email tes melalui koneksi Gmail terautentikasi...';

            try {
                const res = await window.SupportApp.apiPost('api/test-send.php', { test_to: target });
                updateDiagView(res);
                testSendBox.className = 'test-result-box ' + (res.ok ? 'is-ok' : 'is-err');
                testSendBox.textContent = res.message || '1 email tes berhasil dikirim.';
                window.SupportApp.showToast('1 email tes berhasil dikirim.', 'success');
            } catch (err) {
                testSendBox.className = 'test-result-box is-err';
                testSendBox.textContent = err.message || 'Pengiriman email tes gagal.';
                diagErr.textContent = err.message || 'Pengiriman gagal.';
                diagErr.className = 'spec-val text-danger';
                window.SupportApp.showToast(err.message || 'Pengiriman email tes gagal.', 'error');
            } finally {
                btnSendTest.disabled = false;
                btnSendTest.textContent = 'Kirim 1 Email Tes';
            }
        });
    }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
