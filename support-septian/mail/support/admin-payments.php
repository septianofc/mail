<?php
/**
 * Skyzz Mail — Superadmin: Konfirmasi Manual Pembayaran QRIS All Payment
 * Path: /mail/support/admin-payments.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_superadmin();
$pageTitle    = 'Konfirmasi Pembayaran QRIS';
$activePage   = 'admin-payments';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $action  = trim((string) ($_POST['action'] ?? ''));
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $note    = trim((string) ($_POST['admin_note'] ?? ''));

    try {
        if ($action === 'approve' && $orderId > 0) {
            approve_payment_order($orderId, (int) $currentAdmin['id'], $note);
            set_flash('success', 'Pembayaran berhasil disetujui! Paket pengguna langsung aktif sesuai durasi pesanan.');
        } elseif ($action === 'reject' && $orderId > 0) {
            reject_payment_order($orderId, (int) $currentAdmin['id'], $note);
            set_flash('warning', 'Pesanan pembayaran telah ditolak.');
        }
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }
    redirect_to('admin-payments.php');
}

$filterStatus = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($filterStatus, ['all', 'pending', 'approved', 'rejected'], true)) {
    $filterStatus = 'all';
}

$orders = [];
try {
    $sql = 'SELECT o.*, u.name AS user_name, u.username, u.email AS user_email, p.name AS package_name
            FROM payment_orders o
            INNER JOIN admins u ON u.id = o.user_id
            LEFT JOIN packages p ON p.slug = o.package_slug';
    $params = [];
    if ($filterStatus !== 'all') {
        $sql .= ' WHERE o.status = :st';
        $params[':st'] = $filterStatus;
    }
    $sql .= ' ORDER BY (o.status = "pending") DESC, o.id DESC LIMIT 100';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
} catch (Throwable $e) {
    app_log('warning', 'admin-payments query error: ' . $e->getMessage());
}

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Konfirmasi Pembayaran QRIS</h1>
        <p class="page-subtitle">Periksa bukti transfer screenshot/foto dari pengguna lalu setujui atau tolak secara manual</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('admin-payments.php?status=all')) ?>" class="btn btn-sm <?= $filterStatus === 'all' ? 'btn-primary' : 'btn-secondary' ?>">Semua</a>
        <a href="<?= e(base_url('admin-payments.php?status=pending')) ?>" class="btn btn-sm <?= $filterStatus === 'pending' ? 'btn-primary' : 'btn-secondary' ?>">Menunggu</a>
        <a href="<?= e(base_url('admin-payments.php?status=approved')) ?>" class="btn btn-sm <?= $filterStatus === 'approved' ? 'btn-primary' : 'btn-secondary' ?>">Disetujui</a>
        <a href="<?= e(base_url('admin-payments.php?status=rejected')) ?>" class="btn btn-sm <?= $filterStatus === 'rejected' ? 'btn-primary' : 'btn-secondary' ?>">Ditolak</a>
    </div>
</div>

<section class="card">
    <?php if (empty($orders)): ?>
        <div class="empty-state">
            <p class="muted">Tidak ada pesanan pembayaran untuk filter ini.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Pesanan</th>
                        <th>Pengguna</th>
                        <th>Paket &amp; Total</th>
                        <th>Bukti Transfer</th>
                        <th>Status / Tindakan</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $ord): ?>
                        <tr>
                            <td>
                                <code><?= e((string) $ord['order_code']) ?></code>
                                <div class="muted" style="font-size:12px;"><?= e(format_tanggal_id((string) $ord['created_at'])) ?></div>
                                <?php if (!empty($ord['payer_note'])): ?>
                                    <div style="font-size:12px;margin-top:4px;">Catatan: <?= e((string) $ord['payer_note']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e((string) $ord['user_name']) ?></strong>
                                <div class="muted" style="font-size:12px;"><?= e((string) $ord['username']) ?> &middot; <?= e((string) $ord['user_email']) ?></div>
                            </td>
                            <td>
                                <strong><?= e((string) ($ord['package_name'] ?: strtoupper((string) $ord['package_slug']))) ?></strong> (<?= (int) $ord['duration_months'] ?> bln)<br>
                                <strong class="tabular-nums" style="color:var(--primary);"><?= e(format_rupiah((int) $ord['amount_total'])) ?></strong>
                            </td>
                            <td>
                                <a href="<?= e(base_url('api/media.php?type=proof&id=' . (int) $ord['id'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(base_url('api/media.php?type=proof&id=' . (int) $ord['id'])) ?>" alt="Bukti Bayar" class="proof-thumb">
                                </a>
                            </td>
                            <td style="min-width:220px;">
                                <?php if ($ord['status'] === 'pending'): ?>
                                    <form method="post" action="<?= e(base_url('admin-payments.php')) ?>" style="display:flex;flex-direction:column;gap:6px;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="order_id" value="<?= (int) $ord['id'] ?>">
                                        <input type="text" name="admin_note" placeholder="Catatan admin (opsional / alasan tolak)" style="font-size:12.5px;padding:6px 8px;">
                                        <div style="display:flex;gap:6px;">
                                            <button type="submit" name="action" value="approve" class="btn btn-primary btn-sm" onclick="return confirm('Setujui pembayaran dan aktifkan paket user ini?');">Setujui</button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm" onclick="return confirm('Tolak bukti pembayaran ini?');">Tolak</button>
                                        </div>
                                    </form>
                                <?php elseif ($ord['status'] === 'approved'): ?>
                                    <span class="badge-pill badge-success">Disetujui</span>
                                    <div class="muted" style="font-size:12px;margin-top:4px;"><?= e((string) ($ord['admin_note'] ?: '-')) ?></div>
                                <?php else: ?>
                                    <span class="badge-pill badge-danger">Ditolak</span>
                                    <div class="muted" style="font-size:12px;margin-top:4px;"><?= e((string) ($ord['admin_note'] ?: '-')) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
