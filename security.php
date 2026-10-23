<?php
/**
 * Session 与 CSRF 安全辅助函数。
 */

const SESSION_IDLE_TIMEOUT = 7200;
const SESSION_ID_ROTATION_INTERVAL = 900;

function request_is_https() {
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
}

function start_secure_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');

    session_name('ruijin_storage_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => request_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (isset($_SESSION['_last_activity']) && $now - (int) $_SESSION['_last_activity'] > SESSION_IDLE_TIMEOUT) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['_last_activity'] = $now;

    if (!isset($_SESSION['_id_rotated_at'])) {
        $_SESSION['_id_rotated_at'] = $now;
    } elseif ($now - (int) $_SESSION['_id_rotated_at'] > SESSION_ID_ROTATION_INTERVAL) {
        session_regenerate_id(true);
        $_SESSION['_id_rotated_at'] = $now;
    }

    require_once __DIR__ . '/helpers/account_session.php';
    validate_account_session();
}

function rotate_authenticated_session() {
    session_regenerate_id(true);
    $_SESSION['_id_rotated_at'] = time();
    $_SESSION['_last_activity'] = time();
    unset($_SESSION['_csrf_token']);
}

function csrf_token() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="'
        . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8')
        . '">';
}

function verify_csrf_or_fail() {
    $submitted = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = $_SESSION['_csrf_token'] ?? '';

    if (!is_string($submitted) || $expected === '' || !hash_equals($expected, $submitted)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        exit('请求验证失败，请刷新页面后重试。');
    }
}

function destroy_secure_session() {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}
