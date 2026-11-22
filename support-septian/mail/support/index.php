<?php
/**
 * Support Septian — Entry Point Router
 * Path: /mail/support/index.php
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (!is_app_installed()) {
    redirect_to('install.php');
}

if (is_logged_in()) {
    redirect_to('dashboard.php');
}

redirect_to('login.php');
