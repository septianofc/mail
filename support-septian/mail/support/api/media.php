<?php
/**
 * Skyzz Mail — Authenticated & Path-Confined Media Server
 * GET /mail/support/api/media.php?type=qris
 * GET /mail/support/api/media.php?type=proof&id=<payment_order_id>
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';

$user = require_admin(false);
$type = strtolower(trim((string) ($_GET['type'] ?? '')));

$uploadsRoot = defined('UPLOADS_PATH') ? UPLOADS_PATH : (STORAGE_PATH . '/uploads');
$realRoot    = realpath($uploadsRoot);
if ($realRoot === false) {
    http_response_code(404);
    exit('Media tidak ditemukan.');
}

$relPath = '';

if ($type === 'qris') {
    $relPath = ltrim(trim(get_setting('qris_image_path', '')), '/');
    if ($relPath === '' || !str_starts_with($relPath, 'qris/')) {
        http_response_code(404);
        exit('Gambar QRIS belum diunggah.');
    }
} elseif ($type === 'proof') {
    $orderId = (int) ($_GET['id'] ?? 0);
    if ($orderId <= 0) {
        http_response_code(400);
        exit('ID pesanan tidak valid.');
    }
    $stmt = db()->prepare('SELECT user_id, proof_image_path FROM payment_orders WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch();
    if (!$order) {
        http_response_code(404);
        exit('Bukti pembayaran tidak ditemukan.');
    }
    if (!is_superadmin($user) && (int) $order['user_id'] !== (int) $user['id']) {
        http_response_code(403);
        exit('Akses ditolak.');
    }
    $relPath = ltrim(trim((string) ($order['proof_image_path'] ?? '')), '/');
    if ($relPath === '' || !str_starts_with($relPath, 'proofs/')) {
        http_response_code(404);
        exit('File bukti pembayaran tidak ditemukan.');
    }
} else {
    http_response_code(400);
    exit('Tipe media tidak valid.');
}

// Konfinemen path ketat: hanya boleh subdirektori + basename tanpa traversal
$parts = explode('/', $relPath, 2);
if (count($parts) !== 2) {
    http_response_code(403);
    exit('Path media tidak valid.');
}
$safeSub  = $parts[0];
$safeName = basename($parts[1]);
$candidate = realpath($uploadsRoot . '/' . $safeSub . '/' . $safeName);

if (
    $candidate === false
    || !str_starts_with($candidate, $realRoot . DIRECTORY_SEPARATOR)
    || !is_file($candidate)
    || !is_readable($candidate)
) {
    http_response_code(404);
    exit('File media tidak ditemukan.');
}

$ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
$mimeMap = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($candidate));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');
readfile($candidate);
exit;
