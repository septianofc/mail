<?php
/**
 * Support Septian — Logout Endpoint
 * Path: /mail/support/logout.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf(false);
    logout_admin();
    set_flash('success', 'Anda telah keluar dari sesi Support Septian.');
}

redirect_to('login.php');
