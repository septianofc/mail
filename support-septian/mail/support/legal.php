<?php
/**
 * Skyzz Mail — Dokumen Legal, Kepatuhan Google Play Console & Kontak Dukungan
 * Dapat diakses secara publik TANPA perlu login (syarat Google Play Console) maupun saat sudah login.
 * Path: /mail/support/legal.php?doc=privacy|terms|disclaimer|refund|aup|data-safety|account-deletion|contact
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$settings = get_all_settings();
$appName  = $settings['app_name'] ?: DEFAULT_APP_NAME;
$devName  = $settings['developer_name'] ?: ('Tim Pengembang ' . $appName);
$contactEmail = $settings['contact_email'] ?: ($settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL);
$contactWa    = trim((string) ($settings['contact_whatsapp'] ?? ''));
$websiteUrl   = safe_http_url((string) ($settings['website_url'] ?: DEFAULT_WEBSITE_URL), DEFAULT_WEBSITE_URL);
$address      = (string) ($settings['official_address'] ?: DEFAULT_ADDRESS);
$loggedInUser = current_admin();

$docs = [
    'privacy'          => 'Privacy Policy',
    'terms'            => 'Terms & Conditions',
    'disclaimer'       => 'Disclaimer',
    'refund'           => 'Refund Policy',
    'aup'              => 'Acceptable Use Policy',
    'data-safety'      => 'Data Safety',
    'account-deletion' => 'Account Deletion Policy',
    'contact'          => 'Contact & Support',
];

$activeDoc = strtolower(trim((string) ($_GET['doc'] ?? 'privacy')));
if (!isset($docs[$activeDoc])) {
    $activeDoc = 'privacy';
}

$pageTitle  = $docs[$activeDoc] . ' — Legal & Support';
$activePage = 'legal';

if ($loggedInUser) {
    $currentAdmin = $loggedInUser;
    require __DIR__ . '/includes/header.php';
} else {
    $logoUrl      = safe_http_url((string) ($settings['logo_url'] ?: DEFAULT_LOGO_URL), base_url('assets/images/logo.png'));
    $localLogoUrl = base_url('assets/images/logo.png');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($docs[$activeDoc]) ?> — <?= e($appName) ?></title>
    <link rel="icon" type="image/png" href="<?= e($localLogoUrl) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/responsive.css')) ?>">
</head>
<body class="app-body">
<header class="app-header">
    <div class="header-left">
        <a href="<?= e(base_url('login.php')) ?>" class="brand-lockup">
            <img src="<?= e($logoUrl) ?>" onerror="this.onerror=null;this.src='<?= e($localLogoUrl) ?>';" alt="<?= e($appName) ?>" class="brand-logo" width="32" height="32">
            <span class="brand-name"><?= e($appName) ?></span>
        </a>
    </div>
    <div class="header-right">
        <a href="<?= e(base_url('login.php')) ?>" class="btn btn-secondary btn-sm">Masuk</a>
        <a href="<?= e(base_url('register.php')) ?>" class="btn btn-primary btn-sm">Daftar Gratis</a>
    </div>
</header>
<main class="public-legal-main">
<?php } ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= e($docs[$activeDoc]) ?></h1>
        <p class="page-subtitle">Dokumen resmi <?= e($appName) ?> &middot; Terakhir diperbarui <?= date('d M Y') ?></p>
    </div>
    <div class="page-actions">
        <a href="<?= e(base_url('delete-account.php')) ?>" class="btn btn-danger btn-sm">Hapus Akun Saya</a>
    </div>
</div>

<!-- Tab Navigasi 8 Dokumen -->
<nav class="legal-tabs" aria-label="Daftar Dokumen Legal">
    <?php foreach ($docs as $slug => $label): ?>
        <a href="<?= e(base_url('legal.php?doc=' . $slug)) ?>"
           class="legal-tab <?= $activeDoc === $slug ? 'is-active' : '' ?>">
            <?= e($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="card legal-doc-body">
<?php if ($activeDoc === 'privacy'): ?>
    <h2>Privacy Policy (Kebijakan Privasi)</h2>
    <p>Kebijakan Privasi ini menjelaskan bagaimana <strong><?= e($appName) ?></strong> yang dikelola oleh <strong><?= e($devName) ?></strong> mengumpulkan, menggunakan, menyimpan, dan melindungi informasi pribadi pengguna saat menggunakan layanan web, aplikasi mobile, maupun REST API pengiriman email.</p>

    <h3>1. Data yang Kami Kumpulkan</h3>
    <ul>
        <li><strong>Data Akun:</strong> Nama lengkap, username, alamat email pendaftaran, kata sandi (disimpan secara terenkripsi satu arah menggunakan <code>password_hash</code>), serta pilihan akun email layanan.</li>
        <li><strong>Data Penggunaan Email &amp; Kuota:</strong> Metadata pengiriman (alamat pengirim, penerima, subjek, waktu kirim, status berhasil/gagal) untuk menghitung kuota harian dan menampilkan riwayat Terkirim.</li>
        <li><strong>Data Pembayaran QRIS:</strong> Bukti tangkapan layar/foto pembayaran, paket yang dipilih, durasi langganan, dan catatan pembayaran yang Anda unggah secara sukarela untuk verifikasi manual.</li>
        <li><strong>Data Teknis Keamanan:</strong> Alamat IP saat login, jenis browser (User-Agent), dan log aktivitas untuk mencegah penyalahgunaan atau percobaan login tidak sah.</li>
    </ul>

    <h3>2. Cara Kami Menggunakan Data</h3>
    <ul>
        <li>Menyediakan layanan penerimaan pesan tanpa batas dan pengiriman pesan sesuai paket aktif (Free, Lite, Pro, Business).</li>
        <li>Mengautentikasi akses API pengiriman email menggunakan API Key ter-hash (SHA-256).</li>
        <li>Memverifikasi pembayaran upgrade paket QRIS secara manual oleh Superadmin.</li>
        <li>Mencegah spam, phishing, brute-force login, dan menjaga keamanan infrastruktur email.</li>
    </ul>

    <h3>3. Keamanan &amp; Enkripsi</h3>
    <p>Kredensial SMTP/IMAP dan token OAuth dienkripsi di server menggunakan <strong>AES-256-GCM</strong>. Kami tidak pernah menjual, menyewakan, atau memperdagangkan data pribadi pengguna kepada pihak ketiga mana pun.</p>

    <h3>4. Hak Pengguna &amp; Penghapusan Data</h3>
    <p>Anda berhak memperbarui profil atau menghapus akun beserta seluruh data Anda secara permanen kapan saja melalui tombol <a href="<?= e(base_url('delete-account.php')) ?>"><strong>Hapus Akun</strong></a> di dalam aplikasi.</p>

<?php elseif ($activeDoc === 'terms'): ?>
    <h2>Terms &amp; Conditions (Syarat &amp; Ketentuan Penggunaan)</h2>
    <p>Dengan mendaftar, mengakses, atau menggunakan layanan <strong><?= e($appName) ?></strong>, Anda menyetujui seluruh syarat dan ketentuan berikut:</p>

    <h3>1. Akun &amp; Peran Pengguna</h3>
    <ul>
        <li>Setiap pengguna bertanggung jawab penuh atas kerahasiaan kata sandi dan API Key miliknya.</li>
        <li>Pengguna dapat memilih alamat email layanan yang disediakan dan dikonfigurasi oleh Superadmin.</li>
        <li>Pengguna reguler hanya memiliki akses untuk membaca, mengirim, mengelola template kustom, menghapus pesan, menggunakan API, mengelola paket, dan menghapus akun miliknya sendiri.</li>
    </ul>

    <h3>2. Paket Layanan &amp; Kuota Harian</h3>
    <ul>
        <li><strong>Free (Gratis Selamanya):</strong> Maksimal 10 pengiriman email per hari, terima pesan tanpa batas, mendukung API pengiriman, dan menyertakan watermark <em>&ldquo;Dikirim Menggunakan Aplikasi Skyzz Mail&rdquo;</em>.</li>
        <li><strong>Lite, Pro, &amp; Business (Berbayar):</strong> Peningkatan kuota pengiriman harian (50 / 100 / 200 email per hari sesuai ketentuan harga yang ditetapkan Superadmin), bebas watermark, serta mendukung API pengiriman.</li>
        <li>Durasi pembelian paket berbayar adalah minimal 1 bulan dan maksimal 12 bulan (1 tahun). Setelah masa aktif berakhir, akun secara otomatis kembali ke paket Free tanpa menghapus akun.</li>
        <li>Pengiriman email yang gagal akibat gangguan jaringan/server <strong>tidak mengurangi</strong> kuota harian Anda.</li>
    </ul>

    <h3>3. Penangguhan &amp; Penghentian</h3>
    <p>Superadmin berhak menangguhkan atau menghapus akun yang terbukti melanggar <em>Acceptable Use Policy</em> (seperti pengiriman spam massal, penipuan, atau phishing) tanpa pemberitahuan sebelumnya.</p>

<?php elseif ($activeDoc === 'disclaimer'): ?>
    <h2>Disclaimer (Batas Tanggung Jawab Layanan)</h2>
    <p>Layanan <strong><?= e($appName) ?></strong> disediakan sebagaimana adanya (<em>&ldquo;as is&rdquo;</em> dan <em>&ldquo;as available&rdquo;</em>).</p>
    <ul>
        <li>Kami berupaya semaksimal mungkin menjaga keandalan pengiriman SMTP/Gmail API dan ketersediaan server, namun kami tidak menjamin layanan 100% bebas gangguan di luar kendali kami (seperti kebijakan pembatasan Google/Gmail, gangguan ISP, atau server penerima).</li>
        <li>Seluruh isi pesan, lampiran, dan template kustom yang dikirim melalui akun pengguna sepenuhnya menjadi tanggung jawab hukum masing-masing pengguna.</li>
        <li><?= e($devName) ?> tidak bertanggung jawab atas kerugian tidak langsung, kehilangan data pihak ketiga, atau penolakan email oleh filter spam penyedia email tujuan.</li>
    </ul>

<?php elseif ($activeDoc === 'refund'): ?>
    <h2>Refund Policy (Kebijakan Pengembalian Dana)</h2>
    <p>Kebijakan ini mengatur ketentuan pengembalian dana untuk pembelian paket berbayar (Lite, Pro, Business) melalui QRIS All Payment di <strong><?= e($appName) ?></strong>:</p>
    <ul>
        <li><strong>Sebelum Konfirmasi Admin:</strong> Jika Anda melakukan kesalahan nominal transfer atau pesanan ditolak oleh Admin karena ketidaksesuaian bukti, Anda dapat menghubungi tim dukungan dalam waktu 3x24 jam untuk verifikasi atau penyesuaian.</li>
        <li><strong>Setelah Paket Aktif:</strong> Paket langganan digital yang telah disetujui dan diaktifkan pada akun Anda bersifat <strong>tidak dapat dikembalikan (non-refundable)</strong>, kecuali terjadi kegagalan sistem total dari sisi server kami selama lebih dari 72 jam berturut-turut.</li>
        <li><strong>Pelanggaran AUP:</strong> Akun yang dinonaktifkan karena pelanggaran spam, phishing, atau penyalahgunaan layanan tidak berhak mendapatkan pengembalian dana.</li>
        <li>Untuk mengajukan peninjauan pengembalian dana, sertakan Kode Pesanan (<code>SKZ-...</code>) dan bukti transfer ke <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a>.</li>
    </ul>

<?php elseif ($activeDoc === 'aup'): ?>
    <h2>Acceptable Use Policy (Kebijakan Penggunaan yang Dapat Diterima)</h2>
    <p>Demi menjaga reputasi IP, skor DKIM/SPF, dan keamanan seluruh pengguna <strong><?= e($appName) ?></strong>, setiap pengguna <strong>DILARANG KERAS</strong> menggunakan layanan maupun API untuk:</p>
    <ul>
        <li><strong>Spam &amp; Email Massal Tanpa Izin:</strong> Mengirim promosi berulang kepada penerima yang tidak memberikan persetujuan (unsolicited bulk email).</li>
        <li><strong>Phishing &amp; Penipuan:</strong> Menyamarkan identitas untuk mencuri kata sandi, OTP, data perbankan, akun media sosial, atau informasi sensitif lainnya.</li>
        <li><strong>Malware &amp; Konten Berbahaya:</strong> Melampirkan virus, trojan, skrip berbahaya, atau tautan situs penipuan.</li>
        <li><strong>Ujaran Kebencian, Ancaman, &amp; Konten Ilegal:</strong> Mengirim materi yang melanggar hukum Republik Indonesia maupun peraturan internasional.</li>
        <li><strong>Penyalahgunaan Kuota &amp; Pembuatan Banyak Akun Palsu:</strong> Mengakali batas pengiriman harian dengan membuat banyak akun otomatis (bot).</li>
    </ul>
    <p>Pelanggaran terhadap kebijakan ini akan berakibat pada pencabutan API Key, penangguhan permanen akun, dan pelaporan kepada pihak berwenang apabila diperlukan.</p>

<?php elseif ($activeDoc === 'data-safety'): ?>
    <h2>Data Safety (Deklarasi Keamanan Data — Google Play Console)</h2>
    <p>Bagian ini disusun transparan untuk memenuhi standar deklarasi <strong>Google Play Console Data Safety</strong> bagi pengguna web maupun aplikasi Android <?= e($appName) ?>:</p>

    <div class="table-responsive" style="margin:14px 0;">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Kategori Data</th>
                    <th>Dikumpulkan?</th>
                    <th>Dibagikan ke Pihak Ketiga?</th>
                    <th>Tujuan</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Info Pribadi</strong> (Nama, Username, Email)</td>
                    <td>Ya</td>
                    <td>Tidak</td>
                    <td>Fungsi aplikasi, manajemen akun, autentikasi login</td>
                </tr>
                <tr>
                    <td><strong>Pesan &amp; Log Email</strong> (Metadata kirim/terima)</td>
                    <td>Ya</td>
                    <td>Tidak (hanya dikirim ke server email tujuan sesuai permintaan pengguna)</td>
                    <td>Fungsi utama pengiriman/penerimaan email &amp; kuota harian</td>
                </tr>
                <tr>
                    <td><strong>Foto / File Unggahan</strong> (Bukti QRIS &amp; Lampiran)</td>
                    <td>Opsional (saat pengguna mengunggah)</td>
                    <td>Tidak</td>
                    <td>Verifikasi upgrade paket &amp; pengiriman lampiran email</td>
                </tr>
                <tr>
                    <td><strong>Keamanan &amp; Log Diagnostik</strong> (IP Login, Error Log)</td>
                    <td>Ya</td>
                    <td>Tidak</td>
                    <td>Pencegahan brute-force, keamanan akun, &amp; stabilitas server</td>
                </tr>
            </tbody>
        </table>
    </div>
    <ul>
        <li><strong>Enkripsi dalam Transit:</strong> Seluruh komunikasi menggunakan HTTPS/TLS dan SMTP/IMAP terenkripsi (SSL/TLS).</li>
        <li><strong>Penghapusan Data:</strong> Pengguna dapat menghapus akun dan seluruh data pribadinya secara mandiri dan permanen melalui halaman <a href="<?= e(base_url('delete-account.php')) ?>">Hapus Akun</a>.</li>
    </ul>

<?php elseif ($activeDoc === 'account-deletion'): ?>
    <h2>Account Deletion Policy (Kebijakan Penghapusan Akun &amp; Data)</h2>
    <p>Sesuai kebijakan privasi dan persyaratan <strong>Google Play Console</strong>, setiap pengguna <strong><?= e($appName) ?></strong> memiliki kontrol penuh untuk menghapus akun dan seluruh data pribadinya kapan saja.</p>

    <h3>1. Cara Menghapus Akun Secara Mandiri (Instan)</h3>
    <ol>
        <li>Masuk ke akun <?= e($appName) ?> Anda (baik di <strong>Desktop</strong> maupun di <strong>HP Android / Layar Mini</strong>).</li>
        <li>Klik tombol <strong>&ldquo;Hapus Akun&rdquo;</strong> di menu navigasi sidebar, di halaman <strong>Profil &amp; Pengirim</strong>, atau di footer bawah halaman.</li>
        <li>Masukkan kata sandi akun Anda untuk konfirmasi keamanan, centang kotak persetujuan, lalu klik <strong>&ldquo;Hapus Akun Secara Permanen&rdquo;</strong>.</li>
    </ol>

    <h3>2. Data yang Dihapus Secara Permanen</h3>
    <ul>
        <li>Data profil akun (nama, username, email, kata sandi ter-hash, tanda tangan kustom).</li>
        <li>Seluruh API Key pengiriman milik akun Anda.</li>
        <li>Seluruh template email kustom pribadi milik akun Anda.</li>
        <li>Riwayat pesanan pembayaran beserta file foto/screenshot bukti pembayaran QRIS yang pernah diunggah.</li>
        <li>Log aktivitas dan riwayat pengiriman lokal milik akun Anda.</li>
    </ul>

    <h3>3. Permintaan Penghapusan Tanpa Login</h3>
    <p>Apabila Anda lupa kata sandi dan tidak dapat masuk ke aplikasi, Anda dapat mengirim permintaan penghapusan akun dari email terdaftar Anda ke <a href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a> dengan subjek <code>Permintaan Hapus Akun <?= e($appName) ?></code>. Proses penghapusan akan diselesaikan maksimal dalam 7 hari kerja.</p>

    <div style="margin-top:18px;">
        <a href="<?= e(base_url('delete-account.php')) ?>" class="btn btn-danger">Buka Halaman Hapus Akun Sekarang</a>
    </div>

<?php elseif ($activeDoc === 'contact'): ?>
    <h2>Contact &amp; Support (Kontak Resmi Pengembang &amp; Bantuan)</h2>
    <p>Jika Anda mengalami kendala teknis, pertanyaan seputar paket langganan QRIS, integrasi API, laporan penyalahgunaan (abuse/spam), atau permintaan penghapusan data, silakan hubungi kontak resmi berikut:</p>
    <div class="contact-box-grid">
        <div class="contact-item">
            <div class="stat-label">Nama Layanan &amp; Pengembang</div>
            <strong><?= e($appName) ?> — <?= e($devName) ?></strong>
        </div>
        <div class="contact-item">
            <div class="stat-label">Email Dukungan Resmi</div>
            <a href="mailto:<?= e($contactEmail) ?>"><strong><?= e($contactEmail) ?></strong></a>
        </div>
        <?php if ($contactWa !== ''): ?>
            <div class="contact-item">
                <div class="stat-label">WhatsApp / Telepon Admin</div>
                <strong><?= e($contactWa) ?></strong>
            </div>
        <?php endif; ?>
        <div class="contact-item">
            <div class="stat-label">Website Resmi</div>
            <a href="<?= e($websiteUrl) ?>" target="_blank" rel="noopener"><strong><?= e($websiteUrl) ?></strong></a>
        </div>
        <div class="contact-item" style="grid-column:1/-1;">
            <div class="stat-label">Alamat Resmi</div>
            <div><?= e($address) ?></div>
        </div>
    </div>
<?php endif; ?>
</section>

<?php
if ($loggedInUser) {
    require __DIR__ . '/includes/footer.php';
} else {
    ?>
</main>
<footer class="app-legal-footer" style="max-width:960px;margin:20px auto 32px;padding:16px;">
    <div class="legal-footer-links">
        <?php foreach ($docs as $slug => $label): ?>
            <a href="<?= e(base_url('legal.php?doc=' . $slug)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
        <a href="<?= e(base_url('delete-account.php')) ?>" class="legal-link-danger">Hapus Akun</a>
    </div>
    <div class="legal-footer-copy">&copy; <?= date('Y') ?> <?= e($appName) ?></div>
</footer>
</body>
</html>
<?php } ?>
