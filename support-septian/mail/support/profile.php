<?php
/**
 * Skyzz Mail — Profil Pengguna, Identitas Pengirim Kustom, Ganti Password & Hapus Akun
 * Path: /mail/support/profile.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-client.php';
require_once __DIR__ . '/includes/email-renderer.php';

$currentAdmin = require_admin();
$pageTitle    = 'Profil & Akun Saya';
$activePage   = 'profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $formType = (string) ($_POST['form_type'] ?? 'update_profile');

    try {
        if ($formType === 'change_password') {
            $currentPass = (string) ($_POST['current_password'] ?? '');
            $newPass     = (string) ($_POST['new_password'] ?? '');
            $confirmPass = (string) ($_POST['confirm_password'] ?? '');

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
            db()->prepare('UPDATE admins SET password_hash = :h WHERE id = :id')
                ->execute([':h' => $newHash, ':id' => (int) $currentAdmin['id']]);

            session_regenerate_id(true);
            log_activity('password_changed', 'Pengguna memperbarui kata sandi akun.', 'security', (string) $currentAdmin['id']);
            set_flash('success', 'Kata sandi akun berhasil diperbarui.');
        } else {
            $name             = trim((string) ($_POST['name'] ?? ''));
            $username         = trim((string) ($_POST['username'] ?? ''));
            $email            = strtolower(trim((string) ($_POST['email'] ?? '')));
            $jobTitle         = trim((string) ($_POST['job_title'] ?? ''));
            $customSenderName = trim((string) ($_POST['custom_sender_name'] ?? ''));
            $customSignature  = sanitize_outgoing_body_html((string) ($_POST['custom_signature'] ?? ''));
            $customFooterNote = trim((string) ($_POST['custom_footer_note'] ?? ''));
            $selectedMbId     = (int) ($_POST['selected_mailbox_id'] ?? 0);

            if ($name === '' || $username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Nama, username, dan email yang valid wajib diisi.');
            }
            if (!preg_match('/^[a-zA-Z0-9._-]{3,40}$/', $username)) {
                throw new RuntimeException('Username hanya boleh huruf, angka, titik, strip, atau underscore (3–40 karakter).');
            }

            // Cek duplikat username / email pada akun lain
            $dup = db()->prepare('SELECT id FROM admins WHERE (username = :u OR email = :e) AND id <> :id LIMIT 1');
            $dup->execute([':u' => $username, ':e' => $email, ':id' => (int) $currentAdmin['id']]);
            if ($dup->fetch()) {
                throw new RuntimeException('Username atau alamat email tersebut sudah digunakan akun lain.');
            }

            $mbToSave = null;
            if ($selectedMbId > 0) {
                $mb = get_mailbox_by_id($selectedMbId);
                if ($mb && (int) ($mb['is_active'] ?? 0) === 1) {
                    $mbToSave = $selectedMbId;
                }
            }

            $upd = db()->prepare(
                'UPDATE admins
                 SET name = :name,
                     username = :user,
                     email = :email,
                     job_title = :job,
                     custom_sender_name = :csn,
                     custom_signature = :csig,
                     custom_footer_note = :cfoot,
                     selected_mailbox_id = COALESCE(:mb, selected_mailbox_id)
                 WHERE id = :id'
            );
            $upd->execute([
                ':name'  => $name,
                ':user'  => $username,
                ':email' => $email,
                ':job'   => $jobTitle ?: (is_superadmin($currentAdmin) ? 'Superadmin' : 'Pengguna Skyzz Mail'),
                ':csn'   => $customSenderName !== '' ? $customSenderName : null,
                ':csig'  => $customSignature !== '' ? $customSignature : null,
                ':cfoot' => $customFooterNote !== '' ? $customFooterNote : null,
                ':mb'    => $mbToSave,
                ':id'    => (int) $currentAdmin['id'],
            ]);

            unset($_SESSION['admin_cache']);
            log_activity('profile_updated', 'Pengguna memperbarui profil & identitas pengirim kustom.', 'admin', (string) $currentAdmin['id']);
            set_flash('success', 'Profil dan identitas pengirim kustom berhasil diperbarui.');
        }
    } catch (Throwable $e) {
        set_flash('error', $e->getMessage());
    }

    redirect_to('profile.php');
}

$currentAdmin    = current_admin(true) ?? $currentAdmin;
$isSuper         = is_superadmin($currentAdmin);
$activeMailbox   = get_effective_mailbox($currentAdmin);
$activeMailboxes = get_selectable_mailboxes($isSuper);
$quota           = get_user_quota_status($currentAdmin);
$plan            = $quota['plan'];

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Profil &amp; Akun Saya</h1>
        <p class="page-subtitle">Atur informasi akun, pilih akun email sewaan, kustomisasi nama pengirim &amp; tanda tangan email, serta kelola keamanan akun Anda</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('billing.php')) ?>" class="btn btn-secondary btn-sm">Paket &amp; Upgrade QRIS</a>
        <a href="<?= e(base_url('api-keys.php')) ?>" class="btn btn-secondary btn-sm">API Key</a>
        <?php if ($isSuper): ?>
            <a href="<?= e(base_url('security.php')) ?>" class="btn btn-secondary btn-sm">Keamanan Server</a>
        <?php endif; ?>
    </div>
</div>

<div class="two-col-grid">
    <!-- Kolom Kiri: Profil & Identitas Pengirim Kustom -->
    <section class="card">
        <h2 class="card-title">Edit Profil &amp; Identitas Pengirim Kustom</h2>
        <p class="card-subtitle" style="margin-bottom:14px;">Anda bebas menentukan nama pengirim, tanda tangan, dan catatan footer sendiri pada setiap email keluar.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="form_type" value="update_profile">

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="name">Nama Lengkap</label>
                    <input type="text" id="name" name="name" value="<?= e((string) ($currentAdmin['name'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" value="<?= e((string) ($currentAdmin['username'] ?? '')) ?>" required>
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="email">Email Login / Kontak</label>
                    <input type="email" id="email" name="email" value="<?= e((string) ($currentAdmin['email'] ?? '')) ?>" required>
                </div>
                <div class="form-group">
                    <label for="job_title">Jabatan / Label</label>
                    <input type="text" id="job_title" name="job_title" value="<?= e((string) ($currentAdmin['job_title'] ?? '')) ?>">
                </div>
            </div>

            <?php if (!empty($activeMailboxes)): ?>
                <div class="form-group">
                    <label for="selected_mailbox_id">Pilih Akun Email Aktif (Disediakan Superadmin)</label>
                    <select id="selected_mailbox_id" name="selected_mailbox_id">
                        <?php foreach ($activeMailboxes as $mb): ?>
                            <option value="<?= (int) $mb['id'] ?>" <?= (int) ($activeMailbox['id'] ?? 0) === (int) $mb['id'] ? 'selected' : '' ?>>
                                <?= e($mb['label'] ?: ($mb['display_name'] ?? $mb['email'])) ?> — <?= e($mb['email']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <hr class="section-divider">

            <div class="form-group">
                <label for="custom_sender_name">Nama Pengirim Kustom (Tampil di Header &amp; From Name)</label>
                <input type="text" id="custom_sender_name" name="custom_sender_name" value="<?= e((string) ($currentAdmin['custom_sender_name'] ?? '')) ?>" placeholder="Contoh: Tim CS Toko Nusantara (kosongkan untuk memakai nama Anda)">
            </div>

            <div class="form-group">
                <label for="custom_signature">Tanda Tangan Email Kustom (HTML / Teks)</label>
                <textarea id="custom_signature" name="custom_signature" rows="4" class="code-textarea" placeholder="Hormat kami,&#10;Tim Anda"><?= e((string) ($currentAdmin['custom_signature'] ?? '')) ?></textarea>
            </div>

            <div class="form-group">
                <label for="custom_footer_note">Catatan Footer Email Kustom (Opsional)</label>
                <textarea id="custom_footer_note" name="custom_footer_note" rows="2" placeholder="Contoh: Email ini dikirim oleh layanan pelanggan resmi kami."><?= e((string) ($currentAdmin['custom_footer_note'] ?? '')) ?></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Simpan Profil &amp; Identitas</button>
            </div>
        </form>
    </section>

    <!-- Kolom Kanan: Ringkasan Paket, Ganti Kata Sandi & Hapus Akun -->
    <div style="display:flex;flex-direction:column;gap:20px;">
        <section class="card">
            <h2 class="card-title">Ringkasan Akun &amp; Paket Langganan</h2>
            <div class="spec-box" style="margin-top:14px;">
                <div class="spec-row">
                    <span class="spec-label">Peran Akun</span>
                    <span class="spec-val"><strong><?= $isSuper ? 'Superadmin' : 'User' ?></strong></span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Paket Aktif</span>
                    <span class="spec-val">
                        <strong><?= e((string) ($plan['name'] ?? 'Free')) ?></strong>
                        <?php if (!empty($plan['expires_at'])): ?>
                            &middot; Aktif s/d <?= e(format_tanggal_id($plan['expires_at'])) ?>
                        <?php else: ?>
                            &middot; Aktif Selamanya
                        <?php endif; ?>
                    </span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Kuota Kirim Hari Ini</span>
                    <span class="spec-val tabular-nums">
                        <?php if (!empty($quota['is_unlimited'])): ?>
                            <strong><?= (int) $quota['used_today'] ?> / Tanpa Batas</strong> (Superadmin)
                        <?php else: ?>
                            <strong><?= (int) $quota['used_today'] ?> / <?= (int) $quota['daily_limit'] ?></strong> email
                            (Sisa <?= (int) $quota['remaining'] ?> · Terima Pesan: Tanpa Batas)
                        <?php endif; ?>
                    </span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Akun Email Aktif</span>
                    <code class="spec-val"><?= e((string) ($activeMailbox['email'] ?? '-')) ?></code>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Watermark Template</span>
                    <span class="spec-val"><?= !empty($plan['has_watermark']) ? 'Aktif (Paket Free)' : 'Nonaktif (Bebas Watermark)' ?></span>
                </div>
                <div class="spec-row">
                    <span class="spec-label">Login Terakhir</span>
                    <span class="spec-val"><?= e(format_tanggal_id($currentAdmin['last_login_at'])) ?> (IP: <?= e($currentAdmin['last_login_ip'] ?: '-') ?>)</span>
                </div>
            </div>
            <div class="form-actions" style="margin-top:14px;">
                <a href="<?= e(base_url('billing.php')) ?>" class="btn btn-primary btn-sm">Upgrade Paket (QRIS)</a>
                <a href="<?= e(base_url('api-keys.php')) ?>" class="btn btn-secondary btn-sm">Kelola API Key</a>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title">Ubah Kata Sandi</h2>
            <form method="post" style="margin-top:14px;">
                <?= csrf_field() ?>
                <input type="hidden" name="form_type" value="change_password">
                <div class="form-group">
                    <label for="current_password">Kata Sandi Saat Ini</label>
                    <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
                </div>
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="new_password">Kata Sandi Baru (min. 8 karakter)</label>
                        <input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Konfirmasi Kata Sandi Baru</label>
                        <input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password">
                    </div>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-secondary">Perbarui Kata Sandi</button>
                </div>
            </form>
        </section>

        <!-- Zona Hapus Akun (Sesuai Account Deletion Policy — Desktop & HP Android Mini) -->
        <section class="card danger-zone-card">
            <h2 class="card-title text-danger">Hapus Akun Permanen</h2>
            <p class="card-subtitle" style="margin-top:6px;">
                Sesuai <strong>Account Deletion Policy</strong>, Anda berhak menghapus akun Skyzz Mail beserta API Key, template pribadi, riwayat order, dan log aktivitas Anda secara permanen kapan saja.
            </p>
            <div class="form-actions" style="margin-top:14px;">
                <a href="<?= e(base_url('delete-account.php')) ?>" class="btn btn-danger-outline">Hapus Akun Saya</a>
                <a href="<?= e(base_url('legal.php?doc=account-deletion')) ?>" class="btn btn-ghost btn-sm">Baca Kebijakan Penghapusan Akun</a>
            </div>
        </section>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
