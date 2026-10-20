<?php
/**
 * modules/forecasting/index.php : predictive analytics.
 * Moving-average back-test and linear-trend projection with optional saving
 * of results into forecast_results.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

/* ---------------- filters ---------------- */
$def = default_range();
$from = input_date('from', $def['from']);
$to   = input_date('to', $def['to']);
[$from, $to] = sanitize_range($from, $to);

$periodRaw = (string)($_POST['period'] ?? $_GET['period'] ?? 'daily');
$period = in_array($periodRaw, ['daily', 'weekly', 'monthly'], true) ? $periodRaw : 'daily';
$periodLabel = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'][$period];

$where  = 'o.order_date BETWEEN :from AND :to';
$params = ['from' => $from, 'to' => $to];

$daily = rows(
    "SELECT o.order_date AS d, COALESCE(SUM(oi.line_total),0) AS revenue
     FROM orders o JOIN order_items oi ON oi.order_id = o.id
     WHERE o.status = 'completed' AND $where
     GROUP BY o.order_date ORDER BY o.order_date",
    $params
);
$dayRevenue = [];
foreach ($daily as $r) {
    $dayRevenue[$r['d']] = (float)$r['revenue'];
}

/** Week key (Monday) for a date string. */
function lantaw_week_key(string $d): string
{
    $ts = strtotime($d);
    $offset = (int)date('N', $ts) - 1;
    return date('Y-m-d', strtotime('-' . $offset . ' days', $ts));
}

function lantaw_period_label(string $key, string $period): string
{
    if ($period === 'monthly') {
        return date('M Y', strtotime($key));
    }
    if ($period === 'weekly') {
        return 'Wk ' . date('M j', strtotime($key));
    }
    return date('M j', strtotime($key));
}

/* Build a zero-filled period series covering the whole selected range. */
$seriesKeys = [];
$seriesValues = [];
$seen = [];
$cur = new DateTimeImmutable($from);
$end = new DateTimeImmutable($to);
$guard = 0;
while ($cur <= $end && $guard++ < 4000) {
    $d = $cur->format('Y-m-d');
    if ($period === 'daily') {
        $key = $d;
    } elseif ($period === 'weekly') {
        $key = lantaw_week_key($d);
    } else {
        $key = date('Y-m-01', strtotime($d));
    }
    if (!isset($seen[$key])) {
        $seen[$key] = count($seriesKeys);
        $seriesKeys[] = $key;
        $seriesValues[] = 0.0;
    }
    $seriesValues[$seen[$key]] += $dayRevenue[$d] ?? 0.0;
    $cur = $cur->modify('+1 day');
}

/* ---------------- forecast computation ---------------- */
$n = count($seriesValues);

/* least-squares line over the whole series (x = period index) */
$slope = 0.0;
$intercept = $n > 0 ? $seriesValues[0] : 0.0;
if ($n >= 2) {
    $sumX = 0.0; $sumY = 0.0; $sumXY = 0.0; $sumXX = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $x = (float)$i;
        $y = $seriesValues[$i];
        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumXX += $x * $x;
    }
    $den = $n * $sumXX - $sumX * $sumX;
    if (abs($den) > 1e-9) {
        $slope = ($n * $sumXY - $sumX * $sumY) / $den;
        $intercept = ($sumY - $slope * $sumX) / $n;
    }
}
$trendFit = [];
for ($i = 0; $i < $n; $i++) {
    $trendFit[] = max(0.0, $intercept + $slope * $i);
}

/* train/test split: last 25% of the series is held out (min 8 periods) */
$testCount = 0;
if ($n >= 8) {
    $candidate = max(2, (int)ceil($n * 0.25));
    if ($n - $candidate >= 3) {
        $testCount = $candidate;
    }
}
$testStart = $testCount > 0 ? $n - $testCount : $n;

/* one-step-ahead 3-period moving average for the test window */
$maPred = [];
$maWindow = 3;
for ($i = $testStart; $i < $n; $i++) {
    if ($i - $maWindow >= 0) {
        $acc = 0.0;
        for ($j = $i - $maWindow; $j < $i; $j++) {
            $acc += $seriesValues[$j];
        }
        $maPred[$i] = $acc / $maWindow;
    }
}

