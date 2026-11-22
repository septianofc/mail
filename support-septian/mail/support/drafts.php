<?php
/**
 * Support Septian — Draft Email
 * Path: /mail/support/drafts.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$pageTitle      = 'Draft';
$activePage     = 'drafts';
$folderKey      = 'drafts';
$folderTitle    = 'Draft Email';
$folderSubtitle = 'Konsep pesan yang tersimpan di akun Gmail resmi';

require __DIR__ . '/includes/mailbox-view.php';
