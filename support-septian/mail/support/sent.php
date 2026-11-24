<?php
/**
 * Skyzz Mail — Pesan Terkirim (Sent)
 * Path: /mail/support/sent.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$activeMailbox  = get_effective_mailbox($currentAdmin);
$mbEmail        = (string) ($activeMailbox['email'] ?? get_setting('support_email', DEFAULT_OFFICIAL_GMAIL));
$pageTitle      = 'Email Terkirim';
$activePage     = 'sent';
$folderKey      = 'sent';
$folderTitle    = 'Email Terkirim';
$folderSubtitle = 'Riwayat email terkirim & tersinkron ke folder Terkirim Gmail (' . $mbEmail . ')';

require __DIR__ . '/includes/mailbox-view.php';
