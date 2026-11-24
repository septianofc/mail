<?php
/**
 * Skyzz Mail — Superadmin: Kelola Pengguna, Paket Manual & Hak Akses Role
 * Path: /mail/support/admin-users.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_superadmin();
$pageTitle    = 'Kelola Pengguna';
$activePage   = 'admin-users';
$packages     = get_packages(false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $action = trim((string) ($_POST['action'] ?? ''));
    $targetId = (int) ($_POST['user_id'] ?? 0);

    try {
        if ($action === 'update_user' && $targetId > 0) {
            $role    = in_array(($_POST['role'] ?? 'user'), ['superadmin', 'user'], true) ? (string) $_POST['role'] : 'user';
            $pkgSlug = strtolower(trim((string) ($_POST['plan_slug'] ?? 'free')));
            $days    = (int) ($_POST['plan_days'] ?? 0);
            $active  = !empty($_POST['is_active']) ? 1 : 0;

            if ($targetId === (int) $currentAdmin['id'] && ($role !== 'superadmin' || $active === 0)) {
                throw new RuntimeException('Anda tidak dapat menurunkan role atau menonaktifkan akun Superadmin milik Anda sendiri.');
            }

            $expDate = null;
            if ($pkgSlug !== 'free') {
                if ($days > 0) {
                    $expDate = date('Y-m-d H:i:s', strtotime('+' . min(366, $days) . ' days'));
                } else {
                    $cur = db()->prepare('SELECT plan_expires_at FROM admins WHERE id = :id');
                    $cur->execute([':id' => $targetId]);
                    $existingExp = (string) ($cur->fetchColumn() ?: '');
                    $expDate = $existingExp !== '' ? $existingExp : date('Y-m-d H:i:s', strtotime('+30 days'));
                }
            }

            $upd = db()->prepare(
                'UPDATE admins SET role = :r, plan_slug = :p, plan_expires_at = :exp, is_active = :act WHERE id = :id'
            );
            $upd->execute([
                ':r'   => $role,
                ':p'   => $pkgSlug,
                ':exp' => $expDate,
                ':act' => $active,
                ':id'  => $targetId,
            ]);
            log_activity('user_updated', 'Superadmin memperbarui pengguna #' . $targetId . ' (role=' . $role . ', plan=' . $pkgSlug . ').', 'user', (string) $targetId);
            set_flash('success', 'Data pengguna berhasil diperbarui.');
        } elseif ($action === 'delete_user' && $targetId > 0) {
            if ($targetId === (int) $currentAdmin['id']) {
                throw new RuntimeException('Gunakan menu Hapus Akun jika ingin menghapus akun Anda sendiri.');
            }
            delete_user_account_permanently($targetId, '', true);
            log_activity('user_deleted', 'Superadmin menghapus akun pengguna #' . $targetId, 'user', (string) $targetId);
            set_flash('success', 'Akun pengguna berhasil dihapus permanen.');
        }
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }
    redirect_to('admin-users.php');
}

$users = [];
try {
    $today = date('Y-m-d');
    $stmt = db()->prepare(
        "SELECT a.id, a.name, a.username, a.email, a.role, a.plan_slug, a.plan_expires_at, a.is_active, a.last_login_at, a.created_at,
                m.email AS mailbox_email,
                (SELECT COUNT(*) FROM sent_email_logs s WHERE s.user_id = a.id AND s.sent_date = :today AND s.status = 'sent') AS sent_today
         FROM admins a
         LEFT JOIN mailboxes m ON m.id = a.selected_mailbox_id
         ORDER BY (a.role IN ('superadmin','admin')) DESC, a.id DESC"
    );
    $stmt->execute([':today' => $today]);
    $users = $stmt->fetchAll();
} catch (Throwable $e) {
    app_log('warning', 'admin-users query error: ' . $e->getMessage());
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Kelola Pengguna (<?= count($users) ?>)</h1>
        <p class="page-subtitle">Atur role Superadmin/User, paket langganan, masa aktif, dan status aktif seluruh pengguna</p>
    </div>
</div>

<section class="card">
    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Pengguna</th>
                    <th>Email Aktif &amp; Kirim Hari Ini</th>
                    <th>Paket &amp; Role</th>
                    <th>Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php $isSelf = (int) $u['id'] === (int) $currentAdmin['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e((string) $u['name']) ?></strong>
                            <?php if ($isSelf): ?><span class="badge-pill badge-primary">Anda</span><?php endif; ?>
                            <div class="muted" style="font-size:12px;">@<?= e((string) $u['username']) ?> &middot; <?= e((string) $u['email']) ?></div>
                            <div class="muted" style="font-size:11.5px;">Daftar: <?= e(format_tanggal_id((string) $u['created_at'], false)) ?></div>
                        </td>
                        <td>
                            <div style="font-size:12.5px;"><code><?= e((string) ($u['mailbox_email'] ?: 'Default')) ?></code></div>
                            <div class="muted" style="font-size:12px;margin-top:4px;">Terkirim hari ini: <strong><?= (int) $u['sent_today'] ?></strong> email</div>
                        </td>
                        <td colspan="2">
                            <form method="post" action="<?= e(base_url('admin-users.php')) ?>" class="inline-user-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="update_user">
                                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">

                                <select name="role" aria-label="Role">
                                    <option value="user" <?= ($u['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User</option>
                                    <option value="superadmin" <?= in_array(($u['role'] ?? ''), ['superadmin', 'admin'], true) ? 'selected' : '' ?>>Superadmin</option>
                                </select>

                                <select name="plan_slug" aria-label="Paket">
                                    <?php foreach ($packages as $pkg): ?>
                                        <option value="<?= e((string) $pkg['slug']) ?>" <?= strtolower((string) ($u['plan_slug'] ?? 'free')) === strtolower((string) $pkg['slug']) ? 'selected' : '' ?>>
                                            <?= e((string) $pkg['name']) ?> (<?= (int) $pkg['daily_send_limit'] ?>/hr)
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <input type="number" name="plan_days" min="0" max="366" placeholder="+Hari (ops.)" title="Set ulang masa aktif X hari dari sekarang (0 = tetap)" style="width:92px;">

                                <label class="inline-check">
                                    <input type="checkbox" name="is_active" value="1" <?= (int) $u['is_active'] === 1 ? 'checked' : '' ?>>
                                    <span>Aktif</span>
                                </label>

                                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                                <?php if (!$isSelf): ?>
                                    <button type="submit" name="action" value="delete_user" class="btn btn-danger btn-sm" onclick="return confirm('Hapus permanen akun <?= e((string) $u['username']) ?> beserta seluruh datanya?');">Hapus</button>
                                <?php endif; ?>
                            </form>
                            <?php if (!empty($u['plan_expires_at']) && ($u['plan_slug'] ?? 'free') !== 'free'): ?>
                                <div class="muted" style="font-size:11.5px;margin-top:4px;">Berakhir: <?= e(format_tanggal_id((string) $u['plan_expires_at'], false)) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
