<?php
/**
 * Support Septian — OAuth 2.0 Initiate Connection
 * Path: /mail/support/oauth/connect.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/gmail-client.php';

$admin = require_superadmin();

try {
    $client = create_google_oauth_client();

    // Generate state acak kriptografis untuk proteksi CSRF pada callback OAuth
    $state = bin2hex(random_bytes(32));
    $_SESSION['gmail_oauth_state']    = $state;
    $_SESSION['gmail_oauth_state_ts'] = time();

    $client->setState($state);
    $client->setLoginHint(get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));

    $authUrl = $client->createAuthUrl();

    log_activity('oauth_connect_started', 'Admin memulai otorisasi Google OAuth 2.0.', 'oauth', null, 'success', (int) $admin['id']);

    header('Location: ' . $authUrl);
    exit;
} catch (Throwable $e) {
    app_log('error', 'Gagal memulai OAuth connect: ' . $e->getMessage());
    set_flash('error', $e->getMessage());
    redirect_to('gmail-connection.php');
}
