<?php
/**
 * Skyzz Mail — Authentication, Registration, Session Cache & Login Rate Limiting
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/saas.php';

/**
 * Cek apakah pengguna sedang login.
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

/**
 * Ambil data pengguna (Superadmin atau User) yang sedang login.
 * Menggunakan session cache 60 detik (cache login) agar perpindahan halaman & AJAX sangat cepat.
 */
function current_admin(bool $refresh = false): ?array
{
    static $admin = null;
    if (!is_logged_in()) {
        return null;
    }

    if ($admin !== null && !$refresh) {
        return $admin;
    }

    // Gunakan cache sesi (TTL 60 detik) untuk mengurangi query berulang per klik
    if (
        !$refresh
        && isset($_SESSION['admin_cache']['data'], $_SESSION['admin_cache']['ts'])
        && is_array($_SESSION['admin_cache']['data'])
        && ((int) $_SESSION['admin_cache']['data']['id'] === (int) $_SESSION['admin_id'])
        && (time() - (int) $_SESSION['admin_cache']['ts']) < 60
    ) {
        $admin = $_SESSION['admin_cache']['data'];
        return $admin;
    }

    ensure_skyzz_schema();

    try {
        $stmt = db()->prepare(
            'SELECT id, name, email, username, role, job_title, avatar_url,
                    selected_mailbox_id, plan_slug, plan_expires_at,
                    custom_sender_name, custom_signature, custom_footer_note,
                    is_active, last_login_at, last_login_ip, created_at
             FROM admins WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([':id' => $_SESSION['admin_id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        app_log('error', 'current_admin DB error: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        logout_admin();
        return null;
    }

    $admin = $row;
    $_SESSION['admin_role']  = (string) $row['role'];
    $_SESSION['admin_cache'] = [
        'ts'   => time(),
        'data' => $row,
    ];
    return $admin;
}

/**
 * Wajibkan login untuk halaman PHP (berlaku untuk Superadmin maupun User).
 */
function require_admin(bool $jsonResponse = false): array
{
    if (!is_app_installed()) {
        if ($jsonResponse) {
            json_response(['ok' => false, 'message' => 'Aplikasi belum diinstal.'], 503);
        }
        redirect_to('install.php');
    }

    $admin = current_admin();
    if (!$admin) {
        if ($jsonResponse) {
            json_response(['ok' => false, 'message' => 'Sesi login telah berakhir. Silakan login kembali.'], 401);
        }
        redirect_to('login.php');
    }

    // Timeout sesi tidak aktif (8 jam)
    $lastActive = (int) ($_SESSION['last_activity'] ?? time());
    if ((time() - $lastActive) > 28800) {
        logout_admin();
        if ($jsonResponse) {
            json_response(['ok' => false, 'message' => 'Sesi kedaluwarsa karena tidak aktif.'], 401);
        }
        set_flash('warning', 'Sesi Anda telah berakhir karena tidak aktif. Silakan masuk kembali.');
        redirect_to('login.php');
    }

    $_SESSION['last_activity'] = time();
    return $admin;
}

/**
 * Cek apakah IP atau identifier sedang terkena rate limit login.
 */
function check_login_rate_limit(string $identifier): array
{
    try {
        $ip     = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $cutoff = date('Y-m-d H:i:s', time() - (LOGIN_LOCKOUT_MINUTES * 60));

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS fail_count, MAX(attempted_at) AS last_attempt
             FROM login_attempts
             WHERE was_successful = 0
               AND attempted_at >= :cutoff
               AND (ip_address = :ip OR identifier = :ident)'
        );
        $stmt->execute([
            ':cutoff' => $cutoff,
            ':ip'     => $ip,
            ':ident'  => strtolower(trim($identifier)),
        ]);
        $row = $stmt->fetch() ?: ['fail_count' => 0, 'last_attempt' => null];
        $count = (int) $row['fail_count'];

        if ($count >= LOGIN_MAX_ATTEMPTS) {
            $lastTs = $row['last_attempt'] ? strtotime((string) $row['last_attempt']) : time();
            $retryAfter = max(1, (int) ceil((($lastTs + LOGIN_LOCKOUT_MINUTES * 60) - time()) / 60));
            return ['blocked' => true, 'attempts' => $count, 'retry_minutes' => $retryAfter];
        }

        return ['blocked' => false, 'attempts' => $count, 'retry_minutes' => 0];
    } catch (Throwable $e) {
        app_log('warning', 'check_login_rate_limit error: ' . $e->getMessage());
        return ['blocked' => false, 'attempts' => 0, 'retry_minutes' => 0];
    }
}

/**
 * Catat percobaan login ke tabel login_attempts.
 */
function record_login_attempt(string $identifier, bool $success): void
{
    try {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $stmt = db()->prepare(
            'INSERT INTO login_attempts (ip_address, identifier, was_successful) VALUES (:ip, :ident, :ok)'
        );
        $stmt->execute([
            ':ip'    => $ip,
            ':ident' => strtolower(trim($identifier)),
            ':ok'    => $success ? 1 : 0,
        ]);

        if ($success) {
            $clean = db()->prepare(
                'DELETE FROM login_attempts WHERE ip_address = :ip AND was_successful = 0'
            );
            $clean->execute([':ip' => $ip]);
        }
    } catch (Throwable $e) {
        app_log('warning', 'record_login_attempt error: ' . $e->getMessage());
    }
}

/**
 * Proses autentikasi login (Superadmin atau User) + pilihan akun email aktif yang disediakan Superadmin.
 */
function attempt_admin_login(string $identifier, string $password, ?int $selectedMailboxId = null): array
{
    ensure_skyzz_schema();
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return ['ok' => false, 'message' => 'Username/email dan kata sandi wajib diisi.'];
    }

    $rate = check_login_rate_limit($identifier);
    if ($rate['blocked']) {
        log_activity('login_blocked', 'Percobaan login diblokir karena melebihi batas gagal.', 'auth', null, 'warning');
        return [
            'ok'      => false,
            'message' => sprintf('Terlalu banyak percobaan login gagal. Coba lagi dalam %d menit.', $rate['retry_minutes']),
        ];
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, username, password_hash, role, selected_mailbox_id, is_active
         FROM admins
         WHERE (LOWER(username) = LOWER(:ident1) OR LOWER(email) = LOWER(:ident2))
         LIMIT 1'
    );
    $stmt->execute([
        ':ident1' => $identifier,
        ':ident2' => $identifier,
    ]);
    $user = $stmt->fetch();

    if (!$user || (int) $user['is_active'] !== 1 || !password_verify($password, (string) $user['password_hash'])) {
        record_login_attempt($identifier, false);
        log_activity('login_failed', 'Login gagal untuk akun: ' . $identifier, 'auth', null, 'warning');
        return ['ok' => false, 'message' => 'Username/email atau kata sandi tidak sesuai.'];
    }

    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = db()->prepare('UPDATE admins SET password_hash = :h WHERE id = :id');
        $upd->execute([':h' => $newHash, ':id' => (int) $user['id']]);
    }

    $isSuper = in_array((string) $user['role'], ['superadmin', 'admin'], true);
    $chosenMbId = $user['selected_mailbox_id'] ? (int) $user['selected_mailbox_id'] : null;

    if ($selectedMailboxId !== null && $selectedMailboxId > 0) {
        $mb = get_mailbox_by_id($selectedMailboxId);
        if ($mb && (int) $mb['is_active'] === 1 && ($isSuper || (int) $mb['allow_user_select'] === 1)) {
            $chosenMbId = $selectedMailboxId;
        }
    }

    session_regenerate_id(true);
    $_SESSION['admin_id']          = (int) $user['id'];
    $_SESSION['admin_role']        = (string) $user['role'];
    $_SESSION['active_mailbox_id'] = $chosenMbId;
    $_SESSION['last_activity']     = time();
    $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
    unset($_SESSION['admin_cache']);

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $updLogin = db()->prepare(
        'UPDATE admins SET last_login_at = NOW(), last_login_ip = :ip, selected_mailbox_id = :mid WHERE id = :id'
    );
    $updLogin->execute([
        ':ip'  => $ip,
        ':mid' => $chosenMbId,
        ':id'  => (int) $user['id'],
    ]);

    record_login_attempt($identifier, true);
    log_activity('login_success', 'Pengguna berhasil login ke Skyzz Mail (' . $user['role'] . ').', 'user', (string) $user['id'], 'success', (int) $user['id']);

    return ['ok' => true, 'message' => 'Login berhasil.'];
}

/**
 * Pendaftaran akun User baru (role='user', paket awal='free' 10 email/hari selamanya).
 */
function register_new_user(
    string $name,
    string $email,
    string $username,
    string $password,
    ?int $selectedMailboxId = null
): array {
    ensure_skyzz_schema();

    if (get_setting('allow_public_registration', '1') !== '1') {
        return ['ok' => false, 'message' => 'Pendaftaran pengguna baru sedang dinonaktifkan oleh Superadmin.'];
    }

    $name     = trim($name);
    $email    = strtolower(trim($email));
    $username = strtolower(trim($username));

    if (strlen($name) < 2 || strlen($name) > 120) {
        return ['ok' => false, 'message' => 'Nama lengkap wajib diisi (2–120 karakter).'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => 'Alamat email pribadi tidak valid.'];
    }
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
        return ['ok' => false, 'message' => 'Username harus 3–40 karakter (huruf kecil, angka, titik, strip, atau garis bawah).'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'message' => 'Kata sandi minimal 8 karakter.'];
    }

    // Validasi pilihan mailbox yang disediakan Superadmin
    $validMailboxId = null;
    if ($selectedMailboxId !== null && $selectedMailboxId > 0) {
        $mb = get_mailbox_by_id($selectedMailboxId);
        if ($mb && (int) $mb['is_active'] === 1 && (int) $mb['allow_user_select'] === 1) {
            $validMailboxId = $selectedMailboxId;
        }
    }
    if ($validMailboxId === null) {
        $avail = get_selectable_mailboxes(false);
        if (!empty($avail)) {
            $validMailboxId = (int) $avail[0]['id'];
        }
    }

    // Cek duplikasi email / username
    $check = db()->prepare('SELECT id FROM admins WHERE LOWER(email) = LOWER(:em) OR LOWER(username) = LOWER(:un) LIMIT 1');
    $check->execute([':em' => $email, ':un' => $username]);
    if ($check->fetch()) {
        return ['ok' => false, 'message' => 'Email atau username sudah terdaftar. Silakan gunakan yang lain atau masuk ke akun Anda.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare(
        'INSERT INTO admins
            (name, email, username, password_hash, role, job_title, selected_mailbox_id, plan_slug, custom_sender_name, is_active)
         VALUES
            (:name, :em, :un, :ph, "user", "Pengguna Skyzz Mail", :mid, "free", :csn, 1)'
    );
    $stmt->execute([
        ':name' => $name,
        ':em'   => $email,
        ':un'   => $username,
        ':ph'   => $hash,
        ':mid'  => $validMailboxId,
        ':csn'  => $name,
    ]);
    $newUserId = (int) db()->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['admin_id']          = $newUserId;
    $_SESSION['admin_role']        = 'user';
    $_SESSION['active_mailbox_id'] = $validMailboxId;
    $_SESSION['last_activity']     = time();
    $_SESSION['csrf_token']        = bin2hex(random_bytes(32));
    unset($_SESSION['admin_cache']);

    log_activity('user_registered', 'Pengguna baru mendaftar di Skyzz Mail: ' . $username, 'user', (string) $newUserId, 'success', $newUserId);

    return ['ok' => true, 'message' => 'Pendaftaran berhasil! Selamat datang di Skyzz Mail.', 'user_id' => $newUserId];
}

/**
 * Logout dan hapus cookie sesi secara menyeluruh.
 */
function logout_admin(): void
{
    $uid = $_SESSION['admin_id'] ?? null;
    if ($uid) {
        log_activity('logout', 'Pengguna keluar dari sesi Skyzz Mail.', 'user', (string) $uid, 'success', (int) $uid);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }
    session_destroy();
}
