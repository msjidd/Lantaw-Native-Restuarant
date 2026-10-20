<?php
/**
 * config/database.php : PDO connection (XAMPP / MariaDB)
 *
 * Credentials can be overridden with environment variables so that
 * no password is committed in plaintext for production deployments:
 *   LANTAW_DB_HOST, LANTAW_DB_NAME, LANTAW_DB_USER, LANTAW_DB_PASS
 */

declare(strict_types=1);

define('DB_HOST', getenv('LANTAW_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('LANTAW_DB_PORT') ?: '3306');
define('DB_NAME', getenv('LANTAW_DB_NAME') ?: 'lantaw_analytics');
define('DB_USER', getenv('LANTAW_DB_USER') ?: 'root');
define('DB_PASS', getenv('LANTAW_DB_PASS') !== false ? getenv('LANTAW_DB_PASS') : '');

/**
 * Singleton PDO connection using exceptions + prepared statements.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
    } catch (PDOException $e) {
        // Log the real reason server-side, show a safe message.
        error_log('[db] connection failed: ' . $e->getMessage());
        http_response_code(500);
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database error</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#FFF4D6;color:#1E293B;display:flex;'
            . 'align-items:center;justify-content:center;min-height:100vh;margin:0}'
            . '.box{background:#fff;border:1px solid #EBDFC0;border-radius:12px;padding:32px;max-width:540px}'
            . '</style></head><body><div class="box"><h2 style="color:#54260D;margin-top:0">Database connection failed</h2>'
            . '<p>The application could not connect to MySQL/MariaDB.</p>'
            . '<ul style="color:#6B5D45;font-size:14px;line-height:1.7">'
            . '<li>Make sure XAMPP MySQL is running.</li>'
            . '<li>Import <code>database/schema.sql</code> in phpMyAdmin.</li>'
            . '<li>Check <code>config/database.php</code> settings.</li></ul>'
            . '<p style="font-size:13px;color:#6B5D45">Technical details were written to storage/logs/app.log.</p>'
            . '</div></body></html>';
        exit;
    }

    return $pdo;
}

/** Run a prepared statement and return it. */
function q(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/** Fetch all rows. */
function rows(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

/** Fetch one row or null. */
function row(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

/** Fetch a single scalar value or null. */
function scalar(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return ($v === false || $v === null) ? null : $v;
}
