<?php
/**
 * modules/sales/view.php : order detail view.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    flash_set('danger', 'Order not found.');
    redirect(APP_URL . '/modules/sales/index.php');
}

$order = row(
    'SELECT o.*, u.full_name AS created_by_name
     FROM orders o
     LEFT JOIN users u ON u.id = o.created_by
     WHERE o.id = ? LIMIT 1',
    [$id]
);

if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect(APP_URL . '/modules/sales/index.php');
}

$items = rows(
    'SELECT oi.* FROM order_items oi WHERE oi.order_id = ? ORDER BY oi.id ASC',
    [$id]
);

$lineCount = count($items);
$subtotal = 0.0;
foreach ($items as $it) {
    $subtotal += (float)$it['line_total'];
}

$page_title = 'Order ' . e($order['order_reference']);
$page_active = 'sales';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h4 class="mb-0"><?= e($order['order_reference']) ?></h4>
        <p class="text-muted-2 mb-0">Recorded by <?= e($order['created_by_name'] ?? '—') ?> on <?= e(format_date($order['created_at'], true)) ?></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-light btn-icon" href="<?= APP_URL ?>/modules/sales/index.php"><i data-lucide="arrow-left"></i> Back to sales</a>
        <?php if ((string)$order['status'] === 'completed'): ?>
            <?php if (is_admin()): ?>
                <a class="btn btn-light btn-icon" href="<?= APP_URL ?>/modules/sales/edit.php?id=<?= (int)$order['id'] ?>"><i data-lucide="pencil"></i> Edit</a>
                <form method="post" action="<?= APP_URL ?>/modules/sales/void.php" class="d-inline"
                      data-confirm="Void <?= e($order['order_reference']) ?>? It will be excluded from gross sales and an audit record will be kept.">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
                    <button class="btn btn-outline-danger btn-icon" type="submit"><i data-lucide="ban"></i> Void</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="grid-3 mb-3">
    <div class="card-box h-100">
        <div class="card-head">
            <h4>Order details</h4>
        </div>
        <div class="d-flex flex-column gap-2 small">
            <div class="d-flex justify-content-between"><span class="text-muted-2">Reference</span><strong><?= e($order['order_reference']) ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-muted-2">Date</span><span><?= e(format_date($order['order_date'])) ?></span></div>
            <div class="d-flex justify-content-between"><span class="text-muted-2">Order type</span><span class="badge bg-light text-dark border"><?= e($order['order_type']) ?></span></div>
            <div class="d-flex justify-content-between align-items-center"><span class="text-muted-2">Status</span><?= status_badge($order['status']) ?></div>
            <?php if ($order['status_reason'] !== null && $order['status_reason'] !== ''): ?>
                <div class="pt-2 border-top mt-1">
                    <span class="text-muted-2 d-block mb-1">Reason</span>
                    <div><?= e($order['status_reason']) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card-box h-100">
        <div class="card-head">
            <h4>Amounts</h4>
        </div>
        <div class="d-flex flex-column gap-2 small">
            <div class="d-flex justify-content-between"><span class="text-muted-2">Line items</span><span><?= (int)$lineCount ?></span></div>
            <div class="d-flex justify-content-between"><span class="text-muted-2">Subtotal</span><span><?= peso($subtotal) ?></span></div>
            <div class="d-flex justify-content-between pt-2 border-top mt-1"><span class="text-muted-2">Total amount</span><strong class="fs-5"><?= peso($order['total_amount']) ?></strong></div>
        </div>
    </div>

    <div class="card-box h-100">
        <div class="card-head">
            <h4>Audit</h4>
        </div>
        <div class="d-flex flex-column gap-2 small">
            <div class="d-flex justify-content-between"><span class="text-muted-2">Created at</span><span><?= e(format_date($order['created_at'], true)) ?></span></div>
            <div class="d-flex justify-content-between"><span class="text-muted-2">Updated at</span><span><?= e(format_date($order['updated_at'], true)) ?></span></div>
            <div class="d-flex justify-content-between"><span class="text-muted-2">Sample data</span><span><?= (int)$order['is_sample'] ? 'Yes' : 'No' ?></span></div>
        </div>
    </div>
</div>

<div class="card-box">
    <div class="card-head">
        <h4>Line items</h4>
    </div>
    <?php if (!$items): ?>
        <div class="empty-state">
            <i data-lucide="list"></i>
            <h5>No line items on this order</h5>
            <p>This order has no recorded items.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th class="num">Unit price</th>
                    <th class="num">Qty</th>
                    <th class="num">Line total</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td>
                            <strong><?= e($it['item_name']) ?></strong>
                            <?php if ((int)$it['menu_item_id'] > 0): ?>
                                <span class="cell-sub">Menu item #<?= (int)$it['menu_item_id'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= e($it['category']) ?></span></td>
                        <td class="num"><?= peso($it['unit_price']) ?></td>
                        <td class="num"><?= (int)$it['quantity'] ?></td>
                        <td class="num"><strong><?= peso($it['line_total']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="4" class="text-end">Total</td>
                    <td class="num"><?= peso($subtotal) ?></td>
                </tr>
                </tfoot>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