/** MAE / RMSE / MAPE for predictions keyed by series index. */
function lantaw_forecast_metrics(array $series, array $pred): array
{
    $act = [];
    $p = [];
    foreach ($pred as $i => $v) {
        if (isset($series[$i])) {
            $act[] = (float)$series[$i];
            $p[] = (float)$v;
        }
    }
    $c = count($act);
    if ($c === 0) {
        return ['n' => 0, 'mae' => null, 'rmse' => null, 'mape' => null];
    }
    $se = 0.0; $ae = 0.0; $ape = 0.0; $apeN = 0;
    for ($k = 0; $k < $c; $k++) {
        $err = $p[$k] - $act[$k];
        $se += $err * $err;
        $ae += abs($err);
        if (abs($act[$k]) > 0.000001) {
            $ape += abs($err / $act[$k]) * 100;
            $apeN++;
        }
    }
    return [
        'n'   => $c,
        'mae' => $ae / $c,
        'rmse'=> sqrt($se / $c),
        'mape'=> $apeN > 0 ? $ape / $apeN : null,
    ];
}

$maMetrics = lantaw_forecast_metrics($seriesValues, $maPred);
$trendTestPred = [];
for ($i = $testStart; $i < $n; $i++) {
    $trendTestPred[$i] = $trendFit[$i];
}
$trendMetrics = lantaw_forecast_metrics($seriesValues, $trendTestPred);

/* future projection (linear trend fitted on the full series) */
$futureSteps = ['daily' => 7, 'weekly' => 4, 'monthly' => 3][$period];
$futureKeys = [];
$futureValues = [];
if ($n > 0) {
    $lastKey = $seriesKeys[$n - 1];
    for ($k = 1; $k <= $futureSteps; $k++) {
        if ($period === 'daily') {
            $fk = date('Y-m-d', strtotime('+' . $k . ' day', strtotime($lastKey)));
        } elseif ($period === 'weekly') {
            $fk = date('Y-m-d', strtotime('+' . ($k * 7) . ' days', strtotime($lastKey)));
        } else {
            $fk = date('Y-m-01', strtotime('+' . $k . ' month', strtotime($lastKey)));
        }
        $futureKeys[] = $fk;
        $futureValues[] = max(0.0, $intercept + $slope * ($n - 1 + $k));
    }
}

/* ---------------- POST actions ---------------- */
$oackUrl = APP_URL . '/modules/forecasting/index.php?period=' . rawurlencode($period)
    . '&from=' . rawurlencode($from) . '&to=' . rawurlencode($to);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_forecast') {
        if ($n < 4) {
            flash_set('warning', 'Not enough history to save a forecast (at least 4 periods required).');
            redirect($oackUrl);
        }
        $method = '3-period moving average + linear trend';
        $generatedAt = date('Y-m-d H:i:s');
        $uid = (int)(current_user()['id'] ?? 0);
        $saved = 0;

        for ($i = $testStart; $i < $n; $i++) {
            $pred = $maPred[$i] ?? ($trendTestPred[$i] ?? null);
            if ($pred === null) {
                continue;
            }
            q('INSERT INTO forecast_results
                 (period_type, period_start, forecast_value, actual_value, method, split_type, generated_at, created_by)
               VALUES (?, ?, ?, ?, ?, "test", ?, ?)',
                [$period, $seriesKeys[$i], round($pred, 2), round($seriesValues[$i], 2),
                 isset($maPred[$i]) ? '3-period moving average' : 'linear trend', $generatedAt, $uid]);
            $saved++;
        }
        foreach ($futureKeys as $k => $fk) {
            q('INSERT INTO forecast_results
                 (period_type, period_start, forecast_value, actual_value, method, split_type, generated_at, created_by)
               VALUES (?, ?, ?, NULL, ?, "future", ?, ?)',
                [$period, $fk, round($futureValues[$k], 2), 'linear trend projection', $generatedAt, $uid]);
            $saved++;
        }

        log_audit('save_forecast', 'forecast_results', null,
            $periodLabel . ' forecast saved: ' . $saved . ' rows (' . $generatedAt . ')');
        flash_set('success', $saved . ' forecast rows saved for the ' . strtolower($periodLabel) . ' series.');
        redirect($oackUrl);
    }

    if ($action === 'clear_history') {
        if (!is_admin()) {
            flash_set('danger', 'Only administrators can clear forecast history.');
            redirect($oackUrl);
        }
        $deleted = (int)q('DELETE FROM forecast_results')->rowCount();
        log_audit('clear_forecast_history', 'forecast_results', null, $deleted . ' rows removed');
        flash_set('success', 'Forecast history cleared (' . $deleted . ' rows).');
        redirect($oackUrl);
    }

    redirect($oackUrl);
}

