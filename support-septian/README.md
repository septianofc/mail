# Support Septian — Aplikasi Manajemen Email Customer Support (PHP 8.1+ & MySQL PDO)

Aplikasi web manajemen email resmi untuk **Support Septian F. R.** yang terhubung langsung ke akun Gmail **`support.septianfr@gmail.com`**, dibangun murni menggunakan **HTML5, CSS3, JavaScript Vanilla, PHP 8.1+, dan MySQL PDO** tanpa React, TypeScript, Node.js, maupun proses kompilasi frontend.

---

## Identitas Resmi Aplikasi

| Komponen | Nilai |
| --- | --- |
| **Nama Aplikasi** | Support Septian |
| **Identitas Resmi** | Support Septian F. R. |
| **Email Gmail Resmi** | `support.septianfr@gmail.com` |
| **Website** | `https://septianfr.web.id` |
| **URL Aplikasi** | `https://septianfr.web.id/mail/support` |
| **OAuth Callback URI** | `https://septianfr.web.id/mail/support/oauth/callback.php` |
| **Logo Resmi** | `https://septianfr.web.id/logo.png` |
| **Alamat Lengkap** | H4GM+4XX, Jl. PUK Muntur, Ranjeng, Kec. Losarang, Kabupaten Indramayu, Jawa Barat 45253 |

---

## Fitur Utama

1. **Integrasi Gmail Sungguhan (Dual Mode)**:
   - **Google Gmail API (OAuth 2.0 Web Application)** menggunakan `google/apiclient`, dengan verifikasi `state` anti-CSRF, auto-refresh token, deteksi token dicabut, serta validasi ketat bahwa akun yang terhubung wajib `support.septianfr@gmail.com`.
   - **Google App Password (PHPMailer SMTP SSL:465 + IMAP)** menggunakan `phpmailer/phpmailer` ke `smtp.gmail.com:465` (`ENCRYPTION_SMTPS`, `SMTPAuth = true`) dengan identitas `Support Septian F. R. <support.septianfr@gmail.com>` sehingga penandatanganan DKIM dikelola resmi oleh server Google (`DKIM=PASS`), lengkap dengan **Panel Diagnostik SMTP** & tombol **Kirim 1 Email Tes**.
2. **Fitur Email Lengkap**:
   - Kotak Masuk (Inbox), Terkirim (Sent), Draft, Berbintang (Starred), Spam, Sampah (Trash).
   - Membaca thread percakapan lengkap dengan sanitasi HTML anti-XSS.
   - Balas Pesan (Reply), Balas Semua (Reply All), Kirim Baru (Compose), Teruskan (Forward), Simpan Draft.
   - Aksi massal & tunggal: Tandai Sudah/Belum Dibaca, Bintang, Arsip, Spam, Sampah, Hapus Permanen.
   - Pencarian email lintas folder, pagination Gmail, serta unggah & unduh lampiran tervalidasi.
3. **Sistem Template Email Profesional (Gaya Google)**:
   - 8+ template siap pakai (Balasan Standar, Konfirmasi Penerimaan Pesan, Informasi Sedang Diproses, Permintaan Informasi Tambahan, Penyelesaian Masalah, Ucapan Terima Kasih, Balasan Umum, Pesan Baru, dan Draft).
   - HTML inline CSS lebar 600px, background putih, logo & footer identitas resmi Support Septian F. R., responsif di Android, iPhone, dan Desktop.
   - Variabel dinamis: `{nama_penerima}`, `{email_penerima}`, `{subjek}`, `{tanggal}`, `{nama_pengirim}`, `{isi_pesan}`, `{nama_perusahaan}`, `{website}`, `{email_support}`.
4. **Keamanan Standar Produksi**:
   - `password_hash()` & `password_verify()`, session cookie aman (`HttpOnly`, `SameSite=Lax`, `Secure`), CSRF token pada seluruh form & API, PDO prepared statements (`ATTR_EMULATE_PREPARES => false`), rate limiting login, enkripsi AES-256-GCM untuk Client Secret, OAuth Token, & App Password, `.htaccess` pemblokir direktori sensitif, serta modal konfirmasi wajib sebelum mengirim email.

---

## Instalasi Cepat

### 1. Instalasi Library Google API PHP Client & PHPMailer (Composer)
Jalankan perintah berikut di folder root paket `support-septian/`:

```bash
composer install --no-dev --optimize-autoloader
```

Perintah tersebut otomatis memasang `google/apiclient` dan `phpmailer/phpmailer` ke dalam `mail/support/vendor/` serta membersihkan service Google selain `Gmail` & `Oauth2` agar ukuran folder `vendor/` tetap ringan saat diunggah ke shared hosting.

### 2. Unggah ke Hosting PHP
Unggah isi folder `mail/support/` ke direktori:
```
public_html/mail/support/
```
*(Sertakan folder `mail/support/vendor/` hasil Composer serta salin `database.sql` ke `public_html/mail/support/database.sql` selama instalasi, lalu hapus file `database.sql` & `install.php` setelah selesai).*

### 3. Jalankan Web Installer
Buka browser dan akses:
```
https://septianfr.web.id/mail/support/install.php
```
Isi:
1. Koneksi MySQL (host, nama database, username, password).
2. Akun administrator pertama.
3. Identitas resmi Support Septian F. R.
4. Kredensial Google OAuth 2.0 (Client ID & Secret) dan/atau Google App Password 16 digit.

### 4. Konfigurasi Google Cloud Console (Untuk OAuth 2.0)
1. Buka [Google Cloud Console](https://console.cloud.google.com/) &rarr; buat/pilih proyek.
2. Aktifkan **Gmail API**.
3. Konfigurasikan **OAuth Consent Screen** (External/Internal), tambahkan scopes:
   - `https://www.googleapis.com/auth/gmail.readonly`
   - `https://www.googleapis.com/auth/gmail.send`
   - `https://www.googleapis.com/auth/gmail.modify`
   Tambahkan `support.septianfr@gmail.com` pada **Test Users** bila aplikasi masih dalam status Testing.
4. Buat **OAuth Client ID** dengan tipe **Web Application**:
   - **Authorized JavaScript origins**: `https://septianfr.web.id`
   - **Authorized redirect URIs**: `https://septianfr.web.id/mail/support/oauth/callback.php`
5. Masukkan Client ID dan Client Secret pada halaman **Koneksi Gmail**, lalu klik **Hubungkan Gmail**.

### 5. Alternatif: Konfigurasi Google App Password (PHPMailer SMTP SSL:465)
1. Buka akun Google `support.septianfr@gmail.com` &rarr; **Security** &rarr; aktifkan **2-Step Verification**.
2. Buka menu **App Passwords**, buat sandi aplikasi bernama `Support Septian`.
3. Masuk ke halaman **Koneksi Gmail** di aplikasi Support Septian, masukkan 16 karakter App Password tersebut, klik **Jalankan Diagnostik SMTP & IMAP**, lalu simpan & aktifkan.
4. Gunakan panel **Tes Pengiriman Email (1 Email Percobaan)** untuk mengirim 1 email tes ke Gmail admin lain, lalu periksa **Show Original (Tampilkan Asli)** di Gmail penerima untuk memastikan `Authentication-Results` menampilkan `DKIM=PASS`.

Lihat `DEPLOYMENT.md` untuk panduan lengkap deployment pada cPanel / Shared Hosting PHP.
