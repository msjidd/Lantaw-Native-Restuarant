<?php
/**
 * includes/functions.php : shared helper functions.
 */

declare(strict_types=1);

/** HTML output escaping (XSS protection). */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** PHP peso formatting: 1234.5 -> "₱1,234.50" (safe for HTML output). */
function peso(mixed $amount): string
{
    return CURRENCY_SYMBOL . number_format((float)$amount, 2);
}

/** Plain number with 2 decimals. */
function num2(mixed $amount): string
{
    return number_format((float)$amount, 2);
}

/** Friendly date: 2026-10-19 -> "Oct 19, 2026" (pass $withTime for datetime). */
function format_date(?string $date, bool $withTime = false): string
{
    if ($date === null || $date === '' || $date === '0000-00-00') {
        return '—';
    }
    $ts = strtotime($date);
    if ($ts === false) {
        return '—';
    }
    return date($withTime ? 'M j, Y g:i A' : 'M j, Y', $ts);
}

/** Raw input helper. */
function input_str(string $key, int $max = 255): string
{
    $v = trim((string)($_POST[$key] ?? ''));
    return mb_substr($v, 0, $max);
}

/** Reads a GET/POST date and validates the format; returns null when invalid. */
function input_date(string $key, ?string $default = null): ?string
{
    $raw = trim((string)($_POST[$key] ?? $_GET[$key] ?? ''));
    if ($raw === '') {
        return $default;
    }
    $d = DateTime::createFromFormat('Y-m-d', $raw);
    if ($d === false || $d->format('Y-m-d') !== $raw) {
        return $default;
    }
    return $raw;
}

/** Ensures $from <= $to and that both are valid dates. */
function sanitize_range(?string $from, ?string $to): array
{
    $today = date('Y-m-d');
    $defaultFrom = date('Y-m-d', strtotime(DEFAULT_RANGE_DAYS . ' days ago'));

    if ($from === null) {
        $from = $defaultFrom;
    }
    if ($to === null) {
        $to = $today;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }
    // Guard against absurd ranges
    if ((strtotime($to) - strtotime($from)) > 3660 * 86400) {
        $from = date('Y-m-d', strtotime('-10 years', strtotime($to)));
    }
    return [$from, $to];
}

/** Default filter values used by dashboard/analytics pages. */
function default_range(): array
{
    return [
        'from' => date('Y-m-d', strtotime('-' . DEFAULT_RANGE_DAYS . ' days')),
        'to'   => date('Y-m-d'),
    ];
}

/** POST redirect GET with a flash message. */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

/** Client IP (safe). */
function client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45);
}

/** Writes an entry to audit_logs. Never throws. */
function log_audit(string $action, ?string $entity = null, ?int $entityId = null, ?string $details = null): void
{
    try {
        $userId = current_user()['id'] ?? null;
        q(
            'INSERT INTO audit_logs (user_id, action, entity, entity_id, details, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, mb_substr($action, 0, 60), $entity, $entityId, $details, client_ip()]
        );
    } catch (Throwable $e) {
        error_log('[audit] failed: ' . $e->getMessage());
    }
}

/** Next order reference ORD-000001 (must run inside the order transaction). */
function next_order_reference(): string
{
    $max = (int)scalar('SELECT COALESCE(MAX(id), 1000) AS m FROM orders');
    return 'ORD-' . str_pad((string)($max + 1), 6, '0', STR_PAD_LEFT);
}

/** Status badge markup for orders. */
function status_badge(string $status): string
{
    $map = [
        'completed' => ['bg-success', 'Completed'],
        'voided'    => ['bg-danger', 'Voided'],
        'cancelled' => ['bg-secondary', 'Cancelled'],
        'active'    => ['bg-success', 'Active'],
        'inactive'  => ['bg-secondary', 'Inactive'],
        'new'       => ['bg-primary', 'New'],
        'reviewed'  => ['bg-info text-dark', 'Reviewed'],
        'dismissed' => ['bg-secondary', 'Dismissed'],
        'success'   => ['bg-success', 'Success'],
        'partial'   => ['bg-warning text-dark', 'Partial'],
        'failed'    => ['bg-danger', 'Failed'],
    ];
    [$class, $label] = $map[$status] ?? ['bg-secondary', ucfirst($status)];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/** Priority badge. */
function priority_badge(string $priority): string
{
    $map = ['high' => 'badge-danger', 'medium' => 'badge-warning', 'low' => 'badge-info'];
    return '<span class="badge-pill ' . ($map[$priority] ?? 'badge-info') . '">' . e(ucfirst($priority)) . '</span>';
}

/** True when simulated sample data is present (used for dashboard labels). */
function has_sample_data(): bool
{
    static $flag = null;
    if ($flag === null) {
        $flag = (bool)scalar('SELECT MAX(is_sample) FROM orders');
    }
    return $flag;
}

/** Order types used across the app. */
function order_types(): array
{
    return ['Dine-in', 'Takeout', 'Delivery'];
}

/** Menu categories. */
function menu_categories(): array
{
    return ['Seafood', 'Meat', 'Chicken', 'Vegetables', 'Rice and Noodles', 'Beverages', 'Desserts', 'Other'];
}

/** Safe division that returns 0 when the denominator is zero. */
function safe_div(float $a, float $b): float
{
    return $b == 0.0 ? 0.0 : $a / $b;
}

/** Percentage growth with zero-denominator handling. */
function growth_pct(float $current, float $previous): ?float
{
    if ($previous == 0.0) {
        return $current > 0 ? null : 0.0;
    }
    return (($current - $previous) / $previous) * 100;
}

/** Renders a growth chip (up/down/flat, or n/a). */
function growth_chip(?float $g): string
{
    if ($g === null) {
        return '<span class="chip chip-muted">n/a</span>';
    }
    if (abs($g) < 0.005) {
        return '<span class="chip chip-muted">0.0%</span>';
    }
    $cls = $g > 0 ? 'chip-up' : 'chip-down';
    $icon = $g > 0 ? '&#8593;' : '&#8595;';
    return '<span class="chip ' . $cls . '">' . $icon . ' ' . number_format(abs($g), 1) . '%</span>';
}

/** Escapes a value for safe use inside a JavaScript string literal. */
function js(mixed $value): string
{
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}
