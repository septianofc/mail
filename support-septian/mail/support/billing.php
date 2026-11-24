<?php
/**
 * Skyzz Mail — Paket Langganan & Pembayaran QRIS All Payment (1–12 Bulan)
 * Path: /mail/support/billing.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_admin();
$pageTitle    = 'Paket & Pembayaran QRIS';
$activePage   = 'billing';
$settings     = get_all_settings();
$packages     = get_packages(true);
$quota        = get_user_quota_status($currentAdmin);
$isSuper      = is_superadmin($currentAdmin);

// Proses unggah bukti pembayaran QRIS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $pkgSlug  = strtolower(trim((string) ($_POST['package_slug'] ?? '')));
    $duration = (int) ($_POST['duration_months'] ?? 1);
    $note     = trim((string) ($_POST['payer_note'] ?? ''));

    try {
        if (empty($_FILES['proof_image']) || (int) ($_FILES['proof_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Silakan pilih file screenshot atau foto bukti pembayaran QRIS terlebih dahulu.');
        }
        $order = create_payment_order((int) $currentAdmin['id'], $pkgSlug, $duration, $_FILES['proof_image'], $note);
        set_flash(
            'success',
            sprintf(
                'Bukti pembayaran pesanan %s (%s) berhasil dikirim! Menunggu konfirmasi manual oleh Admin.',
                $order['order_code'],
                format_rupiah((int) $order['amount'])
            )
        );
        redirect_to('billing.php');
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
        redirect_to('billing.php');
    }
}

// Ambil riwayat pesanan milik user
$myOrders = [];
try {
    $stmt = db()->prepare(
        'SELECT o.*, p.name AS package_name
         FROM payment_orders o
         LEFT JOIN packages p ON p.slug = o.package_slug
         WHERE o.user_id = :uid
         ORDER BY o.id DESC
         LIMIT 20'
    );
    $stmt->execute([':uid' => (int) $currentAdmin['id']]);
    $myOrders = $stmt->fetchAll();
} catch (Throwable $e) {
}

$qrisUploadedPath = trim((string) ($settings['qris_image_path'] ?? ''));
$qrisExternalUrl  = trim((string) ($settings['qris_image_url'] ?? ''));
$qrisSrc = '';
if ($qrisUploadedPath !== '') {
    $qrisSrc = base_url('api/media.php?type=qris&v=' . time());
} elseif ($qrisExternalUrl !== '') {
    $qrisSrc = safe_http_url($qrisExternalUrl, '');
}
$qrisMerchant     = (string) ($settings['qris_merchant_name'] ?: 'Skyzz Mail Official (QRIS All Payment)');
$qrisInstructions = (string) ($settings['qris_instructions'] ?: 'Scan QRIS All Payment di atas sesuai nominal total, lalu unggah screenshot atau foto bukti transfer untuk dikonfirmasi oleh Admin.');

$paidPackages = array_values(array_filter($packages, static fn(array $p): bool => (int) $p['price_monthly'] > 0));

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Paket Langganan &amp; QRIS All Payment</h1>
        <p class="page-subtitle">Pilih paket sesuai kebutuhan kirim harian Anda (terima email selalu tanpa batas &amp; pengiriman gagal tidak mengurangi kuota)</p>
    </div>
    <?php if ($isSuper): ?>
        <div class="page-actions">
            <a href="<?= e(base_url('admin-packages.php')) ?>" class="btn btn-secondary btn-sm">Atur Harga &amp; Gambar QRIS</a>
            <a href="<?= e(base_url('admin-payments.php')) ?>" class="btn btn-primary btn-sm">Konfirmasi Pembayaran</a>
        </div>
    <?php endif; ?>
</div>

<!-- Ringkasan Paket Aktif -->
<section class="card" style="margin-bottom:20px;">
    <div class="quota-overview-grid">
        <div>
            <div class="stat-label">Paket Aktif Anda</div>
            <div style="display:flex;align-items:center;gap:10px;margin-top:6px;flex-wrap:wrap;">
                <strong style="font-size:22px;"><?= e((string) $quota['plan']['name']) ?></strong>
                <?php if (!$quota['is_unlimited'] && !empty($quota['plan']['expires_at'])): ?>
                    <span class="badge-pill badge-success">Aktif s.d. <?= e(format_tanggal_id((string) $quota['plan']['expires_at'], false)) ?></span>
                <?php elseif (!$quota['is_unlimited']): ?>
                    <span class="badge-pill badge-neutral">Aktif Selamanya</span>
                <?php endif; ?>
            </div>
            <p class="muted" style="margin-top:6px;font-size:13px;">
                <?php if ($quota['is_unlimited']): ?>
                    Hak akses Superadmin: pengiriman tanpa batas harian &amp; bebas watermark.
                <?php else: ?>
                    Terima pesan: <strong>Tanpa Batas</strong> &middot;
                    API Pengiriman: <strong><?= !empty($quota['plan']['allow_api']) ? 'Aktif' : 'Nonaktif' ?></strong> &middot;
                    Watermark: <strong><?= !empty($quota['plan']['has_watermark']) ? e((string) $quota['plan']['watermark_text']) : 'Tidak Ada (Bersih)' ?></strong>
                <?php endif; ?>
            </p>
        </div>

        <div>
            <div class="stat-label">Pemakaian Kirim Hari Ini (WIB)</div>
            <?php if ($quota['is_unlimited']): ?>
                <div class="stat-value tabular-nums" style="margin-top:6px;"><?= (int) $quota['used_today'] ?> <span style="font-size:14px;color:var(--text-muted);font-weight:500;">/ Tanpa Batas</span></div>
            <?php else: ?>
                <div class="stat-value tabular-nums" style="margin-top:6px;">
                    <?= (int) $quota['used_today'] ?> <span style="font-size:15px;color:var(--text-muted);font-weight:500;">/ <?= (int) $quota['daily_limit'] ?> email</span>
                </div>
                <div class="quota-bar-track">
                    <div class="quota-bar-fill <?= $quota['percent_used'] >= 90 ? 'is-danger' : '' ?>" style="width: <?= (int) $quota['percent_used'] ?>%;"></div>
                </div>
                <small class="form-hint">Sisa hari ini: <strong><?= (int) $quota['remaining'] ?> email</strong> &middot; Reset otomatis pukul 00:00 WIB &middot; Email gagal kirim tidak mengurangi kuota.</small>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Daftar Paket -->
<section class="pricing-grid" style="margin-bottom:24px;">
    <?php foreach ($packages as $pkg): ?>
        <?php
        $isCurrent = strtolower((string) $quota['plan']['slug']) === strtolower((string) $pkg['slug']);
        $isFree    = (int) $pkg['price_monthly'] <= 0;
        ?>
        <div class="pricing-card <?= $isCurrent ? 'is-current' : '' ?>">
            <div class="pricing-head">
                <div class="pricing-name"><?= e((string) $pkg['name']) ?></div>
                <?php if ($isCurrent): ?>
                    <span class="badge-pill badge-primary">Paket Saat Ini</span>
                <?php endif; ?>
            </div>
            <div class="pricing-price">
                <?= e(format_rupiah((int) $pkg['price_monthly'])) ?>
                <?php if (!$isFree): ?><span>/ bulan</span><?php else: ?><span>selamanya</span><?php endif; ?>
            </div>
            <ul class="pricing-features">
                <li><strong><?= (int) $pkg['daily_send_limit'] ?> email/hari</strong> pengiriman keluar</li>
                <li><strong>Tanpa batas</strong> terima email masuk</li>
                <li><strong>Support REST API</strong> pengiriman email</li>
                <li>Gagal kirim <strong>tidak mengurangi</strong> limit</li>
                <li>Template kustom bebas</li>
                <?php if (!empty($pkg['has_watermark'])): ?>
                    <li class="feat-muted">Watermark: &ldquo;<?= e((string) ($pkg['watermark_text'] ?: FREE_WATERMARK_DEFAULT)) ?>&rdquo;</li>
                <?php else: ?>
                    <li><strong>Bebas watermark</strong> (tampilan bersih)</li>
                <?php endif; ?>
            </ul>
            <?php if (!$isFree): ?>
                <button type="button"
                        class="btn btn-primary btn-block btn-pick-package"
                        data-slug="<?= e((string) $pkg['slug']) ?>"
                        data-price="<?= (int) $pkg['price_monthly'] ?>">
                    Pilih Paket <?= e((string) $pkg['name']) ?>
                </button>
            <?php else: ?>
                <div class="pricing-free-note">Otomatis aktif untuk semua pengguna terdaftar</div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>

<!-- Form Pembayaran QRIS All Payment -->
<?php if (!empty($paidPackages)): ?>
<section class="card" id="qrisCheckoutCard" style="margin-bottom:24px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Upgrade Paket via QRIS All Payment</h2>
            <p class="card-subtitle">Pembelian paket tersedia mulai 1 bulan hingga maksimal 12 bulan (1 tahun). Konfirmasi manual oleh Admin setelah unggah bukti bayar.</p>
        </div>
    </div>

    <div class="qris-checkout-grid">
        <div class="qris-display-box">
            <div class="qris-merchant-title"><?= e($qrisMerchant) ?></div>
            <?php if ($qrisSrc !== ''): ?>
                <img src="<?= e($qrisSrc) ?>" alt="QRIS All Payment <?= e($qrisMerchant) ?>" class="qris-image-preview">
            <?php else: ?>
                <div class="qris-placeholder">
                    <strong>Gambar QRIS belum diunggah oleh Superadmin.</strong>
                    <p class="muted" style="margin-top:6px;">Superadmin dapat mengunggah gambar QRIS All Payment melalui menu <em>Paket &amp; QRIS Admin</em>.</p>
                </div>
            <?php endif; ?>
            <p class="qris-instructions"><?= nl2br(e($qrisInstructions)) ?></p>
        </div>

        <form method="post" action="<?= e(base_url('billing.php')) ?>" enctype="multipart/form-data" class="qris-upload-form">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="qrisPackageSelect">Pilih Paket Berbayar</label>
                <select id="qrisPackageSelect" name="package_slug" required>
                    <?php foreach ($paidPackages as $pp): ?>
                        <option value="<?= e((string) $pp['slug']) ?>" data-price="<?= (int) $pp['price_monthly'] ?>">
                            <?= e((string) $pp['name']) ?> — <?= e(format_rupiah((int) $pp['price_monthly'])) ?>/bulan (<?= (int) $pp['daily_send_limit'] ?> email/hari)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="qrisDurationSelect">Durasi Langganan (1 Bulan – Maks. 1 Tahun)</label>
                <select id="qrisDurationSelect" name="duration_months" required>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>"><?= $m ?> Bulan<?= $m === 12 ? ' (1 Tahun — Maksimal)' : '' ?></option>
                    <?php endfor; ?>
                </select>
            </div>

            <div class="qris-total-box">
                <span>Total Nominal yang Harus Dibayar:</span>
                <strong id="qrisTotalDisplay" class="tabular-nums"><?= e(format_rupiah((int) $paidPackages[0]['price_monthly'])) ?></strong>
            </div>

            <div class="form-group">
                <label for="proofImageInput">Unggah Bukti Pembayaran (Screenshot / Foto — JPG, PNG, WEBP, maks. 5 MB)</label>
                <input type="file" id="proofImageInput" name="proof_image" accept="image/jpeg,image/png,image/webp" required>
            </div>

            <div class="form-group">
                <label for="payerNoteInput">Catatan Pembayaran (Opsional — mis. nama pengirim / jam transfer)</label>
                <input type="text" id="payerNoteInput" name="payer_note" maxlength="250" placeholder="Contoh: Transfer via DANA a.n. Raka pukul 14:30 WIB">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Kirim Bukti Pembayaran untuk Konfirmasi Admin</button>
        </form>
    </div>
</section>
<?php endif; ?>

<!-- Riwayat Pembayaran User -->
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Riwayat Pembayaran Anda</h2>
            <p class="card-subtitle">Pantau status verifikasi manual dari Superadmin</p>
        </div>
    </div>

    <?php if (empty($myOrders)): ?>
        <div class="empty-state">
            <p class="muted">Belum ada riwayat pengajuan upgrade paket.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Kode Pesanan</th>
                        <th>Paket</th>
                        <th>Durasi</th>
                        <th>Total</th>
                        <th>Bukti</th>
                        <th>Status</th>
                        <th>Catatan Admin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($myOrders as $ord): ?>
                        <tr>
                            <td><code><?= e((string) $ord['order_code']) ?></code><br><small class="muted"><?= e(format_tanggal_id((string) $ord['created_at'])) ?></small></td>
                            <td><strong><?= e((string) ($ord['package_name'] ?: strtoupper((string) $ord['package_slug']))) ?></strong></td>
                            <td><?= (int) $ord['duration_months'] ?> bulan</td>
                            <td class="tabular-nums"><strong><?= e(format_rupiah((int) $ord['amount_total'])) ?></strong></td>
                            <td>
                                <a href="<?= e(base_url('api/media.php?type=proof&id=' . (int) $ord['id'])) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Lihat Bukti</a>
                            </td>
                            <td>
                                <?php if ($ord['status'] === 'approved'): ?>
                                    <span class="badge-pill badge-success">Disetujui</span>
                                <?php elseif ($ord['status'] === 'rejected'): ?>
                                    <span class="badge-pill badge-danger">Ditolak</span>
                                <?php else: ?>
                                    <span class="badge-pill badge-warning">Menunggu Konfirmasi</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) ($ord['admin_note'] ?: '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    const pkgSel = document.getElementById('qrisPackageSelect');
    const durSel = document.getElementById('qrisDurationSelect');
    const totalEl = document.getElementById('qrisTotalDisplay');

    function updateTotal() {
        if (!pkgSel || !durSel || !totalEl) return;
        const opt = pkgSel.options[pkgSel.selectedIndex];
        const price = parseInt(opt ? (opt.getAttribute('data-price') || '0') : '0', 10);
        const months = parseInt(durSel.value || '1', 10);
        const total = price * months;
        totalEl.textContent = 'Rp ' + total.toLocaleString('id-ID');
    }

    if (pkgSel) pkgSel.addEventListener('change', updateTotal);
    if (durSel) durSel.addEventListener('change', updateTotal);

    document.querySelectorAll('.btn-pick-package').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const slug = btn.getAttribute('data-slug');
            if (pkgSel && slug) {
                pkgSel.value = slug;
                updateTotal();
                const card = document.getElementById('qrisCheckoutCard');
                if (card) card.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });
    updateTotal();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