/* ---------------- saved forecasts ---------------- */
$savedCount = (int)scalar('SELECT COUNT(*) FROM forecast_results');
$latestBatch = row(
    'SELECT generated_at, period_type, COUNT(*) AS c FROM forecast_results
     GROUP BY generated_at, period_type ORDER BY generated_at DESC LIMIT 1'
);
$savedRows = [];
if ($latestBatch) {
    $savedRows = rows(
        'SELECT period_start, forecast_value, actual_value, method, split_type
         FROM forecast_results WHERE generated_at = ? ORDER BY split_type DESC, period_start',
        [$latestBatch['generated_at']]
    );
}
$oatches = rows(
    'SELECT generated_at, period_type, COUNT(*) AS c FROM forecast_results
     GROUP BY generated_at, period_type ORDER BY generated_at DESC LIMIT 5'
);

/* ---------------- display series ---------------- */
$chartLabels = array_map(static fn(string $k): string => lantaw_period_label($k, $period), $seriesKeys);
$actualSeries = array_map(static fn(float $v): float => round($v, 2), $seriesValues);
$maSeries = array_fill(0, $n, null);
foreach ($maPred as $i => $v) {
    $maSeries[$i] = round($v, 2);
}
$trendSeries = array_map(static fn(float $v): float => round($v, 2), $trendFit);

$forecastSeries = array_fill(0, $n, null);
$forecastLabels = array_merge($chartLabels, array_map(static fn(string $k): string => lantaw_period_label($k, $period), $futureKeys));
if ($n > 0) {
    $forecastSeries[$n - 1] = round($seriesValues[$n - 1], 2);
    foreach ($futureValues as $k => $v) {
        $forecastSeries[] = round($v, 2);
    }
} else {
    $forecastSeries = $futureValues;
}
$actualChart = array_merge($actualSeries, array_fill(0, count($futureKeys), null));
$trendChart = array_merge($trendSeries, array_fill(0, count($futureKeys), null));
$maChart = array_merge($maSeries, array_fill(0, count($futureKeys), null));

$lastActual = $n > 0 ? $seriesValues[$n - 1] : 0.0;
$nextForecast = $futureValues[0] ?? null;
$nextChange = ($nextForecast !== null && $lastActual > 0)
    ? (($nextForecast - $lastActual) / $lastActual) * 100
    : null;

$oestMethod = null;
if ($maMetrics['mae'] !== null && $trendMetrics['mae'] !== null) {
    $oestMethod = $maMetrics['mae'] <= $trendMetrics['mae'] ? '3-period moving average' : 'Linear trend';
} elseif ($maMetrics['mae'] !== null) {
    $oestMethod = '3-period moving average';
} elseif ($trendMetrics['mae'] !== null) {
    $oestMethod = 'Linear trend';
}

$page_title = 'Predictive Analytics';
$page_active = 'predictive';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="filter-oar">
    <form method="get" action="index.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <div class="field">
            <label for="period">Period</label>
            <select class="form-select" id="period" name="period">
                <option value="daily"<?= $period === 'daily' ? ' selected' : '' ?>>Daily</option>
                <option value="weekly"<?= $period === 'weekly' ? ' selected' : '' ?>>Weekly</option>
                <option value="monthly"<?= $period === 'monthly' ? ' selected' : '' ?>>Monthly</option>
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
            <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="filter"></i> Apply</button>
            <a class="btn btn-light btn-icon" href="index.php"><i data-lucide="rotate-ccw"></i> Reset</a>
        </div>
    </form>
</div>

