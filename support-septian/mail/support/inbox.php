<?php
/**
 * Support Septian — Kotak Masuk (Inbox)
 * Path: /mail/support/inbox.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$pageTitle      = 'Kotak Masuk';
$activePage     = 'inbox';
$folderKey      = trim((string) ($_GET['q'] ?? '')) !== '' ? 'all' : 'inbox';
$folderTitle    = 'Kotak Masuk';
$folderSubtitle = 'Email masuk langsung dari akun resmi ' . get_setting('support_email', DEFAULT_OFFICIAL_GMAIL);

require __DIR__ . '/includes/mailbox-view.php';
