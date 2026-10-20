<?php
/**
 * modules/recommendations/index.php : prescriptive analytics.
 * Generates rule-based recommendations from the current sales data and
 * stores them in the recommendations table.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

$def = default_range();
$from = input_date('from', $def['from']);
$to   = input_date('to', $def['to']);
[$from, $to] = sanitize_range($from, $to);

$backUrl = APP_URL . '/modules/recommendations/index.php';

/* ---------------- POST actions ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'generate') {
        $where  = 'o.order_date BETWEEN :from AND :to';
        $params = ['from' => $from, 'to' => $to];

        $totals = row(
            "SELECT COUNT(DISTINCT o.id) AS orders_cnt, COALESCE(SUM(oi.line_total),0) AS revenue,
                    COALESCE(SUM(oi.quantity),0) AS items
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND $where",
            $params
        );
        $revenue = (float)$totals['revenue'];
        $ordersCount = (int)$totals['orders_cnt'];

        if ($ordersCount === 0) {
            flash_set('warning', 'No completed sales in the selected range — nothing to analyse.');
            redirect($backUrl);
        }

        $spanDays = (int)floor((strtotime($to) - strtotime($from)) / 86400) + 1;
        $prevTo   = date('Y-m-d', strtotime('-1 day', strtotime($from)));
        $prevFrom = date('Y-m-d', strtotime('-' . $spanDays . ' days', strtotime($prevTo)));
        $prev = row(
            "SELECT COALESCE(SUM(oi.line_total),0) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND o.order_date BETWEEN :pf AND :pt",
            ['pf' => $prevFrom, 'pt' => $prevTo]
        );
        $prevRevenue = (float)$prev['revenue'];
        $growth = growth_pct($revenue, $prevRevenue);

        $bestSeller = row(
            "SELECT oi.item_name, oi.category, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND $where
             GROUP BY oi.item_name, oi.category ORDER BY qty DESC, revenue DESC LIMIT 1",
            $params
        );

        $categoryShare = rows(
            "SELECT oi.category, SUM(oi.line_total) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND $where
             GROUP BY oi.category ORDER BY revenue DESC",
            $params
        );

        $typeShare = rows(
            "SELECT o.order_type, COUNT(DISTINCT o.id) AS c, COALESCE(SUM(o.total_amount),0) AS revenue
             FROM orders o WHERE o.status = 'completed' AND $where
             GROUP BY o.order_type ORDER BY revenue DESC",
            $params
        );

        $orderValue = row(
            "SELECT COALESCE(SUM(o.total_amount),0) AS completed_value
             FROM orders o WHERE o.status = 'completed' AND $where",
            $params
        );
        $nonCompleted = row(
            "SELECT COALESCE(SUM(o.total_amount),0) AS v, COUNT(*) AS c
             FROM orders o WHERE o.status <> 'completed' AND $where",
            $params
        );

        $weekend = row(
            "SELECT COALESCE(SUM(CASE WHEN WEEKDAY(o.order_date) IN (5,6) THEN o.total_amount ELSE 0 END),0) AS weekend,
                    COALESCE(SUM(o.total_amount),0) AS total
             FROM orders o WHERE o.status = 'completed' AND $where",
            $params
        );

        $daily = rows(
            "SELECT COALESCE(SUM(oi.line_total),0) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND $where
             GROUP BY o.order_date",
            $params
        );
        $dailyValues = array_map(static fn(array $r): float => (float)$r['revenue'], $daily);
        $meanDaily = $dailyValues !== [] ? array_sum($dailyValues) / count($dailyValues) : 0.0;
        $cv = 0.0;
        if (count($dailyValues) > 1 && $meanDaily > 0) {
            $acc = 0.0;
            foreach ($dailyValues as $v) {
                $acc += ($v - $meanDaily) ** 2;
            }
            $cv = sqrt($acc / (count($dailyValues) - 1)) / $meanDaily;
        }

        $slowMovers = rows(
            "SELECT m.item_name FROM menu_items m
             WHERE m.is_available = 1 AND m.id NOT IN (
                 SELECT DISTINCT oi.menu_item_id FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE o.status = 'completed' AND o.order_date BETWEEN :from AND :to)
             ORDER BY m.item_name LIMIT 6",
            $params
        );
        $slowCount = (int)scalar(
            "SELECT COUNT(*) FROM menu_items m
             WHERE m.is_available = 1 AND m.id NOT IN (
                 SELECT DISTINCT oi.menu_item_id FROM order_items oi
                 JOIN orders o ON o.id = oi.order_id
                 WHERE o.status = 'completed' AND o.order_date BETWEEN :from AND :to)",
            $params
        );

        $aov = safe_div($revenue, (float)$ordersCount);
        $candidates = [];

        if ($bestSeller) {
            $candidates[] = [
                'title' => 'Protect supply of ' . $bestSeller['item_name'],
                'related_metric' => 'Best-selling menu item',
                'evidence' => $bestSeller['item_name'] . ' sold ' . number_format((int)$bestSeller['qty'])
                    . ' units for ' . peso($bestSeller['revenue']) . ' (' . $bestSeller['category']
                    . ') between ' . format_date($from) . ' and ' . format_date($to) . '.',
                'suggested_action' => 'Keep ingredients and stock aligned with demand, and make this item prominent in the menu '
                    . 'and daily specials so it never goes out of stock.',
                'priority' => 'high',
                'metric_value' => number_format((int)$bestSeller['qty']) . ' units · ' . peso($bestSeller['revenue']),
            ];
        }

        if ($growth !== null && $growth <= -10) {
            $candidates[] = [
                'title' => 'Revenue down ' . number_format(abs($growth), 1) . '% versus the previous period',
                'related_metric' => 'Period-over-period revenue',
                'evidence' => 'Current period produced ' . peso($revenue) . ' against ' . peso($prevRevenue)
                    . ' in ' . format_date($prevFrom) . ' – ' . format_date($prevTo) . '.',
                'suggested_action' => 'Review pricing, menu visibility and promotions; compare weekday staffing against the '
                    . 'stronger previous period before it becomes a trend.',
                'priority' => 'high',
                'metric_value' => number_format($growth, 1) . '%',
            ];
        } elseif ($growth !== null && $growth >= 10) {
            $candidates[] = [
                'title' => 'Momentum: revenue up ' . number_format($growth, 1) . '%',
                'related_metric' => 'Period-over-period revenue',
                'evidence' => 'Revenue rose from ' . peso($prevRevenue) . ' to ' . peso($revenue)
                    . ' versus the previous comparable period.',
                'suggested_action' => 'Sustain the drivers of this growth: keep the same promo cadence, staffing and stock '
                    . 'levels, and document what changed so it can be repeated.',
                'priority' => 'low',
                'metric_value' => '+' . number_format($growth, 1) . '%',
            ];
        }

        $nonCompletedValue = (float)$nonCompleted['v'];
        $grossValue = (float)$orderValue['completed_value'] + $nonCompletedValue;
        $voidShare = $grossValue > 0 ? $nonCompletedValue / $grossValue * 100 : 0.0;
        if ($voidShare >= 5.0) {
            $candidates[] = [
                'title' => 'High voided / cancelled order value (' . number_format($voidShare, 1) . '%)',
                'related_metric' => 'Voided / cancelled share',
                'evidence' => (int)$nonCompleted['c'] . ' orders worth ' . peso($nonCompletedValue)
                    . ' were voided or cancelled in the selected range.',
                'suggested_action' => 'Review the void/cancel reasons with staff, tighten order-taking accuracy and confirm '
                    . 'that every void is recorded with a reason to protect revenue.',
                'priority' => 'high',
                'metric_value' => number_format($voidShare, 1) . '%',
            ];
        }

        if ($categoryShare !== []) {
            $catTotal = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $categoryShare));
            $topShare = $catTotal > 0 ? (float)$categoryShare[0]['revenue'] / $catTotal * 100 : 0.0;
            if ($topShare >= 40.0) {
                $candidates[] = [
                    'title' => 'Diversify beyond ' . $categoryShare[0]['category'],
                    'related_metric' => 'Category concentration',
                    'evidence' => $categoryShare[0]['category'] . ' alone generated ' . number_format($topShare, 1)
                        . '% of revenue (' . peso($categoryShare[0]['revenue']) . ') in the range.',
                    'suggested_action' => 'Promote under-performing categories through combos or bundles to reduce revenue '
                        . 'risk if demand for ' . $categoryShare[0]['category'] . ' dips.',
                    'priority' => $topShare >= 55.0 ? 'high' : 'medium',
                    'metric_value' => number_format($topShare, 1) . '% of revenue',
                ];
            }
        }

        if ($typeShare !== []) {
            $typeTotal = array_sum(array_map(static fn(array $r): float => (float)$r['revenue'], $typeShare));
            $leadShare = $typeTotal > 0 ? (float)$typeShare[0]['revenue'] / $typeTotal * 100 : 0.0;
            if ($leadShare >= 45.0) {
                $candidates[] = [
                    'title' => 'Prepare capacity for ' . $typeShare[0]['order_type'] . ' orders',
                    'related_metric' => 'Order type mix',
                    'evidence' => $typeShare[0]['order_type'] . ' accounts for ' . number_format($leadShare, 1)
                        . '% of revenue across ' . (int)$typeShare[0]['c'] . ' orders.',
                    'suggested_action' => 'Align packaging, delivery riders and kitchen flow to the dominant order type '
                        . 'to avoid delays during peak hours.',
                    'priority' => 'medium',
                    'metric_value' => number_format($leadShare, 1) . '% of revenue',
                ];
            }
        }

        $weekendShare = ((float)$weekend['total']) > 0 ? (float)$weekend['weekend'] / (float)$weekend['total'] * 100 : 0.0;
        if ($weekendShare >= 45.0) {
            $candidates[] = [
                'title' => 'Weekend-weighted demand (' . number_format($weekendShare, 1) . '%)',
                'related_metric' => 'Weekend revenue share',
                'evidence' => 'Saturday and Sunday generated ' . number_format($weekendShare, 1) . '% of revenue in the range.',
                'suggested_action' => 'Schedule extra kitchen and service staff on weekends, and run weekday-only promos '
                    . 'to smooth the demand curve.',
                'priority' => 'medium',
                'metric_value' => number_format($weekendShare, 1) . '%',
            ];
        }

        if ($slowCount > 0) {
            $names = array_map(static fn(array $r): string => $r['item_name'], $slowMovers);
            $candidates[] = [
                'title' => 'Review ' . $slowCount . ' available item(s) with no sales',
                'related_metric' => 'Slow-moving menu items',
                'evidence' => $slowCount . ' available menu item(s) recorded no sales in the range'
                    . ($names !== [] ? ', including ' . implode(', ', $names) . (count($names) < $slowCount ? ' and others' : '') : '') . '.',
                'suggested_action' => 'Consider repositioning, re-pricing or removing these items to reduce ingredient '
                    . 'waste and simplify the kitchen line.',
                'priority' => 'medium',
                'metric_value' => $slowCount . ' item(s)',
            ];
        }

        if ($cv >= 1.0) {
            $candidates[] = [
                'title' => 'Daily revenue is volatile (CV ' . number_format($cv, 2) . ')',
                'related_metric' => 'Coefficient of variation',
                'evidence' => 'Daily revenue swung around a mean of ' . peso($meanDaily) . ' with a coefficient of '
                    . 'variation of ' . number_format($cv, 2) . '.',
                'suggested_action' => 'Hold a small cash and stock buffer for weak trading days and use targeted '
                    . 'promotions to lift low days.',
                'priority' => 'low',
                'metric_value' => 'CV ' . number_format($cv, 2),
            ];
        }

        if ($aov < 300.0) {
            $candidates[] = [
                'title' => 'Lift the average order value (' . peso($aov) . ')',
                'related_metric' => 'Average order value',
                'evidence' => $ordersCount . ' completed orders averaged ' . peso($aov) . ' per transaction in the range.',
                'suggested_action' => 'Introduce value meals, side-item bundling and beverage upsell prompts at the point '
                    . 'of ordering to raise the typical ticket size.',
                'priority' => 'low',
                'metric_value' => peso($aov),
            ];
        }

        $uid = (int)(current_user()['id'] ?? 0);
        $inserted = 0;
        foreach ($candidates as $c) {
            q('INSERT INTO recommendations
                 (title, related_metric, evidence, suggested_action, priority, metric_value, created_by)
               VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$c['title'], $c['related_metric'], $c['evidence'], $c['suggested_action'],
                 $c['priority'], $c['metric_value'], $uid]);
            $inserted++;
        }

        log_audit('generate_recommendations', 'recommendations', null,
            $inserted . ' recommendations generated for ' . $from . ' to ' . $to);
        flash_set($inserted > 0 ? 'success' : 'warning',
            $inserted > 0
                ? $inserted . ' recommendation(s) generated from ' . format_date($from) . ' – ' . format_date($to) . '.'
                : 'No recommendation rules were triggered by the current data.');
        redirect($backUrl);
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $rec = row('SELECT id, created_by, title FROM recommendations WHERE id = ?', [$id]);
        if (!$rec) {
            flash_set('danger', 'Recommendation not found.');
            redirect($backUrl);
        }
        if (!is_admin() && (int)$rec['created_by'] !== (int)(current_user()['id'] ?? 0)) {
            flash_set('danger', 'You can only delete recommendations you generated.');
            redirect($backUrl);
        }
        q('DELETE FROM recommendations WHERE id = ?', [$id]);
        log_audit('delete_recommendation', 'recommendations', $id, (string)$rec['title']);
        flash_set('success', 'Recommendation deleted.');
        redirect($backUrl);
    }

    redirect($backUrl);
}

/* ---------------- listing ---------------- */
$total = (int)scalar('SELECT COUNT(*) FROM recommendations');
$highCount = (int)scalar("SELECT COUNT(*) FROM recommendations WHERE priority = 'high'");
$lastGenerated = scalar('SELECT MAX(generated_at) FROM recommendations');
$perPage = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$recs = rows(
    'SELECT r.*, u.full_name AS created_by_name
     FROM recommendations r LEFT JOIN users u ON u.id = r.created_by
     ORDER BY r.generated_at DESC, FIELD(r.priority, "high", "medium", "low"), r.id DESC
     LIMIT ' . $perPage . ' OFFSET ' . $offset
);