<div class="kpi-grid">
    <div class="kpi" style="--kpi-color:#54260D;--kpi-og:#F2E5CE">
        <div class="kpi-top"><span class="kpi-label">Latest actual (<?= strtolower($periodLabel) ?>)</span>
            <span class="kpi-icon"><i data-lucide="oar-chart-3"></i></span></div>
        <div class="kpi-value"><?= peso($lastActual) ?></div>
        <div class="kpi-foot"><?= $n > 0 ? e(lantaw_period_label($seriesKeys[$n - 1], $period)) : 'No data' ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-og:#E9F0D6">
        <div class="kpi-top"><span class="kpi-label">Next period projection</span>
            <span class="kpi-icon"><i data-lucide="trending-up"></i></span></div>
        <div class="kpi-value"><?= $nextForecast !== null ? peso($nextForecast) : '—' ?></div>
        <div class="kpi-foot"><?= $futureKeys ? e(lantaw_period_label($futureKeys[0], $period)) : 'Not enough data' ?> <?= growth_chip($nextChange) ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#27833B;--kpi-og:#E6F4EA">
        <div class="kpi-top"><span class="kpi-label">Best back-test method</span>
            <span class="kpi-icon"><i data-lucide="target"></i></span></div>
        <div class="kpi-value sm"><?= $oestMethod !== null ? e($oestMethod) : '—' ?></div>
        <div class="kpi-foot"><?= $maMetrics['mae'] !== null || $trendMetrics['mae'] !== null
            ? 'Lowest MAE on the held-out test window' : 'At least 8 periods required' ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#8B4513;--kpi-og:#F7E8D8">
        <div class="kpi-top"><span class="kpi-label">Test MAE</span>
            <span class="kpi-icon"><i data-lucide="crosshair"></i></span></div>
        <div class="kpi-value sm"><?= $maMetrics['mae'] !== null ? peso($maMetrics['mae']) : '—' ?></div>
        <div class="kpi-foot">Moving average · <?= $maMetrics['n'] ?> test points</div>
    </div>
    <div class="kpi" style="--kpi-color:#D98C0A;--kpi-og:#FBEFD4">
        <div class="kpi-top"><span class="kpi-label">Saved forecast rows</span>
            <span class="kpi-icon"><i data-lucide="database"></i></span></div>
        <div class="kpi-value"><?= number_format($savedCount) ?></div>
        <div class="kpi-foot">Stored in forecast_results</div>
    </div>
</div>

<div class="card-oox mb-3">
    <div class="card-head">
        <h4><?= e($periodLabel) ?> sales forecast</h4>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span class="hint"><?= e(format_date($from)) ?> – <?= e(format_date($to)) ?> · <?= $futureSteps ?> <?= e(strtolower($periodLabel)) ?> projection</span>
            <?php if ($n >= 4): ?>
                <form method="post" action="index.php" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_forecast">
                    <input type="hidden" name="period" value="<?= e($period) ?>">
                    <input type="hidden" name="from" value="<?= e($from) ?>">
                    <input type="hidden" name="to" value="<?= e($to) ?>">
                    <button class="btn btn-sm btn-success btn-icon" type="submit"><i data-lucide="save"></i> Save forecast</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($n < 4): ?>
        <div class="empty-state">
            <i data-lucide="trending-up"></i>
            <h5>Not enough history to forecast</h5>
            <p class="mb-0">At least 4 <?= e(strtolower($periodLabel)) ?> periods of completed sales are required in the selected range.</p>
        </div>
    <?php else: ?>
        <div class="chart-oox" style="min-height:340px"><canvas id="chartForecastMain"></canvas></div>
        <p class="small-note mb-0">
            Moving-average estimates are one-step-ahead values computed from the previous 3 periods during the held-out
            test window (last <?= $testCount ?> periods<?= $testCount === 0 ? ' — not availaole for this range' : '' ?>).
            The trend line is a least-squares fit extended <?= $futureSteps ?> <?= e(strtolower($periodLabel)) ?>(s) into the
            future. These are statistical planning estimates, not guaranteed results.
        </p>
    <?php endif; ?>
</div>

