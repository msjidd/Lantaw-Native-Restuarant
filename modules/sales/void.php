<?php
/**
 * modules/sales/void.php : voids a completed order (admin only, POST).
 * Changes status to 'voided', records reason, logs audit, updates timestamp.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_admin();
require_post();
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
$reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 255);

if ($id <= 0) {
    flash_set('danger', 'Order not found.');
    redirect(APP_URL . '/modules/sales/index.php');
}

$order = row('SELECT id, order_reference, status FROM orders WHERE id = ? LIMIT 1', [$id]);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect(APP_URL . '/modules/sales/index.php');
}

if ((string)$order['status'] !== 'completed') {
    flash_set('warning', 'Only completed orders can be voided.');
    redirect(APP_URL . '/modules/sales/view.php?id=' . $id);
}

$db = db();
try {
    $db->beginTransaction();

    $stmt = $db->prepare(
        "UPDATE orders SET status = 'voided', status_reason = ?, updated_at = NOW() WHERE id = ? AND status = 'completed'"
    );
    $stmt->execute([$reason === '' ? null : $reason, $id]);
    if ($stmt->rowCount() === 0) {
        $db->rollBack();
        flash_set('warning', 'This order was already processed.');
        redirect(APP_URL . '/modules/sales/view.php?id=' . $id);
    }

    log_audit('order_void', 'orders', $id, 'Voided ' . $order['order_reference'] . ($reason === '' ? '' : ' (reason: ' . $reason . ')'));
    $db->commit();
    flash_set('success', 'Order ' . $order['order_reference'] . ' was voided.');
} catch (PDOException $pe) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[sales/void] ' . $pe->getMessage());
    flash_set('danger', 'Could not void the order.');
}

redirect(APP_URL . '/modules/sales/index.php');
