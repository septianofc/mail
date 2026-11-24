<?php
/**
 * Support Septian — Keamanan Admin, Ganti Kata Sandi & Audit Log
 * Path: /mail/support/security.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';

$currentAdmin = require_admin();
$pageTitle    = 'Keamanan Admin';
$activePage   = 'security';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $formType = (string) ($_POST['form_type'] ?? 'change_password');

    if ($formType === 'clear_error_logs') {
        try {
            $cleared = clear_error_logs();
            log_activity('error_logs_cleared', 'Admin membersihkan ' . $cleared . ' file log error server.', 'security', (string) $currentAdmin['id']);
            set_flash('success', 'Log error berhasil dibersihkan (' . $cleared . ' file).');
        } catch (Throwable $e) {
            set_flash('error', 'Gagal membersihkan log error: ' . $e->getMessage());
        }
        redirect_to('security.php#server-error-logs');
    }

    $currentPass = (string) ($_POST['current_password'] ?? '');
    $newPass     = (string) ($_POST['new_password'] ?? '');
    $confirmPass = (string) ($_POST['confirm_password'] ?? '');

    try {
        if ($currentPass === '' || $newPass === '') {
            throw new RuntimeException('Kata sandi saat ini dan kata sandi baru wajib diisi.');
        }
        if (strlen($newPass) < 8) {
            throw new RuntimeException('Kata sandi baru minimal 8 karakter.');
        }
        if ($newPass !== $confirmPass) {
            throw new RuntimeException('Konfirmasi kata sandi baru tidak cocok.');
        }

        $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $currentAdmin['id']]);
        $row = $stmt->fetch();

        if (!$row || !password_verify($currentPass, (string) $row['password_hash'])) {
            throw new RuntimeException('Kata sandi saat ini tidak sesuai.');
        }

        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $upd = db()->prepare('UPDATE admins SET password_hash = :h WHERE id = :id');
        $upd->execute([':h' => $newHash, ':id' => (int) $currentAdmin['id']]);

        session_regenerate_id(true);
        log_activity('password_changed', 'Admin mengubah kata sandi akun.', 'security', (string) $currentAdmin['id']);
        set_flash('success', 'Kata sandi admin berhasil diperbarui dan sesi diamankan ulang.');
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }

    redirect_to('security.php');
}

// Ambil riwayat aktivitas & percobaan login terbaru (dengan perlindungan try/catch)
$logs = [];
try {
    $logs = db()->query(
        'SELECT a.*, ad.name AS admin_name
         FROM activity_logs a
         LEFT JOIN admins ad ON ad.id = a.admin_id
         ORDER BY a.id DESC
         LIMIT 30'
    )->fetchAll();
} catch (Throwable $e) {
    app_log('error', 'security.php gagal memuat activity_logs: ' . $e->getMessage());
}

$failedRecent = 0;
try {
    $failedRecent = (int) db()->query(
        'SELECT COUNT(*) FROM login_attempts WHERE was_successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
    )->fetchColumn();
} catch (Throwable $e) {
    app_log('warning', 'security.php gagal memuat login_attempts: ' . $e->getMessage());
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443)
    || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

$serverErrorLogs = read_recent_error_logs(80);
$envChecks = [
    'Versi PHP'           => PHP_VERSION . (version_compare(PHP_VERSION, '8.1.0', '>=') ? ' (Ideal)' : ' (Disarankan 8.1+)'),
    'PDO MySQL'           => extension_loaded('pdo_mysql') ? 'Aktif' : 'Tidak Aktif',
    'OpenSSL (Enkripsi)'  => extension_loaded('openssl') ? 'Aktif' : 'Tidak Aktif',
    'MBString / DOM'      => (extension_loaded('mbstring') ? 'mbstring OK' : 'mbstring Fallback') . ' · ' . (class_exists('DOMDocument') ? 'DOM OK' : 'DOM Fallback'),
    'PHPMailer'           => class_exists(\PHPMailer\PHPMailer\PHPMailer::class) ? 'Terpasang' : 'Belum Terpasang',
    'Google API SDK'      => class_exists(\Google\Client::class) ? 'Terpasang' : 'Belum Terpasang',
    'Ekstensi PHP IMAP'   => function_exists('imap_open') ? 'Aktif' : 'Tidak Aktif',
    'Folder storage/logs' => (is_dir(LOGS_PATH) && is_writable(LOGS_PATH)) ? 'Dapat Ditulis (Aktif)' : 'Tidak Dapat Ditulis (CHMOD 755/750)',
];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Keamanan Admin</h1>
        <p class="page-subtitle">Ganti kata sandi, pantau perlindungan sesi, enkripsi kredensial, dan audit log aktivitas</p>
    </div>
</div>

<div class="two-col-grid" style="margin-bottom:20px;">
    <section class="card">
        <h2 class="card-title">Ubah Kata Sandi Admin</h2>
        <form method="post" style="margin-top:16px;">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="current_password">Kata Sandi Saat Ini</label>
                <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label for="new_password">Kata Sandi Baru (minimal 8 karakter)</label>
                <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
            </div>
            <div class="form-group">
                <label for="confirm_password">Konfirmasi Kata Sandi Baru</label>
                <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Perbarui Kata Sandi</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2 class="card-title">Status Perlindungan Sistem</h2>
        <div class="spec-box" style="margin-top:16px;">
            <div class="spec-row">
                <span class="spec-label">Enkripsi Token &amp; Secret</span>
                <span class="spec-val text-success">AES-256-GCM Aktif</span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Hash Kata Sandi</span>
                <span class="spec-val text-success">PHP password_hash() / password_verify()</span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Proteksi CSRF &amp; PDO</span>
                <span class="spec-val text-success">Token Sesi + Prepared Statements Asli</span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Rate Limiting Login</span>
                <span class="spec-val">Maks. <?= LOGIN_MAX_ATTEMPTS ?> gagal / <?= LOGIN_LOCKOUT_MINUTES ?> menit (<?= $failedRecent ?> gagal dalam 24 jam)</span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Koneksi HTTPS</span>
                <span class="spec-val <?= $isHttps ? 'text-success' : 'text-warning' ?>"><?= $isHttps ? 'Aktif (Cookie Secure aktif)' : 'Non-HTTPS (Aktifkan SSL di produksi)' ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Lokasi File Konfigurasi</span>
                <code class="spec-val"><?= e(basename(CONFIG_SOURCE_PATH)) ?> (dilindungi .htaccess)</code>
            </div>
        </div>
    </section>
</div>

<section class="card">
    <div class="card-header">
        <h2 class="card-title">Log Audit Aktivitas Terbaru (30 Terakhir)</h2>
    </div>
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Waktu</th>
                    <th>Admin</th>
                    <th>Aksi</th>
                    <th>Keterangan</th>
                    <th>IP</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="muted" style="text-align:center;padding:20px;">Belum ada catatan aktivitas.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <?php $logStatus = (string) ($log['status'] ?? 'success'); ?>
                        <tr>
                            <td class="tabular-nums nowrap"><?= e(format_tanggal_id($log['created_at'] ?? null)) ?></td>
                            <td class="nowrap"><?= e((string) ($log['admin_name'] ?: 'Sistem')) ?></td>
                            <td><code><?= e((string) ($log['action'] ?? '')) ?></code></td>
                            <td><?= e((string) ($log['description'] ?? '')) ?></td>
                            <td class="tabular-nums nowrap"><?= e((string) ($log['ip_address'] ?: '-')) ?></td>
                            <td><span class="status-text status-<?= e($logStatus) ?>"><?= e(ucfirst($logStatus)) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- Panel Diagnostik Server & Log Error PHP / Aplikasi -->
<section class="card" id="server-error-logs" style="margin-top:20px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Diagnostik Server &amp; Log Error PHP / Aplikasi</h2>
            <p class="card-subtitle">Pantau kesehatan lingkungan PHP dan lihat catatan error langsung dari <code>storage/logs/</code> (password &amp; token otomatis disensor)</p>
        </div>
        <div class="page-actions">
            <a href="<?= e(base_url('security.php#server-error-logs')) ?>" class="btn btn-secondary btn-sm">Muat Ulang Log</a>
            <form method="post" style="display:inline;" onsubmit="return confirm('Bersihkan seluruh isi file log error di storage/logs/?');">
                <?= csrf_field() ?>
                <input type="hidden" name="form_type" value="clear_error_logs">
                <button type="submit" class="btn btn-danger-outline btn-sm">Bersihkan Log</button>
            </form>
        </div>
    </div>

    <div class="spec-box" style="margin-bottom:16px;">
        <?php foreach ($envChecks as $label => $statusVal): ?>
            <div class="spec-row">
                <span class="spec-label"><?= e($label) ?></span>
                <span class="spec-val"><?= e($statusVal) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (empty($serverErrorLogs)): ?>
        <div class="alert alert-success">
            <span>Tidak ada catatan error di <code>storage/logs/</code>. Apabila terjadi error baru di server, detailnya akan otomatis muncul di sini.</span>
        </div>
    <?php else: ?>
        <?php foreach ($serverErrorLogs as $logFile): ?>
            <div style="margin-bottom:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;font-size:13px;">
                    <strong><code><?= e($logFile['file']) ?></code></strong>
                    <span class="muted">Diperbarui <?= e(format_tanggal_id($logFile['updated_at'])) ?> &middot; <?= e(format_bytes((int) $logFile['size'])) ?></span>
                </div>
                <pre class="code-textarea" style="padding:12px;background:var(--bg-canvas);border:1px solid var(--border-color);border-radius:var(--radius-sm);max-height:280px;overflow:auto;white-space:pre-wrap;font-size:12px;line-height:1.5;margin:0;"><?= e(implode("\n", $logFile['lines'])) ?></pre>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