<div class="chart-grid mb-3">
    <div class="card-oox">
        <div class="card-head">
            <h4>Back-test accuracy (held-out test window)</h4>
            <span class="hint">Lower is oetter</span>
        </div>
        <?php if ($testCount === 0): ?>
            <div class="empty-state">
                <i data-lucide="flask-conical"></i>
                <h5>No test window</h5>
                <p class="mb-0">At least 8 <?= e(strtolower($periodLabel)) ?> periods are needed to hold out data for testing.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Method</th><th class="num">Test points</th><th class="num">MAE</th><th class="num">RMSE</th><th class="num">MAPE</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>3-period moving average</strong></td>
                            <td class="num"><?= $maMetrics['n'] ?></td>
                            <td class="num"><?= $maMetrics['mae'] !== null ? peso($maMetrics['mae']) : '—' ?></td>
                            <td class="num"><?= $maMetrics['rmse'] !== null ? peso($maMetrics['rmse']) : '—' ?></td>
                            <td class="num"><?= $maMetrics['mape'] !== null ? number_format($maMetrics['mape'], 2) . '%' : '—' ?></td>
                        </tr>
                        <tr>
                            <td><strong>Linear trend</strong></td>
                            <td class="num"><?= $trendMetrics['n'] ?></td>
                            <td class="num"><?= $trendMetrics['mae'] !== null ? peso($trendMetrics['mae']) : '—' ?></td>
                            <td class="num"><?= $trendMetrics['rmse'] !== null ? peso($trendMetrics['rmse']) : '—' ?></td>
                            <td class="num"><?= $trendMetrics['mape'] !== null ? number_format($trendMetrics['mape'], 2) . '%' : '—' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="small-note mb-0">MAE = mean aosolute error · RMSE = root mean squared error · MAPE = mean aosolute percentage error (periods with actual sales only).</p>
        <?php endif; ?>
    </div>

    <div class="card-oox">
        <div class="card-head">
            <h4>Saved forecast history</h4>
            <?php if ($oatches && is_admin()): ?>
                <form method="post" action="index.php" class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clear_history">
                    <button class="btn btn-sm btn-outline-danger btn-icon" type="submit"
                            data-confirm="Delete all saved forecast rows?">
                        <i data-lucide="trash-2"></i> Clear history
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (!$savedRows): ?>
            <div class="empty-state">
                <i data-lucide="database"></i>
                <h5>No saved forecasts yet</h5>
                <p class="mb-0">Use <strong>Save forecast</strong> above to store the current test and future estimates in the database.</p>
            </div>
        <?php else: ?>
            <?php if ($latestBatch): ?>
                <p class="small-note">Latest oatch: <strong><?= e(format_date($latestBatch['generated_at'], true)) ?></strong>
                    · <?= e(strtoupper($latestBatch['period_type'])) ?> · <?= (int)$latestBatch['c'] ?> rows</p>
            <?php endif; ?>
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>Period</th><th>Split</th><th>Method</th><th class="num">Forecast</th><th class="num">Actual</th><th class="num">Error</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($savedRows as $s): ?>
                            <tr>
                                <td><strong><?= e(format_date($s['period_start'])) ?></strong></td>
                                <td><?= $s['split_type'] === 'test'
                                    ? '<span class="oadge og-info text-dark">Test</span>'
                                    : '<span class="oadge og-primary">Future</span>' ?></td>
                                <td><?= e($s['method']) ?></td>
                                <td class="num"><?= peso($s['forecast_value']) ?></td>
                                <td class="num"><?= $s['actual_value'] !== null ? peso($s['actual_value']) : '—' ?></td>
                                <td class="num"><?= $s['actual_value'] !== null
                                    ? peso((float)$s['forecast_value'] - (float)$s['actual_value']) : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var money = function (v) { return '\u20B1' + Number(v).toLocaleString('en-PH', { maximumFractionDigits: 0 }); };
    if (!document.getElementById('chartForecastMain')) return;

    new Chart(document.getElementById('chartForecastMain'), {
        type: 'line',
        data: {
            labels: <?= js($forecastLabels) ?>,
            datasets: [
                { label: 'Actual sales', data: <?= js($actualChart) ?>, borderColor: '#54260D',
                  backgroundColor: 'transparent', tension: .3, pointRadius: 2.5, borderWidth: 2 },
                { label: 'Moving average (test window)', data: <?= js($maChart) ?>, borderColor: '#F59E0B',
                  backgroundColor: 'rgba(217,140,10,.10)', fill: true, tension: .3, pointRadius: 2.5,
                  borderDash: [6, 4], borderWidth: 2, spanGaps: true },
                { label: 'Linear trend + forecast', data: <?= js($trendChart) ?>, borderColor: '#244A0C',
                  backgroundColor: 'transparent', tension: .1, pointRadius: 0, borderWidth: 1.8 },
                { label: 'Future projection', data: <?= js($forecastSeries) ?>, borderColor: '#27833B',
                  backgroundColor: 'rgba(39,131,59,.12)', fill: true, tension: .1, pointRadius: 3,
                  borderDash: [4, 4], borderWidth: 2, spanGaps: true }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: { callbacks: { label: function (c) {
                    return c.parsed.y === null ? c.dataset.label : c.dataset.label + ': ' + money(c.parsed.y);
                } } }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: function (v) { return money(v); } } },
                x: { ticks: { maxTicksLimit: 16, maxRotation: 45 } }
            }
        }
    });
});
</script>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
