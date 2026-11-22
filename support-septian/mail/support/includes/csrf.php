<?php
/**
 * Support Septian — CSRF Protection Module
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

/**
 * Ambil atau buat CSRF token per sesi.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Cetak hidden input HTML untuk form POST.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Verifikasi CSRF token dari POST body atau HTTP header X-CSRF-Token.
 */
function verify_csrf(?string $provided = null): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '') {
        return false;
    }

    if ($provided === null) {
        $provided = $_POST['csrf_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? '';
    }

    if (!is_string($provided) || $provided === '') {
        return false;
    }

    return hash_equals($expected, $provided);
}

/**
 * Wajibkan CSRF valid pada endpoint POST/PUT/DELETE.
 */
function require_csrf(bool $jsonResponse = false): void
{
    if (!verify_csrf()) {
        if ($jsonResponse) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok'      => false,
                'message' => 'Sesi keamanan (CSRF) tidak valid atau kedaluwarsa. Muat ulang halaman lalu coba lagi.',
            ]);
            exit;
        }
        http_response_code(403);
        exit('Permintaan ditolak: token keamanan CSRF tidak valid.');
    }
}
