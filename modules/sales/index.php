<?php
/**
 * modules/sales/index.php : transaction list with filters and summary.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

$def = default_range();
$from = input_date('from', $def['from']);
$to   = input_date('to', $def['to']);
[$from, $to] = sanitize_range($from, $to);

$statusF   = in_array($_GET['status'] ?? '', ['completed', 'voided', 'cancelled'], true) ? $_GET['status'] : '';
$orderTypeF = in_array($_GET['order_type'] ?? '', order_types(), true) ? $_GET['order_type'] : '';
$search    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;

$where  = 'o.order_date BETWEEN :from AND :to';
$params = ['from' => $from, 'to' => $to];
if ($statusF !== '') {
    $where .= ' AND o.status = :status';
    $params['status'] = $statusF;
}
if ($orderTypeF !== '') {
    $where .= ' AND o.order_type = :otype';
    $params['otype'] = $orderTypeF;
}
if ($search !== '') {
    $where .= ' AND (o.order_reference LIKE :q OR EXISTS (
                    SELECT 1 FROM order_items s WHERE s.order_id = o.id AND s.item_name LIKE :q))';
    $params['q'] = '%' . $search . '%';
}

$total = (int)scalar("SELECT COUNT(*) FROM orders o WHERE $where", $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$orders = rows(
    "SELECT o.*, u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
     FROM orders o
     LEFT JOIN users u ON u.id = o.created_by
     WHERE $where
     ORDER BY o.id DESC
     LIMIT $perPage OFFSET $offset",
    $params
);

/* filtered totals (completed only = gross sales) */
$summary = row(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(o.total_amount),0) AS total
     FROM orders o WHERE $where AND o.status = 'completed'",
    $params
);
$summaryAll = row(
    "SELECT COALESCE(SUM(o.total_amount),0) AS total
     FROM orders o WHERE $where AND o.status <> 'completed'",
    $params
);

$qsParts = [];
if ($statusF !== '') { $qsParts[] = 'status=' . $statusF; }
if ($orderTypeF !== '') { $qsParts[] = 'order_type=' . rawurlencode($orderTypeF); }
if ($search !== '') { $qsParts[] = 'q=' . rawurlencode($search); }
$qsParts[] = 'from=' . $from;
$qsParts[] = 'to=' . $to;
$qs = implode('&', $qsParts);

$page_title = 'Sales & Transactions';
$page_active = 'sales';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="filter-bar">
    <form method="get" action="index.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <div class="field">
            <label for="from">Date from</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>">
        </div>
        <div class="field">
            <label for="to">Date to</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>">
        </div>
        <div class="field">
            <label for="order_type">Order type</label>
            <select class="form-select" id="order_type" name="order_type">
                <option value="">All types</option>
                <?php foreach (order_types() as $t): ?>
                    <option value="<?= e($t) ?>"<?= $orderTypeF === $t ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All statuses</option>
                <option value="completed"<?= $statusF === 'completed' ? ' selected' : '' ?>>Completed</option>
                <option value="voided"<?= $statusF === 'voided' ? ' selected' : '' ?>>Voided</option>
                <option value="cancelled"<?= $statusF === 'cancelled' ? ' selected' : '' ?>>Cancelled</option>
            </select>
        </div>
        <div class="field grow">
            <label for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Reference or item name...">
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="search"></i> Apply</button>
            <a class="btn btn-light btn-icon" href="index.php"><i data-lucide="rotate-ccw"></i> Reset</a>
            <a class="btn btn-success btn-icon" href="create.php"><i data-lucide="plus"></i> New order</a>
        </div>
    </form>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
        <div class="kpi-top"><span class="kpi-label">Completed orders (filter)</span>
            <span class="kpi-icon"><i data-lucide="receipt"></i></span></div>
        <div class="kpi-value"><?= number_format((int)$summary['cnt']) ?></div>
        <div class="kpi-foot"><?= e(format_date($from)) ?> – <?= e(format_date($to)) ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
        <div class="kpi-top"><span class="kpi-label">Gross sales (filter)</span>
            <span class="kpi-icon"><i data-lucide="peso"></i></span></div>
        <div class="kpi-value"><?= peso($summary['total']) ?></div>
        <div class="kpi-foot">Excludes voided / cancelled</div>
    </div>
    <div class="kpi" style="--kpi-color:#DC3545;--kpi-bg:#FBE9EA">
        <div class="kpi-top"><span class="kpi-label">Voided / cancelled value</span>
            <span class="kpi-icon"><i data-lucide="ban"></i></span></div>
        <div class="kpi-value"><?= peso($summaryAll['total']) ?></div>
        <div class="kpi-foot">Recorded but excluded from revenue</div>
    </div>
</div>

<div class="card-box">
    <div class="card-head">
        <h4>Transactions <span class="badge bg-primary"><?= number_format($total) ?></span></h4>
        <span class="hint">Line totals use the price recorded when the order was placed.</span>
    </div>

    <?php if (!$orders): ?>
        <div class="empty-state">
            <i data-lucide="receipt"></i>
            <h5>No transactions found</h5>
            <p class="mb-2">No orders matched the current filters.</p>
            <a class="btn btn-primary btn-sm" href="create.php">Record a new order</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Reference</th>
                    <th>Date</th>
                    <th>Type</th>
                    <th class="num">Lines</th>
                    <th class="num">Total amount</th>
                    <th>Status</th>
                    <th>Recorded by</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr class="<?= $o['status'] !== 'completed' ? 'row-muted' : '' ?>">
                        <td><strong><?= e($o['order_reference']) ?></strong></td>
                        <td><?= e(format_date($o['order_date'])) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($o['order_type']) ?></span></td>
                        <td class="num"><?= (int)$o['line_count'] ?></td>
                        <td class="num"><strong><?= peso($o['total_amount']) ?></strong></td>
                        <td><?= status_badge($o['status']) ?></td>
                        <td><?= e($o['created_by_name'] ?? '—') ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-light btn-icon" href="view.php?id=<?= (int)$o['id'] ?>">
                                <i data-lucide="eye"></i> View
                            </a>
                            <?php if ($o['status'] === 'completed'): ?>
                                <?php if (is_admin()): ?>
                                    <a class="btn btn-sm btn-light btn-icon" href="edit.php?id=<?= (int)$o['id'] ?>">
                                        <i data-lucide="pencil"></i> Edit
                                    </a>
                                    <form method="post" action="void.php" class="d-inline"
                                          data-confirm="Void <?= e($o['order_reference']) ?>? It will be excluded from gross sales and an audit record will be kept.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger btn-icon" type="button"
                                                onclick="this.closest('form').submit()">
                                            <i data-lucide="ban"></i> Void
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small-note">Page <?= $page ?> of <?= $pages ?></span>
                <ul class="pagination mb-0">
                    <?php
                    $link = function (int $p, string $label, bool $disabled = false, bool $active = false) use ($qs) {
                        $url = 'index.php?page=' . $p . '&' . $qs;
                        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
                        echo '<li class="' . $cls . '"><a class="page-link" href="' . e($url) . '">' . $label . '</a></li>';
                    };
                    $link(max(1, $page - 1), '&laquo;', $page === 1);
                    $start = max(1, $page - 2);
                    $end = min($pages, $page + 2);
                    for ($p = $start; $p <= $end; $p++) {
                        $link($p, (string)$p, false, $p === $page);
                    }
                    $link(min($pages, $page + 1), '&raquo;', $page === $pages);
                    ?>
                </ul>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
