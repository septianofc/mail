<?php
/**
 * Support Septian — Sampah (Trash)
 * Path: /mail/support/trash.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/gmail-service.php';

$currentAdmin   = require_admin();
$pageTitle      = 'Sampah';
$activePage     = 'trash';
$folderKey      = 'trash';
$folderTitle    = 'Folder Sampah';
$folderSubtitle = 'Pesan terhapus yang masih dapat dipulihkan atau dihapus permanen';

require __DIR__ . '/includes/mailbox-view.php';