$page_title = 'Recommendations';
$page_active = 'prescriptive';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="filter-bar">
    <form method="post" action="index.php" class="d-flex flex-wrap gap-2 align-items-end w-100">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">
        <div class="field">
            <label for="from">Analyse from</label>
            <input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>" max="<?= e($to) ?>">
        </div>
        <div class="field">
            <label for="to">Analyse to</label>
            <input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="filter-actions">
            <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="sparkles"></i> Generate recommendations</button>
        </div>
    </form>
</div>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
        <div class="kpi-top"><span class="kpi-label">Stored recommendations</span>
            <span class="kpi-icon"><i data-lucide="lightbulb"></i></span></div>
        <div class="kpi-value"><?= number_format($total) ?></div>
        <div class="kpi-foot">Generated from historical sales</div>
    </div>
    <div class="kpi" style="--kpi-color:#DC3545;--kpi-bg:#FBE9EA">
        <div class="kpi-top"><span class="kpi-label">High priority</span>
            <span class="kpi-icon"><i data-lucide="alert-triangle"></i></span></div>
        <div class="kpi-value"><?= number_format($highCount) ?></div>
        <div class="kpi-foot">Action recommended soon</div>
    </div>
    <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
        <div class="kpi-top"><span class="kpi-label">Last generated</span>
            <span class="kpi-icon"><i data-lucide="clock"></i></span></div>
        <div class="kpi-value sm"><?= $lastGenerated ? e(format_date($lastGenerated, true)) : '—' ?></div>
        <div class="kpi-foot"><?= $lastGenerated ? 'Most recent analysis run' : 'Generate to begin' ?></div>
    </div>
