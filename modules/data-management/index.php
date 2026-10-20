<?php
/**
 * modules/data-management/index.php : Excel import / export centre.
 * Imports menu items from .xlsx and exports data through export.php.
 */

declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/includes/init.php';
require_login();

$backUrl = APP_URL . '/modules/data-management/index.php';

/* ---------------- import handler ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
    $action = (string)($_POST['action'] ?? '');

    if ($action !== 'import') {
        redirect($backUrl);
    }

    $file = $_FILES['import_file'] ?? null;
    $fail = static function (string $msg) use ($backUrl): never {
        flash_set('danger', $msg);
        redirect($backUrl);
    };

    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $fail('Please choose an .xlsx file to import.');
    }
    $filename = basename((string)$file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $size = (int)$file['size'];

    if (!in_array($ext, ALLOWED_IMPORT_EXT, true)) {
        $fail('Unsupported file type. Only .xlsx files are accepted (download the template from this page).');
    }
    if ($size <= 0 || $size > MAX_IMPORT_BYTES) {
        $fail('The file must be larger than 0 bytes and no bigger than ' . number_format(MAX_IMPORT_BYTES / 1048576, 1) . ' MB.');
    }
    if (!is_uploaded_file((string)$file['tmp_name'])) {
        $fail('The uploaded file could not be verified.');
    }
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $fi ? (string)finfo_file($fi, (string)$file['tmp_name']) : '';
        if ($fi) {
            finfo_close($fi);
        }
        if ($mime !== '' && !in_array($mime, ALLOWED_IMPORT_MIME, true)) {
            $fail('The uploaded file does not look like a valid Excel workbook (' . $mime . ').');
        }
    }

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $fail('PhpSpreadsheet is not installed (vendor/autoload.php missing). Run composer install.');
    }
    require_once $autoload;
    if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
        $fail('PhpSpreadsheet library could not be loaded.');
    }

    $importDir = dirname(__DIR__, 2) . '/storage/imports';
    if (!is_dir($importDir)) {
        @mkdir($importDir, 0775, true);
    }
    $tmpPath = $importDir . '/upload_' . bin2hex(random_bytes(8)) . '.xlsx';
    if (!move_uploaded_file((string)$file['tmp_name'], $tmpPath)) {
        $fail('The file could not be stored for processing. Check that storage/imports is writable.');
    }

    $total = 0;
    $imported = 0;
    $skipped = 0;
    $errorRows = 0;
    $errors = [];
    $status = 'failed';

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($tmpPath);
        $sheet = $spreadsheet->getActiveSheet();
        $grid = $sheet->toArray(null, true, true, false);

        if (count($grid) < 2) {
            @unlink($tmpPath);
            $fail('The workbook is empty — the first row must contain the column headings.');
        }

        $headerRow = array_map(static fn($v): string => strtolower(trim((string)$v)), $grid[0]);
        $col = [];
        foreach (['item_name', 'description', 'category', 'unit_price', 'is_available'] as $name) {
            $idx = array_search($name, $headerRow, true);
            if ($idx !== false) {
                $col[$name] = (int)$idx;
            }
        }
        if (!isset($col['item_name'], $col['unit_price'])) {
            @unlink($tmpPath);
            $fail('Missing required columns. The sheet must have at least "item_name" and "unit_price" headings.');
        }

        $catByName = [];
        foreach (menu_categories() as $c) {
            $catByName[strtolower($c)] = $c;
        }

        $uid = (int)(current_user()['id'] ?? 0);
        $existing = [];
        foreach (rows('SELECT LOWER(item_name) AS n FROM menu_items') as $r) {
            $existing[$r['n']] = true;
        }

        for ($i = 1, $count = count($grid); $i < $count; $i++) {
            $row = $grid[$i];
            $rowHasData = false;
            foreach ($row as $cell) {
                if ($cell !== null && trim((string)$cell) !== '') {
                    $rowHasData = true;
                    break;
                }
            }
            if (!$rowHasData) {
                continue;
            }

            $total++;
            if ($total > MAX_IMPORT_ROWS) {
                $errors[] = 'Row limit of ' . number_format(MAX_IMPORT_ROWS) . ' reached — remaining rows were ignored.';
                $total--;
                break;
            }

            $rawName = trim((string)($row[$col['item_name']] ?? ''));
            $rawPrice = trim((string)($row[$col['unit_price']] ?? ''));
            $rawDesc = isset($col['description']) ? trim((string)($row[$col['description']] ?? '')) : '';
            $rawCat = isset($col['category']) ? trim((string)($row[$col['category']] ?? '')) : '';
            $rawAvail = isset($col['is_available']) ? trim((string)($row[$col['is_available']] ?? '')) : '';

            if ($rawName === '') {
                $errorRows++;
                $errors[] = 'Row ' . ($i + 1) . ': item_name is required.';
                continue;
            }
            if (mb_strlen($rawName) > 120) {
                $errorRows++;
                $errors[] = 'Row ' . ($i + 1) . ': item_name exceeds 120 characters.';
                continue;
            }
            if (!is_numeric($rawPrice) || (float)$rawPrice < 0) {
                $errorRows++;
                $errors[] = 'Row ' . ($i + 1) . ': unit_price must be a positive number.';
                continue;
            }
            $price = round((float)$rawPrice, 2);
            if ($price > 99999999.99) {
                $errorRows++;
                $errors[] = 'Row ' . ($i + 1) . ': unit_price is unrealistically large.';
                continue;
            }

            $category = 'Other';
            if ($rawCat !== '') {
                $category = $catByName[strtolower($rawCat)] ?? '';
                if ($category === '') {
                    $errorRows++;
                    $errors[] = 'Row ' . ($i + 1) . ': unknown category "' . $rawCat . '".';
                    continue;
                }
            }

            $available = 1;
            if ($rawAvail !== '') {
                $avail = strtolower($rawAvail);
                if (in_array($avail, ['0', 'no', 'false', 'unavailable', 'inactive'], true)) {
                    $available = 0;
                } elseif (in_array($avail, ['1', 'yes', 'true', 'available', 'active'], true)) {
                    $available = 1;
                } else {
                    $errorRows++;
                    $errors[] = 'Row ' . ($i + 1) . ': is_available must be 1/0 or yes/no.';
                    continue;
                }
            }

            $key = mb_strtolower($rawName);
            if (isset($existing[$key])) {
                $skipped++;
                $errors[] = 'Row ' . ($i + 1) . ': "' . $rawName . '" already exists (skipped).';
                continue;
            }

            try {
                q('INSERT INTO menu_items (item_name, description, category, unit_price, is_available, is_sample)
                   VALUES (?, ?, ?, ?, ?, 0)',
                    [$rawName, $rawDesc !== '' ? mb_substr($rawDesc, 0, 255) : null, $category, $price, $available]);
                $existing[$key] = true;
                $imported++;
            } catch (Throwable $e) {
                $errorRows++;
                $errors[] = 'Row ' . ($i + 1) . ': could not be saved.';
                error_log('[import] row failed: ' . $e->getMessage());
            }
        }

        if ($imported > 0 && $errorRows === 0 && $skipped === 0) {
            $status = 'success';
        } elseif ($imported > 0) {
            $status = 'partial';
        } else {
            $status = 'failed';
        }
    } catch (Throwable $e) {
        error_log('[import] ' . $e->getMessage());
        @unlink($tmpPath);
        flash_set('danger', 'Import failed: the workbook could not be read. Make sure it is a valid .xlsx file.');
        redirect($backUrl);
    }

    @unlink($tmpPath);

    $notes = implode("\n", array_slice($errors, 0, 25));
    if (count($errors) > 25) {
        $notes .= "\n… and " . (count($errors) - 25) . ' more note(s).';
    }

    q('INSERT INTO import_logs
         (user_id, filename, file_size, import_type, status, total_rows, imported_rows, skipped_rows, error_rows, errors)
       VALUES (?, ?, ?, "menu_items", ?, ?, ?, ?, ?, ?)',
        [(int)(current_user()['id'] ?? 0), $filename, $size, $status, $total, $imported, $skipped, $errorRows,
         $notes !== '' ? $notes : null]);

    log_audit('import', 'menu_items', null,
        $filename . ' imported: ' . $imported . ' added, ' . $skipped . ' skipped, ' . $errorRows . ' errors');

    flash_set($status === 'failed' ? 'danger' : ($status === 'partial' ? 'warning' : 'success'),
        'Import finished: ' . $imported . ' item(s) added, ' . $skipped . ' skipped, ' . $errorRows
        . ' error(s) from ' . $total . ' data row(s).');
    redirect($backUrl);
}

/* ---------------- page data ---------------- */
$menuCount = (int)scalar('SELECT COUNT(*) FROM menu_items');
$orderCount = (int)scalar('SELECT COUNT(*) FROM orders');
$lineCount = (int)scalar('SELECT COUNT(*) FROM order_items');
$lastImport = row(
    'SELECT created_at, filename, status, imported_rows FROM import_logs
     WHERE import_type <> "export" OR import_type IS NULL
     ORDER BY created_at DESC LIMIT 1'
);
$history = rows(
    'SELECT l.*, u.full_name AS user_name FROM import_logs l
     LEFT JOIN users u ON u.id = l.user_id
     ORDER BY l.created_at DESC, l.id DESC LIMIT 12'
);

