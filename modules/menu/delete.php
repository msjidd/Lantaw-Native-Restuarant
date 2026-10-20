<?php
/**
 * modules/menu/delete.php : administrator-only hard delete.
 * Items referenced by order_items are protected by a RESTRICT foreign key.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_admin();
require_post();
csrf_verify();

$id   = (int)($_POST['id'] ?? 0);
$item = row('SELECT id, item_name FROM menu_items WHERE id = ? LIMIT 1', [$id]);

if (!$item) {
    flash_set('danger', 'Menu item not found.');
    redirect(APP_URL . '/modules/menu/index.php');
}

$used = (int)scalar('SELECT COUNT(*) FROM order_items WHERE menu_item_id = ?', [$id]);
if ($used > 0) {
    flash_set('warning', '"' . $item['item_name'] . '" cannot be deleted because it appears in '
        . number_format($used) . ' sales line(s). Disable it instead so history stays intact.');
    redirect(APP_URL . '/modules/menu/index.php');
}

try {
    q('DELETE FROM menu_items WHERE id = ?', [$id]);
    log_audit('menu_delete', 'menu_items', $id, 'Deleted menu item "' . $item['item_name'] . '"');
    flash_set('success', 'Menu item deleted.');
} catch (PDOException $pe) {
    error_log('[menu/delete] ' . $pe->getMessage());
    flash_set('danger', 'The item could not be deleted.');
}

redirect(APP_URL . '/modules/menu/index.php');
