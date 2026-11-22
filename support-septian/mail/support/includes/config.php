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
define('TEMPLATES_PATH', APP_ROOT . '/templates');
define('BASE_PATH', '/mail/support');

// Identitas Resmi Support Septian F. R.
define('DEFAULT_APP_NAME', 'Support Septian');
define('DEFAULT_OFFICIAL_NAME', 'Support Septian F. R.');
define('DEFAULT_OFFICIAL_GMAIL', 'support.septianfr@gmail.com');
define('DEFAULT_WEBSITE_URL', 'https://septianfr.web.id');
define('DEFAULT_APP_URL', 'https://septianfr.web.id/mail/support');
define('DEFAULT_LOGO_URL', 'https://septianfr.web.id/logo.png');
define('DEFAULT_ADDRESS', 'H4GM+4XX, Jl. PUK Muntur, Ranjeng, Kec. Losarang, Kabupaten Indramayu, Jawa Barat 45253');
define('OAUTH_REDIRECT_URI', 'https://septianfr.web.id/mail/support/oauth/callback.php');

// Batas keamanan
define('MAX_ATTACHMENT_SIZE', 15 * 1024 * 1024); // 15 MB per file
define('MAX_TOTAL_ATTACHMENTS_SIZE', 25 * 1024 * 1024); // 25 MB total
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
if (is_dir(LOGS_PATH) && is_writable(LOGS_PATH)) {
    ini_set('error_log', LOGS_PATH . '/php-error.log');
}

// Konfigurasi PHP Session yang aman
if (session_status() === PHP_SESSION_NONE) {
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
    session_start();
}

// Muat Composer Autoload jika tersedia (untuk Google API PHP Client)
$vendorAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require_once $vendorAutoload;
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
