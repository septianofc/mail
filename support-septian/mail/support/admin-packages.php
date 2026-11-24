<?php
/**
 * Skyzz Mail — Superadmin: Kelola Paket Langganan (Free/Lite/Pro/Business) & Gambar QRIS All Payment
 * Path: /mail/support/admin-packages.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_superadmin();
$pageTitle    = 'Kelola Paket & QRIS';
$activePage   = 'admin-packages';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'save_packages') {
            $slugs = $_POST['slug'] ?? [];
            if (is_array($slugs)) {
                $upd = db()->prepare(
                    'UPDATE packages
                     SET name = :name, price_monthly = :price, daily_send_limit = :lim,
                         allow_api = :api, has_watermark = :hwm, watermark_text = :wmt,
                         description = :desc, is_active = :act
                     WHERE slug = :slug'
                );
                foreach ($slugs as $slug) {
                    $s = strtolower(trim((string) $slug));
                    if ($s === '') {
                        continue;
                    }
                    $upd->execute([
                        ':name'  => substr(trim((string) ($_POST['name'][$s] ?? ucfirst($s))), 0, 75),
                        ':price' => max(0, (int) ($_POST['price_monthly'][$s] ?? 0)),
                        ':lim'   => max(1, (int) ($_POST['daily_send_limit'][$s] ?? 10)),
                        ':api'   => !empty($_POST['allow_api'][$s]) ? 1 : 0,
                        ':hwm'   => !empty($_POST['has_watermark'][$s]) ? 1 : 0,
                        ':wmt'   => substr(trim((string) ($_POST['watermark_text'][$s] ?? '')), 0, 250),
                        ':desc'  => substr(trim((string) ($_POST['description'][$s] ?? '')), 0, 250),
                        ':act'   => !empty($_POST['is_active'][$s]) ? 1 : 0,
                        ':slug'  => $s,
                    ]);
                }
                cache_forget_prefix('packages_');
                log_activity('packages_updated', 'Superadmin memperbarui harga & konfigurasi paket langganan.', 'packages');
                set_flash('success', 'Konfigurasi seluruh paket (harga, limit harian, API, dan watermark) berhasil disimpan.');
            }
            redirect_to('admin-packages.php');
        } elseif ($action === 'save_qris') {
            $merchant = trim((string) ($_POST['qris_merchant_name'] ?? ''));
            $inst     = trim((string) ($_POST['qris_instructions'] ?? ''));
            $urlInput = trim((string) ($_POST['qris_image_url'] ?? ''));

            save_setting('qris_merchant_name', $merchant ?: 'Skyzz Mail Official (QRIS All Payment)', false, (int) $currentAdmin['id']);
            save_setting('qris_instructions', $inst, false, (int) $currentAdmin['id']);
            save_setting('qris_image_url', $urlInput !== '' ? safe_http_url($urlInput, '') : '', false, (int) $currentAdmin['id']);

            if (!empty($_FILES['qris_image']) && (int) ($_FILES['qris_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $savedRel = save_uploaded_image_safe($_FILES['qris_image'], 'qris');
                save_setting('qris_image_path', $savedRel, false, (int) $currentAdmin['id']);
            } elseif (!empty($_POST['remove_uploaded_qris'])) {
                save_setting('qris_image_path', '', false, (int) $currentAdmin['id']);
            }

            log_activity('qris_updated', 'Superadmin memperbarui pengaturan QRIS All Payment.', 'settings');
            set_flash('success', 'Pengaturan dan gambar QRIS All Payment berhasil diperbarui.');
            redirect_to('admin-packages.php');
        }
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
        redirect_to('admin-packages.php');
    }
}

$packages = get_packages(false);
$settings = get_all_settings(true);
$qrisUploaded = trim((string) ($settings['qris_image_path'] ?? ''));
$qrisUrl      = trim((string) ($settings['qris_image_url'] ?? ''));
$qrisPreview  = $qrisUploaded !== '' ? base_url('api/media.php?type=qris&v=' . time()) : ($qrisUrl !== '' ? safe_http_url($qrisUrl, '') : '');

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Kelola Paket &amp; Gambar QRIS</h1>
        <p class="page-subtitle">Atur bebas harga paket, batas kirim harian, watermark paket Free, serta gambar QRIS All Payment</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('admin-payments.php')) ?>" class="btn btn-primary btn-sm">Konfirmasi Pembayaran</a>
    </div>
</div>

<!-- Pengaturan QRIS All Payment -->
<section class="card" style="margin-bottom:24px;">
    <div class="card-header">
        <div>
            <h2 class="card-title">Pengaturan QRIS All Payment</h2>
            <p class="card-subtitle">Gambar QRIS ini akan muncul di halaman Paket &amp; QRIS pengguna</p>
        </div>
    </div>

    <form method="post" action="<?= e(base_url('admin-packages.php')) ?>" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_qris">

        <div class="qris-checkout-grid">
            <div class="qris-display-box">
                <div class="qris-merchant-title">Preview QRIS Saat Ini</div>
                <?php if ($qrisPreview !== ''): ?>
                    <img src="<?= e($qrisPreview) ?>" alt="Preview QRIS" class="qris-image-preview">
                <?php else: ?>
                    <div class="qris-placeholder">Belum ada gambar QRIS. Unggah gambar QRIS atau masukkan URL gambar di sebelah kanan.</div>
                <?php endif; ?>
            </div>

            <div>
                <div class="form-group">
                    <label for="qrisMerchant">Nama Merchant / Judul QRIS</label>
                    <input type="text" id="qrisMerchant" name="qris_merchant_name" value="<?= e((string) ($settings['qris_merchant_name'] ?? '')) ?>" required>
                </div>

                <div class="form-group">
                    <label for="qrisImageFile">Unggah Gambar QRIS Baru (JPG, PNG, WEBP — maks. 5 MB)</label>
                    <input type="file" id="qrisImageFile" name="qris_image" accept="image/jpeg,image/png,image/webp">
                    <?php if ($qrisUploaded !== ''): ?>
                        <label class="inline-check" style="margin-top:8px;">
                            <input type="checkbox" name="remove_uploaded_qris" value="1">
                            <span style="font-size:12.5px;color:var(--danger);">Hapus file QRIS yang diunggah saat ini</span>
                        </label>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label for="qrisImageUrl">Atau URL Gambar QRIS Eksternal (Opsional)</label>
                    <input type="url" id="qrisImageUrl" name="qris_image_url" value="<?= e($qrisUrl) ?>" placeholder="https://domainanda.com/qris.png">
                    <small class="form-hint">Jika file diunggah di atas, file unggahan server akan diprioritaskan daripada URL eksternal.</small>
                </div>

                <div class="form-group">
                    <label for="qrisInst">Instruksi Pembayaran untuk Pengguna</label>
                    <textarea id="qrisInst" name="qris_instructions" rows="3"><?= e((string) ($settings['qris_instructions'] ?? '')) ?></textarea>
                </div>

                <button type="submit" class="btn btn-primary">Simpan Pengaturan QRIS</button>
            </div>
        </div>
    </form>
</section>

<!-- Pengaturan Paket Langganan -->
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Pengaturan Harga &amp; Kuota Paket</h2>
            <p class="card-subtitle">Default: Free (0 / 10/hr + Watermark), Lite (5.000 / 50/hr), Pro (10.000 / 100/hr), Business (20.000 / 200/hr)</p>
        </div>
    </div>

    <form method="post" action="<?= e(base_url('admin-packages.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_packages">

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Slug</th>
                        <th>Nama Paket</th>
                        <th>Harga / Bulan (Rp)</th>
                        <th>Limit Kirim / Hari</th>
                        <th>Watermark</th>
                        <th>Opsi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($packages as $pkg): ?>
                        <?php $s = (string) $pkg['slug']; ?>
                        <tr>
                            <td>
                                <code><?= e($s) ?></code>
                                <input type="hidden" name="slug[]" value="<?= e($s) ?>">
                            </td>
                            <td>
                                <input type="text" name="name[<?= e($s) ?>]" value="<?= e((string) $pkg['name']) ?>" required style="min-width:110px;">
                                <input type="text" name="description[<?= e($s) ?>]" value="<?= e((string) ($pkg['description'] ?? '')) ?>" placeholder="Deskripsi singkat" style="margin-top:6px;font-size:12px;">
                            </td>
                            <td>
                                <input type="number" min="0" step="500" name="price_monthly[<?= e($s) ?>]" value="<?= (int) $pkg['price_monthly'] ?>" style="width:115px;">
                            </td>
                            <td>
                                <input type="number" min="1" name="daily_send_limit[<?= e($s) ?>]" value="<?= (int) $pkg['daily_send_limit'] ?>" style="width:95px;">
                            </td>
                            <td>
                                <label class="inline-check" style="margin-bottom:6px;">
                                    <input type="checkbox" name="has_watermark[<?= e($s) ?>]" value="1" <?= !empty($pkg['has_watermark']) ? 'checked' : '' ?>>
                                    <span>Tampilkan</span>
                                </label>
                                <input type="text" name="watermark_text[<?= e($s) ?>]" value="<?= e((string) ($pkg['watermark_text'] ?? '')) ?>" placeholder="Teks watermark..." style="min-width:190px;font-size:12px;">
                            </td>
                            <td>
                                <label class="inline-check" style="margin-bottom:6px;">
                                    <input type="checkbox" name="allow_api[<?= e($s) ?>]" value="1" <?= !empty($pkg['allow_api']) ? 'checked' : '' ?>>
                                    <span>Support API</span>
                                </label>
                                <label class="inline-check">
                                    <input type="checkbox" name="is_active[<?= e($s) ?>]" value="1" <?= !empty($pkg['is_active']) ? 'checked' : '' ?>>
                                    <span>Aktif</span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px;">
            <button type="submit" class="btn btn-primary">Simpan Seluruh Konfigurasi Paket</button>
        </div>
    </form>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
