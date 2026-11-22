<?php
/**
 * Support Septian — Pesan Terkirim (Sent)
 * Path: /mail/support/sent.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$pageTitle      = 'Email Terkirim';
$activePage     = 'sent';
$folderKey      = 'sent';
$folderTitle    = 'Email Terkirim';
$folderSubtitle = 'Seluruh email yang dikirim dari ' . get_setting('support_email', DEFAULT_OFFICIAL_GMAIL);

require __DIR__ . '/includes/mailbox-view.php';
