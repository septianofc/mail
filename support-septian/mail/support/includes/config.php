<?php
/**
 * Support Septian — Core Configuration & Bootstrap
 * Base URL: https://septianfr.web.id/mail/support
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

define('APP_ROOT', dirname(__DIR__));
define('INCLUDES_PATH', APP_ROOT . '/includes');
define('STORAGE_PATH', APP_ROOT . '/storage');
define('TOKENS_PATH', STORAGE_PATH . '/tokens');
define('LOGS_PATH', STORAGE_PATH . '/logs');
define('CACHE_PATH', STORAGE_PATH . '/cache');
define('UPLOADS_PATH', STORAGE_PATH . '/uploads');
define('TEMPLATES_PATH', APP_ROOT . '/templates');
define('BASE_PATH', '/mail/support');

// Identitas Default Aplikasi Skyzz Mail (dapat diubah Superadmin dari Pengaturan)
define('DEFAULT_APP_NAME', 'Skyzz Mail');
define('DEFAULT_OFFICIAL_NAME', 'Skyzz Mail');
define('DEFAULT_OFFICIAL_GMAIL', 'support.septianfr@gmail.com');
define('DEFAULT_WEBSITE_URL', 'https://septianfr.web.id');
define('DEFAULT_APP_URL', 'https://septianfr.web.id/mail/support');
define('DEFAULT_LOGO_URL', 'https://septianfr.web.id/logo.png');
define('DEFAULT_ADDRESS', 'H4GM+4XX, Jl. PUK Muntur, Ranjeng, Kec. Losarang, Kabupaten Indramayu, Jawa Barat 45253');
define('OAUTH_REDIRECT_URI', 'https://septianfr.web.id/mail/support/oauth/callback.php');
define('FREE_WATERMARK_DEFAULT', 'Dikirim Menggunakan Aplikasi Skyzz Mail');

// Batas keamanan
define('MAX_ATTACHMENT_SIZE', 15 * 1024 * 1024); // 15 MB per file
define('MAX_TOTAL_ATTACHMENTS_SIZE', 25 * 1024 * 1024); // 25 MB total
define('MAX_IMAGE_UPLOAD_SIZE', 5 * 1024 * 1024); // 5 MB untuk gambar QRIS / bukti bayar
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

// Muat konfigurasi privat (prioritas: di luar public_html -> storage/app-config.php -> env)
$externalConfigPath = dirname(APP_ROOT, 3) . '/support-septian-private/config.php';
$localConfigPath    = STORAGE_PATH . '/app-config.php';

$loadedConfig = [];
if (is_file($externalConfigPath) && is_readable($externalConfigPath)) {
    $loadedConfig = (array) require $externalConfigPath;
    define('CONFIG_SOURCE_PATH', $externalConfigPath);
} elseif (is_file($localConfigPath) && is_readable($localConfigPath)) {
    $loadedConfig = (array) require $localConfigPath;
    define('CONFIG_SOURCE_PATH', $localConfigPath);
} else {
    define('CONFIG_SOURCE_PATH', $localConfigPath);
}

$GLOBALS['APP_CONFIG'] = [
    'installed' => (bool) ($loadedConfig['installed'] ?? false),
    'db' => [
        'host'    => getenv('DB_HOST') ?: ($loadedConfig['db']['host'] ?? 'localhost'),
        'port'    => getenv('DB_PORT') ?: ($loadedConfig['db']['port'] ?? '3306'),
        'name'    => getenv('DB_NAME') ?: ($loadedConfig['db']['name'] ?? ''),
        'user'    => getenv('DB_USER') ?: ($loadedConfig['db']['user'] ?? ''),
        'pass'    => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : ($loadedConfig['db']['pass'] ?? ''),
        'charset' => $loadedConfig['db']['charset'] ?? 'utf8mb4',
    ],
    'encryption_key' => getenv('APP_ENCRYPTION_KEY') ?: ($loadedConfig['encryption_key'] ?? ''),
    'base_path'      => $loadedConfig['base_path'] ?? BASE_PATH,
    'app_url'        => rtrim((string) ($loadedConfig['app_url'] ?? DEFAULT_APP_URL), '/'),
    'official_gmail' => strtolower(trim((string) ($loadedConfig['official_gmail'] ?? DEFAULT_OFFICIAL_GMAIL))),
    'debug'          => (bool) ($loadedConfig['debug'] ?? false),
];

date_default_timezone_set('Asia/Jakarta');

error_reporting(E_ALL);
ini_set('display_errors', $GLOBALS['APP_CONFIG']['debug'] ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(LOGS_PATH)) {
    @mkdir(LOGS_PATH, 0750, true);
}
if (is_dir(LOGS_PATH) && is_writable(LOGS_PATH)) {
    ini_set('error_log', LOGS_PATH . '/php-error.log');
}

/**
 * Redaksi cepat data sensitif untuk error handler global (sebelum functions.php dimuat).
 */
