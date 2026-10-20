<?php
/**
 * modules/reports/index.php : printable business reports built from MySQL data.
 * Report types: sales_summary, category, products, order_types.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

$def = default_range();
$from = input_date('from', $def['from']);
$to   = input_date('to', $def['to']);
[$from, $to] = sanitize_range($from, $to);

$reportTypes = [
    'sales_summary' => 'Sales summary',
    'category'      => 'Category performance',
    'products'      => 'Product performance',
    'order_types'   => 'Order type analysis',
];
$report = (string)($_GET['report'] ?? 'sales_summary');
if (!isset($reportTypes[$report])) {
    $report = 'sales_summary';
}

$where  = 'o.order_date BETWEEN :from AND :to';
$params = ['from' => $from, 'to' => $to];
$itemWhere = $where;

$kpi = row(
    "SELECT COUNT(DISTINCT o.id) AS orders_cnt, COALESCE(SUM(oi.line_total),0) AS revenue,
            COALESCE(SUM(oi.quantity),0) AS items
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere",
    $params
);
$grossRevenue = (float)$kpi['revenue'];
$completedOrders = (int)$kpi['orders_cnt'];
$itemsSold = (int)$kpi['items'];
$avgOrder = safe_div($grossRevenue, (float)$completedOrders);

$spanDays = max(1, (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1);

$byCategory = rows(
    "SELECT oi.category, COALESCE(SUM(oi.line_total),0) AS revenue, COALESCE(SUM(oi.quantity),0) AS units,
            COUNT(DISTINCT o.id) AS orders_cnt
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.category ORDER BY revenue DESC",
    $params
);

$byProduct = rows(
    "SELECT oi.item_name, oi.category, COALESCE(SUM(oi.quantity),0) AS units,
            COALESCE(SUM(oi.line_total),0) AS revenue
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.item_name, oi.category ORDER BY revenue DESC LIMIT 40",
    $params
);

$byType = rows(
    "SELECT o.order_type, COUNT(DISTINCT o.id) AS orders_cnt,
            COALESCE(SUM(o.total_amount),0) AS revenue
     FROM orders o WHERE o.status = 'completed' AND $where
     GROUP BY o.order_type ORDER BY revenue DESC",
    $params
);

/* sales trend: daily for short ranges, monthly for long ranges */
$useMonthly = $spanDays > 62;
if ($useMonthly) {
    $trend = rows(
        "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS label, COUNT(DISTINCT o.id) AS orders_cnt,
                COALESCE(SUM(oi.line_total),0) AS revenue, COALESCE(SUM(oi.quantity),0) AS items
         FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE o.status = 'completed' AND $itemWhere
         GROUP BY label ORDER BY label",
        $params
    );
    $trend = array_map(static function (array $r): array {
        $r['label'] = date('M Y', strtotime($r['label'] . '-01'));
        return $r;
    }, $trend);
} else {
    $trend = rows(
        "SELECT o.order_date AS label, COUNT(DISTINCT o.id) AS orders_cnt,
                COALESCE(SUM(oi.line_total),0) AS revenue, COALESCE(SUM(oi.quantity),0) AS items
         FROM orders o JOIN order_items oi ON oi.order_id = o.id
         WHERE o.status = 'completed' AND $itemWhere
         GROUP BY o.order_date ORDER BY o.order_date",
        $params
    );
    $trend = array_map(static function (array $r): array {
        $r['label'] = date('M j', strtotime($r['label']));
        return $r;
    }, $trend);
}

$trendLabels = array_column($trend, 'label');
$trendRevenue = array_map('floatval', array_column($trend, 'revenue'));

$catTotal = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $byCategory));
$prodTotal = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $byProduct));
$typeTotal = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $byType));

$query = ['report' => $report, 'from' => $from, 'to' => $to];

$page_title = 'Reports';
$page_active = 'reports';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="filter-bar">
    <form method="get" action="index.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <div class="field">
            <label for="report">Report</label>
            <select class="form-select" id="report" name="report">
                <?php foreach ($reportTypes as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $report === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="from">Date from</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($to) ?>">
        </div>
        <div class="field">
            <label for="to">Date to</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="file-text"></i> Generate</button>
            <a class="btn btn-light btn-icon" href="index.php"><i data-lucide="rotate-ccw"></i> Reset</a>
            <button class="btn btn-outline-primary btn-icon" type="button" onclick="window.print()">
                <i data-lucide="printer"></i> Print / Save as PDF
            </button>
        </div>
    </form>
</div>

<div class="card-box mb-3">
    <div class="card-head">
        <div>
            <h4 class="mb-1"><?= e(APP_RESTAURANT) ?></h4>
            <div class="small-note">
                <strong><?= e($reportTypes[$report]) ?></strong> ·
                <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?> ·
                Generated <?= e(format_date(date('Y-m-d H:i:s'), true)) ?>
                by <?= e(current_user()['full_name'] ?? '') ?>
            </div>
        </div>
        <span class="badge bg-primary"><?= $spanDays ?>-day range</span>
    </div>

    <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr))">
        <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
            <div class="kpi-top"><span class="kpi-label">Gross revenue</span></div>
            <div class="kpi-value"><?= peso($grossRevenue) ?></div>
            <div class="kpi-foot">Completed orders only</div>
        </div>
        <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
            <div class="kpi-top"><span class="kpi-label">Completed orders</span></div>
            <div class="kpi-value"><?= number_format($completedOrders) ?></div>
            <div class="kpi-foot">Excludes voided / cancelled</div>
        </div>
        <div class="kpi" style="--kpi-color:#D98C0A;--kpi-bg:#FBEFD4">
            <div class="kpi-top"><span class="kpi-label">Items sold</span></div>
            <div class="kpi-value"><?= number_format($itemsSold) ?></div>
            <div class="kpi-foot">Sum of line quantities</div>
        </div>
        <div class="kpi" style="--kpi-color:#8B4513;--kpi-bg:#F7E8D8">
            <div class="kpi-top"><span class="kpi-label">Average order value</span></div>
            <div class="kpi-value"><?= peso($avgOrder) ?></div>
            <div class="kpi-foot">Revenue ÷ orders</div>
        </div>
    </div>
