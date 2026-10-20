<?php
/**
 * dashboard.php : executive KPI dashboard driven entirely by MySQL queries.
 */

declare(strict_types=1);
require_once __DIR__ . '/includes/init.php';
require_login();

/* ---------------- filters ---------------- */
$def = default_range();
$from = input_date('from', $def['from']);
$to   = input_date('to', $def['to']);
[$from, $to] = sanitize_range($from, $to);

$category  = in_array($_GET['category'] ?? '', menu_categories(), true) ? $_GET['category'] : '';
$orderType = in_array($_GET['order_type'] ?? '', order_types(), true) ? $_GET['order_type'] : '';

$where  = 'o.order_date BETWEEN :from AND :to';
$params = ['from' => $from, 'to' => $to];
if ($orderType !== '') {
    $where .= ' AND o.order_type = :otype';
    $params['otype'] = $orderType;
}
$itemWhere = $where;
if ($category !== '') {
    $itemWhere .= ' AND oi.category = :cat';
    $params['cat'] = $category;
}

/* ---------------- KPI cards ---------------- */
$kpi = row(
    "SELECT COUNT(DISTINCT o.id) AS orders_cnt,
            COALESCE(SUM(oi.line_total), 0) AS revenue,
            COALESCE(SUM(oi.quantity), 0)   AS items_sold
     FROM orders o
     JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere",
    $params
);
$revenue     = (float)$kpi['revenue'];
$ordersCount = (int)$kpi['orders_cnt'];
$itemsSold   = (int)$kpi['items_sold'];
$aov         = safe_div($revenue, (float)$ordersCount);

$bestSeller = row(
    "SELECT oi.item_name, oi.category, SUM(oi.quantity) AS qty
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.item_name, oi.category
     ORDER BY qty DESC, oi.item_name ASC LIMIT 1",
    $params
);

$topRevenueItem = row(
    "SELECT oi.item_name, SUM(oi.line_total) AS revenue, SUM(oi.quantity) AS qty
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.item_name
     ORDER BY revenue DESC, oi.item_name ASC LIMIT 1",
    $params
);

/* previous comparable period (same length, immediately before) */
$spanDays = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
$prevTo   = date('Y-m-d', strtotime('-1 day', strtotime($from)));
$prevFrom = date('Y-m-d', strtotime('-' . $spanDays . ' days', strtotime($prevTo)));

$prevWhere = 'o.order_date BETWEEN :pfrom AND :pto';
$pParams = ['pfrom' => $prevFrom, 'pto' => $prevTo];
if ($orderType !== '') { $prevWhere .= ' AND o.order_type = :otype'; $pParams['otype'] = $orderType; }
$prevItemWhere = $prevWhere;
if ($category !== '') { $prevItemWhere .= ' AND oi.category = :cat'; $pParams['cat'] = $category; }

$prev = row(
    "SELECT COALESCE(SUM(oi.line_total),0) AS revenue, COUNT(DISTINCT o.id) AS orders_cnt
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $prevItemWhere",
    $pParams
);
$prevRevenue = (float)$prev['revenue'];
$growth = growth_pct($revenue, $prevRevenue);

/* ---------------- chart data ---------------- */
$daily = rows(
    "SELECT o.order_date AS d, COALESCE(SUM(oi.line_total),0) AS revenue, COUNT(DISTINCT o.id) AS orders_cnt
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY o.order_date ORDER BY o.order_date",
    $params
);
$dailyLabels = [];
$dailyRevenue = [];
$dailyOrders = [];
$dailyMap = [];
foreach ($daily as $r) {
    $dailyLabels[] = date('M j', strtotime($r['d']));
    $dailyRevenue[] = round((float)$r['revenue'], 2);
    $dailyOrders[] = (int)$r['orders_cnt'];
    $dailyMap[$r['d']] = (float)$r['revenue'];
}

/* monthly trend: 12 months ending at $to */
$monthStart = date('Y-m-01', strtotime('-11 months', strtotime($to . ' 00:00:00')));
$monthlyParams = ['mfrom' => $monthStart, 'mto' => $to];
$monthlyWhere = "o.order_date BETWEEN :mfrom AND :mto AND o.status = 'completed'";
if ($orderType !== '') { $monthlyWhere .= ' AND o.order_type = :otype'; $monthlyParams['otype'] = $orderType; }
$monthlyItemWhere = $monthlyWhere;
if ($category !== '') { $monthlyItemWhere .= ' AND oi.category = :cat'; $monthlyParams['cat'] = $category; }