function ss_redact_error_text(string $text): string
{
    $secrets = [
        (string) ($GLOBALS['APP_CONFIG']['db']['pass'] ?? ''),
        (string) ($GLOBALS['APP_CONFIG']['encryption_key'] ?? ''),
    ];
    foreach ($secrets as $sec) {
        if ($sec !== '' && strlen($sec) >= 4) {
            $text = str_replace($sec, '[REDACTED]', $text);
        }
    }
    $patterns = [
        '/(ya29\.[A-Za-z0-9\-_]+)/i',
        '/(1\/\/[A-Za-z0-9\-_]+)/i',
        '/(GOCSPX-[A-Za-z0-9\-_]+)/i',
        '/(password|secret|access_token|refresh_token|authorization)\s*[:=]\s*[^\s,;]+/i',
    ];
    return (string) preg_replace($patterns, '[REDACTED]', $text);
}

/**
 * Catat & tampilkan diagnostik error aman (tanpa membocorkan password/token) agar tidak tampil layar 500 kosong.
 */
function ss_render_fatal_error(string $type, string $message, string $file, int $line): void
{
    $refId = 'ERR-' . date('His') . '-' . substr(md5($file . ':' . $line . ':' . $message), 0, 6);
    $safeMsg = ss_redact_error_text($message);
    $relFile = str_replace([APP_ROOT . DIRECTORY_SEPARATOR, APP_ROOT . '/', APP_ROOT], '', $file);
    $reqUri  = (string) ($_SERVER['REQUEST_URI'] ?? '');

    if (!is_dir(LOGS_PATH)) {
        @mkdir(LOGS_PATH, 0750, true);
    }
    $logLine = sprintf(
        "[%s] [%s] [%s] %s in %s:%d (URI: %s)\n",
        date('Y-m-d H:i:s'),
        $refId,
        $type,
        $safeMsg,
        $relFile,
        $line,
        $reqUri
    );
    @file_put_contents(LOGS_PATH . '/php-error.log', $logLine, FILE_APPEND | LOCK_EX);
    @file_put_contents(LOGS_PATH . '/app-' . date('Y-m-d') . '.log', $logLine, FILE_APPEND | LOCK_EX);

    if (PHP_SAPI === 'cli') {
        return;
    }

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains($reqUri, '/api/')
        || str_contains($accept, 'application/json');

    if (!headers_sent()) {
        http_response_code(500);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
    }

    if ($isAjax) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok'        => false,
            'error_ref' => $refId,
            'message'   => 'Terjadi kesalahan server (' . $refId . '): ' . $safeMsg . ' [' . $relFile . ':' . $line . ']',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }

    $eRef  = htmlspecialchars($refId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eType = htmlspecialchars($type, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eMsg  = htmlspecialchars($safeMsg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $eLoc  = htmlspecialchars($relFile . ' (baris ' . $line . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dashUrl = htmlspecialchars(base_url('dashboard.php'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $secUrl  = htmlspecialchars(base_url('security.php#server-error-logs'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Diagnostik Error — Skyzz Mail</title>
<style>
body{margin:0;padding:24px;background:#f5f7fa;color:#202124;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;line-height:1.55}
.box{max-width:680px;margin:32px auto;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:28px;box-shadow:0 4px 16px rgba(15,23,42,.06)}
h1{margin:0 0 8px;font-size:20px;color:#b91c1c}
p{margin:0 0 14px;color:#4b5563;font-size:14px}
.detail{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;color:#111827;overflow-x:auto;margin-bottom:18px}
.row{margin-bottom:6px}.lbl{color:#64748b;font-weight:600}
.actions{display:flex;flex-wrap:wrap;gap:10px}
.btn{display:inline-block;padding:9px 16px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none}
.btn-primary{background:#1a73e8;color:#fff}.btn-secondary{background:#eef2f7;color:#1f2937}
</style>
</head>
<body>
<div class="box">
  <h1>Terjadi Kesalahan Sistem (HTTP 500)</h1>
  <p>Aplikasi <strong>Skyzz Mail</strong> menangkap error agar tidak menampilkan layar kosong. Detail lengkap telah dicatat secara aman ke <code>storage/logs/php-error.log</code>.</p>
  <div class="detail">
    <div class="row"><span class="lbl">Kode Referensi:</span> {$eRef}</div>
    <div class="row"><span class="lbl">Tipe Error:</span> {$eType}</div>
    <div class="row"><span class="lbl">Pesan:</span> {$eMsg}</div>
    <div class="row"><span class="lbl">Lokasi:</span> {$eLoc}</div>
  </div>
  <div class="actions">
    <a class="btn btn-primary" href="{$dashUrl}">Kembali ke Dashboard</a>
    <a class="btn btn-secondary" href="{$secUrl}">Buka Log Error Server</a>
  </div>
</div>
</body>
</html>
HTML;
}

set_exception_handler(static function (Throwable $e): void {
    ss_render_fatal_error(get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    exit(1);
});

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        ss_render_fatal_error('PHP Fatal Error', (string) $err['message'], (string) $err['file'], (int) $err['line']);
    }
});

// Konfigurasi PHP Session yang aman
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443)
        || (strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_name('SUPPORT_SEPTIAN_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH . '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    @session_start();
}

// Muat Composer Autoload jika tersedia (untuk Google API PHP Client & PHPMailer)
$vendorAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($vendorAutoload) && is_readable($vendorAutoload)) {
    require_once $vendorAutoload;
}

// Fallback otomatis untuk instalasi PHPMailer manual (tanpa Composer)
if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
    $manualPhpMailerDirs = [
        INCLUDES_PATH . '/phpmailer/src',
        INCLUDES_PATH . '/phpmailer',
        APP_ROOT . '/phpmailer/src',
        APP_ROOT . '/vendor/phpmailer/phpmailer/src',
    ];
    foreach ($manualPhpMailerDirs as $pmDir) {
        if (
            is_file($pmDir . '/Exception.php') &&
            is_file($pmDir . '/PHPMailer.php') &&
            is_file($pmDir . '/SMTP.php')
        ) {
            require_once $pmDir . '/Exception.php';
            require_once $pmDir . '/PHPMailer.php';
            require_once $pmDir . '/SMTP.php';
            break;
        }
    }
}

/**
 * Ambil nilai konfigurasi dengan dot notation.
 */
function app_config(string $key, mixed $default = null): mixed
{
    $segments = explode('.', $key);
    $value = $GLOBALS['APP_CONFIG'] ?? [];
    foreach ($segments as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }
    return $value;
}

/**
 * Cek apakah aplikasi sudah terinstal.
 */
function is_app_installed(): bool
{
    return (bool) app_config('installed', false) && app_config('db.name', '') !== '';
}

/**
 * Bangun URL absolut relatif terhadap /mail/support.
 */
function base_url(string $path = ''): string
{
    $base = rtrim((string) app_config('base_path', BASE_PATH), '/');
    $clean = ltrim($path, '/');
    return $clean === '' ? $base . '/' : $base . '/' . $clean;
}
