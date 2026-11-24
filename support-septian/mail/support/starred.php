<?php
/**
 * Skyzz Mail — Email Berbintang (Starred)
 * Path: /mail/support/starred.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$activeMailbox  = get_effective_mailbox($currentAdmin);
$mbEmail        = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$pageTitle      = 'Berbintang';
$activePage     = 'starred';
$folderKey      = 'starred';
$folderTitle    = 'Email Berbintang';
$folderSubtitle = 'Email penting yang ditandai bintang pada akun ' . $mbEmail;

require __DIR__ . '/includes/mailbox-view.php';
