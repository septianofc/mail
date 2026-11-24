<?php
/**
 * Skyzz Mail — Draft Email
 * Path: /mail/support/drafts.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$activeMailbox  = get_effective_mailbox($currentAdmin);
$mbEmail        = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$pageTitle      = 'Draft';
$activePage     = 'drafts';
$folderKey      = 'drafts';
$folderTitle    = 'Draft Email';
$folderSubtitle = 'Konsep email tersimpan untuk akun ' . $mbEmail;

require __DIR__ . '/includes/mailbox-view.php';
