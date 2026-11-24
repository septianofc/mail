<?php
/**
 * Skyzz Mail — Multi-User SaaS Engine
 *
 * Mencakup:
 * - RBAC (Superadmin vs User)
 * - Manajemen Akun Email / Mailbox (Superadmin membuat & mengonfigurasi, User bebas memilih)
 * - Paket Langganan (Free 10/hari, Lite 50/hari, Pro 100/hari, Business 200/hari; durasi 1–12 bulan)
 * - Penegakan Kuota Harian (pengiriman GAGAL tidak mengurangi kuota!)
 * - API Key per User (REST API pengiriman email)
 * - Pembayaran QRIS All Payment + Upload Bukti Aman + Verifikasi Manual Superadmin
 * - Penghapusan Akun Permanen (Account Deletion Policy)
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/cache.php';

/**
 * Cek apakah pengguna merupakan Superadmin (atau admin sistem penuh).
 */
function is_superadmin(?array $user = null): bool
{
    $u = $user ?? (function_exists('current_admin') ? current_admin() : null);
    if (!$u) {
        return false;
    }
    return in_array((string) ($u['role'] ?? ''), ['superadmin', 'admin'], true);
}

/**
 * Wajibkan hak akses Superadmin di sisi server (server-side RBAC enforcement).
 */
function require_superadmin(bool $jsonResponse = false): array
{
    $user = require_admin($jsonResponse);
    if (!is_superadmin($user)) {
        if ($jsonResponse) {
            json_response(['ok' => false, 'message' => 'Akses ditolak. Fitur ini khusus Superadmin.'], 403);
        }
        set_flash('danger', 'Akses ditolak. Halaman tersebut hanya dapat diakses oleh Superadmin.');
        redirect_to('dashboard.php');
    }
    return $user;
}

/**
 * Format rupiah Indonesia yang bersih.
 */
