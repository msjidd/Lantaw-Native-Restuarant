<?php
/**
 * includes/auth.php : session handling, login, role-based access control.
 */

declare(strict_types=1);

/** Starts a hardened PHP session (httpOnly, SameSite, strict mode). */
function lantaw_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('LANTAWSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

/** Requires a logged-in user; redirects to login.php otherwise. */
function require_login(): void
{
    if (!is_logged_in()) {
        flash_set('warning', 'Please sign in to continue.');
        redirect(APP_URL . '/login.php');
    }
    // Re-validate the account on every request (disabled/deleted users lose access).
    $u = row('SELECT id, username, full_name, role, is_active FROM users WHERE id = ? LIMIT 1', [current_user()['id']]);
    if (!$u || !(int)$u['is_active']) {
        logout_user();
        flash_set('danger', 'Your account is no longer active. Please contact the administrator.');
        redirect(APP_URL . '/login.php');
    }
    $_SESSION['user'] = $u;
}

/** Requires administrator role; shows a friendly 403 page otherwise. */
function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Access denied</title>'
            . '<link rel="stylesheet" href="' . APP_URL . '/assets/css/style.css"></head>'
            . '<body class="page-centered"><div class="card-box text-center" style="max-width:440px">'
            . '<h3 style="color:#DC3545">Access denied</h3>'
            . '<p>You need administrator privileges to open this page.</p>'
            . '<a class="btn btn-primary" href="' . APP_URL . '/dashboard.php">Back to dashboard</a>'
            . '</div></body></html>';
        exit;
    }
}

/**
 * Verifies credentials and builds the session.
 * Returns [ok:bool, message:string].
 */
function attempt_login(string $username, string $password): array
{
    $username = trim($username);

    if ($username === '' || $password === '') {
        return [false, 'Username and password are required.'];
    }

    $user = row('SELECT * FROM users WHERE username = ? LIMIT 1', [$username]);
    if (!$user) {
        // Constant-ish work to reduce user enumeration timing signal.
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return [false, 'Invalid username or password.'];
    }
    if (!(int)$user['is_active']) {
        return [false, 'This account is disabled. Contact the administrator.'];
    }
    if (!password_verify($password, $user['password_hash'])) {
        return [false, 'Invalid username or password.'];
    }

    // Re-hash when the algorithm/parameters change.
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
    }

    session_regenerate_id(true);
    csrf_rotate();

    $_SESSION['user'] = [
        'id'        => (int)$user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
        'is_active' => (int)$user['is_active'],
    ];

    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$user['id']]);
    log_audit('login', 'users', (int)$user['id'], 'Successful login for ' . $user['username']);

    return [true, 'Welcome back, ' . $user['full_name'] . '.'];
}

function logout_user(): void
{
    if (is_logged_in()) {
        log_audit('logout', 'users', (int)current_user()['id'], 'User logged out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
