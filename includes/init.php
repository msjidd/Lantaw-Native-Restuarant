<?php
/**
 * includes/init.php : single bootstrap point for every page.
 * Loads configuration, helpers, CSRF protection and authentication.
 */

declare(strict_types=1);

if (defined('LANTAW_INIT')) {
    return;
}
define('LANTAW_INIT', true);

require_once dirname(__DIR__) . '/config/app.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

lantaw_start_session();
