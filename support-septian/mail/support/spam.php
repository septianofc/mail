<?php
/**
 * Skyzz Mail — Folder Spam
 * Path: /mail/support/spam.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$activeMailbox  = get_effective_mailbox($currentAdmin);
$mbEmail        = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$pageTitle      = 'Spam';
$activePage     = 'spam';
$folderKey      = 'spam';
$folderTitle    = 'Folder Spam';
$folderSubtitle = 'Email yang ditandai sebagai spam pada akun ' . $mbEmail;

require __DIR__ . '/includes/mailbox-view.php';
