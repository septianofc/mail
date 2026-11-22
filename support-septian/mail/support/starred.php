<?php
/**
 * Support Septian — Email Berbintang (Starred)
 * Path: /mail/support/starred.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$pageTitle      = 'Berbintang';
$activePage     = 'starred';
$folderKey      = 'starred';
$folderTitle    = 'Email Berbintang';
$folderSubtitle = 'Pesan penting yang ditandai bintang oleh tim Support Septian';

require __DIR__ . '/includes/mailbox-view.php';
