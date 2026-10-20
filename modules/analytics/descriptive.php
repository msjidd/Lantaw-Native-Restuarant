<?php
/**
 * modules/analytics/descriptive.php : descriptive statistics over sales history.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

/** Summary statistics for a numeric sample (count, sum, mean, median, min, max, stdev). */
function lantaw_descriptive_stats(array $values): array
{
    $n = count($values);
    $sum = array_sum($values);
    $mean = $n > 0 ? $sum / $n : 0.0;

    $sorted = $values;
    sort($sorted, SORT_NUMERIC);

    if ($n === 0) {
        $median = 0.0;
    } elseif ($n % 2 === 1) {
        $median = (float)$sorted[(int)floor($n / 2)];
    } else {
        $median = ((float)$sorted[$n / 2 - 1] + (float)$sorted[$n / 2]) / 2;
    }

    $variance = 0.0;
    if ($n > 1) {
        $acc = 0.0;
        foreach ($values as $v) {
            $acc += ($v - $mean) ** 2;
        }
        $variance = $acc / ($n - 1);
    }

    return [
        'n'     => $n,
        'sum'   => $sum,
        'mean'  => $mean,
        'median'=> $median,
        'min'   => $n > 0 ? (float)$sorted[0] : 0.0,
        'max'   => $n > 0 ? (float)$sorted[$n - 1] : 0.0,
        'stdev' => sqrt($variance),
    ];
}

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

/* ---------------- zero-filled daily series ---------------- */
$daily = rows(
    "SELECT o.order_date AS d, COALESCE(SUM(oi.line_total),0) AS revenue, COUNT(DISTINCT o.id) AS orders_cnt
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY o.order_date ORDER BY o.order_date",
    $params
);
$revenueByDay = [];
$ordersByDay = [];
foreach ($daily as $r) {
    $revenueByDay[$r['d']] = (float)$r['revenue'];
    $ordersByDay[$r['d']] = (int)$r['orders_cnt'];
}

$seriesDates = [];
$seriesRevenue = [];
$seriesOrders = [];
$weekdayRevenue = array_fill(1, 7, 0.0);
$weekdayDays = array_fill(1, 7, 0);
$weekdayOrders = array_fill(1, 7, 0);

$cur = new DateTimeImmutable($from);
$end = new DateTimeImmutable($to);
$guard = 0;
while ($cur <= $end && $guard++ < 4000) {
    $d = $cur->format('Y-m-d');
    $wd = (int)$cur->format('N');
    $rev = $revenueByDay[$d] ?? 0.0;
    $ord = $ordersByDay[$d] ?? 0;

    $seriesDates[] = $d;
    $seriesRevenue[] = $rev;
    $seriesOrders[] = $ord;

    $weekdayDays[$wd]++;
    $weekdayRevenue[$wd] += $rev;
    $weekdayOrders[$wd] += $ord;

    $cur = $cur->modify('+1 day');
}

/* ---------------- measures ---------------- */
$revStats = lantaw_descriptive_stats($seriesRevenue);
$ordStats = lantaw_descriptive_stats(array_map('floatval', $seriesOrders));

$orderParams = $params;
unset($orderParams['cat']);
$orderTotals = array_map(
    'floatval',
    array_column(
        rows("SELECT o.total_amount FROM orders o WHERE o.status = 'completed' AND $where", $orderParams),
        'total_amount'
    )
);
$orderStats = lantaw_descriptive_stats($orderTotals);

$cv = $revStats['mean'] > 0 ? $revStats['stdev'] / $revStats['mean'] : 0.0;

$peakIdx = 0;
foreach ($seriesRevenue as $i => $v) {
    if ($v > $seriesRevenue[$peakIdx]) {
        $peakIdx = $i;
    }
}
$peakDate = $seriesDates[$peakIdx] ?? $from;
$peakValue = $seriesRevenue[$peakIdx] ?? 0.0;
$activeDays = count(array_filter($seriesRevenue, static fn(float $v): bool => $v > 0));

/* ---------------- breakdowns ---------------- */
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

$byType = rows(
    "SELECT o.order_type, COUNT(DISTINCT o.id) AS c, COALESCE(SUM(o.total_amount),0) AS revenue
     FROM orders o WHERE o.status = 'completed' AND $where
     GROUP BY o.order_type ORDER BY c DESC",
    $orderParams
);

$topItems = rows(
    "SELECT oi.item_name, oi.category, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $itemWhere
     GROUP BY oi.item_name, oi.category ORDER BY qty DESC, revenue DESC LIMIT 10",
    $params
);
$topLabels = [];
$topQty = [];
foreach ($topItems as $r) {
    $topLabels[] = $r['item_name'];
    $topQty[] = (int)$r['qty'];
}

$weekdayNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
$weekdayAvgLabels = [];
$weekdayAvgValues = [];
$busiestWd = 1;
foreach ($weekdayNames as $wd => $name) {
    $avg = $weekdayDays[$wd] > 0 ? $weekdayRevenue[$wd] / $weekdayDays[$wd] : 0.0;
    $weekdayAvgLabels[] = $name;
    $weekdayAvgValues[] = round($avg, 2);
    if ($avg > $weekdayAvgValues[$busiestWd - 1]) {
        $busiestWd = $wd;
    }
}

/* ---------------- insights ---------------- */
$insights = [];
if ($revStats['sum'] <= 0) {
    $insights[] = ['type' => 'warn', 'title' => 'No completed sales in this range',
        'text' => 'Widen the date range or clear the filters to compute descriptive statistics.'];
} else {
    if ($cv >= 1.0) {
        $insights[] = ['type' => 'warn', 'title' => 'Daily revenue is highly variable (CV ' . number_format($cv, 2) . ')',
            'text' => 'The coefficient of variation exceeds 1.00, so daily takings swing widely around the '
                . peso($revStats['mean']) . ' average. Keep a cash buffer for weak days.'];
    } elseif ($cv >= 0.5) {
        $insights[] = ['type' => 'info', 'title' => 'Moderate day-to-day variability (CV ' . number_format($cv, 2) . ')',
            'text' => 'Daily revenue varies moderately around the ' . peso($revStats['mean']) . ' mean.'];
    } else {
        $insights[] = ['type' => 'good', 'title' => 'Stable daily revenue (CV ' . number_format($cv, 2) . ')',
            'text' => 'Daily takings stay close to the ' . peso($revStats['mean']) . ' average.'];
    }
    if ($peakValue > 0) {
        $insights[] = ['type' => 'good', 'title' => 'Peak day: ' . format_date($peakDate) . ' (' . peso($peakValue) . ')',
            'text' => 'That single day was ' . number_format(safe_div($peakValue, $revStats['mean']), 1)
                . '× the average daily revenue of ' . peso($revStats['mean']) . '.'];
    }
    $insights[] = ['type' => 'info', 'title' => $weekdayNames[$busiestWd] . ' is the strongest weekday on average',
        'text' => 'Average ' . $weekdayNames[$busiestWd] . ' revenue is '
            . peso($weekdayDays[$busiestWd] > 0 ? $weekdayRevenue[$busiestWd] / $weekdayDays[$busiestWd] : 0)
            . ' across ' . $weekdayDays[$busiestWd] . ' occurrence(s) in the range. Use this for staffing and delivery capacity.'];
    if ($catLabels !== []) {
        $totalRev = array_sum($catValues);
        $share = $totalRev > 0 ? $catValues[0] / $totalRev * 100 : 0;
        $insights[] = ['type' => $share >= 50 ? 'warn' : 'info',
            'title' => $catLabels[0] . ' accounts for ' . number_format($share, 1) . '% of revenue',
            'text' => 'Revenue concentration of ' . peso($catValues[0]) . ' in one category. '
                . ($share >= 50 ? 'A single category dominates the mix — protect supply for it and promote the rest.' : 'The mix is reasonably balanced across categories.')];
    }
    if ($orderStats['n'] > 0 && $orderStats['median'] > 0 && $orderStats['max'] > $orderStats['median'] * 5) {
        $insights[] = ['type' => 'info', 'title' => 'Order values are right-skewed',
            'text' => 'Median order value is ' . peso($orderStats['median']) . ' while the maximum is '
                . peso($orderStats['max']) . '. Large occasional orders pull the mean (' . peso($orderStats['mean']) . ') above the typical ticket.'];
    }
}

$page_title = 'Descriptive Analytics';
$page_active = 'descriptive';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="filter-bar">
    <form method="get" action="descriptive.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
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
            <a class="btn btn-light btn-icon" href="descriptive.php"><i data-lucide="rotate-ccw"></i> Reset</a>
        </div>
    </form>
</div>

