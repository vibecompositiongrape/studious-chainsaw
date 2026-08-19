<?php
declare(strict_types=1);
// admin/_auth.php — shared session/auth/CSRF guard for all admin endpoints.
//
// Usage:
//   require __DIR__ . '/_auth.php';
//   lsb_require_admin();            // HTML pages: redirects to login.php
//   lsb_require_admin(true);        // JSON endpoints: emits 401 JSON instead
//   lsb_csrf_token();               // token to embed in forms / JS
//   lsb_verify_csrf();              // dies with 403 unless a valid token was sent

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'cookie_secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
    ]);
}

$lsbConfig = dirname(__DIR__) . '/config.php';
if (!file_exists($lsbConfig)) {
    http_response_code(500);
    exit('config.php not found');
}
require_once $lsbConfig;

function lsb_is_admin(): bool {
    return (!empty($_SESSION['admin']) && $_SESSION['admin'] === true)
        || (!empty($_SESSION['admin_ok']) && $_SESSION['admin_ok'] === true);
}

function lsb_require_admin(bool $json = false): void {
    if (lsb_is_admin()) return;
    if ($json) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Admin login required']);
    } else {
        header('Location: login.php');
    }
    exit;
}

function lsb_csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function lsb_verify_csrf(): void {
    $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $known = $_SESSION['csrf_token'] ?? '';
    if ($known === '' || !is_string($sent) || !hash_equals($known, $sent)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token']);
        exit;
    }
}
