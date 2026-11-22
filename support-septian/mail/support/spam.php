<?php
/**
 * Support Septian — Spam
 * Path: /mail/support/spam.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$pageTitle      = 'Spam';
$activePage     = 'spam';
$folderKey      = 'spam';
$folderTitle    = 'Folder Spam';
$folderSubtitle = 'Email yang ditandai sebagai spam oleh Gmail atau admin';

require __DIR__ . '/includes/mailbox-view.php';