<div class="kpi-grid">
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
        <div class="kpi-top"><span class="kpi-label">Mean daily revenue</span>
            <span class="kpi-icon"><i data-lucide="calculator"></i></span></div>
        <div class="kpi-value"><?= peso($revStats['mean']) ?></div>
        <div class="kpi-foot">Sum <?= peso($revStats['sum']) ?> ÷ <?= $revStats['n'] ?> days</div>
    </div>
    <div class="kpi" style="--kpi-color:#8B4513;--kpi-bg:#F7E8D8">
        <div class="kpi-top"><span class="kpi-label">Median daily revenue</span>
            <span class="kpi-icon"><i data-lucide="align-center"></i></span></div>
        <div class="kpi-value"><?= peso($revStats['median']) ?></div>
        <div class="kpi-foot">Middle of <?= $revStats['n'] ?> daily values</div>
    </div>
    <div class="kpi" style="--kpi-color:#D98C0A;--kpi-bg:#FBEFD4">
        <div class="kpi-top"><span class="kpi-label">Std deviation</span>
            <span class="kpi-icon"><i data-lucide="activity"></i></span></div>
        <div class="kpi-value"><?= peso($revStats['stdev']) ?></div>
        <div class="kpi-foot">CV <?= number_format($cv, 2) ?> · <?= $cv >= 1 ? 'high variability' : ($cv >= 0.5 ? 'moderate variability' : 'stable') ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
        <div class="kpi-top"><span class="kpi-label">Peak day</span>
            <span class="kpi-icon"><i data-lucide="trophy"></i></span></div>
        <div class="kpi-value"><?= peso($peakValue) ?></div>
        <div class="kpi-foot"><?= e(format_date($peakDate)) ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#91D523;--kpi-bg:#F1F8DE">
        <div class="kpi-top"><span class="kpi-label">Average order value</span>
            <span class="kpi-icon"><i data-lucide="receipt"></i></span></div>
        <div class="kpi-value"><?= peso($orderStats['mean']) ?></div>
        <div class="kpi-foot">Median <?= peso($orderStats['median']) ?> · <?= number_format($orderStats['n']) ?> orders</div>
    </div>
    <div class="kpi" style="--kpi-color:#54260D;--kpi-bg:#F2E5CE">
        <div class="kpi-top"><span class="kpi-label">Days with sales</span>
            <span class="kpi-icon"><i data-lucide="calendar-check"></i></span></div>
        <div class="kpi-value"><?= number_format($activeDays) ?> / <?= number_format($revStats['n']) ?></div>
        <div class="kpi-foot">Active days in selected range</div>
    </div>
</div>

