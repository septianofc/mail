-- ============================================================================
-- SUPPORT SEPTIAN — DATABASE SCHEMA (MySQL 8.0+ / MariaDB 10.4+)
-- Aplikasi Web Manajemen Email Support Septian F. R.
-- Base URL: https://septianfr.web.id/mail/support
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Tabel admins
-- Menyimpan akun administrator aplikasi (password di-hash dengan password_hash)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `username` VARCHAR(80) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('superadmin', 'admin') NOT NULL DEFAULT 'superadmin',
    `job_title` VARCHAR(120) NOT NULL DEFAULT 'Customer Support Administrator',
    `avatar_url` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_email` (`email`),
    UNIQUE KEY `uq_admins_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Tabel app_settings
-- Menyimpan konfigurasi aplikasi, identitas resmi, OAuth & Google App Password
-- Nilai sensitif (client_secret, app_password) disimpan terenkripsi AES-256-GCM
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` MEDIUMTEXT DEFAULT NULL,
    `is_encrypted` TINYINT(1) NOT NULL DEFAULT 0,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`),
    KEY `idx_app_settings_updated_by` (`updated_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 3. Tabel oauth_tokens
-- Menyimpan OAuth 2.0 Access Token & Refresh Token terenkripsi AES-256-GCM
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `oauth_tokens` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `provider` VARCHAR(40) NOT NULL DEFAULT 'google_gmail',
    `account_email` VARCHAR(190) NOT NULL,
    `access_token_enc` TEXT NOT NULL,
    `refresh_token_enc` TEXT DEFAULT NULL,
    `token_type` VARCHAR(40) NOT NULL DEFAULT 'Bearer',
    `scope` TEXT DEFAULT NULL,
    `expires_at` DATETIME NOT NULL,
    `status` ENUM('connected', 'expired', 'revoked', 'disconnected') NOT NULL DEFAULT 'connected',
    `last_refreshed_at` DATETIME DEFAULT NULL,
    `last_error` VARCHAR(500) DEFAULT NULL,
    `connected_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_oauth_provider_email` (`provider`, `account_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Tabel email_templates
-- Sistem template email profesional untuk compose, reply, reply_all, forward, draft
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug` VARCHAR(120) NOT NULL,
    `name` VARCHAR(160) NOT NULL,
    `category` ENUM('reply', 'reply_all', 'compose', 'forward', 'draft') NOT NULL DEFAULT 'reply',
    `subject_template` VARCHAR(255) NOT NULL DEFAULT '',
    `body_html` LONGTEXT NOT NULL,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `description` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `updated_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_email_templates_slug` (`slug`),
    KEY `idx_email_templates_category` (`category`, `is_default`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Tabel activity_logs
-- Audit log aktivitas admin, pengiriman email, perubahan konfigurasi, & keamanan
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(80) NOT NULL,
    `entity_type` VARCHAR(60) DEFAULT NULL,
    `entity_id` VARCHAR(160) DEFAULT NULL,
    `description` VARCHAR(500) NOT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `user_agent` VARCHAR(255) DEFAULT NULL,
    `status` ENUM('success', 'warning', 'error') NOT NULL DEFAULT 'success',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_admin` (`admin_id`, `created_at`),
    KEY `idx_activity_action` (`action`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Tabel login_attempts
-- Rate limiting login untuk mencegah brute force
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `ip_address` VARCHAR(45) NOT NULL,
    `identifier` VARCHAR(190) NOT NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `was_successful` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`),
    KEY `idx_login_attempts_ident_time` (`identifier`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- SEED DATA: Pengaturan Awal Identitas Resmi Support Septian F. R.
-- ----------------------------------------------------------------------------
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
('app_name', 'Support Septian', 0),
('official_name', 'Support Septian F. R.', 0),
('support_email', 'support.septianfr@gmail.com', 0),
('website_url', 'https://septianfr.web.id', 0),
('app_url', 'https://septianfr.web.id/mail/support', 0),
('logo_url', 'https://septianfr.web.id/logo.png', 0),
('official_address', 'H4GM+4XX, Jl. PUK Muntur, Ranjeng, Kec. Losarang, Kabupaten Indramayu, Jawa Barat 45253', 0),
('mail_connection_mode', 'oauth', 0),
('gmail_oauth_client_id', '', 0),
('gmail_oauth_client_secret', '', 1),
('gmail_app_password_email', 'support.septianfr@gmail.com', 0),
('gmail_app_password_secret', '', 1),
('gmail_smtp_host', 'smtp.gmail.com', 0),
('gmail_smtp_port', '465', 0),
('gmail_smtp_encryption', 'ssl', 0),
('gmail_imap_host', 'imap.gmail.com', 0),
('gmail_imap_port', '993', 0),
('email_signature', '<p style="margin:0 0 6px 0;font-weight:600;color:#111827;">Hormat kami,</p><p style="margin:0 0 2px 0;font-weight:600;color:#1a73e8;">Tim Support Septian F. R.</p><p style="margin:0;font-size:13px;color:#5f6368;">Layanan Bantuan Resmi Septian F. R.</p>', 0),
('footer_note', 'Email ini dikirim melalui sistem layanan resmi Support Septian F. R. Mohon tidak membagikan informasi sensitif seperti kata sandi melalui email.', 0),
('items_per_page', '20', 0),
('timezone', 'Asia/Jakarta', 0)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

-- ----------------------------------------------------------------------------
-- SEED DATA: 8 Template Email Profesional Awal + 1 Template Draft
-- ----------------------------------------------------------------------------
INSERT INTO `email_templates` (`slug`, `name`, `category`, `subject_template`, `body_html`, `is_default`, `is_active`, `description`) VALUES
(
    'balasan-standar',
    'Balasan Standar',
    'reply',
    'Re: {subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Apabila masih ada pertanyaan atau kendala lain yang dapat kami bantu, silakan balas langsung email ini.</p>',
    1,
    1,
    'Template default untuk membalas email pelanggan secara profesional.'
),
(
    'konfirmasi-penerimaan-pesan',
    'Konfirmasi Penerimaan Pesan',
    'reply',
    'Re: {subjek} — Pesan Anda Telah Kami Terima',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami mengonfirmasi bahwa pesan Anda dengan subjek <strong>&ldquo;{subjek}&rdquo;</strong> telah kami terima dengan baik pada {tanggal}.</p><p>{isi_pesan}</p><p>Tim Support Septian F. R. akan meninjau permintaan Anda dan segera memberikan tindak lanjut.</p>',
    0,
    1,
    'Digunakan untuk mengonfirmasi bahwa pesan pelanggan sudah masuk ke sistem.'
),
(
    'informasi-sedang-diproses',
    'Informasi Sedang Diproses',
    'reply',
    'Re: {subjek} — Permintaan Anda Sedang Diproses',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami ingin menginformasikan bahwa laporan atau permintaan Anda terkait <strong>&ldquo;{subjek}&rdquo;</strong> saat ini sedang dalam tahap penanganan oleh tim kami.</p><p>{isi_pesan}</p><p>Kami akan segera menghubungi Anda kembali begitu terdapat pembaruan terbaru. Terima kasih atas kesabaran Anda.</p>',
    0,
    1,
    'Memberi kabar bahwa tiket/permintaan pelanggan sedang ditangani.'
),
(
    'permintaan-informasi-tambahan',
    'Permintaan Informasi Tambahan',
    'reply',
    'Re: {subjek} — Mohon Informasi Tambahan',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih telah menghubungi <strong>{nama_perusahaan}</strong>. Untuk mempercepat proses pemeriksaan terkait <strong>&ldquo;{subjek}&rdquo;</strong>, kami memerlukan beberapa informasi tambahan berikut:</p><p>{isi_pesan}</p><p>Silakan balas email ini beserta detail yang dibutuhkan agar kami dapat segera melanjutkan penanganan.</p>',
    0,
    1,
    'Meminta detail tambahan, tangkapan layar, atau data pendukung dari pengirim.'
),
(
    'penyelesaian-masalah',
    'Penyelesaian Masalah',
    'reply_all',
    'Re: {subjek} — Kendala Telah Diselesaikan',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami informasikan bahwa kendala terkait <strong>&ldquo;{subjek}&rdquo;</strong> telah berhasil diselesaikan oleh tim <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Mohon lakukan pengecekan kembali di sisi Anda. Jika masih ditemukan kendala, jangan ragu untuk membalas email ini.</p>',
    1,
    1,
    'Template default untuk Balas Semua saat melaporkan penyelesaian kendala.'
),
(
    'ucapan-terima-kasih',
    'Ucapan Terima Kasih',
    'reply',
    'Re: {subjek} — Terima Kasih',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih atas kepercayaan dan kerja sama Anda bersama <strong>{nama_perusahaan}</strong>.</p><p>{isi_pesan}</p><p>Kami selalu siap membantu Anda kapan pun dibutuhkan melalui {email_support} atau situs web kami di {website}.</p>',
    0,
    1,
    'Ucapan terima kasih dan penutupan layanan secara hangat.'
),
(
    'balasan-umum-teruskan',
    'Balasan Umum',
    'forward',
    'Fwd: {subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Berikut kami teruskan informasi terkait <strong>&ldquo;{subjek}&rdquo;</strong> dari layanan <strong>{nama_perusahaan}</strong> untuk dapat ditindaklanjuti.</p><p>{isi_pesan}</p><p>Apabila membutuhkan informasi lanjutan, silakan hubungi kami melalui {email_support}.</p>',
    1,
    1,
    'Template default untuk meneruskan pesan dan komunikasi umum.'
),
(
    'pesan-baru',
    'Pesan Baru',
    'compose',
    '{subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Perkenalkan, kami dari tim resmi <strong>{nama_perusahaan}</strong> menghubungi Anda terkait hal berikut:</p><p>{isi_pesan}</p><p>Jika ada hal yang ingin dikonfirmasi, silakan balas langsung email ini atau kunjungi {website}.</p>',
    1,
    1,
    'Template default saat menulis email baru dari halaman Compose.'
),
(
    'template-draft-standar',
    'Template Draft Standar',
    'draft',
    '{subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>{isi_pesan}</p><p>Silakan lengkapi catatan ini sebelum dikirimkan melalui akun resmi {email_support}.</p>',
    1,
    1,
    'Template default saat menyimpan atau membuka konsep (draft) email.'
)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

SET FOREIGN_KEY_CHECKS = 1;
