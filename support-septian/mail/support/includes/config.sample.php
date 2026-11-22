<?php
/**
 * Support Septian — Contoh Konfigurasi Server
 *
 * Salin file ini menjadi:
 *   1) /home/username/support-septian-private/config.php (DI LUAR public_html — sangat disarankan), ATAU
 *   2) /mail/support/storage/app-config.php (dibuat otomatis oleh install.php)
 *
 * JANGAN PERNAH menyimpan kredensial sungguhan ke repository Git publik.
 */

return [
    'installed' => false,
    'db' => [
        'host'    => 'localhost',
        'port'    => '3306',
        'name'    => 'support_septian',
        'user'    => 'db_username',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    // Kunci enkripsi 64 karakter hex (32 byte) untuk AES-256-GCM.
    // Otomatis dihasilkan oleh install.php atau via: php -r "echo bin2hex(random_bytes(32));"
    'encryption_key' => '',
    'base_path' => '/mail/support',
    'app_url'   => 'https://septianfr.web.id/mail/support',
    'official_gmail' => 'support.septianfr@gmail.com',
    'debug'     => false,
];
