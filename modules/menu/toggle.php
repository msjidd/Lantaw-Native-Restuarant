<?php
/**
 * modules/menu/toggle.php : activate / deactivate a menu item.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();
require_post();
csrf_verify();

$id   = (int)($_POST['id'] ?? 0);
$item = row('SELECT id, item_name, is_available FROM menu_items WHERE id = ? LIMIT 1', [$id]);

if (!$item) {
    flash_set('danger', 'Menu item not found.');
    redirect(APP_URL . '/modules/menu/index.php');
}

$newState = ((int)$item['is_available'] === 1) ? 0 : 1;
q('UPDATE menu_items SET is_available = ? WHERE id = ?', [$newState, $id]);

log_audit(
    $newState ? 'menu_enable' : 'menu_disable',
    'menu_items',
    $id,
    $item['item_name'] . ($newState ? ' marked available' : ' marked unavailable')
);

flash_set('success', '"' . $item['item_name'] . '" is now ' . ($newState ? 'available' : 'unavailable') . '.');
redirect(APP_URL . '/modules/menu/index.php');
