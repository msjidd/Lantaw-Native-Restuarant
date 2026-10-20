<?php
/**
 * login.php : authentication page.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/init.php';

if (is_logged_in()) {
    redirect(APP_URL . '/dashboard.php');
}

$error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = input_str('username', 50);
    $password = (string)($_POST['password'] ?? '');

    [$ok, $message] = attempt_login($username, $password);
    if ($ok) {
        flash_set('success', $message);
        redirect(APP_URL . '/dashboard.php');
    }
    $error = $message;
    log_audit('login_failed', 'users', null, 'Failed login attempt for username: ' . $username);
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<script>
window.APP_URL = <?= js(APP_URL) ?>;
window.CSRF_TOKEN = <?= js(csrf_token()) ?>;
</script>
<script src="<?= APP_URL ?>/assets/vendor/lucide.min.js" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js" defer></script>
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-brand">
            <img class="brand-logo-img"
                 src="<?= APP_URL ?>/assets/images/logo.png"
                 alt="Lantaw Native Restaurant logo"
                 width="96" height="96">
            <div class="brand-text">
                <strong class="brand-wordmark">Lantaw</strong>
                <span>Business Analytics System</span>
            </div>
        </div>
        <p class="login-sub">
            <?= e(APP_RESTAURANT) ?><br>
            Sign in with your assigned account to continue.
        </p>

        <?php foreach (flash_pull() as $f): ?>
            <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?> py-2" style="font-size:13.5px">
                <?= e($f['message']) ?>
            </div>
        <?php endforeach; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger py-2" style="font-size:13.5px">
                <i data-lucide="alert-circle" style="width:15px;height:15px;vertical-align:-3px"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="off" novalidate>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i data-lucide="user" style="width:16px;height:16px"></i></span>
                    <input class="form-control" type="text" id="username" name="username"
                           value="<?= e($username) ?>" maxlength="50" placeholder="e.g. admin" required autofocus>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i data-lucide="lock" style="width:16px;height:16px"></i></span>
                    <input class="form-control" type="password" id="password" name="password"
                           maxlength="100" placeholder="Your password" required>
                </div>
            </div>
            <button class="btn btn-primary w-100 mt-2 btn-icon" type="submit">
                <i data-lucide="log-in"></i> Sign in
            </button>
        </form>

        <div class="login-foot">
            © 2026 Lantaw Analytics. All rights reserved.
        </div>
    </div>
</div>
</body>
</html>
