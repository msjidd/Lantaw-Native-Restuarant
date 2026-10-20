<?php
/**
 * modules/sales/edit.php : edit a completed order (admin only).
 * GET loads order + lines; POST validates and saves in transaction.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_once __DIR__ . '/_lines.php';
require_admin();

$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($id <= 0) {
    flash_set('danger', 'Order not found.');
    redirect(APP_URL . '/modules/sales/index.php');
}

$page_title = 'Edit order';
$page_active = 'sales';
$errors = $_SESSION['sales_edit_errors'] ?? [];
$old = $_SESSION['sales_edit_old'] ?? null;
unset($_SESSION['sales_edit_errors'], $_SESSION['sales_edit_old']);

$order = row(
    'SELECT o.*, u.full_name AS created_by_name FROM orders o LEFT JOIN users u ON u.id = o.created_by WHERE o.id = ? LIMIT 1',
    [$id]
);
if (!$order) {
    flash_set('danger', 'Order not found.');
    redirect(APP_URL . '/modules/sales/index.php');
}
if ((string)$order['status'] !== 'completed') {
    flash_set('warning', 'Only completed orders can be edited.');
    redirect(APP_URL . '/modules/sales/view.php?id=' . $id);
}

$existingItems = rows('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC', [$id]);
$menuItems = rows(
    "SELECT id, item_name, category, unit_price, is_available FROM menu_items WHERE is_available = 1 ORDER BY category ASC, item_name ASC"
);

if (!empty($_POST) && (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST')) {
    require_post();
    csrf_verify();

    $orderDateRaw = trim((string)($_POST['order_date'] ?? ''));
    $orderDate = null;
    if ($orderDateRaw !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $orderDateRaw);
        if ($d !== false && $d->format('Y-m-d') === $orderDateRaw) {
            $orderDate = $orderDateRaw;
        }
    }
    $orderType = in_array($_POST['order_type'] ?? '', order_types(), true) ? $_POST['order_type'] : (string)$order['order_type'];

    $itemsPost = $_POST['items'] ?? [];
    $rowErrors = [];

    if (!is_array($itemsPost) || count($itemsPost) === 0) {
        $rowErrors[] = 'Add at least one order line.';
        $lines = [];
    } else {
        [$lines, $lineErrors] = lantaw_parse_lines($itemsPost, false);
        $rowErrors = array_merge($rowErrors, $lineErrors);
    }

    if ($orderDate === null) {
        $rowErrors[] = 'Please enter a valid order date.';
    } elseif ($orderDate > date('Y-m-d')) {
        $rowErrors[] = 'The order date cannot be in the future.';
    }

    if (!empty($rowErrors)) {
        $_SESSION['sales_edit_errors'] = $rowErrors;
        $_SESSION['sales_edit_old'] = [
            'order_date' => $orderDate,
            'order_type' => $orderType,
            'items'      => $itemsPost,
        ];
        redirect(APP_URL . '/modules/sales/edit.php?id=' . $id);
    }

    $total = 0.0;
    foreach ($lines as $l) {
        $total += (float)$l['line_total'];
    }
    $total = round($total, 2);

    $db = db();
    try {
        $db->beginTransaction();
        $stmt = $db->prepare(
            'UPDATE orders SET order_date = ?, order_type = ?, total_amount = ?, updated_at = NOW()
             WHERE id = ? AND status = \'completed\''
        );
        $stmt->execute([$orderDate, $orderType, $total, $id]);
        if ($stmt->rowCount() === 0) {
            // status may have changed
            $chk = row('SELECT status FROM orders WHERE id = ? LIMIT 1', [$id]);
            if (!$chk || (string)$chk['status'] !== 'completed') {
                $db->rollBack();
                flash_set('warning', 'This order cannot be edited.');
                redirect(APP_URL . '/modules/sales/view.php?id=' . $id);
            }
        }

        $db->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$id]);
        $ins = $db->prepare(
            'INSERT INTO order_items (order_id, menu_item_id, item_name, category, unit_price, quantity, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($lines as $l) {
            $ins->execute([$id, $l['menu_item_id'], $l['item_name'], $l['category'], $l['unit_price'], $l['quantity'], $l['line_total']]);
        }

        log_audit('order_update', 'orders', $id,
            'Updated ' . $order['order_reference'] . ' (' . $orderType . ') total ' . num2($total) . ' — ' . lantaw_lines_summary($lines));
        $db->commit();
        flash_set('success', 'Order ' . $order['order_reference'] . ' updated.');
        redirect(APP_URL . '/modules/sales/view.php?id=' . $id);
    } catch (PDOException $pe) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[sales/edit] ' . $pe->getMessage());
        $_SESSION['sales_edit_errors'] = ['Could not save changes. Please try again.'];
        $_SESSION['sales_edit_old'] = [
            'order_date' => $orderDate,
            'order_type' => $orderType,
            'items'      => $itemsPost,
        ];
        redirect(APP_URL . '/modules/sales/edit.php?id=' . $id);
    }
}

$initialItems = [];
if ($old !== null && isset($old['items']) && is_array($old['items'])) {
    foreach ($old['items'] as $it) {
        $initialItems[] = $it;
    }
} else {
    foreach ($existingItems as $it) {
        $initialItems[] = [
            'menu_item_id' => $it['menu_item_id'],
            'item_name'    => $it['item_name'],
            'category'     => $it['category'],
            'unit_price'   => $it['unit_price'],
            'quantity'     => $it['quantity'],
        ];
    }
}

include dirname(__DIR__, 2) . '/includes/header.php';
?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
                <li><?= e($e) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= APP_URL ?>/modules/sales/edit.php?id=<?= (int)$order['id'] ?>" id="orderForm" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">
    <div class="grid-2 mb-3">
        <div class="card-box h-100">
            <div class="card-head">
                <h4>Order header</h4>
            </div>
            <div class="d-grid gap-2">
                <div class="field">
                    <label class="form-label" for="order_date">Order date</label>
                    <input class="form-control" type="date" id="order_date" name="order_date"
                           value="<?= e($old['order_date'] ?? (string)$order['order_date']) ?>">
                </div>
                <div class="field">
                    <label class="form-label" for="order_type">Order type</label>
                    <select class="form-select" id="order_type" name="order_type">
                        <?php foreach (order_types() as $t): ?>
                            <option value="<?= e($t) ?>"<?= (($old['order_type'] ?? (string)$order['order_type']) === $t) ? ' selected' : '' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="text-muted-2 small">Reference: <strong><?= e($order['order_reference']) ?></strong></div>
            </div>
        </div>
        <div class="card-box h-100">
            <div class="card-head">
                <h4>Quick add from menu</h4>
                <span class="hint">Available items only</span>
            </div>
            <div class="field mb-2">
                <select class="form-select" id="menuPicker">
                    <option value="">— Select available menu item —</option>
                    <?php foreach ($menuItems as $m): ?>
                        <option value="<?= (int)$m['id'] ?>" data-name="<?= e($m['item_name']) ?>"
                                data-cat="<?= e($m['category']) ?>" data-price="<?= e($m['unit_price']) ?>">
                            <?= e($m['category']) ?> · <?= e($m['item_name']) ?> (<?= peso($m['unit_price']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-light btn-icon" type="button" id="btnAddMenu"><i data-lucide="plus-square"></i> Add line</button>
        </div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Order lines</h4>
            <button class="btn btn-light btn-sm btn-icon" type="button" id="btnAddBlank"><i data-lucide="file-plus-2"></i> Add blank line</button>
        </div>
        <div id="linesContainer"></div>
        <div class="d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
            <div class="text-muted-2">Total lines: <span id="lineCount">0</span></div>
            <div class="fs-5 fw-bold">Total: <span id="grandTotal">₱0.00</span></div>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2 mt-3">
        <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="save"></i> Save changes</button>
        <a class="btn btn-light btn-icon" href="<?= APP_URL ?>/modules/sales/view.php?id=<?= (int)$order['id'] ?>"><i data-lucide="x"></i> Cancel</a>
    </div>
</form>

<template id="lineTemplate">
    <div class="line-item row g-2 align-items-center mb-2">
        <div class="col-12 col-lg-5">
            <input class="form-control form-control-sm" type="text" data-field="name" placeholder="Item name" required>
            <input type="hidden" data-field="menu_id" name="items[__i__][menu_item_id]" value="0">
        </div>
        <div class="col-6 col-lg-2">
            <input class="form-control form-control-sm" type="text" data-field="cat" placeholder="Category" name="items[__i__][category]">
        </div>
        <div class="col-6 col-lg-2">
            <input class="form-control form-control-sm text-end" type="number" step="0.01" min="0" data-field="price"
                   placeholder="0.00" name="items[__i__][unit_price]" required>
        </div>
        <div class="col-9 col-lg-2">
            <input class="form-control form-control-sm text-end" type="number" min="1" max="9999" data-field="qty"
                   placeholder="1" name="items[__i__][quantity]" value="1" required>
        </div>
        <div class="col-3 col-lg-1 d-flex justify-content-end gap-1">
            <button class="btn btn-sm btn-outline-danger btn-icon" type="button" data-action="remove" title="Remove line">
                <i data-lucide="trash-2"></i>
            </button>
        </div>
        <div class="col-12 text-end">
            <span class="line-total" data-field="line">₱0.00</span>
        </div>
    </div>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var picker = document.getElementById('menuPicker');
    var btnAddMenu = document.getElementById('btnAddMenu');
    var btnAddBlank = document.getElementById('btnAddBlank');
    var container = document.getElementById('linesContainer');
    var tpl = document.getElementById('lineTemplate');
    var lineCountEl = document.getElementById('lineCount');
    var grandTotalEl = document.getElementById('grandTotal');

    var idx = 0;
    var initial = <?= json_encode($initialItems) ?>;

    function fmt(n) {
        return '\u20B1' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalc() {
        var rows = container.querySelectorAll('.line-item');
        var total = 0;
        rows.forEach(function (r) {
            var price = parseFloat(r.querySelector('[data-field="price"]').value || 0);
            var qty = parseInt(r.querySelector('[data-field="qty"]').value || 0);
            if (isNaN(price) || isNaN(qty) || qty <= 0) {
                r.querySelector('[data-field="line"]').textContent = fmt(0);
            } else {
                var l = price * qty;
                r.querySelector('[data-field="line"]').textContent = fmt(l);
                total += l;
            }
        });
        lineCountEl.textContent = rows.length;
        grandTotalEl.textContent = fmt(total);
    }

    function addLine(data) {
        if (!tpl) return;
        var html = tpl.innerHTML.replace(/__i__/g, idx++);
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        var row = wrap.firstElementChild;
        if (data) {
            row.querySelector('[data-field="menu_id"]').value = data.menu_item_id || 0;
            row.querySelector('[data-field="name"]').value = data.item_name || '';
            row.querySelector('[data-field="cat"]').value = data.category || '';
            row.querySelector('[data-field="price"]').value = data.unit_price || 0;
            row.querySelector('[data-field="qty"]').value = data.quantity || 1;
        }
        container.appendChild(row);
        row.querySelector('[data-action="remove"]').addEventListener('click', function () {
            row.remove();
            recalc();
        });
        row.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('input', recalc);
            inp.addEventListener('change', recalc);
        });
        recalc();
        if (window.lucide) lucide.createIcons();
    }

    btnAddMenu.addEventListener('click', function () {
        if (!picker.value) return;
        var opt = picker.options[picker.selectedIndex];
        addLine({
            menu_item_id: opt.value,
            item_name: opt.getAttribute('data-name') || opt.text,
            category: opt.getAttribute('data-cat') || 'Other',
            unit_price: opt.getAttribute('data-price') || 0,
            quantity: 1
        });
        picker.value = '';
    });
    btnAddBlank.addEventListener('click', function () { addLine({ menu_item_id: 0, item_name: '', category: '', unit_price: '', quantity: 1 }); });

    if (initial && initial.length > 0) {
        initial.forEach(function (it) { addLine(it); });
    } else {
        addLine({ menu_item_id: 0, item_name: '', category: '', unit_price: '', quantity: 1 });
    }
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
