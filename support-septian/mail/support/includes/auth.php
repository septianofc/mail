<?php
/**
 * Support Septian — Admin Authentication & Login Rate Limiting
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

/**
 * Cek apakah admin sedang login.
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);
}

/**
 * Ambil data admin yang sedang login.
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

    $stmt = db()->prepare(
        'SELECT id, name, email, username, role, job_title, avatar_url, is_active, last_login_at, last_login_ip, created_at
         FROM admins WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([':id' => $_SESSION['admin_id']]);
    $row = $stmt->fetch();

    if (!$row) {
        logout_admin();
        return null;
    }

    $admin = $row;
    return $admin;
}

/**
 * Wajibkan login untuk halaman PHP.
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
}

/**
 * Catat percobaan login ke tabel login_attempts.
 */
function record_login_attempt(string $identifier, bool $success): void
{
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
        // Bersihkan percobaan gagal lama untuk IP ini
        $clean = db()->prepare(
            'DELETE FROM login_attempts WHERE ip_address = :ip AND was_successful = 0'
        );
        $clean->execute([':ip' => $ip]);
    }
}

/**
 * Proses autentikasi login admin menggunakan password_verify().
 */
function attempt_admin_login(string $identifier, string $password): array
{
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
        'SELECT id, name, email, username, password_hash, role, is_active
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

    // Rehash otomatis jika algoritma PHP diperbarui
    if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        $upd = db()->prepare('UPDATE admins SET password_hash = :h WHERE id = :id');
        $upd->execute([':h' => $newHash, ':id' => (int) $user['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['admin_id']      = (int) $user['id'];
    $_SESSION['admin_role']    = (string) $user['role'];
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $updLogin = db()->prepare('UPDATE admins SET last_login_at = NOW(), last_login_ip = :ip WHERE id = :id');
    $updLogin->execute([':ip' => $ip, ':id' => (int) $user['id']]);

    record_login_attempt($identifier, true);
    log_activity('login_success', 'Admin berhasil login ke Support Septian.', 'admin', (string) $user['id'], 'success', (int) $user['id']);

    return ['ok' => true, 'message' => 'Login berhasil.'];
}

/**
 * Logout admin dan hapus cookie sesi secara menyeluruh.
 */
function logout_admin(): void
{
    $uid = $_SESSION['admin_id'] ?? null;
    if ($uid) {
        log_activity('logout', 'Admin keluar dari sesi Support Septian.', 'admin', (string) $uid, 'success', (int) $uid);
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