$defaultRange = default_range();

$page_title = 'Excel Import / Export';
$page_active = 'data';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(230px,1fr))">
    <div class="kpi" style="--kpi-color:#244A0C;--kpi-bg:#E9F0D6">
        <div class="kpi-top"><span class="kpi-label">Menu items</span>
            <span class="kpi-icon"><i data-lucide="utensils-crossed"></i></span></div>
        <div class="kpi-value"><?= number_format($menuCount) ?></div>
        <div class="kpi-foot">Records available for export</div>
    </div>
    <div class="kpi" style="--kpi-color:#27833B;--kpi-bg:#E6F4EA">
        <div class="kpi-top"><span class="kpi-label">Transactions</span>
            <span class="kpi-icon"><i data-lucide="receipt-text"></i></span></div>
        <div class="kpi-value"><?= number_format($orderCount) ?></div>
        <div class="kpi-foot"><?= number_format($lineCount) ?> line items</div>
    </div>
    <div class="kpi" style="--kpi-color:#D98C0A;--kpi-bg:#FBEFD4">
        <div class="kpi-top"><span class="kpi-label">Last import</span>
            <span class="kpi-icon"><i data-lucide="upload"></i></span></div>
        <div class="kpi-value sm"><?= $lastImport ? e(format_date($lastImport['created_at'], true)) : '—' ?></div>
        <div class="kpi-foot"><?= $lastImport ? e($lastImport['filename']) : 'No imports recorded' ?></div>
    </div>
    <div class="kpi" style="--kpi-color:#8B4513;--kpi-bg:#F7E8D8">
        <div class="kpi-top"><span class="kpi-label">Import size limit</span>
            <span class="kpi-icon"><i data-lucide="gauge"></i></span></div>
        <div class="kpi-value"><?= number_format(MAX_IMPORT_ROWS) ?></div>
        <div class="kpi-foot">Max rows · <?= number_format(MAX_IMPORT_BYTES / 1048576, 1) ?> MB per .xlsx file</div>
    </div>
