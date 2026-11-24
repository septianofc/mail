<?php
/**
 * Skyzz Mail — Fast Server-Side File Cache
 * Menyimpan cache sementara (daftar email, statistik mailbox, pengaturan) di storage/cache/
 * agar halaman Inbox, Terkirim, dan Dashboard termuat cepat.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

require_once __DIR__ . '/config.php';

/**
 * Pastikan direktori cache siap dan terlindungi.
 */
function cache_ensure_dir(): string
{
    $dir = defined('CACHE_PATH') ? CACHE_PATH : (STORAGE_PATH . '/cache');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht) && is_writable($dir)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n", LOCK_EX);
    }
    return $dir;
}

/**
 * Bangun path file cache yang aman (hanya huruf/angka/underscore/strip).
 */
function cache_file_path(string $key): string
{
    $safePrefix = preg_replace('/[^a-zA-Z0-9_\-]/', '_', substr($key, 0, 48)) ?: 'item';
    $hash       = hash('sha256', $key);
    return cache_ensure_dir() . '/' . $safePrefix . '_' . substr($hash, 0, 24) . '.cache.json';
}

/**
 * Ambil data dari cache jika belum kedaluwarsa.
 */
function cache_get(string $key): mixed
{
    $path = cache_file_path($key);
    if (!is_file($path) || !is_readable($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['expires_at'])) {
        @unlink($path);
        return null;
    }

    if ((int) $decoded['expires_at'] < time()) {
        @unlink($path);
        return null;
    }

    return $decoded['data'] ?? null;
}

/**
 * Simpan data ke cache dengan TTL (detik).
 */
function cache_set(string $key, mixed $data, int $ttlSeconds = 60): bool
{
    $path = cache_file_path($key);
    $payload = json_encode([
        'key'        => $key,
        'stored_at'  => time(),
        'expires_at' => time() + max(5, $ttlSeconds),
        'data'       => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        return false;
    }

    return @file_put_contents($path, $payload, LOCK_EX) !== false;
}

/**
 * Hapus satu item cache berdasarkan key.
 */
function cache_delete(string $key): void
{
    $path = cache_file_path($key);
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * Hapus semua file cache yang dimulai dengan prefix tertentu (mis. 'mb_1_' atau 'stats_').
 */
function cache_forget_prefix(string $prefix): int
{
    $dir = cache_ensure_dir();
    $safePrefix = preg_replace('/[^a-zA-Z0-9_\-]/', '_', substr($prefix, 0, 48)) ?: '';
    if ($safePrefix === '') {
        return 0;
    }

    $files = glob($dir . '/' . $safePrefix . '*.cache.json') ?: [];
    $removed = 0;
    foreach ($files as $f) {
        if (is_file($f) && @unlink($f)) {
            $removed++;
        }
    }
    return $removed;
}

/**
 * Bersihkan seluruh file cache di storage/cache/.
 */
function cache_flush_all(): int
{
    $dir = cache_ensure_dir();
    $files = glob($dir . '/*.cache.json') ?: [];
    $removed = 0;
    foreach ($files as $f) {
        if (is_file($f) && @unlink($f)) {
            $removed++;
        }
    }
    return $removed;
}
