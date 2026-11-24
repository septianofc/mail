<?php
/**
 * Support Septian — AES-256-GCM Encryption Helper
 * Mengenkripsi OAuth tokens, Client Secret, dan Google App Password sebelum disimpan.
 */

declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Akses langsung tidak diizinkan.');
}

const CRYPTO_CIPHER = 'aes-256-gcm';

/**
 * Mengembalikan kunci biner 32-byte dari APP_ENCRYPTION_KEY.
 */
function crypto_get_key(): string
{
    $rawKey = (string) app_config('encryption_key', '');
    if ($rawKey === '') {
        throw new RuntimeException('Kunci enkripsi aplikasi belum dikonfigurasi. Jalankan install.php.');
    }

    if (ctype_xdigit($rawKey) && strlen($rawKey) === 64) {
        $bin = hex2bin($rawKey);
        if ($bin !== false && strlen($bin) === 32) {
            return $bin;
        }
    }

    return hash('sha256', $rawKey, true);
}

/**
 * Enkripsi teks menggunakan AES-256-GCM.
 */
function crypto_encrypt(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException('Ekstensi PHP OpenSSL belum aktif di server hosting.');
    }

    $key = crypto_get_key();
    $iv  = random_bytes(12);
    $tag = '';

    $ciphertext = openssl_encrypt(
        $plaintext,
        CRYPTO_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($ciphertext === false) {
        throw new RuntimeException('Gagal mengenkripsi data sensitif.');
    }

    return 'enc:v1:' . base64_encode($iv . $tag . $ciphertext);
}

/**
 * Dekripsi teks hasil crypto_encrypt().
 */
function crypto_decrypt(?string $payload): string
{
    if ($payload === null || $payload === '') {
        return '';
    }

    if (!str_starts_with($payload, 'enc:v1:')) {
        return $payload;
    }

    if (!function_exists('openssl_decrypt')) {
        return '';
    }

    $decoded = base64_decode(substr($payload, 7), true);
    if ($decoded === false || strlen($decoded) < 28) {
        return '';
    }

    $iv         = substr($decoded, 0, 12);
    $tag        = substr($decoded, 12, 16);
    $ciphertext = substr($decoded, 28);

    try {
        $plaintext = openssl_decrypt(
            $ciphertext,
            CRYPTO_CIPHER,
            crypto_get_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        return $plaintext === false ? '' : $plaintext;
    } catch (Throwable $e) {
        return '';
    }
}