<div class="card-box mb-3">
    <div class="card-head">
        <h4>Descriptive statistics</h4>
        <span class="hint"><?= e(format_date($from)) ?> – <?= e(format_date($to)) ?><?= $category !== '' ? ' · ' . e($category) : '' ?></span>
    </div>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Measure</th>
                    <th class="num">n</th>
                    <th class="num">Sum</th>
                    <th class="num">Mean</th>
                    <th class="num">Median</th>
                    <th class="num">Min</th>
                    <th class="num">Max</th>
                    <th class="num">Std dev</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Daily revenue</strong><br><span class="small-note">Zero-filled calendar days</span></td>
                    <td class="num"><?= $revStats['n'] ?></td>
                    <td class="num"><?= peso($revStats['sum']) ?></td>
                    <td class="num"><?= peso($revStats['mean']) ?></td>
                    <td class="num"><?= peso($revStats['median']) ?></td>
                    <td class="num"><?= peso($revStats['min']) ?></td>
                    <td class="num"><?= peso($revStats['max']) ?></td>
                    <td class="num"><?= peso($revStats['stdev']) ?></td>
                </tr>
                <tr>
                    <td><strong>Order value</strong><br><span class="small-note">Per completed transaction</span></td>
                    <td class="num"><?= $orderStats['n'] ?></td>
                    <td class="num"><?= peso($orderStats['sum']) ?></td>
                    <td class="num"><?= peso($orderStats['mean']) ?></td>
                    <td class="num"><?= peso($orderStats['median']) ?></td>
                    <td class="num"><?= peso($orderStats['min']) ?></td>
                    <td class="num"><?= peso($orderStats['max']) ?></td>
                    <td class="num"><?= peso($orderStats['stdev']) ?></td>
                </tr>
                <tr>
                    <td><strong>Daily orders</strong><br><span class="small-note">Completed orders per day</span></td>
                    <td class="num"><?= $ordStats['n'] ?></td>
                    <td class="num"><?= number_format($ordStats['sum']) ?></td>
                    <td class="num"><?= num2($ordStats['mean']) ?></td>
                    <td class="num"><?= num2($ordStats['median']) ?></td>
                    <td class="num"><?= num2($ordStats['min']) ?></td>
                    <td class="num"><?= num2($ordStats['max']) ?></td>
                    <td class="num"><?= num2($ordStats['stdev']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="chart-grid mb-3">
    <div class="card-box">
        <div class="card-head">
            <h4>Daily revenue distribution</h4>
            <span class="hint">Dashed line = mean</span>
        </div>
        <div class="chart-box"><canvas id="chartDescDaily"></canvas></div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Average revenue by weekday</h4>
            <span class="hint">Mean per calendar occurrence</span>
        </div>
        <div class="chart-box"><canvas id="chartDescWeekday"></canvas></div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Revenue share by category</h4>
            <span class="hint"><?= $category !== '' ? 'Filtered: ' . e($category) : 'All categories' ?></span>
        </div>
        <div class="chart-box"><canvas id="chartDescCategory"></canvas></div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Top 10 items by units sold</h4>
            <span class="hint">Quantity</span>
        </div>
        <div class="chart-box"><canvas id="chartDescTop10"></canvas></div>
    </div>
</div>

<div class="chart-grid mb-3">
    <div class="card-box">
        <div class="card-head">
            <h4>Order type distribution</h4>
            <span class="hint">Completed orders</span>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Order type</th><th class="num">Orders</th><th class="num">Revenue</th><th class="num">Share</th><th class="num">Avg / order</th></tr>
                </thead>
                <tbody>
                <?php $typeTotal = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $byType)); ?>
                <?php foreach ($byType as $t): ?>
                    <tr>
                        <td><?= e($t['order_type']) ?></td>
                        <td class="num"><?= number_format((int)$t['c']) ?></td>
                        <td class="num"><?= peso($t['revenue']) ?></td>
                        <td class="num"><?= number_format(safe_div((float)$t['revenue'], $typeTotal) * 100, 1) ?>%</td>
                        <td class="num"><?= peso(safe_div((float)$t['revenue'], (float)$t['c'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$byType): ?>
                    <tr><td colspan="5" class="text-center small-note py-3">No completed sales in this range.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Statistical insights</h4>
            <span class="hint">Derived from the measures above</span>
        </div>
        <?php foreach ($insights as $ins): ?>
            <div class="insight <?= e($ins['type'] === 'info' ? '' : $ins['type']) ?>">
                <i data-lucide="<?= $ins['type'] === 'warn' ? 'triangle-alert' : ($ins['type'] === 'good' ? 'trending-up' : 'lightbulb') ?>"></i>
                <div>
                    <strong><?= e($ins['title']) ?></strong>
                    <p><?= e($ins['text']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var money = function (v) { return '\u20B1' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }); };

    if (document.getElementById('chartDescDaily')) {
        new Chart(document.getElementById('chartDescDaily'), {
            type: 'bar',
            data: {
                labels: <?= js(array_map(static fn(string $d): string => date('M j', strtotime($d)), $seriesDates)) ?>,
                datasets: [
                    { label: 'Daily revenue', data: <?= js(array_map('round', $seriesRevenue)) ?>,
                      backgroundColor: '#91D523', hoverBackgroundColor: '#244A0C', borderRadius: 3 },
                    { label: 'Mean', type: 'line', data: <?= js(array_fill(0, max(1, $revStats['n']), round($revStats['mean'], 2))) ?>,
                      borderColor: '#DC3545', borderDash: [6, 4], borderWidth: 1.6, pointRadius: 0 }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + money(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } },
                          x: { ticks: { maxTicksLimit: 14, maxRotation: 45 } } }
            }
        });
    }

    if (document.getElementById('chartDescWeekday')) {
        new Chart(document.getElementById('chartDescWeekday'), {
            type: 'bar',
            data: {
                labels: <?= js($weekdayAvgLabels) ?>,
                datasets: [{ label: 'Average revenue', data: <?= js($weekdayAvgValues) ?>,
                    backgroundColor: ['#244A0C', '#91D523', '#8FBF3F', '#54260D', '#8B4513', '#27833B', '#D98C0A'],
                    borderRadius: 6 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false },
                    tooltip: { callbacks: { label: function (c) { return 'Average: ' + money(c.parsed.y); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } } }
            }
        });
    }

    var catLabels = <?= js($catLabels) ?>;
    if (document.getElementById('chartDescCategory')) {
        if (!catLabels.length) {
            document.getElementById('chartDescCategory').parentNode.innerHTML =
                '<div class="empty-state"><i data-lucide="pie-chart"></i><h5>No category data</h5>' +
                '<p class="mb-0">No completed sales matched the current filters.</p></div>';
            if (window.lucide) lucide.createIcons();
        } else {
            new Chart(document.getElementById('chartDescCategory'), {
                type: 'doughnut',
                data: {
                    labels: catLabels,
                    datasets: [{ data: <?= js($catValues) ?>, backgroundColor: BluePalette, borderWidth: 2, borderColor: '#fff' }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '58%',
                    plugins: {
                        legend: { position: 'right' },
                        tooltip: { callbacks: { label: function (c) {
                            var total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            var pct = total ? (c.parsed / total * 100).toFixed(1) : 0;
                            return c.label + ': ' + money(c.parsed) + ' (' + pct + '%)';
                        } } }
                    }
                }
            });
        }
    }

    if (document.getElementById('chartDescTop10')) {
        new Chart(document.getElementById('chartDescTop10'), {
            type: 'bar',
            data: {
                labels: <?= js($topLabels) ?>,
                datasets: [{ label: 'Units sold', data: <?= js($topQty) ?>,
                    backgroundColor: '#244A0C', hoverBackgroundColor: '#91D523', borderRadius: 6 }]
            },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
