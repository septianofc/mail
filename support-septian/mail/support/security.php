<?php
/**
 * Support Septian — Keamanan Admin, Ganti Kata Sandi & Audit Log
 * Path: /mail/support/security.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_admin();
$pageTitle    = 'Keamanan Admin';
$activePage   = 'security';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);

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

// Ambil riwayat aktivitas & percobaan login terbaru
$logs = db()->query(
    'SELECT a.*, ad.name AS admin_name
     FROM activity_logs a
     LEFT JOIN admins ad ON ad.id = a.admin_id
     ORDER BY a.id DESC
     LIMIT 30'
)->fetchAll();

$failedRecent = (int) db()->query(
    'SELECT COUNT(*) FROM login_attempts WHERE was_successful = 0 AND attempted_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)'
)->fetchColumn();

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);

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
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="tabular-nums nowrap"><?= e(format_tanggal_id($log['created_at'])) ?></td>
                        <td class="nowrap"><?= e($log['admin_name'] ?: 'Sistem') ?></td>
                        <td><code><?= e($log['action']) ?></code></td>
                        <td><?= e($log['description']) ?></td>
                        <td class="tabular-nums nowrap"><?= e($log['ip_address'] ?: '-') ?></td>
                        <td><span class="status-text status-<?= e($log['status']) ?>"><?= e(ucfirst($log['status'])) ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
