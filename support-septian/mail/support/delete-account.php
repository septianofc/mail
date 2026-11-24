<?php
/**
 * Skyzz Mail — Halaman Penghapusan Akun Permanen (Desktop & HP Android Mini)
 * Path: /mail/support/delete-account.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_admin();
$pageTitle    = 'Hapus Akun Permanen';
$activePage   = 'delete-account';
$appName      = get_setting('app_name', DEFAULT_APP_NAME);
$error        = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $password = (string) ($_POST['confirm_password'] ?? '');
    $agree    = !empty($_POST['confirm_delete']);

    if (!$agree) {
        $error = 'Centang kotak persetujuan penghapusan akun permanen terlebih dahulu.';
    } elseif ($password === '') {
        $error = 'Masukkan kata sandi akun Anda untuk konfirmasi keamanan.';
    } else {
        try {
            $uid = (int) $currentAdmin['id'];
            delete_user_account_permanently($uid, $password, false);
            logout_admin();
            session_start();
            set_flash('success', 'Akun dan seluruh data pribadi Anda telah dihapus secara permanen dari ' . $appName . '.');
            redirect_to('login.php');
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title" style="color:var(--danger);">Hapus Akun Permanen</h1>
        <p class="page-subtitle">Sesuai Account Deletion Policy <?= e($appName) ?> — penghapusan berlaku langsung dan tidak dapat dibatalkan</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('legal.php?doc=account-deletion')) ?>" class="btn btn-secondary btn-sm">Baca Kebijakan Hapus Akun</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php endif; ?>

<section class="card danger-zone-card">
    <h2 class="card-title" style="color:var(--danger);margin-bottom:8px;">Peringatan Sebelum Menghapus Akun</h2>
    <p style="font-size:14px;color:var(--text-muted);margin-bottom:14px;">
        Anda sedang masuk sebagai <strong><?= e((string) $currentAdmin['name']) ?></strong> (<code><?= e((string) $currentAdmin['username']) ?></code> &middot; <?= e((string) $currentAdmin['email']) ?>).
        Menghapus akun akan menghapus secara permanen:
    </p>
    <ul class="danger-bullet-list">
        <li>Akun login, profil, nama pengirim kustom, dan tanda tangan email Anda.</li>
        <li>Seluruh API Key pengiriman yang terhubung dengan akun Anda.</li>
        <li>Seluruh template email kustom pribadi milik Anda.</li>
        <li>Riwayat pembayaran QRIS beserta file foto bukti transfer yang pernah Anda unggah.</li>
        <li>Sisa masa aktif paket berbayar (jika ada) tidak dapat dipulihkan setelah akun dihapus.</li>
    </ul>

    <form method="post" action="<?= e(base_url('delete-account.php')) ?>" class="delete-account-form" style="margin-top:18px;">
        <?= csrf_field() ?>

        <div class="form-group">
            <label for="confirmPasswordInput">Masukkan Kata Sandi Anda untuk Konfirmasi</label>
            <input type="password" id="confirmPasswordInput" name="confirm_password" placeholder="Kata sandi akun saat ini" required autocomplete="current-password">
        </div>

        <label class="inline-check" style="align-items:flex-start;margin-bottom:16px;">
            <input type="checkbox" name="confirm_delete" value="1" required style="margin-top:3px;">
            <span style="font-size:13.5px;color:var(--text);">
                Saya memahami bahwa tindakan ini <strong>permanen</strong> dan seluruh data akun saya di <?= e($appName) ?> akan dihapus selamanya.
            </span>
        </label>

        <div style="display:flex;flex-wrap:wrap;gap:10px;">
            <button type="submit" class="btn btn-danger">Hapus Akun Secara Permanen</button>
            <a href="<?= e(base_url('profile.php')) ?>" class="btn btn-secondary">Batal &amp; Kembali ke Profil</a>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
