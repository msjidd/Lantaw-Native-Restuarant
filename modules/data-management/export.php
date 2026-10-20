<?php
/**
 * modules/data-management/export.php : Excel (.xlsx) exports via PhpSpreadsheet.
 * Datasets: menu, transactions, daily, summary, template.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

$dataset = (string)($_GET['dataset'] ?? 'menu');
$allowed = ['menu', 'transactions', 'daily', 'summary', 'template'];
if (!in_array($dataset, $allowed, true)) {
    flash_set('danger', 'Unknown export dataset.');
    redirect(APP_URL . '/modules/data-management/index.php');
}

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    flash_set('danger', 'PhpSpreadsheet is not installed (vendor/autoload.php missing). Run composer install.');
    redirect(APP_URL . '/modules/data-management/index.php');
}
require_once $autoload;

if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
    flash_set('danger', 'PhpSpreadsheet library could not be loaded.');
    redirect(APP_URL . '/modules/data-management/index.php');
}

$def = default_range();
$from = input_date('from', $def['from']);
$to   = input_date('to', $def['to']);
[$from, $to] = sanitize_range($from, $to);

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Applies the project's blue theme to the header row and sets column widths. */
function lantaw_style_sheet(Spreadsheet $spreadsheet, array $widths): void
{
    $sheet = $spreadsheet->getActiveSheet();
    $highestCol = $sheet->getHighestColumn();
    $sheet->getStyle('A1:' . $highestCol . '1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sheet->getStyle('A1:' . $highestCol . '1')->getFill()
        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB('1565C0');
    $sheet->getStyle('A1:' . $highestCol . '1')->getAlignment()->setVertical(
        \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
    );
    $sheet->freezePane('A2');
    $i = 1;
    foreach ($widths as $w) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
        $sheet->getColumnDimension($col)->setWidth($w);
        $i++;
    }
}

