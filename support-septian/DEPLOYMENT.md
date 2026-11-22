# Panduan Deployment ke Shared Hosting PHP (cPanel / DirectAdmin / VPS)

Panduan langkah demi langkah untuk memasang **Support Septian** pada URL produksi:
`https://septianfr.web.id/mail/support`

---

## Prasyarat Hosting
- **PHP 8.1 / 8.2 / 8.3**
- **MySQL 8.0+** atau **MariaDB 10.4+**
- Ekstensi PHP aktif: `pdo_mysql`, `openssl`, `mbstring`, `json`, `dom`, `fileinfo`, `curl`
- *(Opsional jika memakai mode Google App Password untuk membaca Inbox)*: aktifkan ekstensi `imap` di menu **Select PHP Version** pada cPanel.
- SSL/HTTPS aktif untuk `https://septianfr.web.id` (wajib untuk Google OAuth 2.0).

---

## Langkah 1 — Siapkan Folder `vendor/` (Google API PHP Client & PHPMailer)

Jika hosting **mendukung SSH / Terminal**:
```bash
cd ~/public_html/mail/support
composer require google/apiclient:^2.18 phpmailer/phpmailer:^6.9
```
Atau jalankan di komputer lokal Anda pada folder `support-septian/`:
```bash
composer install --no-dev --optimize-autoloader
```
Folder `mail/support/vendor/` akan terbentuk berisi `google/apiclient` dan `phpmailer/phpmailer` (ukuran ringan karena `composer.json` sudah mengaktifkan `Google\Task\Composer::cleanup` khusus `Gmail` & `Oauth2`). Kompres `vendor/` menjadi ZIP dan ekstrak di `public_html/mail/support/vendor/`.

---

## Langkah 2 — Buat Database MySQL di cPanel

1. Buka **MySQL® Databases** di cPanel.
2. Buat database baru (misalnya `septianf_support`).
3. Buat user database baru dengan kata sandi kuat.
4. Tambahkan user tersebut ke database dengan hak akses **ALL PRIVILEGES**.
5. *(Opsional jika tidak memakai auto-import di `install.php`)*: Impor file `database.sql` melalui **phpMyAdmin**.

---

## Langkah 3 — Unggah File ke `public_html/mail/support/`

Struktur akhir di server hosting:
```
/home/username/
├── support-septian-private/        <-- (Disarankan) Pindahkan config.php ke sini setelah instalasi
│   └── config.php                  (chmod 0600)
└── public_html/
    ├── logo.png                    (https://septianfr.web.id/logo.png)
    └── mail/
        └── support/
            ├── .htaccess
            ├── index.php
            ├── login.php
            ├── logout.php
            ├── dashboard.php
            ├── inbox.php
            ├── sent.php
            ├── drafts.php
            ├── starred.php
            ├── spam.php
            ├── trash.php
            ├── compose.php
            ├── view-email.php
            ├── templates.php
            ├── template-preview.php
            ├── gmail-connection.php
            ├── settings.php
            ├── profile.php
            ├── security.php
            ├── install.php
            ├── oauth/
            │   ├── connect.php
            │   ├── callback.php    <-- URL: https://septianfr.web.id/mail/support/oauth/callback.php
            │   └── disconnect.php
            ├── api/
            ├── assets/
            ├── includes/
            ├── storage/            (chmod 0750)
            │   ├── tokens/         (chmod 0750)
            │   └── logs/           (chmod 0750)
            ├── templates/
            └── vendor/             (hasil Composer)
```

Pastikan `database.sql` berada di `public_html/mail/support/database.sql` saat menjalankan `install.php`.

---

## Langkah 4 — Jalankan Instalasi Web

1. Kunjungi `https://septianfr.web.id/mail/support/install.php`.
2. Pastikan seluruh item pada bagian **Pemeriksaan Lingkungan Server PHP** berstatus **Siap**.
3. Isi detail database MySQL, akun admin pertama, identitas Support Septian F. R., dan kredensial OAuth / App Password.
4. Klik **Selesaikan Instalasi Support Septian**.

---

## Langkah 5 — Hardening Keamanan Pasca-Instalasi (Wajib)

1. **Pindahkan File Konfigurasi ke Luar `public_html`** (jika hosting mengizinkan akses direktori home):
   - Buat folder `/home/username/support-septian-private/` (`chmod 0700`).
   - Pindahkan `public_html/mail/support/storage/app-config.php` menjadi `/home/username/support-septian-private/config.php` (`chmod 0600`).
   - `includes/config.php` otomatis mendeteksi lokasi tersebut terlebih dahulu.
2. **Hapus File Instalasi**:
   - Hapus `public_html/mail/support/install.php` dan `public_html/mail/support/database.sql` setelah instalasi sukses.
3. **Verifikasi Proteksi `.htaccess`**:
   - Buka `https://septianfr.web.id/mail/support/includes/config.php` &rarr; harus mengembalikan **403 Forbidden**.
   - Buka `https://septianfr.web.id/mail/support/storage/tokens/` &rarr; harus mengembalikan **403 Forbidden**.
4. **Hubungkan Gmail & Verifikasi DKIM**:
   - Login ke `https://septianfr.web.id/mail/support/login.php`.
   - Buka menu **Koneksi Gmail**, hubungkan akun `support.septianfr@gmail.com` via OAuth 2.0 atau masukkan Google App Password 16 digit (PHPMailer `smtp.gmail.com:465` SSL, `SMTPAuth=true`).
   - Klik **Jalankan Diagnostik SMTP**, lalu kirim **1 Email Tes** ke alamat Gmail lain milik admin dan pastikan di Gmail penerima menu **Show Original** menunjukkan **`DKIM: PASS`**.
