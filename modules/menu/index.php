<?php
/**
 * modules/menu/index.php : menu item list, search, filters and CRUD modals.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

/* ------------------------- filters ------------------------- */
$search    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 120);
$categoryF = in_array($_GET['category'] ?? '', menu_categories(), true) ? $_GET['category'] : '';
$statusF   = in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 15;

$where  = '1=1';
$params = [];
if ($search !== '') {
    $where .= ' AND (item_name LIKE :q OR description LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
if ($categoryF !== '') {
    $where .= ' AND category = :cat';
    $params['cat'] = $categoryF;
}
if ($statusF !== '') {
    $where .= ' AND is_available = :avail';
    $params['avail'] = $statusF === 'active' ? 1 : 0;
}

$total = (int)scalar("SELECT COUNT(*) FROM menu_items WHERE $where", $params);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$items = rows(
    "SELECT m.*, (SELECT COUNT(*) FROM order_items oi WHERE oi.menu_item_id = m.id) AS times_sold,
            (SELECT COALESCE(SUM(oi.quantity),0) FROM order_items oi WHERE oi.menu_item_id = m.id) AS units_sold
     FROM menu_items m
     WHERE $where
     ORDER BY m.category, m.item_name
     LIMIT $perPage OFFSET $offset",
    $params
);

$categorySummary = rows(
    'SELECT category, COUNT(*) AS c, ROUND(AVG(unit_price),2) AS avg_price FROM menu_items GROUP BY category ORDER BY c DESC'
);

$qsParts = [];
if ($search !== '') { $qsParts[] = 'q=' . rawurlencode($search); }
if ($categoryF !== '') { $qsParts[] = 'category=' . rawurlencode($categoryF); }
if ($statusF !== '') { $qsParts[] = 'status=' . $statusF; }
$qs = implode('&', $qsParts);

$page_title = 'Menu Management';
$page_active = 'menu';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="filter-bar">
    <form method="get" action="index.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <div class="field grow">
            <label for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= e($search) ?>"
                   placeholder="Search item name or description...">
        </div>
        <div class="field">
            <label for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All categories</option>
                <?php foreach (menu_categories() as $c): ?>
                    <option value="<?= e($c) ?>"<?= $categoryF === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="status">Availability</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="active"<?= $statusF === 'active' ? ' selected' : '' ?>>Available</option>
                <option value="inactive"<?= $statusF === 'inactive' ? ' selected' : '' ?>>Unavailable</option>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="search"></i> Search</button>
            <a class="btn btn-light btn-icon" href="index.php"><i data-lucide="rotate-ccw"></i> Reset</a>
            <button class="btn btn-success btn-icon" type="button" data-bs-toggle="modal" data-bs-target="#menuModal"
                    data-mode="create">
                <i data-lucide="plus"></i> Add menu item
            </button>
        </div>
    </form>
</div>

<div class="grid-3 mb-3">
    <?php foreach ($categorySummary as $cs): ?>
        <div class="card-box" style="padding:13px 15px">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <div class="kpi-label"><?= e($cs['category']) ?></div>
                    <div style="font-size:19px;font-weight:800;color:var(--text-strong)"><?= (int)$cs['c'] ?> items</div>
                </div>
                <div class="text-end small-note">avg <?= peso($cs['avg_price']) ?></div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="card-box">
    <div class="card-head">
        <h4>Menu items <span class="badge bg-primary"><?= number_format($total) ?></span></h4>
        <span class="hint">Historical sales keep the price recorded at the time of the transaction.</span>
    </div>

    <?php if (!$items): ?>
        <div class="empty-state">
            <i data-lucide="utensils-crossed"></i>
            <h5>No menu items found</h5>
            <p class="mb-2">Try clearing the filters, or add your first item.</p>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#menuModal" data-mode="create">
                Add menu item
            </button>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th style="width:64px">ID</th>
                    <th>Item name / description</th>
                    <th>Category</th>
                    <th class="num">Unit price</th>
                    <th class="num">Units sold</th>
                    <th>Status</th>
                    <th>Date created</th>
                    <th class="text-end">Actions</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="text-muted-2">#<?= (int)$it['id'] ?></td>
                        <td>
                            <strong><?= e($it['item_name']) ?></strong>
                            <span class="cell-sub"><?= e($it['description'] ?: '—') ?></span>
                        </td>
                        <td><span class="badge bg-info text-dark"><?= e($it['category']) ?></span></td>
                        <td class="num"><strong><?= peso($it['unit_price']) ?></strong></td>
                        <td class="num"><?= number_format((int)$it['units_sold']) ?>
                            <span class="cell-sub"><?= (int)$it['times_sold'] ?> orders</span></td>
                        <td><?= (int)$it['is_available']
                                ? '<span class="badge bg-success">Available</span>'
                                : '<span class="badge bg-secondary">Unavailable</span>' ?></td>
                        <td><?= e(format_date($it['created_at'])) ?></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-light btn-icon" data-bs-toggle="modal"
                                    data-bs-target="#menuModal" data-mode="edit"
                                    data-id="<?= (int)$it['id'] ?>"
                                    data-name="<?= e($it['item_name']) ?>"
                                    data-description="<?= e($it['description']) ?>"
                                    data-category="<?= e($it['category']) ?>"
                                    data-price="<?= e($it['unit_price']) ?>"
                                    data-available="<?= (int)$it['is_available'] ?>">
                                <i data-lucide="pencil"></i> Edit
                            </button>

                            <form method="post" action="toggle.php" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                <button class="btn btn-sm btn-light btn-icon" type="submit">
                                    <i data-lucide="<?= (int)$it['is_available'] ? 'eye-off' : 'eye' ?>"></i>
                                    <?= (int)$it['is_available'] ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>

                            <?php if (is_admin()): ?>
                                <form method="post" action="delete.php" class="d-inline"
                                      data-confirm="Delete this item permanently? Items with existing sales cannot be deleted.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$it['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger btn-icon" type="submit">
                                        <i data-lucide="trash-2"></i>
                                    </button>
                                </form>
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
                        $url = 'index.php?page=' . $p . ($qs !== '' ? '&' . $qs : '');
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

<!-- Add / Edit modal -->
<div class="modal fade" id="menuModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" method="post" action="save.php" id="menuForm" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="menuId" value="">
            <div class="modal-header">
                <h5 class="modal-title" id="menuModalTitle">Add menu item</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="item_name">Item name <span class="text-danger">*</span></label>
                    <input class="form-control" type="text" id="item_name" name="item_name" maxlength="120"
                           placeholder="e.g. Grilled Tanigue" required>
                    <div class="invalid-msg" id="err_item_name" hidden></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" type="text" id="description" name="description" maxlength="255"
                           placeholder="Short description (optional)">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="category">Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="category" name="category" required>
                            <?php foreach (menu_categories() as $c): ?>
                                <option value="<?= e($c) ?>"><?= e($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="unit_price">Unit price (PHP) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">&#8369;</span>
                            <input class="form-control" type="number" id="unit_price" name="unit_price"
                                   min="1" max="1000000" step="0.01" placeholder="0.00" required>
                        </div>
                        <div class="invalid-msg" id="err_unit_price" hidden></div>
                    </div>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="is_available" name="is_available" value="1" checked>
                    <label class="form-check-label" for="is_available">Available for ordering</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-icon"><i data-lucide="save"></i> Save item</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('menuModal');
    var form = document.getElementById('menuForm');

    modal.addEventListener('show.bs.modal', function (event) {
        var btn = event.relatedTarget;
        var mode = btn ? btn.getAttribute('data-mode') : 'create';
        form.reset();
        document.getElementById('err_item_name').hidden = true;
        document.getElementById('err_unit_price').hidden = true;
        document.getElementById('menuId').value = '';
        document.getElementById('menuModalTitle').textContent = 'Add menu item';
        document.getElementById('is_available').checked = true;

        if (mode === 'edit' && btn) {
            document.getElementById('menuModalTitle').textContent = 'Edit menu item';
            document.getElementById('menuId').value = btn.getAttribute('data-id');
            document.getElementById('item_name').value = btn.getAttribute('data-name') || '';
            document.getElementById('description').value = btn.getAttribute('data-description') || '';
            document.getElementById('category').value = btn.getAttribute('data-category') || 'Other';
            document.getElementById('unit_price').value = btn.getAttribute('data-price') || '';
            document.getElementById('is_available').checked = btn.getAttribute('data-available') === '1';
        }
    });

    form.addEventListener('submit', function (e) {
        var name = document.getElementById('item_name');
        var price = document.getElementById('unit_price');
        var ok = true;

        var errName = document.getElementById('err_item_name');
        if (name.value.trim().length < 2) {
            errName.textContent = 'Item name must be at least 2 characters.';
            errName.hidden = false; ok = false;
        } else { errName.hidden = true; }

        var errPrice = document.getElementById('err_unit_price');
        var p = parseFloat(price.value);
        if (isNaN(p) || p <= 0) {
            errPrice.textContent = 'Unit price must be greater than 0.';
            errPrice.hidden = false; ok = false;
        } else if (p > 1000000) {
            errPrice.textContent = 'Unit price looks unrealistic (max 1,000,000).';
            errPrice.hidden = false; ok = false;
        } else { errPrice.hidden = true; }

        if (!ok) e.preventDefault();
    });
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
