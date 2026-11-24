<?php
/**
 * Skyzz Mail — Kotak Masuk (Inbox)
 * Path: /mail/support/inbox.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$activeMailbox  = get_effective_mailbox($currentAdmin);
$mbEmail        = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$pageTitle      = 'Kotak Masuk';
$activePage     = 'inbox';
$folderKey      = trim((string) ($_GET['q'] ?? '')) !== '' ? 'all' : 'inbox';
$folderTitle    = 'Kotak Masuk';
$folderSubtitle = 'Terima pesan tanpa batas pada akun aktif ' . $mbEmail;

require __DIR__ . '/includes/mailbox-view.php';
