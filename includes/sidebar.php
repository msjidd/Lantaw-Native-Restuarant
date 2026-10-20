<?php
/**
 * includes/sidebar.php : responsive navigation sidebar.
 * Expects $page_active (string) from the page.
 */

declare(strict_types=1);

$active = $page_active ?? '';
$nav = [
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'layout-dashboard', 'url' => APP_URL . '/dashboard.php'],
    ['key' => 'menu', 'label' => 'Menu Management', 'icon' => 'utensils-crossed', 'url' => APP_URL . '/modules/menu/index.php'],
    ['key' => 'sales', 'label' => 'Sales & Transactions', 'icon' => 'receipt-text', 'url' => APP_URL . '/modules/sales/index.php'],
    ['key' => 'descriptive', 'label' => 'Descriptive Analytics', 'icon' => 'bar-chart-3', 'url' => APP_URL . '/modules/analytics/descriptive.php'],
    ['key' => 'predictive', 'label' => 'Predictive Analytics', 'icon' => 'trending-up', 'url' => APP_URL . '/modules/forecasting/index.php'],
    ['key' => 'prescriptive', 'label' => 'Recommendations', 'icon' => 'lightbulb', 'url' => APP_URL . '/modules/recommendations/index.php'],
    ['key' => 'data', 'label' => 'Excel Import / Export', 'icon' => 'file-spreadsheet', 'url' => APP_URL . '/modules/data-management/index.php'],
    ['key' => 'reports', 'label' => 'Reports', 'icon' => 'file-text', 'url' => APP_URL . '/modules/reports/index.php'],
];
if (is_admin()) {
    $nav[] = ['key' => 'users', 'label' => 'User Management', 'icon' => 'users', 'url' => APP_URL . '/modules/users/index.php'];
}
?>
<aside class="sidebar" id="appSidebar">

    <div class="sidebar-brand">
        <img class="brand-logo-img"
             src="<?= APP_URL ?>/assets/images/logo.png"
             alt="Lantaw Native Restaurant logo"
             width="62" height="62">
        <div class="brand-text">
            <strong class="brand-wordmark">Lantaw</strong>
            <span>Analytics</span>
        </div>
    </div>

    <div class="sidebar-section">Main menu</div>
    <nav class="sidebar-nav">
        <?php foreach ($nav as $item): ?>
            <a class="nav-link-item<?= $active === $item['key'] ? ' active' : '' ?>"
               href="<?= e($item['url']) ?>">
                <i data-lucide="<?= e($item['icon']) ?>"></i>
                <span><?= e($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="sidebar-note"><?= e(APP_RESTAURANT) ?></div>
         </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" hidden></div>