$monthly = rows(
    "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS ym, COALESCE(SUM(oi.line_total),0) AS revenue,
            COUNT(DISTINCT o.id) AS orders_cnt
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE $monthlyItemWhere
     GROUP BY ym ORDER BY ym",
    $monthlyParams
);
$monthMap = [];
foreach ($monthly as $r) {
    $monthMap[$r['ym']] = ['revenue' => (float)$r['revenue'], 'orders' => (int)$r['orders_cnt']];
}
$monthlyLabels = [];
$monthlyValues = [];
for ($i = 11; $i >= 0; $i--) {
    $key = date('Y-m', strtotime(date('Y-m-01', strtotime($to)) . " -$i months"));
    $monthlyLabels[] = date('M Y', strtotime($key . '-01'));
    $monthlyValues[] = round($monthMap[$key]['revenue'] ?? 0, 2);
}

$byCategory = rows(
    "SELECT oi.category, SUM(oi.line_total) AS revenue, SUM(oi.quantity) AS qty
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.category ORDER BY revenue DESC",
    $params
);
$catLabels = [];
$catValues = [];
foreach ($byCategory as $r) {
    $catLabels[] = $r['category'];
    $catValues[] = round((float)$r['revenue'], 2);
}

$top10 = rows(
    "SELECT oi.item_name, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.item_name ORDER BY qty DESC, revenue DESC LIMIT 10",
    $params
);
$top10Labels = [];
$top10Qty = [];
foreach ($top10 as $r) {
    $top10Labels[] = $r['item_name'];
    $top10Qty[] = (int)$r['qty'];
}

/* forecast vs actual: 3-period moving average, one-step-ahead, daily sales */
$fcLabels = [];
$fcActual = [];
$fcPredicted = [];
$fcSeries = array_values($dailyMap);
$fcDates = array_keys($dailyMap);
$serieN = count($fcSeries);
if ($serieN >= 4) {
    for ($i = 3; $i < $serieN; $i++) {
        $predicted = ($fcSeries[$i - 1] + $fcSeries[$i - 2] + $fcSeries[$i - 3]) / 3;
        $fcLabels[] = date('M j', strtotime($fcDates[$i]));
        $fcActual[] = round($fcSeries[$i], 2);
        $fcPredicted[] = round($predicted, 2);
    }
}