try {
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $filename = 'lantaw-' . $dataset . '-' . date('Ymd_His') . '.xlsx';
    $title = 'Export';
    $rowCount = 0;

    if ($dataset === 'menu') {
        $title = 'Menu items';
        $sheet->setTitle('Menu items');
        $header = ['ID', 'Item name', 'Description', 'Category', 'Unit price', 'Available', 'Sample data', 'Created'];
        $data = rows('SELECT id, item_name, description, category, unit_price, is_available, is_sample, created_at
                      FROM menu_items ORDER BY category, item_name');
        $body = [];
        foreach ($data as $r) {
            $body[] = [
                (int)$r['id'], $r['item_name'], $r['description'] ?? '', $r['category'],
                (float)$r['unit_price'], (int)$r['is_available'] === 1 ? 'Yes' : 'No',
                (int)$r['is_sample'] === 1 ? 'Yes' : 'No', (string)$r['created_at'],
            ];
        }
        $sheet->fromArray(array_merge([$header], $body), null, 'A1');
        lantaw_style_sheet($spreadsheet, [8, 32, 40, 18, 13, 11, 12, 20]);
        $sheet->getStyle('E2:E' . max(2, count($body) + 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $rowCount = count($body);
    } elseif ($dataset === 'transactions') {
        $title = 'Sales transactions';
        $sheet->setTitle('Transactions');
        $header = ['Order ref', 'Date', 'Order type', 'Status', 'Item name', 'Category',
                   'Unit price', 'Qty', 'Line total', 'Recorded by'];
        $data = rows(
            "SELECT o.order_reference, o.order_date, o.order_type, o.status, oi.item_name, oi.category,
                    oi.unit_price, oi.quantity, oi.line_total, u.full_name AS created_by_name
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             LEFT JOIN users u ON u.id = o.created_by
             WHERE o.order_date BETWEEN :from AND :to
             ORDER BY o.order_date, o.id, oi.id",
            ['from' => $from, 'to' => $to]
        );
        $body = [];
        foreach ($data as $r) {
            $body[] = [
                $r['order_reference'], $r['order_date'], $r['order_type'], $r['status'],
                $r['item_name'], $r['category'], (float)$r['unit_price'], (int)$r['quantity'],
                (float)$r['line_total'], $r['created_by_name'] ?? '',
            ];
        }
        $sheet->fromArray(array_merge([$header], $body), null, 'A1');
        lantaw_style_sheet($spreadsheet, [14, 12, 12, 12, 30, 18, 12, 7, 13, 20]);
        $last = max(2, count($body) + 1);
        $sheet->getStyle('G2:G' . $last)->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('I2:I' . $last)->getNumberFormat()->setFormatCode('#,##0.00');
        $rowCount = count($body);
    } elseif ($dataset === 'daily') {
        $title = 'Daily sales';
        $sheet->setTitle('Daily sales');
        $header = ['Date', 'Completed orders', 'Items sold', 'Revenue'];
        $data = rows(
            "SELECT o.order_date AS d, COUNT(DISTINCT o.id) AS orders_cnt,
                    COALESCE(SUM(oi.quantity),0) AS items, COALESCE(SUM(oi.line_total),0) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND o.order_date BETWEEN :from AND :to
             GROUP BY o.order_date ORDER BY o.order_date",
            ['from' => $from, 'to' => $to]
        );
        $body = [];
        foreach ($data as $r) {
            $body[] = [$r['d'], (int)$r['orders_cnt'], (int)$r['items'], (float)$r['revenue']];
        }
        $sheet->fromArray(array_merge([$header], $body), null, 'A1');
        lantaw_style_sheet($spreadsheet, [14, 18, 12, 16]);
        $sheet->getStyle('D2:D' . max(2, count($body) + 1))->getNumberFormat()->setFormatCode('#,##0.00');
        $rowCount = count($body);
    } elseif ($dataset === 'summary') {
        $title = 'Monthly summary';
        $sheet->setTitle('Monthly summary');
        $header = ['Month', 'Completed orders', 'Items sold', 'Revenue', 'Average order value'];
        $data = rows(
            "SELECT DATE_FORMAT(o.order_date, '%Y-%m') AS ym, COUNT(DISTINCT o.id) AS orders_cnt,
                    COALESCE(SUM(oi.quantity),0) AS items, COALESCE(SUM(oi.line_total),0) AS revenue
             FROM orders o JOIN order_items oi ON oi.order_id = o.id
             WHERE o.status = 'completed' AND o.order_date BETWEEN :from AND :to
             GROUP BY ym ORDER BY ym",
            ['from' => $from, 'to' => $to]
        );
        $body = [];
        foreach ($data as $r) {
            $body[] = [
                $r['ym'], (int)$r['orders_cnt'], (int)$r['items'], (float)$r['revenue'],
                round(safe_div((float)$r['revenue'], (float)$r['orders_cnt']), 2),
            ];
        }
        $sheet->fromArray(array_merge([$header], $body), null, 'A1');
        lantaw_style_sheet($spreadsheet, [12, 18, 12, 16, 20]);
        $last = max(2, count($body) + 1);
        $sheet->getStyle('D2:E' . $last)->getNumberFormat()->setFormatCode('#,##0.00');
        $rowCount = count($body);
    } else { /* template */
        $title = 'Menu import template';
        $sheet->setTitle('Menu import');
        $header = ['item_name', 'description', 'category', 'unit_price', 'is_available'];
        $body = [
            ['Grilled Bangus', 'Milkfish grilled with native spices', 'Seafood', 185.00, 1],
            ['Chicken Inasal', 'Cebu-style grilled chicken', 'Chicken', 165.00, 1],
        ];
        $sheet->fromArray(array_merge([$header], $body), null, 'A1');
        lantaw_style_sheet($spreadsheet, [30, 42, 18, 13, 14]);
        $sheet->getStyle('D2:D100')->getNumberFormat()->setFormatCode('#,##0.00');
        $note = $sheet->getCell('G1');
        $note->setValue('Allowed categories: ' . implode(', ', menu_categories()));
        $sheet->getStyle('G1')->getFont()->setItalic(true)->getColor()->setRGB('64748B');
        $rowCount = count($body);
    }

    $writer = new Xlsx($spreadsheet);

    log_audit('export', $dataset, null, $title . ' exported (' . $rowCount . ' rows)');
    try {
        q('INSERT INTO import_logs (user_id, filename, file_size, import_type, status, total_rows, imported_rows, skipped_rows, error_rows, errors)
           VALUES (?, ?, 0, ?, "success", ?, ?, 0, 0, ?)',
            [(int)(current_user()['id'] ?? 0), $filename, 'export:' . $dataset, $rowCount, $rowCount,
             $title . ' export for ' . $from . ' to ' . $to]);
    } catch (Throwable $e) {
        error_log('[export] log failed: ' . $e->getMessage());
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
} catch (Throwable $e) {
    error_log('[export] ' . $e->getMessage());
    flash_set('danger', 'Export failed: could not generate the Excel file. Details were written to the application log.');
    redirect(APP_URL . '/modules/data-management/index.php');
}
