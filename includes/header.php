<?php
/**
 * includes/header.php : page opening, head, topbar and flash messages.
 * Page files must set $page_title and (optionally) $page_active.
 */

declare(strict_types=1);

if (!defined('LANTAW_INIT')) {
    require_once __DIR__ . '/init.php';
}

$page_title = $page_title ?? 'Dashboard';
$page_active = $page_active ?? '';
$flashes = flash_pull();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e(APP_NAME) ?> – <?= e(APP_RESTAURANT) ?>">
<title><?= e($page_title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= APP_URL ?>/assets/vendor/bootstrap.min.css">
<link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
<script>
window.APP_URL = <?= js(APP_URL) ?>;
window.CSRF_TOKEN = <?= js(csrf_token()) ?>;
</script>
<script src="<?= APP_URL ?>/assets/vendor/bootstrap.bundle.min.js" defer></script>
<script src="<?= APP_URL ?>/assets/vendor/chart.umd.min.js" defer></script>
<script src="<?= APP_URL ?>/assets/vendor/lucide.min.js" defer></script>
<script src="<?= APP_URL ?>/assets/js/app.js" defer></script>
</head>
<body>
<?php require __DIR__ . '/sidebar.php'; ?>
<div class="main-wrap">
    <header class="topbar">
        <button class="icon-btn" id="sidebarToggle" type="button" aria-label="Toggle navigation">
            <i data-lucide="menu"></i>
        </button>
        <div class="topbar-title">
            <h1><?= e($page_title) ?></h1>
            <span class="topbar-sub"><?= e(APP_RESTAURANT) ?></span>
        </div>
        <div class="topbar-right">
            <?php if (has_sample_data()): ?>
                <span class="badge-simulated" title="Menu prices and sales rows are generated sample records">
                    <i data-lucide="flask-conical"></i> Simulated sample data
                </span>
            <?php endif; ?>
            <?php $u = current_user(); ?>
            <div class="dropdown">
                <button class="user-chip" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="avatar"><?= e(strtoupper(mb_substr($u['full_name'] ?? 'U', 0, 1))) ?></span>
                    <span class="user-meta">
                        <strong><?= e($u['full_name'] ?? '') ?></strong>
                        <small><?= e(ucfirst($u['role'] ?? '')) ?></small>
                    </span>
                    <i data-lucide="chevron-down"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li class="dropdown-item-text small text-muted">
                        Signed in as <strong><?= e($u['username'] ?? '') ?></strong>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="<?= APP_URL ?>/logout.php" class="m-0">
                            <?= csrf_field() ?>
                            <button class="dropdown-item" type="submit">
                                <i data-lucide="log-out" class="me-1"></i> Sign out
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <main class="content">
        <?php foreach ($flashes as $f): ?>
            <div class="alert alert-<?= e($f['type'] === 'error' ? 'danger' : $f['type']) ?> alert-dismissible fade show" role="alert">
                <?= e($f['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endforeach; ?>
