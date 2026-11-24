<?php
/**
 * Support Septian — OAuth 2.0 Disconnect Endpoint
 * Path: /mail/support/oauth/disconnect.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-client.php';

$admin = require_superadmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to('gmail-connection.php');
}

require_csrf(false);

try {
    disconnect_oauth_account((int) $admin['id']);
    set_flash('success', 'Koneksi Google Gmail OAuth 2.0 telah diputuskan dan token telah dihapus.');
} catch (Throwable $e) {
    app_log('error', 'Gagal memutuskan koneksi OAuth: ' . $e->getMessage());
    set_flash('error', 'Terjadi kesalahan saat memutuskan koneksi: ' . $e->getMessage());
}

redirect_to('gmail-connection.php');
