<?php
/**
 * Lantaw Native Restaurant - Business Analytics System
 * config/app.php : application-wide constants and bootstrap
 */

declare(strict_types=1);

if (!defined('LANTAW_APP')) {
    define('LANTAW_APP', true);
}

/* --------------------------------------------------------------
 * Time and locale
 * ------------------------------------------------------------- */
date_default_timezone_set('Asia/Manila');
setlocale(LC_ALL, 'en_US.UTF-8');

/* --------------------------------------------------------------
 * Debugging: set LANTAW_DEBUG to 0 in production.
 * Errors are always written to storage/logs/app.log and are
 * never allowed to print database credentials.
 * ------------------------------------------------------------- */
define('LANTAW_DEBUG', (bool)(getenv('LANTAW_DEBUG') ?: false));

ini_set('display_errors', LANTAW_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

/* Ensure the log directory exists, otherwise error_log() falls back to the
   SAPI log and storage/logs/app.log is never created. */
$logDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
ini_set('error_log', $logDir . '/app.log');

/* --------------------------------------------------------------
 * Application identity
 * ------------------------------------------------------------- */
define('APP_NAME', 'Lantaw Analytics');
define('APP_RESTAURANT', 'Lantaw Native Restaurant – Cordova, Cebu');
define('APP_PROJECT_NOTE', 'BSIT Academic Project – not affiliated official branding');

/**
 * Application base URL, derived from the document root so the
 * project also works if the folder is renamed.
 */
function app_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $root = str_replace('\\', '/', dirname(__DIR__));
    $docRoot = str_replace('\\', '/', rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\'));

    if ($docRoot !== '' && stripos($root, $docRoot) === 0) {
        $rel = rtrim(substr($root, strlen($docRoot)), '/');
    } else {
        // CLI or unusual layout: fall back to the folder name.
        $rel = '/' . basename($root);
    }

    // Percent-encode each segment so folders containing spaces produce a
    // valid base URL such as /Lantaw%20Native%20Restaurant/lantaw-analytics.
    $segments = explode('/', trim((string)$rel, '/'));
    $segments = array_values(array_filter($segments, static fn(string $s): bool => $s !== ''));
    $base = $segments === [] ? '' : '/' . implode('/', array_map('rawurlencode', $segments));
    return $base;
}

define('APP_URL', app_base_url());

/* --------------------------------------------------------------
 * Upload / import limits
 * ------------------------------------------------------------- */
define('MAX_IMPORT_BYTES', 2 * 1024 * 1024);          // 2 MB
define('MAX_IMPORT_ROWS', 2000);                       // safety cap
define('ALLOWED_IMPORT_MIME', [
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/octet-stream',
    'application/zip',
    'application/x-zip',
]);
define('ALLOWED_IMPORT_EXT', ['xlsx']);

/* --------------------------------------------------------------
 * Business defaults
 * ------------------------------------------------------------- */
define('CURRENCY', 'PHP');
define('CURRENCY_SYMBOL', '&#8369;'); // peso sign (escaped for HTML)
define('DEFAULT_RANGE_DAYS', 30);

/**
 * Friendly error page: never exposes credentials or SQL.
 */
set_exception_handler(static function (Throwable $e): void {
    error_log('[exception] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $msg = LANTAW_DEBUG
        ? htmlspecialchars($e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine(), ENT_QUOTES)
        : 'An unexpected error occurred. Please try again or contact the administrator.';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>System error</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#FFF4D6;color:#1E293B;display:flex;'
        . 'align-items:center;justify-content:center;min-height:100vh;margin:0}'
        . '.box{background:#fff;border:1px solid #EBDFC0;border-radius:12px;padding:32px;max-width:520px;'
        . 'box-shadow:0 10px 30px rgba(11,31,58,.08)}</style></head><body><div class="box">'
        . '<h2 style="color:#54260D;margin-top:0">Something went wrong</h2><p>' . $msg . '</p>'
        . '<p style="color:#6B5D45;font-size:14px">Details were written to storage/logs/app.log.</p>'
        . '<a href="' . APP_URL . '/dashboard.php" style="color:#244A0C">Return to dashboard</a>'
        . '</div></body></html>';
    exit;
});

set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    if (!(error_reporting() & $no)) {
        return false;
    }
    error_log(sprintf('[error %d] %s @ %s:%d', $no, $str, $file, $line));
    if (LANTAW_DEBUG && !headers_sent()) {
        // Development convenience only; message contains no credentials.
        echo '<!-- php error: ' . htmlspecialchars($str . ' @ ' . $file . ':' . $line, ENT_QUOTES) . ' -->';
    }
    return true;
});