/* recent transactions */
$recent = rows(
    "SELECT o.id, o.order_reference, o.order_date, o.order_type, o.total_amount, o.status,
            u.full_name AS created_by_name,
            (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS line_count
     FROM orders o
     LEFT JOIN users u ON u.id = o.created_by
     ORDER BY o.id DESC LIMIT 8"
);

$coverage = row('SELECT MIN(order_date) AS min_date, MAX(order_date) AS max_date, COUNT(*) AS total_orders FROM orders');

/* order type distribution insight (must use $itemWhere + $params so the
   :cat placeholder and its value always stay in sync) */
$typeDist = rows(
    "SELECT o.order_type, COUNT(DISTINCT o.id) AS c
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY o.order_type ORDER BY c DESC",
    $params
);

/* business insights (all derived from the queries above) */
$insights = [];
if ($ordersCount === 0) {
    $insights[] = ['type' => 'warn', 'title' => 'No completed sales in this range',
        'text' => 'Widen the date range or clear the category / order type filters to see activity.'];
} else {
    if ($growth !== null) {
        $insights[] = ['type' => ($growth >= 0 ? 'good' : 'bad'),
            'title' => 'Revenue ' . ($growth >= 0 ? 'increased' : 'decreased') . ' by '
                . number_format(abs($growth), 1) . '% versus the previous period',
            'text' => 'The selected ' . $spanDays . '-day window produced ' . peso($revenue)
                . ' against ' . peso($prevRevenue) . ' in the comparable prior period ('
                . format_date($prevFrom) . ' – ' . format_date($prevTo) . ').'];
    } elseif ($prevRevenue == 0 && $revenue > 0) {
        $insights[] = ['type' => 'good', 'title' => 'New sales activity detected',
            'text' => 'The previous comparable period recorded no sales, so a growth percentage cannot be computed (zero denominator).'];
    }
    if ($bestSeller) {
        $share = safe_div((float)$bestSeller['qty'], (float)$itemsSold) * 100;
        $insights[] = ['type' => 'good',
            'title' => $bestSeller['item_name'] . ' leads volume with ' . number_format((int)$bestSeller['qty']) . ' units',
            'text' => 'That is ' . number_format($share, 1) . '% of all items sold in the period. Keep ingredients and stock aligned with this demand.'];
    }
    if ($catLabels !== []) {
        $topShare = safe_div($catValues[0], $revenue) * 100;
        $insights[] = ['type' => 'info',
            'title' => $catLabels[0] . ' contributes ' . number_format($topShare, 1) . '% of revenue',
            'text' => 'Category revenue is ' . peso($catValues[0]) . ' of ' . peso($revenue)
                . ' total. Review assortment and promotions for the remaining categories.'];
    }
    if ($typeDist !== []) {
        $totalOrdersAll = array_sum(array_column($typeDist, 'c'));
        $lead = $typeDist[0];
        $pct = safe_div((float)$lead['c'], (float)$totalOrdersAll) * 100;
        $insights[] = ['type' => 'info',
            'title' => $lead['order_type'] . ' is the dominant order type (' . number_format($pct, 1) . '% of orders)',
            'text' => 'Use this when reviewing staffing, packaging inventory and delivery capacity.'];
    }
}
$insights[] = ['type' => 'warn', 'title' => 'Revenue is not profit',
    'text' => 'These figures are gross sales only. Profit can only oe reported after verified ingredient cost data is recorded.'];

$page_title = 'Dashboard';
$page_active = 'dashboard';
include __DIR__ . '/includes/header.php';
?>

<div class="filter-bar">
    <form method="get" action="dashboard.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <div class="field">
            <label for="from">Date from</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($to) ?>">
        </div>
        <div class="field">
            <label for="to">Date to</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="field">
            <label for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All categories</option>
                <?php foreach (menu_categories() as $c): ?>
                    <option value="<?= e($c) ?>"<?= $category === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="order_type">Order type</label>
            <select class="form-select" id="order_type" name="order_type">
                <option value="">All types</option>
                <?php foreach (order_types() as $t): ?>
                    <option value="<?= e($t) ?>"<?= $orderType === $t ? ' selected' : '' ?>><?= e($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="filter"></i> Apply</button>
            <a class="btn btn-light btn-icon" href="dashboard.php"><i data-lucide="rotate-ccw"></i> Reset</a>
        </div>
    </form>
</div>

<?php if (has_sample_data()): ?>
    <div class="alert alert-warning py-2 mb-3" style="font-size:13px">
        <strong>Simulated data:</strong> menu prices and sales records are generated sample data for this academic
        ouild (<?= (int)$coverage['total_orders'] ?> orders, <?= e(format_date($coverage['min_date'])) ?> – <?= e(format_date($coverage['max_date'])) ?>).
        They are not actual Lantaw Native Restaurant sales.
    </div>
<?php endif; ?>

<div class="kpi-grid">
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
        <div class="kpi-top">
            <span class="kpi-label">Total Sales Revenue</span>
            <span class="kpi-icon"><i data-lucide="peso"></i></span>
        </div>
        <div class="kpi-value"><?= peso($revenue) ?></div>
        <div class="kpi-foot">Gross sales · <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?> <?= growth_chip($growth) ?></div>
    </div>

    <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
        <div class="kpi-top">
            <span class="kpi-label">Completed Orders</span>
            <span class="kpi-icon"><i data-lucide="receipt-text"></i></span>
        </div>
        <div class="kpi-value"><?= number_format($ordersCount) ?></div>
        <div class="kpi-foot">Voided / cancelled orders excluded</div>
    </div>

    <div class="kpi" style="--kpi-color:#D98C0A;--kpi-bg:#FBEFD4">
        <div class="kpi-top">
            <span class="kpi-label">Total Items Sold</span>
            <span class="kpi-icon"><i data-lucide="package"></i></span>
        </div>
        <div class="kpi-value"><?= number_format($itemsSold) ?></div>
        <div class="kpi-foot">Sum of line quantities</div>
    </div>

    <div class="kpi" style="--kpi-color:#8B4513;--kpi-bg:#F7E8D8">
        <div class="kpi-top">
            <span class="kpi-label">Average Order Value</span>
            <span class="kpi-icon"><i data-lucide="calculator"></i></span>
        </div>
        <div class="kpi-value"><?= peso($aov) ?></div>
        <div class="kpi-foot">Revenue ÷ completed orders</div>
    </div>

    <div class="kpi" style="--kpi-color:#91D523;--kpi-bg:#F1F8DE">
        <div class="kpi-top">
            <span class="kpi-label">Best-Selling Item</span>
            <span class="kpi-icon"><i data-lucide="award"></i></span>
        </div>
        <div class="kpi-value sm"><?= $bestSeller ? e($bestSeller['item_name']) : '—' ?></div>
        <div class="kpi-foot"><?= $bestSeller ? number_format((int)$bestSeller['qty']) . ' units · ' . e($bestSeller['category']) : 'No sales in range' ?></div>
    </div>

    <div class="kpi" style="--kpi-color:#54260D;--kpi-bg:#F2E5CE">
        <div class="kpi-top">
            <span class="kpi-label">Highest-Revenue Item</span>
            <span class="kpi-icon"><i data-lucide="star"></i></span>
        </div>
        <div class="kpi-value sm"><?= $topRevenueItem ? e($topRevenueItem['item_name']) : '—' ?></div>
        <div class="kpi-foot"><?= $topRevenueItem ? peso($topRevenueItem['revenue']) . ' · ' . number_format((int)$topRevenueItem['qty']) . ' units' : 'No sales in range' ?></div>
    </div>
</div>

<div class="chart-grid mb-3">
    <div class="card-box">
        <div class="card-head">
            <h4>Daily sales trend</h4>
            <span class="hint"><?= e(format_date($from)) ?> – <?= e(format_date($to)) ?></span>
        </div>
        <div class="chart-box"><canvas id="chartDaily"></canvas></div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Monthly sales trend</h4>
            <span class="hint">12 months ending <?= e(format_date($to)) ?></span>
        </div>
        <div class="chart-box"><canvas id="chartMonthly"></canvas></div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Revenue by menu category</h4>
            <span class="hint"><?= $category !== '' ? 'Filtered: ' . e($category) : 'All categories' ?></span>
        </div>
        <div class="chart-box"><canvas id="chartCategory"></canvas></div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Top 10 best-selling items</h4>
            <span class="hint">Units sold</span>
        </div>
        <div class="chart-box"><canvas id="chartTop10"></canvas></div>
    </div>
</div>

<div class="chart-grid mb-3">
    <div class="card-box">
        <div class="card-head">
            <h4>Forecast versus actual sales</h4>
            <span class="hint">3-period moving average · one-step-ahead estimate</span>
        </div>
        <?php if ($serieN >= 4): ?>
            <div class="chart-box"><canvas id="chartForecast"></canvas></div>
            <p class="small-note mb-0">
                Estimates are computed from historical daily sales using (latest + previous + two periods earlier) ÷ 3.
                They are planning estimates, not guaranteed future results.
            </p>
        <?php else: ?>
            <div class="empty-state">
                <i data-lucide="trending-up"></i>
                <h5>Not enough history to estimate</h5>
                <p class="mb-0">At least 4 days of sales in the selected range are required.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Business insights</h4>
            <span class="hint">Calculated from the current filters</span>
        </div>
        <?php foreach ($insights as $ins): ?>
            <div class="insight <?= e($ins['type'] === 'info' ? '' : $ins['type']) ?>">
                <i data-lucide="<?= $ins['type'] === 'bad' ? 'trending-down' : ($ins['type'] === 'warn' ? 'triangle-alert' : ($ins['type'] === 'good' ? 'trending-up' : 'lightbulb')) ?>"></i>
                <div>
                    <strong><?= $ins['title'] ?></strong>
                    <p><?= e($ins['text']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card-box">
    <div class="card-head">
        <h4>Recent transactions</h4>
        <a class="btn btn-sm btn-outline-primary btn-icon" href="<?= APP_URL ?>/modules/sales/index.php">
            <i data-lucide="arrow-right"></i> View all transactions
        </a>
    </div>
    <?php if (!$recent): ?>
        <div class="empty-state">
            <i data-lucide="receipt"></i>
            <h5>No transactions yet</h5>
            <p class="mb-0">Record your first order in Sales &amp; Transactions.</p>
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
                        <th class="num">Total</th>
                        <th>Status</th>
                        <th>Recorded by</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td><strong><?= e($r['order_reference']) ?></strong></td>
                            <td><?= e(format_date($r['order_date'])) ?></td>
                            <td><?= e($r['order_type']) ?></td>
                            <td class="num"><?= (int)$r['line_count'] ?></td>
                            <td class="num"><?= peso($r['total_amount']) ?></td>
                            <td><?= status_badge($r['status']) ?></td>
                            <td><?= e($r['created_by_name'] ?? '—') ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-light btn-icon"
                                   href="<?= APP_URL ?>/modules/sales/view.php?id=<?= (int)$r['id'] ?>">
                                    <i data-lucide="eye"></i> Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var money = function (v) { return '\u20B1' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }); };

    var dailyLabels = <?= js($dailyLabels) ?>;
    var dailyRevenue = <?= js($dailyRevenue) ?>;
    var dailyOrders = <?= js($dailyOrders) ?>;

    if (document.getElementById('chartDaily')) {
        new Chart(document.getElementById('chartDaily'), {
            type: 'line',
            data: {
                labels: dailyLabels,
                datasets: [
                    { label: 'Daily revenue', data: dailyRevenue, borderColor: '#244A0C',
                      backgroundColor: 'rgba(36,74,12,.12)', fill: true, tension: .32,
                      pointRadius: dailyLabels.length > 45 ? 0 : 3, borderWidth: 2 },
                    { label: 'Orders', data: dailyOrders, borderColor: '#F59E0B',
                      backgroundColor: 'transparent', borderDash: [5, 4], tension: .32,
                      pointRadius: 0, borderWidth: 1.6, yAxisID: 'y1' }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) {
                        return c.dataset.yAxisID === 'y1'
                            ? c.dataset.label + ': ' + c.parsed.y + ' orders'
                            : c.dataset.label + ': ' + money(c.parsed.y);
                    } } }
                },
                scales: {
                    y: { ticks: { callback: function (v) { return money(v); } }, beginAtZero: true },
                    y1: { position: 'right', grid: { drawOnChartArea: false }, beginAtZero: true,
                          ticks: { callback: function (v) { return v + ' ord'; } } },
                    x: { ticks: { maxTicksLimit: 14, maxRotation: 45 } }
                }
            }
        });
    }

    if (document.getElementById('chartMonthly')) {
        new Chart(document.getElementById('chartMonthly'), {
            type: 'bar',
            data: {
                labels: <?= js($monthlyLabels) ?>,
                datasets: [{ label: 'Monthly revenue', data: <?= js($monthlyValues) ?>,
                    backgroundColor: '#91D523', hoverBackgroundColor: '#244A0C',
                    borderRadius: 6, maxBarThickness: 46 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return 'Revenue: ' + money(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } } }
            }
        });
    }

    if (document.getElementById('chartCategory')) {
        var catLabels = <?= js($catLabels) ?>;
        var catValues = <?= js($catValues) ?>;
        if (!catLabels.length) {
            document.getElementById('chartCategory').parentNode.innerHTML =
                '<div class="empty-state"><i data-lucide="pie-chart"></i><h5>No category data</h5>' +
                '<p class="mb-0">No completed sales matched the current filters.</p></div>';
            if (window.lucide) lucide.createIcons();
        } else {
            new Chart(document.getElementById('chartCategory'), {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{ data: catValues, backgroundColor: BluePalette, borderWidth: 2, borderColor: '#fff' }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '58%',
                    plugins: {
                        legend: { position: 'right' },
                        tooltip: { callbacks: { label: function (c) {
                            var total = c.dataset.data.reduce(function (a, o) { return a + o; }, 0);
                            var pct = total ? (c.parsed / total * 100).toFixed(1) : 0;
                            return c.label + ': ' + money(c.parsed) + ' (' + pct + '%)';
                        } } }
                    }
                }
            });
        }
    }

    if (document.getElementById('chartTop10')) {
        new Chart(document.getElementById('chartTop10'), {
            type: 'bar',
            data: {
                labels: <?= js($top10Labels) ?>,
                datasets: [{ label: 'Units sold', data: <?= js($top10Qty) ?>,
                    backgroundColor: '#244A0C', hoverBackgroundColor: '#91D523', borderRadius: 6 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    if (document.getElementById('chartForecast')) {
        new Chart(document.getElementById('chartForecast'), {
            type: 'line',
            data: {
                labels: <?= js($fcLabels) ?>,
                datasets: [
                    { label: 'Actual sales', data: <?= js($fcActual) ?>, borderColor: '#54260D',
                      backgroundColor: 'transparent', tension: .3, pointRadius: 2.5, borderWidth: 2 },
                    { label: '3-period moving average estimate', data: <?= js($fcPredicted) ?>,
                      borderColor: '#F59E0B', backgroundColor: 'rgba(217,140,10,.10)', fill: true,
                      tension: .3, pointRadius: 0, borderDash: [6, 4], borderWidth: 2 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } },
                          x: { ticks: { maxTicksLimit: 12, maxRotation: 45 } } }
            }
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
