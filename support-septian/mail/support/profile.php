<?php
/**
 * Support Septian — Profil Admin
 * Path: /mail/support/profile.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';

$currentAdmin = require_admin();
$pageTitle    = 'Profil Admin';
$activePage   = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);

    $name     = trim((string) ($_POST['name'] ?? ''));
    $username = trim((string) ($_POST['username'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));
    $jobTitle = trim((string) ($_POST['job_title'] ?? ''));

    try {
        if ($name === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Nama, username, dan email yang valid wajib diisi.');
        }

        $upd = db()->prepare(
            'UPDATE admins SET name = :name, username = :user, email = :email, job_title = :job WHERE id = :id'
        );
        $upd->execute([
            ':name'  => $name,
            ':user'  => $username,
            ':email' => $email,
            ':job'   => $jobTitle ?: 'Customer Support Administrator',
            ':id'    => (int) $currentAdmin['id'],
        ]);

        log_activity('profile_updated', 'Admin memperbarui informasi profil akun.', 'admin', (string) $currentAdmin['id']);
        set_flash('success', 'Profil admin berhasil diperbarui.');
    } catch (Throwable $e) {
        set_flash('error', 'Gagal menyimpan profil: ' . $e->getMessage());
    }

    redirect_to('profile.php');
}

$currentAdmin = current_admin(true) ?? $currentAdmin;
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profil Admin</h1>
        <p class="page-subtitle">Informasi akun administrator yang mengelola layanan Support Septian F. R.</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('security.php')) ?>" class="btn btn-secondary">Keamanan &amp; Kata Sandi</a>
    </div>
</div>

<div class="two-col-grid">
    <section class="card">
        <h2 class="card-title">Edit Informasi Profil</h2>
        <form method="post" style="margin-top:16px;">
            <?= csrf_field() ?>
            <div class="form-group">
                <label for="name">Nama Lengkap (digunakan pada variabel {nama_pengirim})</label>
                <input type="text" id="name" name="name" value="<?= e((string) ($currentAdmin['name'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" value="<?= e((string) ($currentAdmin['username'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="email">Email Admin</label>
                <input type="email" id="email" name="email" value="<?= e((string) ($currentAdmin['email'] ?? '')) ?>" required>
            </div>
            <div class="form-group">
                <label for="job_title">Jabatan / Peran</label>
                <input type="text" id="job_title" name="job_title" value="<?= e((string) ($currentAdmin['job_title'] ?? '')) ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Simpan Profil</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2 class="card-title">Ringkasan Identitas Pengirim</h2>
        <div class="spec-box" style="margin-top:16px;">
            <div class="spec-row">
                <span class="spec-label">Identitas Resmi</span>
                <span class="spec-val"><strong><?= e(get_setting('official_name', DEFAULT_OFFICIAL_NAME)) ?></strong></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Email Pengirim Gmail</span>
                <code class="spec-val"><?= e(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL)) ?></code>
            </div>
            <div class="spec-row">
                <span class="spec-label">Website</span>
                <span class="spec-val"><?= e(get_setting('website_url', DEFAULT_WEBSITE_URL)) ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Alamat Resmi</span>
                <span class="spec-val"><?= e(get_setting('official_address', DEFAULT_ADDRESS)) ?></span>
            </div>
            <div class="spec-row">
                <span class="spec-label">Login Terakhir</span>
                <span class="spec-val"><?= e(format_tanggal_id($currentAdmin['last_login_at'])) ?> (IP: <?= e($currentAdmin['last_login_ip'] ?: '-') ?>)</span>
            </div>
        </div>
    </section>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
