<?php
/**
 * includes/csrf.php : cross-site request forgery protection.
 * Every state-changing form must post a `_csrf` token.
 */

declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Rotates the token (call after privilege changes such as login). */
function csrf_rotate(): string
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);
}

/**
 * Validates the POST token and stops the request with HTTP 403 when invalid.
 */
function csrf_verify(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!csrf_check(is_string($token) ? $token : null)) {
        http_response_code(403);
        error_log('[csrf] rejected request to ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>403 Forbidden</title></head>'
            . '<body style="font-family:system-ui,sans-serif;background:#FFF4D6;display:flex;align-items:center;'
            . 'justify-content:center;min-height:100vh;margin:0"><div style="background:#fff;border:1px solid #EBDFC0;'
            . 'padding:32px;border-radius:12px;max-width:460px;text-align:center">'
            . '<h2 style="color:#DC3545;margin-top:0">Invalid request (403)</h2>'
            . '<p>Your session security token is missing or expired. Please go back, refresh the page and try again.</p>'
            . '</div></body></html>';
        exit;
    }
}

/** Only allow POST for state-changing actions. */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        exit('405 Method Not Allowed');
    }
}