function format_rupiah(int $amount): string
{
    if ($amount <= 0) {
        return 'Gratis';
    }
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

/* ============================================================================
 * 1. MANAJEMEN MAILBOX (AKUN EMAIL YANG DISEDIAKAN SUPERADMIN)
 * ============================================================================ */

/**
 * Ambil seluruh mailbox (untuk Superadmin).
 */
function get_all_mailboxes(): array
{
    ensure_skyzz_schema();
    try {
        return db()->query(
            'SELECT * FROM mailboxes ORDER BY is_default DESC, is_active DESC, id ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        app_log('warning', 'get_all_mailboxes error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ambil mailbox aktif yang boleh dipilih oleh User (di-cache 60 detik).
 */
function get_selectable_mailboxes(bool $includeAllActiveForSuperadmin = false): array
{
    ensure_skyzz_schema();
    $cacheKey = $includeAllActiveForSuperadmin ? 'mailboxes_active_all' : 'mailboxes_selectable';
    $cached = cache_get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    try {
        if ($includeAllActiveForSuperadmin) {
            $rows = db()->query(
                'SELECT id, label, email, display_name, provider_mode, smtp_host, smtp_port, smtp_encryption, smtp_username, imap_host, imap_port, is_active, is_default, allow_user_select
                 FROM mailboxes
                 WHERE is_active = 1
                 ORDER BY is_default DESC, id ASC'
            )->fetchAll();
        } else {
            $rows = db()->query(
                'SELECT id, label, email, display_name, provider_mode, smtp_host, smtp_port, smtp_encryption, smtp_username, imap_host, imap_port, is_active, is_default, allow_user_select
                 FROM mailboxes
                 WHERE is_active = 1 AND allow_user_select = 1
                 ORDER BY is_default DESC, id ASC'
            )->fetchAll();
        }
        cache_set($cacheKey, $rows, 60);
        return $rows;
    } catch (Throwable $e) {
        app_log('warning', 'get_selectable_mailboxes error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ambil satu mailbox berdasarkan ID (termasuk dekripsi smtp_password).
 */
function get_mailbox_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    ensure_skyzz_schema();
    try {
        $stmt = db()->prepare('SELECT * FROM mailboxes WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $row['smtp_password'] = !empty($row['smtp_password_enc'])
            ? crypto_decrypt((string) $row['smtp_password_enc'])
            : '';
        return $row;
    } catch (Throwable $e) {
        app_log('warning', 'get_mailbox_by_id error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Ambil mailbox aktif yang sedang dipakai oleh pengguna saat ini.
 * Jika user belum memilih atau mailbox tidak lagi aktif, otomatis fallback ke mailbox default atau app_settings.
 */
function get_effective_mailbox(?array $user = null): array
{
    ensure_skyzz_schema();
    $u = $user ?? (function_exists('current_admin') ? current_admin() : null);
    $isSuper = is_superadmin($u);

    $chosenId = (int) ($_SESSION['active_mailbox_id'] ?? ($u['selected_mailbox_id'] ?? 0));
    if ($chosenId > 0) {
        $mb = get_mailbox_by_id($chosenId);
        if ($mb && (int) $mb['is_active'] === 1 && ($isSuper || (int) $mb['allow_user_select'] === 1)) {
            return $mb;
        }
    }

    // Ambil mailbox default aktif pertama
    try {
        $sql = $isSuper
            ? 'SELECT * FROM mailboxes WHERE is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1'
            : 'SELECT * FROM mailboxes WHERE is_active = 1 AND allow_user_select = 1 ORDER BY is_default DESC, id ASC LIMIT 1';
        $row = db()->query($sql)->fetch();
        if ($row) {
            $row['smtp_password'] = !empty($row['smtp_password_enc'])
                ? crypto_decrypt((string) $row['smtp_password_enc'])
                : '';
            return $row;
        }
    } catch (Throwable $e) {
        app_log('warning', 'get_effective_mailbox fallback: ' . $e->getMessage());
    }

    // Fallback ke app_settings
    $settings = get_all_settings();
    $email = strtolower(trim((string) ($settings['gmail_app_password_email'] ?: $settings['support_email'] ?: DEFAULT_OFFICIAL_GMAIL)));
    return [
        'id'                => 0,
        'label'             => 'Default (' . $email . ')',
        'email'             => $email,
        'display_name'      => (string) ($settings['official_name'] ?: DEFAULT_OFFICIAL_NAME),
        'provider_mode'     => (string) ($settings['mail_connection_mode'] ?: 'app_password'),
        'smtp_host'         => (string) ($settings['gmail_smtp_host'] ?: 'smtp.gmail.com'),
        'smtp_port'         => (int) ($settings['gmail_smtp_port'] ?: 465),
        'smtp_encryption'   => (string) ($settings['gmail_smtp_encryption'] ?: 'ssl'),
        'smtp_username'     => $email,
        'smtp_password'     => (string) ($settings['gmail_app_password_secret'] ?? ''),
        'imap_host'         => (string) ($settings['gmail_imap_host'] ?: 'imap.gmail.com'),
        'imap_port'         => (int) ($settings['gmail_imap_port'] ?: 993),
        'is_active'         => 1,
        'is_default'        => 1,
        'allow_user_select' => 1,
    ];
}

/**
 * Simpan (tambah/ubah) mailbox oleh Superadmin.
 */
function save_mailbox(array $data, int $adminId): int
{
    ensure_skyzz_schema();
    $id          = (int) ($data['id'] ?? 0);
    $label       = trim((string) ($data['label'] ?? ''));
    $email       = strtolower(trim((string) ($data['email'] ?? '')));
    $displayName = trim((string) ($data['display_name'] ?? ''));
    $mode        = ($data['provider_mode'] ?? 'app_password') === 'oauth' ? 'oauth' : 'app_password';
    $smtpHost    = trim((string) ($data['smtp_host'] ?? 'smtp.gmail.com')) ?: 'smtp.gmail.com';
    $smtpPort    = max(1, min(65535, (int) ($data['smtp_port'] ?? 465)));
    $smtpEnc     = in_array(($data['smtp_encryption'] ?? 'ssl'), ['ssl', 'tls', 'none'], true) ? (string) $data['smtp_encryption'] : 'ssl';
    $smtpUser    = strtolower(trim((string) ($data['smtp_username'] ?? ''))) ?: $email;
    $smtpPassRaw = str_replace(' ', '', trim((string) ($data['smtp_password'] ?? '')));
    $imapHost    = trim((string) ($data['imap_host'] ?? 'imap.gmail.com')) ?: 'imap.gmail.com';
    $imapPort    = max(1, min(65535, (int) ($data['imap_port'] ?? 993)));
    $isActive    = !empty($data['is_active']) ? 1 : 0;
    $isDefault   = !empty($data['is_default']) ? 1 : 0;
    $allowUser   = !empty($data['allow_user_select']) ? 1 : 0;

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Alamat email mailbox tidak valid.');
    }
    if ($label === '') {
        $label = $email;
    }
    if ($displayName === '') {
        $displayName = get_setting('app_name', DEFAULT_APP_NAME);
    }

    $pdo = db();
    if ($isDefault === 1) {
        $pdo->exec('UPDATE mailboxes SET is_default = 0');
    }

    if ($id > 0) {
        $existing = get_mailbox_by_id($id);
        if (!$existing) {
            throw new RuntimeException('Mailbox tidak ditemukan.');
        }
        $encPass = $smtpPassRaw !== ''
            ? crypto_encrypt($smtpPassRaw)
            : ($existing['smtp_password_enc'] ?? null);

        $stmt = $pdo->prepare(
            'UPDATE mailboxes
             SET label = :lbl, email = :em, display_name = :dn, provider_mode = :pm,
                 smtp_host = :sh, smtp_port = :sp, smtp_encryption = :se,
                 smtp_username = :su, smtp_password_enc = :pw,
                 imap_host = :ih, imap_port = :ip,
                 is_active = :act, is_default = :def, allow_user_select = :allow
             WHERE id = :id'
        );
        $stmt->execute([
            ':lbl'   => $label,
            ':em'    => $email,
            ':dn'    => $displayName,
            ':pm'    => $mode,
            ':sh'    => $smtpHost,
            ':sp'    => $smtpPort,
            ':se'    => $smtpEnc,
            ':su'    => $smtpUser,
            ':pw'    => $encPass,
            ':ih'    => $imapHost,
            ':ip'    => $imapPort,
            ':act'   => $isActive,
            ':def'   => $isDefault,
            ':allow' => $allowUser,
            ':id'    => $id,
        ]);
        $savedId = $id;
    } else {
        $encPass = $smtpPassRaw !== '' ? crypto_encrypt($smtpPassRaw) : null;
        $stmt = $pdo->prepare(
            'INSERT INTO mailboxes
                (label, email, display_name, provider_mode, smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password_enc, imap_host, imap_port, is_active, is_default, allow_user_select, created_by)
             VALUES
                (:lbl, :em, :dn, :pm, :sh, :sp, :se, :su, :pw, :ih, :ip, :act, :def, :allow, :uid)'
        );
        $stmt->execute([
            ':lbl'   => $label,
            ':em'    => $email,
            ':dn'    => $displayName,
            ':pm'    => $mode,
            ':sh'    => $smtpHost,
            ':sp'    => $smtpPort,
            ':se'    => $smtpEnc,
            ':su'    => $smtpUser,
            ':pw'    => $encPass,
            ':ih'    => $imapHost,
            ':ip'    => $imapPort,
            ':act'   => $isActive,
            ':def'   => $isDefault,
            ':allow' => $allowUser,
            ':uid'   => $adminId,
        ]);
        $savedId = (int) $pdo->lastInsertId();
    }

    cache_forget_prefix('mailboxes_');
    cache_forget_prefix('mb_' . $savedId . '_');
    return $savedId;
}

/**
 * Hapus mailbox oleh Superadmin.
 */
function delete_mailbox(int $id): void
{
    ensure_skyzz_schema();
    $pdo = db();
    $stmt = $pdo->prepare('DELETE FROM mailboxes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $upd = $pdo->prepare('UPDATE admins SET selected_mailbox_id = NULL WHERE selected_mailbox_id = :id');
    $upd->execute([':id' => $id]);
    cache_forget_prefix('mailboxes_');
    cache_forget_prefix('mb_' . $id . '_');
}

/**
 * Ubah mailbox aktif untuk user yang sedang login.
 */
function set_user_selected_mailbox(int $userId, int $mailboxId, bool $isSuper = false): array
{
    $mb = get_mailbox_by_id($mailboxId);
    if (!$mb || (int) $mb['is_active'] !== 1) {
        throw new RuntimeException('Akun email yang dipilih tidak aktif atau tidak ditemukan.');
    }
    if (!$isSuper && (int) $mb['allow_user_select'] !== 1) {
        throw new RuntimeException('Akun email tersebut tidak tersedia untuk pengguna reguler.');
    }

    $stmt = db()->prepare('UPDATE admins SET selected_mailbox_id = :mid WHERE id = :uid');
    $stmt->execute([':mid' => $mailboxId, ':uid' => $userId]);
    $_SESSION['active_mailbox_id'] = $mailboxId;
    unset($_SESSION['admin_cache']);

    return $mb;
}

/* ============================================================================
 * 2. PAKET LANGGANAN & KUOTA HARIAN (GAGAL KIRIM = KUOTA TIDAK BERKURANG)
 * ============================================================================ */

/**
 * Ambil seluruh paket langganan (diurutkan sort_order).
 */
function get_packages(bool $onlyActive = false): array
{
    ensure_skyzz_schema();
    $cacheKey = $onlyActive ? 'packages_active' : 'packages_all';
    $cached = cache_get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }

    try {
        $sql = $onlyActive
            ? 'SELECT * FROM packages WHERE is_active = 1 ORDER BY sort_order ASC, price_monthly ASC'
            : 'SELECT * FROM packages ORDER BY sort_order ASC, price_monthly ASC';
        $rows = db()->query($sql)->fetchAll();
        cache_set($cacheKey, $rows, 120);
        return $rows;
    } catch (Throwable $e) {
        app_log('warning', 'get_packages error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Ambil satu paket berdasarkan slug.
 */
function get_package_by_slug(string $slug): array
{
    $slug = strtolower(trim($slug));
    foreach (get_packages(false) as $pkg) {
        if (strtolower((string) $pkg['slug']) === $slug) {
            return $pkg;
        }
    }
    return [
        'slug'             => 'free',
        'name'             => 'Free',
        'price_monthly'    => 0,
        'daily_send_limit' => 10,
        'allow_api'        => 1,
        'has_watermark'    => 1,
        'watermark_text'   => FREE_WATERMARK_DEFAULT,
        'description'      => 'Gratis selamanya · Maks. 10 email/hari',
        'is_active'        => 1,
        'sort_order'       => 1,
    ];
}

/**
 * Ambil paket efektif pengguna (jika paket berbayar kedaluwarsa, otomatis kembali ke Free).
 */
function get_user_effective_plan(array $user): array
{
    if (is_superadmin($user)) {
        return [
            'slug'             => 'superadmin',
            'name'             => 'Superadmin (Unlimited)',
            'price_monthly'    => 0,
            'daily_send_limit' => 0, // 0 = tanpa batas
            'allow_api'        => 1,
            'has_watermark'    => 0,
            'watermark_text'   => '',
            'is_unlimited'     => true,
            'expires_at'       => null,
            'is_expired'       => false,
        ];
    }

    $slug      = strtolower(trim((string) ($user['plan_slug'] ?? 'free')));
    $expiresAt = $user['plan_expires_at'] ?? null;
    $isExpired = false;

    if ($slug !== 'free' && $expiresAt) {
        $expTs = strtotime((string) $expiresAt);
        if ($expTs !== false && $expTs < time()) {
            $isExpired = true;
            $slug = 'free';
            // Kembalikan otomatis ke free di database
            try {
                $upd = db()->prepare("UPDATE admins SET plan_slug = 'free', plan_expires_at = NULL WHERE id = :id");
                $upd->execute([':id' => (int) $user['id']]);
                unset($_SESSION['admin_cache']);
            } catch (Throwable $e) {
            }
        }
    }

    $pkg = get_package_by_slug($slug);
    $pkg['is_unlimited'] = false;
    $pkg['expires_at']   = ($slug === 'free') ? null : $expiresAt;
    $pkg['is_expired']   = $isExpired;
    return $pkg;
}

/**
 * Hitung jumlah email yang BERHASIL terkirim hari ini (WIB) untuk user tertentu.
 * Hanya status='sent' yang dihitung; email yang gagal kirim ('failed') TIDAK dihitung.
 */
function get_user_daily_sent_count(int $userId, ?string $date = null): int
{
    ensure_skyzz_schema();
    $today = $date ?: date('Y-m-d');
    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM sent_email_logs
             WHERE user_id = :uid AND sent_date = :d AND status = 'sent'"
        );
        $stmt->execute([
            ':uid' => $userId,
            ':d'   => $today,
        ]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        app_log('warning', 'get_user_daily_sent_count error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Kembalikan status kuota harian lengkap pengguna.
 */
function get_user_quota_status(array $user): array
{
    $plan  = get_user_effective_plan($user);
    $used  = get_user_daily_sent_count((int) $user['id']);
    $limit = (int) ($plan['daily_send_limit'] ?? 10);
    $isUnlimited = !empty($plan['is_unlimited']) || $limit <= 0;

    return [
        'plan'           => $plan,
        'used_today'     => $used,
        'daily_limit'    => $isUnlimited ? 0 : $limit,
        'remaining'      => $isUnlimited ? 999999 : max(0, $limit - $used),
        'is_unlimited'   => $isUnlimited,
        'percent_used'   => $isUnlimited ? 0 : ($limit > 0 ? min(100, (int) round(($used / $limit) * 100)) : 100),
        'reset_date'     => date('Y-m-d', strtotime('+1 day')) . ' 00:00 WIB',
    ];
}

/**
 * Validasi apakah user masih memiliki kuota kirim hari ini.
 * Melempar RuntimeException jika batas harian telah tercapai.
 */
function check_user_send_quota(array $user): array
{
    $quota = get_user_quota_status($user);
    if (!$quota['is_unlimited'] && $quota['remaining'] <= 0) {
        throw new RuntimeException(
            sprintf(
                'Batas pengiriman harian paket %s (%d email/hari) sudah tercapai (%d/%d terkirim hari ini). Terima pesan tetap tanpa batas, atau upgrade paket di menu Paket & QRIS.',
                (string) $quota['plan']['name'],
                (int) $quota['daily_limit'],
                (int) $quota['used_today'],
                (int) $quota['daily_limit']
            )
        );
    }
    return $quota;
}

/**
 * Catat hasil pengiriman ke sent_email_logs.
 * PENTING: Hanya status='sent' yang dihitung sebagai pemakaian kuota harian.
 * Jika pengiriman gagal (status='failed'), kuota pengguna TIDAK berkurang.
 */
function record_sent_email_log(array $data): int
{
    ensure_skyzz_schema();
    try {
        $status = ($data['status'] ?? 'sent') === 'failed' ? 'failed' : 'sent';
        $sentVia = ($data['sent_via'] ?? 'web') === 'api' ? 'api' : 'web';
        $stmt = db()->prepare(
            'INSERT INTO sent_email_logs
                (user_id, mailbox_id, from_email, to_emails, cc_emails, subject, body_preview, body_html, transport, message_id, imap_sent_synced, sent_via, status, error_message, sent_date)
             VALUES
                (:uid, :mid, :from_em, :to_em, :cc_em, :subj, :prev, :html, :tr, :msgid, :synced, :via, :st, :err, :sdate)'
        );
        $stmt->execute([
            ':uid'     => isset($data['user_id']) ? (int) $data['user_id'] : null,
            ':mid'     => isset($data['mailbox_id']) && (int) $data['mailbox_id'] > 0 ? (int) $data['mailbox_id'] : null,
            ':from_em' => substr((string) ($data['from_email'] ?? ''), 0, 190),
            ':to_em'   => substr((string) ($data['to_emails'] ?? ''), 0, 495),
            ':cc_em'   => isset($data['cc_emails']) && $data['cc_emails'] !== '' ? substr((string) $data['cc_emails'], 0, 495) : null,
            ':subj'    => substr((string) ($data['subject'] ?? '(Tanpa Subjek)'), 0, 250),
            ':prev'    => substr(trim((string) ($data['body_preview'] ?? '')), 0, 300),
            ':html'    => (string) ($data['body_html'] ?? ''),
            ':tr'      => substr((string) ($data['transport'] ?? 'phpmailer_gmail_smtp'), 0, 40),
            ':msgid'   => isset($data['message_id']) ? substr((string) $data['message_id'], 0, 250) : null,
            ':synced'  => !empty($data['imap_sent_synced']) ? 1 : 0,
            ':via'     => $sentVia,
            ':st'      => $status,
            ':err'     => isset($data['error_message']) && $data['error_message'] !== '' ? substr(redact_sensitive_text((string) $data['error_message']), 0, 395) : null,
            ':sdate'   => date('Y-m-d'),
        ]);
        return (int) db()->lastInsertId();
    } catch (Throwable $e) {
        app_log('warning', 'record_sent_email_log error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Ambil daftar log email terkirim dari database lokal (fallback & pelengkap folder Terkirim).
 */
function get_local_sent_messages(?int $userId, ?int $mailboxId, bool $isSuper, int $limit = 20, int $offset = 0, string $search = ''): array
{
    ensure_skyzz_schema();
    try {
        $where  = ["status = 'sent'"];
        $params = [];

        if (!$isSuper && $userId !== null) {
            $where[] = 'user_id = :uid';
            $params[':uid'] = $userId;
        } elseif ($mailboxId !== null && $mailboxId > 0) {
            $where[] = '(mailbox_id = :mid OR mailbox_id IS NULL)';
            $params[':mid'] = $mailboxId;
        }

        if (trim($search) !== '') {
            $where[] = '(to_emails LIKE :q1 OR subject LIKE :q2 OR body_preview LIKE :q3)';
            $like = '%' . trim($search) . '%';
            $params[':q1'] = $like;
            $params[':q2'] = $like;
            $params[':q3'] = $like;
        }

        $whereSql = implode(' AND ', $where);
        $countStmt = db()->prepare("SELECT COUNT(*) FROM sent_email_logs WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT * FROM sent_email_logs WHERE {$whereSql} ORDER BY id DESC LIMIT :lim OFFSET :off";
        $stmt = db()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':lim', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $messages = [];
        foreach ($rows as $row) {
            $toFirst = explode(',', (string) $row['to_emails'])[0] ?? '';
            $messages[] = [
                'id'              => 'local:' . (int) $row['id'],
                'thread_id'       => 'local:' . (int) $row['id'],
                'from_name'       => 'Kepada: ' . trim($toFirst),
                'from_email'      => (string) $row['from_email'],
                'to'              => (string) $row['to_emails'],
                'subject'         => (string) $row['subject'],
                'snippet'         => (string) ($row['body_preview'] ?? ''),
                'date_raw'        => (string) $row['created_at'],
                'date_short'      => format_short_date((string) $row['created_at']),
                'date_full'       => format_tanggal_id((string) $row['created_at'], true),
                'is_unread'       => false,
                'is_starred'      => false,
                'has_attachments' => false,
                'labels'          => ['SENT', strtoupper((string) $row['sent_via'])],
                'imap_synced'     => (int) $row['imap_sent_synced'] === 1,
                'sent_via'        => (string) $row['sent_via'],
            ];
        }

        return [
            'messages'        => $messages,
            'next_page_token' => ($offset + $limit < $total) ? (string) ($offset + $limit) : null,
            'result_estimate' => $total,
            'transport'       => 'local_sent_log',
        ];
    } catch (Throwable $e) {
        app_log('warning', 'get_local_sent_messages error: ' . $e->getMessage());
        return [
            'messages'        => [],
            'next_page_token' => null,
            'result_estimate' => 0,
            'transport'       => 'local_sent_log',
        ];
    }
}

/**
 * Baca detail pesan lokal dari sent_email_logs (id berformat local:123).
 */
function get_local_sent_message_detail(int $logId, ?array $viewer = null): array
{
    ensure_skyzz_schema();
    $stmt = db()->prepare('SELECT * FROM sent_email_logs WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $logId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new RuntimeException('Riwayat pesan terkirim tidak ditemukan.');
    }

    if ($viewer && !is_superadmin($viewer) && (int) ($row['user_id'] ?? 0) !== (int) $viewer['id']) {
        throw new RuntimeException('Anda tidak memiliki izin melihat pesan ini.');
    }

    $safeHtml = !empty($row['body_html'])
        ? sanitize_email_html((string) $row['body_html'])
        : '<p>' . e((string) ($row['body_preview'] ?? '')) . '</p>';

    $msg = [
        'id'             => 'local:' . (int) $row['id'],
        'thread_id'      => 'local:' . (int) $row['id'],
        'message_id_hdr' => (string) ($row['message_id'] ?? ''),
        'references_hdr' => '',
        'from_raw'       => (string) $row['from_email'],
        'from_name'      => (string) $row['from_email'],
        'from_email'     => (string) $row['from_email'],
        'to'             => (string) $row['to_emails'],
        'cc'             => (string) ($row['cc_emails'] ?? ''),
        'reply_to'       => (string) $row['from_email'],
        'subject'        => (string) $row['subject'],
        'date_raw'       => (string) $row['created_at'],
        'date_full'      => format_tanggal_id((string) $row['created_at'], true),
        'body_html'      => $safeHtml,
        'body_plain'     => html_to_plain_text($safeHtml),
        'attachments'    => [],
        'is_unread'      => false,
        'is_starred'     => false,
        'labels'         => ['SENT'],
    ];

    return [
        'thread_id' => $msg['thread_id'],
        'subject'   => $msg['subject'],
        'messages'  => [$msg],
        'transport' => 'local_sent_log',
    ];
}

/* ============================================================================
 * 3. API KEY MANAGEMENT (REST API PENGIRIMAN EMAIL)
 * ============================================================================ */

/**
 * Buat API Key baru untuk user (plain key hanya dikembalikan sekali).
 */
function create_user_api_key(int $userId, string $name): array
{
    ensure_skyzz_schema();
    $cleanName = trim($name) !== '' ? substr(trim($name), 0, 95) : 'API Key ' . date('d M Y');
    $plainKey  = 'skz_' . bin2hex(random_bytes(24));
    $prefix    = substr($plainKey, 0, 12) . '...';
    $hash      = hash('sha256', $plainKey);

    $stmt = db()->prepare(
        'INSERT INTO user_api_keys (user_id, name, key_prefix, key_hash, is_active)
         VALUES (:uid, :name, :pref, :hash, 1)'
    );
    $stmt->execute([
        ':uid'  => $userId,
        ':name' => $cleanName,
        ':pref' => $prefix,
        ':hash' => $hash,
    ]);

    return [
        'id'         => (int) db()->lastInsertId(),
        'name'       => $cleanName,
        'key_prefix' => $prefix,
        'plain_key'  => $plainKey,
    ];
}

/**
 * Daftar API Key milik user tertentu.
 */
function list_user_api_keys(int $userId): array
{
    ensure_skyzz_schema();
    $stmt = db()->prepare(
        'SELECT id, name, key_prefix, last_used_at, is_active, created_at
         FROM user_api_keys
         WHERE user_id = :uid
         ORDER BY id DESC'
    );
    $stmt->execute([':uid' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Cabut / hapus API Key milik user (dengan verifikasi kepemilikan).
 */
function revoke_user_api_key(int $keyId, int $userId, bool $isSuper = false): bool
{
    ensure_skyzz_schema();
    if ($isSuper) {
        $stmt = db()->prepare('DELETE FROM user_api_keys WHERE id = :id');
        return $stmt->execute([':id' => $keyId]);
    }
    $stmt = db()->prepare('DELETE FROM user_api_keys WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $keyId, ':uid' => $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Autentikasi request REST API menggunakan Bearer Token atau X-API-Key.
 * Mengembalikan array data user lengkap atau null jika tidak valid.
 */
function authenticate_api_key(): ?array
{
    ensure_skyzz_schema();
    $rawKey = '';

    $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/^\s*Bearer\s+([A-Za-z0-9_\-\.]+)\s*$/i', $authHeader, $m)) {
        $rawKey = trim($m[1]);
    }
    if ($rawKey === '' && !empty($_SERVER['HTTP_X_API_KEY'])) {
        $rawKey = trim((string) $_SERVER['HTTP_X_API_KEY']);
    }

    if ($rawKey === '' || strlen($rawKey) < 20) {
        return null;
    }

    $hash = hash('sha256', $rawKey);
    $stmt = db()->prepare(
        'SELECT k.id AS api_key_id, k.key_hash, u.*
         FROM user_api_keys k
         INNER JOIN admins u ON u.id = k.user_id
         WHERE k.key_hash = :hash AND k.is_active = 1 AND u.is_active = 1
         LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $row = $stmt->fetch();
    if (!$row || !hash_equals((string) $row['key_hash'], $hash)) {
        return null;
    }

    try {
        $upd = db()->prepare('UPDATE user_api_keys SET last_used_at = NOW() WHERE id = :id');
        $upd->execute([':id' => (int) $row['api_key_id']]);
    } catch (Throwable $e) {
    }

    unset($row['password_hash'], $row['key_hash']);
    return $row;
}

/* ============================================================================
 * 4. UPLOAD GAMBAR AMAN & PEMBAYARAN QRIS ALL PAYMENT
 * ============================================================================ */

/**
 * Simpan unggahan gambar (QRIS atau bukti bayar) secara aman di storage/uploads/{subdir}/.
 * Memvalidasi MIME sebenarnya via finfo/getimagesize, ekstensi, dan ukuran <= 5 MB.
 * Mengembalikan path relatif aman (mis. "proofs/ab12cd34.jpg").
 */
function save_uploaded_image_safe(array $file, string $subdir): string
{
    $allowedSubdirs = ['qris', 'proofs'];
    if (!in_array($subdir, $allowedSubdirs, true)) {
        throw new RuntimeException('Direktori unggahan tidak diizinkan.');
    }

    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Gagal mengunggah file gambar. Pastikan file sudah dipilih dan ukurannya di bawah 5 MB.');
    }

    $tmp  = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Unggahan file tidak valid.');
    }

    $maxBytes = defined('MAX_IMAGE_UPLOAD_SIZE') ? MAX_IMAGE_UPLOAD_SIZE : (5 * 1024 * 1024);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Ukuran gambar maksimal 5 MB.');
    }

    $mime = '';
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = (string) ($finfo->file($tmp) ?: '');
    } elseif (function_exists('getimagesize')) {
        $info = @getimagesize($tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    }

    $mimeToExt = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($mimeToExt[$mime])) {
        throw new RuntimeException('Format gambar wajib JPG, PNG, atau WEBP.');
    }

    // Validasi tambahan bahwa konten benar-benar gambar valid
    if (function_exists('getimagesize') && @getimagesize($tmp) === false) {
        throw new RuntimeException('File yang diunggah bukan gambar yang valid.');
    }

    $uploadsRoot = defined('UPLOADS_PATH') ? UPLOADS_PATH : (STORAGE_PATH . '/uploads');
    $targetDir   = $uploadsRoot . '/' . $subdir;
    if (!is_dir($targetDir)) {
        @mkdir($targetDir, 0750, true);
    }

    $filename   = bin2hex(random_bytes(16)) . '.' . $mimeToExt[$mime];
    $targetPath = $targetDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $targetPath)) {
        throw new RuntimeException('Gagal menyimpan gambar ke penyimpanan server.');
    }
    @chmod($targetPath, 0640);

    return $subdir . '/' . $filename;
}

/**
 * Buat pesanan upgrade paket via QRIS All Payment (durasi 1 s.d. 12 bulan).
 */
function create_payment_order(int $userId, string $packageSlug, int $durationMonths, array $proofFile, string $payerNote = ''): array
{
    ensure_skyzz_schema();
    $pkg = get_package_by_slug($packageSlug);
    if ($pkg['slug'] === 'free' || (int) $pkg['price_monthly'] <= 0 || (int) $pkg['is_active'] !== 1) {
        throw new RuntimeException('Silakan pilih paket berbayar yang valid (Lite, Pro, atau Business).');
    }

    if ($durationMonths < 1 || $durationMonths > 12) {
        throw new RuntimeException('Durasi langganan minimal 1 bulan dan maksimal 12 bulan (1 tahun).');
    }

    $proofPath = save_uploaded_image_safe($proofFile, 'proofs');
    $total     = (int) $pkg['price_monthly'] * $durationMonths;
    $orderCode = 'SKZ-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

    $stmt = db()->prepare(
        'INSERT INTO payment_orders (order_code, user_id, package_slug, duration_months, amount_total, proof_image_path, payer_note, status)
         VALUES (:code, :uid, :pkg, :dur, :amt, :proof, :note, "pending")'
    );
    $stmt->execute([
        ':code'  => $orderCode,
        ':uid'   => $userId,
        ':pkg'   => $pkg['slug'],
        ':dur'   => $durationMonths,
        ':amt'   => $total,
        ':proof' => $proofPath,
        ':note'  => substr(trim($payerNote), 0, 290),
    ]);

    log_activity(
        'payment_submitted',
        sprintf('Pengguna mengirim bukti pembayaran QRIS %s (%s, %d bulan, %s).', $orderCode, $pkg['name'], $durationMonths, format_rupiah($total)),
        'payment',
        $orderCode,
        'success',
        $userId
    );

    return [
        'id'         => (int) db()->lastInsertId(),
        'order_code' => $orderCode,
        'amount'     => $total,
    ];
}

/**
 * Setujui pesanan QRIS secara atomik & aktifkan/perpanjang paket user.
 */
function approve_payment_order(int $orderId, int $superadminId, string $adminNote = ''): void
{
    ensure_skyzz_schema();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM payment_orders WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new RuntimeException('Pesanan pembayaran tidak ditemukan.');
        }
        if ((string) $order['status'] !== 'pending') {
            throw new RuntimeException('Pesanan ini sudah diproses sebelumnya.');
        }

        $uStmt = $pdo->prepare('SELECT id, plan_slug, plan_expires_at FROM admins WHERE id = :uid FOR UPDATE');
        $uStmt->execute([':uid' => (int) $order['user_id']]);
        $user = $uStmt->fetch();
        if (!$user) {
            throw new RuntimeException('Akun pengguna tidak ditemukan.');
        }

        $months = max(1, min(12, (int) $order['duration_months']));
        $baseTs = time();
        if (
            strtolower((string) $user['plan_slug']) === strtolower((string) $order['package_slug'])
            && !empty($user['plan_expires_at'])
        ) {
            $currExp = strtotime((string) $user['plan_expires_at']);
            if ($currExp !== false && $currExp > time()) {
                $baseTs = $currExp;
            }
        }
        $newExpiresAt = date('Y-m-d H:i:s', strtotime('+' . $months . ' months', $baseTs));

        $updUser = $pdo->prepare('UPDATE admins SET plan_slug = :pkg, plan_expires_at = :exp WHERE id = :uid');
        $updUser->execute([
            ':pkg' => (string) $order['package_slug'],
            ':exp' => $newExpiresAt,
            ':uid' => (int) $order['user_id'],
        ]);

        $updOrder = $pdo->prepare(
            'UPDATE payment_orders
             SET status = "approved", admin_note = :note, reviewed_by = :adm, reviewed_at = NOW()
             WHERE id = :id'
        );
        $updOrder->execute([
            ':note' => substr(trim($adminNote), 0, 290) ?: 'Pembayaran QRIS terverifikasi.',
            ':adm'  => $superadminId,
            ':id'   => $orderId,
        ]);

        $pdo->commit();
        log_activity(
            'payment_approved',
            sprintf('Superadmin menyetujui pembayaran %s — paket %s aktif hingga %s.', $order['order_code'], $order['package_slug'], $newExpiresAt),
            'payment',
            (string) $order['order_code'],
            'success',
            $superadminId
        );
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Tolak pesanan QRIS dengan alasan yang jelas untuk user.
 */
function reject_payment_order(int $orderId, int $superadminId, string $reason): void
{
    ensure_skyzz_schema();
    $cleanReason = substr(trim($reason), 0, 290) ?: 'Bukti pembayaran tidak valid atau nominal belum sesuai.';
    $stmt = db()->prepare(
        'UPDATE payment_orders
         SET status = "rejected", admin_note = :note, reviewed_by = :adm, reviewed_at = NOW()
         WHERE id = :id AND status = "pending"'
    );
    $stmt->execute([
        ':note' => $cleanReason,
        ':adm'  => $superadminId,
        ':id'   => $orderId,
    ]);
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('Pesanan tidak ditemukan atau sudah diproses.');
    }
    log_activity('payment_rejected', 'Superadmin menolak pesanan #' . $orderId . ': ' . $cleanReason, 'payment', (string) $orderId, 'warning', $superadminId);
}

/* ============================================================================
 * 5. PENGHAPUSAN AKUN PERMANEN (ACCOUNT DELETION POLICY)
 * ============================================================================ */

/**
 * Hapus akun pengguna beserta data pribadinya secara permanen.
 * Mencegah penghapusan jika merupakan satu-satunya Superadmin aktif.
 */
function delete_user_account_permanently(int $userId, string $confirmPassword, bool $bypassPasswordCheck = false): void
{
    ensure_skyzz_schema();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, name, email, username, role, password_hash FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new RuntimeException('Akun tidak ditemukan.');
    }

    if (!$bypassPasswordCheck && !password_verify($confirmPassword, (string) $user['password_hash'])) {
        throw new RuntimeException('Kata sandi konfirmasi tidak sesuai.');
    }

    if (in_array((string) $user['role'], ['superadmin', 'admin'], true)) {
        $otherSupers = (int) $pdo->query(
            "SELECT COUNT(*) FROM admins WHERE role IN ('superadmin', 'admin') AND is_active = 1 AND id != " . (int) $userId
        )->fetchColumn();
        if ($otherSupers === 0) {
            throw new RuntimeException('Akun Superadmin terakhir tidak dapat dihapus agar sistem tetap memiliki pengelola.');
        }
    }

    // Hapus file bukti pembayaran milik user di disk secara aman
    $proofStmt = $pdo->prepare('SELECT proof_image_path FROM payment_orders WHERE user_id = :uid');
    $proofStmt->execute([':uid' => $userId]);
    $uploadsRoot = defined('UPLOADS_PATH') ? UPLOADS_PATH : (STORAGE_PATH . '/uploads');
    $realRoot = realpath($uploadsRoot);
    foreach ($proofStmt->fetchAll() as $pRow) {
        $rel = ltrim((string) ($pRow['proof_image_path'] ?? ''), '/');
        if ($rel !== '' && $realRoot !== false) {
            $candidate = realpath($uploadsRoot . '/' . $rel);
            if ($candidate !== false && str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR) && is_file($candidate)) {
                @unlink($candidate);
            }
        }
    }

    $pdo->beginTransaction();
    try {
        foreach ([
            'DELETE FROM user_api_keys WHERE user_id = :uid',
            'DELETE FROM payment_orders WHERE user_id = :uid',
            'DELETE FROM email_templates WHERE owner_id = :uid',
            'DELETE FROM sent_email_logs WHERE user_id = :uid',
            'DELETE FROM activity_logs WHERE admin_id = :uid',
            'DELETE FROM admins WHERE id = :uid',
        ] as $sql) {
            $del = $pdo->prepare($sql);
            $del->execute([':uid' => $userId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    cache_forget_prefix('user_' . $userId . '_');
}