</div>

<?php if (!$recs): ?>
    <div class="card-box">
        <div class="empty-state">
            <i data-lucide="lightbulb"></i>
            <h5>No recommendations yet</h5>
            <p class="mb-2">Pick a date range above and generate rule-based, evidence-backed recommendations from the
                recorded sales data.</p>
        </div>
    </div>
<?php else: ?>
    <div class="card-box">
        <div class="card-head">
            <h4>Recommendations <span class="badge bg-primary"><?= number_format($total) ?></span></h4>
            <span class="hint">Each entry shows the evidence and the suggested action.</span>
        </div>
        <div class="d-flex flex-column gap-2">
            <?php foreach ($recs as $r): ?>
                <div class="insight <?= $r['priority'] === 'high' ? 'bad' : ($r['priority'] === 'medium' ? 'warn' : '') ?>">
                    <i data-lucide="<?= $r['priority'] === 'high' ? 'alert-triangle' : ($r['priority'] === 'medium' ? 'lightbulb' : 'info') ?>"></i>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                            <strong><?= e($r['title']) ?></strong>
                            <span class="text-nowrap">
                                <?= priority_badge($r['priority']) ?>
                                <?php if ($r['metric_value'] !== null): ?>
                                    <span class="badge bg-light text-dark border"><?= e($r['metric_value']) ?></span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <p class="mb-1"><strong>Evidence:</strong> <?= e($r['evidence']) ?></p>
                        <p class="mb-1"><strong>Suggested action:</strong> <?= e($r['suggested_action']) ?></p>
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                            <span class="small-note">
                                Metric: <?= e($r['related_metric']) ?> ·
                                <?= e(format_date($r['generated_at'], true)) ?>
                                <?= $r['created_by_name'] !== null ? ' · by ' . e($r['created_by_name']) : '' ?>
                            </span>
                            <form method="post" action="index.php" class="m-0"
                                  data-confirm="Delete this recommendation?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn btn-sm btn-light btn-icon" type="submit">
                                    <i data-lucide="trash-2"></i> Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($pages > 1): ?>
            <nav class="mt-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small-note">Page <?= $page ?> of <?= $pages ?></span>
                <ul class="pagination mb-0">
                    <?php
                    $link = function (int $p, string $label, bool $disabled = false, bool $active = false) {
                        $cls = 'page-item' . ($disabled ? ' disabled' : '') . ($active ? ' active' : '');
                        echo '<li class="' . $cls . '"><a class="page-link" href="index.php?page=' . $p . '">' . $label . '</a></li>';
                    };
                    $link(max(1, $page - 1), '&laquo;', $page === 1);
                    for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++) {
                        $link($p, (string)$p, false, $p === $page);
                    }
                    $link(min($pages, $page + 1), '&raquo;', $page === $pages);
                    ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
