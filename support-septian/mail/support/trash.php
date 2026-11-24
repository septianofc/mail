<?php
/**
 * Skyzz Mail — Folder Sampah (Trash)
 * Path: /mail/support/trash.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$activeMailbox  = get_effective_mailbox($currentAdmin);
$mbEmail        = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$pageTitle      = 'Sampah';
$activePage     = 'trash';
$folderKey      = 'trash';
$folderTitle    = 'Folder Sampah';
$folderSubtitle = 'Email yang dihapus pada akun ' . $mbEmail;

require __DIR__ . '/includes/mailbox-view.php';