</div>

<?php if ($report === 'sales_summary'): ?>
    <div class="card-box mb-3">
        <div class="card-head">
            <h4><?= $useMonthly ? 'Monthly' : 'Daily' ?> sales breakdown</h4>
            <span class="hint">Completed orders · <?= e(format_date($from)) ?> – <?= e(format_date($to)) ?></span>
        </div>
        <?php if (!$trend): ?>
            <div class="empty-state">
                <i data-lucide="bar-chart-3"></i>
                <h5>No completed sales in this range</h5>
                <p class="mb-0">Adjust the date range to generate this report.</p>
            </div>
        <?php else: ?>
            <div class="chart-box mb-3" style="min-height:280px"><canvas id="chartReportTrend"></canvas></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th><?= $useMonthly ? 'Month' : 'Date' ?></th><th class="num">Orders</th><th class="num">Items sold</th><th class="num">Revenue</th><th class="num">Avg / order</th><th class="num">Share</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trend as $t): ?>
                            <tr>
                                <td><?= e($t['label']) ?></td>
                                <td class="num"><?= number_format((int)$t['orders_cnt']) ?></td>
                                <td class="num"><?= number_format((int)$t['items']) ?></td>
                                <td class="num"><?= peso($t['revenue']) ?></td>
                                <td class="num"><?= peso(safe_div((float)$t['revenue'], (float)$t['orders_cnt'])) ?></td>
                                <td class="num"><?= number_format(safe_div((float)$t['revenue'], $grossRevenue) * 100, 1) ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total</th>
                            <th class="num"><?= number_format($completedOrders) ?></th>
                            <th class="num"><?= number_format($itemsSold) ?></th>
                            <th class="num"><?= peso($grossRevenue) ?></th>
                            <th class="num"><?= peso($avgOrder) ?></th>
                            <th class="num">100.0%</th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($report === 'category'): ?>
    <div class="card-box mb-3">
        <div class="card-head">
            <h4>Revenue by menu category</h4>
            <span class="hint">Share of completed sales revenue</span>
        </div>
        <?php if (!$byCategory): ?>
            <div class="empty-state">
                <i data-lucide="pie-chart"></i>
                <h5>No category data</h5>
                <p class="mb-0">No completed sales matched this range.</p>
            </div>
        <?php else: ?>
            <div class="chart-box mb-3" style="min-height:280px"><canvas id="chartReportCategory"></canvas></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Category</th><th class="num">Orders</th><th class="num">Units sold</th><th class="num">Revenue</th><th class="num">Share</th><th class="num">Avg unit value</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byCategory as $c): ?>
                            <tr>
                                <td><strong><?= e($c['category']) ?></strong></td>
                                <td class="num"><?= number_format((int)$c['orders_cnt']) ?></td>
                                <td class="num"><?= number_format((int)$c['units']) ?></td>
                                <td class="num"><?= peso($c['revenue']) ?></td>
                                <td class="num"><?= number_format(safe_div((float)$c['revenue'], $catTotal) * 100, 1) ?>%</td>
                                <td class="num"><?= peso(safe_div((float)$c['revenue'], (float)$c['units'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th>Total</th><th class="num"><?= number_format($completedOrders) ?></th>
                            <th class="num"><?= number_format($itemsSold) ?></th>
                            <th class="num"><?= peso($grossRevenue) ?></th>
                            <th class="num">100.0%</th><th class="num">—</th></tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php elseif ($report === 'products'): ?>
    <div class="card-box mb-3">
        <div class="card-head">
            <h4>Product performance</h4>
            <span class="hint">Top <?= count($byProduct) ?> items by revenue</span>
        </div>
        <?php if (!$byProduct): ?>
            <div class="empty-state">
                <i data-lucide="package"></i>
                <h5>No product data</h5>
                <p class="mb-0">No completed sales matched this range.</p>
            </div>
        <?php else: ?>
            <div class="chart-box mb-3" style="min-height:300px"><canvas id="chartReportProducts"></canvas></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th style="width:52px">#</th><th>Item</th><th>Category</th><th class="num">Units sold</th><th class="num">Revenue</th><th class="num">Share</th><th class="num">Avg unit price</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byProduct as $i => $p): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><strong><?= e($p['item_name']) ?></strong></td>
                                <td><?= e($p['category']) ?></td>
                                <td class="num"><?= number_format((int)$p['units']) ?></td>
                                <td class="num"><?= peso($p['revenue']) ?></td>
                                <td class="num"><?= number_format(safe_div((float)$p['revenue'], $prodTotal) * 100, 1) ?>%</td>
                                <td class="num"><?= peso(safe_div((float)$p['revenue'], (float)$p['units'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card-box mb-3">
        <div class="card-head">
            <h4>Order type analysis</h4>
            <span class="hint">Completed orders by channel</span>
        </div>
        <?php if (!$byType): ?>
            <div class="empty-state">
                <i data-lucide="truck"></i>
                <h5>No order data</h5>
                <p class="mb-0">No completed orders matched this range.</p>
            </div>
        <?php else: ?>
            <div class="chart-box mb-3" style="min-height:280px"><canvas id="chartReportTypes"></canvas></div>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Order type</th><th class="num">Orders</th><th class="num">Revenue</th><th class="num">Share</th><th class="num">Avg order value</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byType as $t): ?>
                            <tr>
                                <td><strong><?= e($t['order_type']) ?></strong></td>
                                <td class="num"><?= number_format((int)$t['orders_cnt']) ?></td>
                                <td class="num"><?= peso($t['revenue']) ?></td>
                                <td class="num"><?= number_format(safe_div((float)$t['revenue'], $typeTotal) * 100, 1) ?>%</td>
                                <td class="num"><?= peso(safe_div((float)$t['revenue'], (float)$t['orders_cnt'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><th>Total</th><th class="num"><?= number_format($completedOrders) ?></th>
                            <th class="num"><?= peso($grossRevenue) ?></th><th class="num">100.0%</th>
                            <th class="num"><?= peso($avgOrder) ?></th></tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card-box">
    <p class="small-note mb-0">
        <strong>Note:</strong> all figures are gross sales from records marked <em>completed</em>.
        Voided and cancelled transactions are excluded. Profit cannot be reported because ingredient
        and operating costs are not captured by this system. This report is generated from live database
        records and can be printed or saved as a PDF using the button above.
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var money = function (v) { return '\u20B1' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }); };
    var base = {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false },
            tooltip: { callbacks: { label: function (c) { return money(c.parsed.y); } } } },
        scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } } }
    };

    if (document.getElementById('chartReportTrend')) {
        new Chart(document.getElementById('chartReportTrend'), {
            type: 'line',
            data: {
                labels: <?= js($trendLabels) ?>,
                datasets: [{ label: 'Revenue', data: <?= js($trendRevenue) ?>, borderColor: '#244A0C',
                    backgroundColor: 'rgba(36,74,12,.12)', fill: true, tension: .3,
                    pointRadius: 2.5, borderWidth: 2 }]
            },
            options: Object.assign({}, base, { plugins: { legend: { display: false } } })
        });
    }

    if (document.getElementById('chartReportCategory')) {
        new Chart(document.getElementById('chartReportCategory'), {
            type: 'bar',
            data: {
                labels: <?= js(array_column($byCategory, 'category')) ?>,
                datasets: [{ label: 'Revenue', data: <?= js(array_map('floatval', array_column($byCategory, 'revenue'))) ?>,
                    backgroundColor: BluePalette, borderRadius: 6, maxBarThickness: 54 }]
            },
            options: base
        });
    }

    if (document.getElementById('chartReportProducts')) {
        var pLabels = <?= js(array_slice(array_column($byProduct, 'item_name'), 0, 10)) ?>;
        var pUnits = <?= js(array_map('intval', array_slice(array_column($byProduct, 'units'), 0, 10))) ?>;
        new Chart(document.getElementById('chartReportProducts'), {
            type: 'bar',
            data: {
                labels: pLabels,
                datasets: [{ label: 'Units sold', data: pUnits, backgroundColor: '#244A0C',
                    hoverBackgroundColor: '#91D523', borderRadius: 5 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }

    if (document.getElementById('chartReportTypes')) {
        new Chart(document.getElementById('chartReportTypes'), {
            type: 'doughnut',
            data: {
                labels: <?= js(array_column($byType, 'order_type')) ?>,
                datasets: [{ data: <?= js(array_map('floatval', array_column($byType, 'revenue'))) ?>,
                    backgroundColor: BluePalette, borderWidth: 2, borderColor: '#fff' }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '58%',
                plugins: { legend: { position: 'right' },
                    tooltip: { callbacks: { label: function (c) {
                        var total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                        var pct = total ? (c.parsed / total * 100).toFixed(1) : 0;
                        return c.label + ': ' + money(c.parsed) + ' (' + pct + '%)';
                    } } } }
            }
        });
    }
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
