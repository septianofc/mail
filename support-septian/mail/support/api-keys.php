<?php
/**
 * Skyzz Mail — API Pengiriman Email & Manajemen API Key
 * Path: /mail/support/api-keys.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$currentAdmin = require_admin();
$pageTitle    = 'API Pengiriman Email';
$activePage   = 'api-keys';
$quota        = get_user_quota_status($currentAdmin);
$activeMb     = get_effective_mailbox($currentAdmin);
$newPlainKey  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    $action = trim((string) ($_POST['action'] ?? ''));

    try {
        if ($action === 'create') {
            $keyName = trim((string) ($_POST['key_name'] ?? ''));
            $created = create_user_api_key((int) $currentAdmin['id'], $keyName);
            $newPlainKey = $created['plain_key'];
            log_activity('api_key_created', 'Pengguna membuat API Key: ' . $created['name'], 'api_key', (string) $created['id']);
            set_flash('success', 'API Key baru berhasil dibuat! Salin sekarang karena hanya ditampilkan sekali.');
        } elseif ($action === 'revoke') {
            $keyId = (int) ($_POST['key_id'] ?? 0);
            if ($keyId > 0 && revoke_user_api_key($keyId, (int) $currentAdmin['id'], is_superadmin($currentAdmin))) {
                log_activity('api_key_revoked', 'Pengguna mencabut API Key #' . $keyId, 'api_key', (string) $keyId);
                set_flash('success', 'API Key berhasil dihapus dan tidak dapat digunakan lagi.');
            }
            redirect_to('api-keys.php');
        }
    } catch (Throwable $e) {
        set_flash('danger', $e->getMessage());
    }
}

$apiKeys  = list_user_api_keys((int) $currentAdmin['id']);
$apiUrl   = rtrim((string) get_setting('app_url', DEFAULT_APP_URL), '/') . '/api/v1/send.php';

require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">API Pengiriman Email</h1>
        <p class="page-subtitle">Kirim email otomatis dari aplikasi, bot, atau website Anda menggunakan Skyzz Mail REST API</p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('billing.php')) ?>" class="btn btn-secondary btn-sm">Upgrade Limit Harian</a>
    </div>
</div>

<?php if ($newPlainKey !== ''): ?>
    <div class="alert alert-success" role="alert" style="flex-direction:column;align-items:flex-start;gap:10px;">
        <div><strong>Simpan API Key Baru Anda Sekarang!</strong> Kunci ini disimpan ter-hash di server sehingga tidak dapat dilihat lagi setelah halaman ditutup.</div>
        <div class="copy-key-row">
            <code id="newlyCreatedApiKey"><?= e($newPlainKey) ?></code>
            <button type="button" class="btn btn-primary btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('newlyCreatedApiKey').textContent).then(()=>window.SS && SS.toast('API Key disalin!','success'))">Salin API Key</button>
        </div>
    </div>
<?php endif; ?>

<div class="dashboard-columns" style="margin-bottom:20px;">
    <!-- Buat API Key -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Buat API Key Baru</h2>
                <p class="card-subtitle">Tersedia untuk semua paket (Free 10/hari, Lite 50/hari, Pro 100/hari, Business 200/hari)</p>
            </div>
        </div>
        <form method="post" action="<?= e(base_url('api-keys.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label for="keyNameInput">Nama Label API Key</label>
                <input type="text" id="keyNameInput" name="key_name" placeholder="Contoh: Website Toko / Bot Notifikasi OTP" required maxlength="90">
            </div>
            <button type="submit" class="btn btn-primary">Buat API Key Baru</button>
        </form>

        <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--border);font-size:13px;color:var(--text-muted);">
            <div><strong>Status Kuota Hari Ini:</strong>
                <?php if ($quota['is_unlimited']): ?>
                    Tanpa Batas (Superadmin)
                <?php else: ?>
                    Terpakai <strong><?= (int) $quota['used_today'] ?></strong> / <strong><?= (int) $quota['daily_limit'] ?></strong> email (Sisa: <strong><?= (int) $quota['remaining'] ?></strong>)
                <?php endif; ?>
            </div>
            <div style="margin-top:4px;">Pengiriman yang gagal <strong>tidak mengurangi</strong> kuota harian Anda.</div>
            <div style="margin-top:4px;">Akun email default saat ini: <code><?= e((string) ($activeMb['email'] ?? '')) ?></code> (ID: <code><?= (int) ($activeMb['id'] ?? 0) ?></code>)</div>
        </div>
    </section>

    <!-- Daftar API Key Aktif -->
    <section class="card">
        <div class="card-header">
            <div>
                <h2 class="card-title">Daftar API Key Anda</h2>
                <p class="card-subtitle">Cabut kunci lama jika sudah tidak digunakan</p>
            </div>
        </div>
        <?php if (empty($apiKeys)): ?>
            <div class="empty-state">
                <p class="muted">Anda belum memiliki API Key. Buat satu di formulir sebelah kiri untuk mulai mengirim via API.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Prefix</th>
                            <th>Terakhir Dipakai</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($apiKeys as $k): ?>
                            <tr>
                                <td><strong><?= e((string) $k['name']) ?></strong></td>
                                <td><code><?= e((string) $k['key_prefix']) ?></code></td>
                                <td><?= $k['last_used_at'] ? e(format_tanggal_id((string) $k['last_used_at'])) : '<span class="muted">Belum pernah</span>' ?></td>
                                <td>
                                    <form method="post" action="<?= e(base_url('api-keys.php')) ?>" onsubmit="return confirm('Cabut dan hapus API Key ini?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="key_id" value="<?= (int) $k['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Cabut</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<!-- Dokumentasi Endpoint -->
<section class="card">
    <div class="card-header">
        <div>
            <h2 class="card-title">Dokumentasi Endpoint Pengiriman Email</h2>
            <p class="card-subtitle">Endpoint: <code>POST <?= e($apiUrl) ?></code></p>
        </div>
    </div>

    <div class="api-docs-block">
        <h4>1. Contoh cURL</h4>
        <pre class="code-snippet"><code>curl -X POST "<?= e($apiUrl) ?>" \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "penerima@contoh.com",
    "subject": "Konfirmasi Pesanan #1024",
    "body_html": "&lt;p&gt;Halo &lt;b&gt;Pelanggan&lt;/b&gt;, pesanan Anda berhasil diproses.&lt;/p&gt;",
    "from_name": "Toko Anda",
    "wrap_template": true
  }'</code></pre>

        <h4>2. Contoh JavaScript (Fetch)</h4>
        <pre class="code-snippet"><code>const res = await fetch("<?= e($apiUrl) ?>", {
  method: "POST",
  headers: {
    "Authorization": "Bearer YOUR_API_KEY",
    "Content-Type": "application/json"
  },
  body: JSON.stringify({
    to: "penerima@contoh.com",
    subject: "Notifikasi Sistem",
    text: "Halo, ini pesan yang dikirim melalui Skyzz Mail API."
  })
});
const data = await res.json();
console.log(data);</code></pre>

        <h4>3. Contoh Respons Sukses (HTTP 200)</h4>
        <pre class="code-snippet"><code>{
  "ok": true,
  "message": "Email berhasil dikirim melalui Skyzz Mail API.",
  "message_id": "&lt;...@gmail.com&gt;",
  "from_email": "<?= e((string) ($activeMb['email'] ?? DEFAULT_OFFICIAL_GMAIL)) ?>",
  "imap_sent_synced": true,
  "quota": {
    "plan": "<?= e((string) $quota['plan']['name']) ?>",
    "used_today": 1,
    "daily_limit": <?= (int) $quota['daily_limit'] ?>,
    "remaining": <?= max(0, (int) $quota['daily_limit'] - 1) ?>
  }
}</code></pre>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
