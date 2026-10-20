<?php
/**
 * index.php : entry point – sends visitors to the dashboard or login.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect(APP_URL . '/dashboard.php');
}
redirect(APP_URL . '/login.php');
