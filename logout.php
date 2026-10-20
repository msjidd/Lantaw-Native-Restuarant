<?php
/**
 * logout.php : ends the session (POST only, CSRF protected).
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Allow GET logout too for convenience, but still require a valid token.
    if (!csrf_check($_GET['_csrf'] ?? null)) {
        redirect(APP_URL . '/login.php');
    }
} else {
    csrf_verify();
}

logout_user();
session_start();
flash_set('success', 'You have been signed out.');
redirect(APP_URL . '/login.php');
