-- ============================================================================
-- SKYZZ MAIL — DATABASE SCHEMA (MySQL 8.0+ / MariaDB 10.4+)
-- Platform Layanan Email Multi-User, Sewa Email, API Pengiriman & Pembayaran QRIS
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------------------
-- 1. Tabel admins (Menyimpan akun Superadmin dan User terdaftar)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admins` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `username` VARCHAR(80) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('superadmin', 'admin', 'user') NOT NULL DEFAULT 'user',
    `job_title` VARCHAR(120) NOT NULL DEFAULT 'Pengguna Skyzz Mail',
    `avatar_url` VARCHAR(255) DEFAULT NULL,
    `selected_mailbox_id` INT UNSIGNED DEFAULT NULL,
    `plan_slug` VARCHAR(40) NOT NULL DEFAULT 'free',
    `plan_expires_at` DATETIME DEFAULT NULL,
    `custom_sender_name` VARCHAR(120) DEFAULT NULL,
    `custom_signature` MEDIUMTEXT DEFAULT NULL,
    `custom_footer_note` VARCHAR(500) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at` DATETIME DEFAULT NULL,
    `last_login_ip` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admins_email` (`email`),
    UNIQUE KEY `uq_admins_username` (`username`),
    KEY `idx_admins_role` (`role`),
    KEY `idx_admins_plan` (`plan_slug`, `plan_expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 2. Tabel app_settings (Pengaturan global aplikasi, branding, QRIS, legal & kontak)
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
-- 3. Tabel mailboxes (Akun email yang dikonfigurasi Superadmin untuk dipilih User)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mailboxes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `label` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `display_name` VARCHAR(120) NOT NULL,
    `provider_mode` ENUM('app_password', 'oauth') NOT NULL DEFAULT 'app_password',
    `smtp_host` VARCHAR(160) NOT NULL DEFAULT 'smtp.gmail.com',
    `smtp_port` INT UNSIGNED NOT NULL DEFAULT 465,
    `smtp_encryption` ENUM('ssl', 'tls', 'none') NOT NULL DEFAULT 'ssl',
    `smtp_username` VARCHAR(190) NOT NULL,
    `smtp_password_enc` TEXT DEFAULT NULL,
    `imap_host` VARCHAR(160) NOT NULL DEFAULT 'imap.gmail.com',
    `imap_port` INT UNSIGNED NOT NULL DEFAULT 993,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `is_default` TINYINT(1) NOT NULL DEFAULT 0,
    `allow_user_select` TINYINT(1) NOT NULL DEFAULT 1,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_mailboxes_email` (`email`),
    KEY `idx_mailboxes_active` (`is_active`, `allow_user_select`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 4. Tabel packages (Paket langganan Free, Lite, Pro, Business — dapat diubah Superadmin)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `packages` (
    `slug` VARCHAR(40) NOT NULL,
    `name` VARCHAR(80) NOT NULL,
    `price_monthly` INT UNSIGNED NOT NULL DEFAULT 0,
    `daily_send_limit` INT UNSIGNED NOT NULL DEFAULT 10,
    `allow_api` TINYINT(1) NOT NULL DEFAULT 1,
    `has_watermark` TINYINT(1) NOT NULL DEFAULT 0,
    `watermark_text` VARCHAR(255) NOT NULL DEFAULT '',
    `description` VARCHAR(255) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 5. Tabel payment_orders (Pembayaran QRIS All Payment & verifikasi manual Superadmin)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_code` VARCHAR(40) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `package_slug` VARCHAR(40) NOT NULL,
    `duration_months` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `amount_total` INT UNSIGNED NOT NULL DEFAULT 0,
    `proof_image_path` VARCHAR(255) NOT NULL,
    `payer_note` VARCHAR(300) DEFAULT NULL,
    `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    `admin_note` VARCHAR(300) DEFAULT NULL,
    `reviewed_by` INT UNSIGNED DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_payment_order_code` (`order_code`),
    KEY `idx_payment_user` (`user_id`, `created_at`),
    KEY `idx_payment_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 6. Tabel user_api_keys (API Key per user untuk mengirim email melalui REST API)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `user_api_keys` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `key_prefix` VARCHAR(24) NOT NULL,
    `key_hash` CHAR(64) NOT NULL,
    `last_used_at` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_user_api_keys_hash` (`key_hash`),
    KEY `idx_user_api_keys_user` (`user_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 7. Tabel sent_email_logs (Riwayat email terkirim & penghitung kuota harian)
-- Catatan penting: hanya status='sent' yang dihitung mengurangi kuota harian.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `sent_email_logs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `mailbox_id` INT UNSIGNED DEFAULT NULL,
    `from_email` VARCHAR(190) NOT NULL,
    `to_emails` VARCHAR(500) NOT NULL,
    `cc_emails` VARCHAR(500) DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_preview` VARCHAR(320) DEFAULT NULL,
    `body_html` MEDIUMTEXT DEFAULT NULL,
    `transport` VARCHAR(40) NOT NULL DEFAULT 'phpmailer_gmail_smtp',
    `message_id` VARCHAR(255) DEFAULT NULL,
    `imap_sent_synced` TINYINT(1) NOT NULL DEFAULT 0,
    `sent_via` ENUM('web', 'api') NOT NULL DEFAULT 'web',
    `status` ENUM('sent', 'failed') NOT NULL DEFAULT 'sent',
    `error_message` VARCHAR(400) DEFAULT NULL,
    `sent_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sent_quota` (`user_id`, `sent_date`, `status`),
    KEY `idx_sent_mailbox` (`mailbox_id`, `created_at`),
    KEY `idx_sent_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 8. Tabel oauth_tokens (Token OAuth 2.0 terenkripsi AES-256-GCM)
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
-- 9. Tabel email_templates (Template bebas kustom per user & template global)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `email_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_id` INT UNSIGNED DEFAULT NULL,
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
    KEY `idx_email_templates_category` (`category`, `is_default`),
    KEY `idx_email_templates_owner` (`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- 10. Tabel activity_logs
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
-- 11. Tabel login_attempts
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
-- SEED DATA: Pengaturan Awal Skyzz Mail
-- ----------------------------------------------------------------------------
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `is_encrypted`) VALUES
('app_name', 'Skyzz Mail', 0),
('official_name', 'Skyzz Mail', 0),
('support_email', 'support.septianfr@gmail.com', 0),
('website_url', 'https://septianfr.web.id', 0),
('app_url', 'https://septianfr.web.id/mail/support', 0),
('logo_url', 'https://septianfr.web.id/logo.png', 0),
('official_address', 'H4GM+4XX, Jl. PUK Muntur, Ranjeng, Kec. Losarang, Kabupaten Indramayu, Jawa Barat 45253', 0),
('developer_name', 'Tim Pengembang Skyzz Mail', 0),
('contact_email', 'support.septianfr@gmail.com', 0),
('contact_whatsapp', '', 0),
('mail_connection_mode', 'app_password', 0),
('imap_append_sent', '1', 0),
('allow_public_registration', '1', 0),
('qris_merchant_name', 'Skyzz Mail Official (QRIS All Payment)', 0),
('qris_image_path', '', 0),
('qris_image_url', '', 0),
('qris_instructions', 'Scan QRIS All Payment di atas menggunakan Dana, OVO, GoPay, ShopeePay, LinkAja, atau Mobile Banking sesuai nominal total, lalu unggah tangkapan layar/foto bukti pembayaran untuk konfirmasi manual oleh Admin.', 0),
('gmail_oauth_client_id', '', 0),
('gmail_oauth_client_secret', '', 1),
('gmail_app_password_email', 'support.septianfr@gmail.com', 0),
('gmail_app_password_secret', '', 1),
('gmail_smtp_host', 'smtp.gmail.com', 0),
('gmail_smtp_port', '465', 0),
('gmail_smtp_encryption', 'ssl', 0),
('gmail_imap_host', 'imap.gmail.com', 0),
('gmail_imap_port', '993', 0),
('email_signature', '<p style="margin:0 0 6px 0;font-weight:600;color:#111827;">Hormat kami,</p><p style="margin:0 0 2px 0;font-weight:600;color:#1a73e8;">{nama_pengirim}</p><p style="margin:0;font-size:13px;color:#5f6368;">{nama_perusahaan}</p>', 0),
('footer_note', 'Mohon tidak membagikan informasi sensitif seperti kata sandi atau kode OTP melalui email.', 0),
('items_per_page', '20', 0),
('timezone', 'Asia/Jakarta', 0)
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);

-- ----------------------------------------------------------------------------
-- SEED DATA: 4 Paket Langganan Skyzz Mail (Free, Lite, Pro, Business)
-- ----------------------------------------------------------------------------
INSERT INTO `packages` (`slug`, `name`, `price_monthly`, `daily_send_limit`, `allow_api`, `has_watermark`, `watermark_text`, `description`, `is_active`, `sort_order`) VALUES
(
    'free',
    'Free',
    0,
    10,
    1,
    1,
    'Dikirim Menggunakan Aplikasi Skyzz Mail',
    'Gratis selamanya · Maks. 10 kirim email/hari · Terima email tanpa batas · Support API · Template kustom bebas (dengan watermark).',
    1,
    1
),
(
    'lite',
    'Lite',
    5000,
    50,
    1,
    0,
    '',
    'Rp 5.000/bulan · Maks. 50 kirim email/hari · Terima email tanpa batas · Support API pengiriman · Bebas watermark.',
    1,
    2
),
(
    'pro',
    'Pro',
    10000,
    100,
    1,
    0,
    '',
    'Rp 10.000/bulan · Maks. 100 kirim email/hari · Terima email tanpa batas · Support API pengiriman · Bebas watermark.',
    1,
    3
),
(
    'business',
    'Business',
    20000,
    200,
    1,
    0,
    '',
    'Rp 20.000/bulan · Maks. 200 kirim email/hari · Terima email tanpa batas · Support API pengiriman · Bebas watermark.',
    1,
    4
)
ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`);

-- ----------------------------------------------------------------------------
-- SEED DATA: Template Email Bebas Kustom Awal (Netral & Dapat Diubah)
-- ----------------------------------------------------------------------------
INSERT INTO `email_templates` (`owner_id`, `slug`, `name`, `category`, `subject_template`, `body_html`, `is_default`, `is_active`, `description`) VALUES
(
    NULL,
    'balasan-standar',
    'Balasan Standar',
    'reply',
    'Re: {subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Terima kasih telah menghubungi kami.</p><p>{isi_pesan}</p><p>Apabila masih ada pertanyaan lain, silakan balas langsung email ini.</p>',
    1,
    1,
    'Template default untuk membalas email secara ringkas dan profesional.'
),
(
    NULL,
    'konfirmasi-penerimaan-pesan',
    'Konfirmasi Penerimaan Pesan',
    'reply',
    'Re: {subjek} — Pesan Anda Telah Kami Terima',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami mengonfirmasi bahwa pesan Anda dengan subjek <strong>&ldquo;{subjek}&rdquo;</strong> telah kami terima pada {tanggal}.</p><p>{isi_pesan}</p><p>Kami akan segera menindaklanjuti permintaan Anda.</p>',
    0,
    1,
    'Konfirmasi bahwa email pengirim telah diterima.'
),
(
    NULL,
    'informasi-sedang-diproses',
    'Informasi Sedang Diproses',
    'reply',
    'Re: {subjek} — Sedang Diproses',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Permintaan Anda terkait <strong>&ldquo;{subjek}&rdquo;</strong> saat ini sedang kami proses.</p><p>{isi_pesan}</p><p>Kami akan segera mengabari Anda kembali begitu ada pembaruan.</p>',
    0,
    1,
    'Memberi kabar bahwa permintaan sedang ditangani.'
),
(
    NULL,
    'penyelesaian-masalah',
    'Penyelesaian Masalah',
    'reply_all',
    'Re: {subjek} — Selesai Ditangani',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Kami informasikan bahwa hal terkait <strong>&ldquo;{subjek}&rdquo;</strong> telah selesai ditangani.</p><p>{isi_pesan}</p><p>Jika masih diperlukan bantuan tambahan, silakan balas email ini.</p>',
    1,
    1,
    'Template default untuk Balas Semua saat melaporkan penyelesaian.'
),
(
    NULL,
    'balasan-umum-teruskan',
    'Teruskan Pesan',
    'forward',
    'Fwd: {subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>Berikut kami teruskan informasi terkait <strong>&ldquo;{subjek}&rdquo;</strong> untuk dapat ditindaklanjuti.</p><p>{isi_pesan}</p>',
    1,
    1,
    'Template default untuk meneruskan pesan.'
),
(
    NULL,
    'pesan-baru',
    'Pesan Baru Bebas Kustom',
    'compose',
    '{subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>{isi_pesan}</p><p>Silakan balas email ini apabila ada informasi yang perlu dikonfirmasi.</p>',
    1,
    1,
    'Template default netral saat menulis email baru — bebas diedit sesuai kebutuhan Anda.'
),
(
    NULL,
    'template-draft-standar',
    'Template Draft Standar',
    'draft',
    '{subjek}',
    '<p>Halo <strong>{nama_penerima}</strong>,</p><p>{isi_pesan}</p>',
    1,
    1,
    'Template default untuk konsep (draft) email.'
)
ON DUPLICATE KEY UPDATE `slug` = VALUES(`slug`);

SET FOREIGN_KEY_CHECKS = 1;