</div>

<div class="chart-grid mb-3">
    <div class="card-box">
        <div class="card-head">
            <h4>Import menu items</h4>
            <span class="hint">.xlsx only</span>
        </div>
        <form method="post" action="index.php" enctype="multipart/form-data" class="d-flex flex-column gap-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <div class="field">
                <label for="import_file">Excel workbook</label>
                <input class="form-control" type="file" id="import_file" name="import_file" accept=".xlsx" required>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-primary btn-icon" type="submit"><i data-lucide="upload"></i> Import</button>
                <a class="btn btn-light btn-icon" href="export.php?dataset=template"><i data-lucide="download"></i> Download template</a>
            </div>
            <p class="small-note mb-0">
                Required columns: <strong>item_name</strong>, <strong>unit_price</strong>. Optional:
                <strong>description</strong>, <strong>category</strong> (<?= e(implode(', ', menu_categories())) ?>),
                <strong>is_available</strong> (1/0 or yes/no). Existing item names are skipped so imports never
                overwrite your catalogue.
            </p>
        </form>
    </div>

    <div class="card-box">
        <div class="card-head">
            <h4>Export data to Excel</h4>
            <span class="hint">Generated with PhpSpreadsheet</span>
        </div>
        <p class="small-note">Date-limited exports use the current default range
            (<?= e(format_date($defaultRange['from'])) ?> – <?= e(format_date($defaultRange['to'])) ?>).</p>
        <div class="d-flex flex-column gap-2">
            <a class="btn btn-outline-primary btn-icon justify-content-start" href="export.php?dataset=menu">
                <i data-lucide="file-spreadsheet"></i> Menu items (.xlsx)
            </a>
            <a class="btn btn-outline-primary btn-icon justify-content-start" href="export.php?dataset=transactions">
                <i data-lucide="file-spreadsheet"></i> Sales transactions, line level (.xlsx)
            </a>
            <a class="btn btn-outline-primary btn-icon justify-content-start" href="export.php?dataset=daily">
                <i data-lucide="file-spreadsheet"></i> Daily sales summary (.xlsx)
            </a>
            <a class="btn btn-outline-primary btn-icon justify-content-start" href="export.php?dataset=summary">
                <i data-lucide="file-spreadsheet"></i> Monthly summary (.xlsx)
            </a>
            <a class="btn btn-light btn-icon justify-content-start" href="export.php?dataset=template">
                <i data-lucide="file-down"></i> Import template (.xlsx)
            </a>
        </div>
    </div>
</div>

<div class="card-box">
    <div class="card-head">
        <h4>Import / export history</h4>
        <span class="hint">Last 12 operations</span>
    </div>
    <?php if (!$history): ?>
        <div class="empty-state">
            <i data-lucide="history"></i>
            <h5>No history yet</h5>
            <p class="mb-0">Import a workbook or run an export to populate this log.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Operation</th>
                        <th>File</th>
                        <th>User</th>
                        <th class="num">Rows</th>
                        <th>Result</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= e(format_date($h['created_at'], true)) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= e($h['import_type']) ?></span></td>
                        <td><?= e($h['filename']) ?></td>
                        <td><?= e($h['user_name'] ?? '—') ?></td>
                        <td class="num">
                            <?= $h['import_type'] === 'menu_items'
                                ? (int)$h['imported_rows'] . ' saved · ' . (int)$h['skipped_rows'] . ' skipped · ' . (int)$h['error_rows'] . ' errors'
                                : number_format((int)$h['total_rows']) ?>
                        </td>
                        <td><?= status_badge((string)$h['status']) ?></td>
                        <td class="small-note" style="max-width:320px;white-space:pre-line">
                            <?= $h['errors'] !== null && $h['errors'] !== '' ? e($h['errors']) : '—' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
