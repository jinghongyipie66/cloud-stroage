<?php
/**
 * 当前登录用户修改自己的密码。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

// 当前登录用户修改自己的密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['account_action'] ?? '') === 'change_own_password') {
    header('Content-Type: text/plain; charset=utf-8');

    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $new_password_confirm = $_POST['new_password_confirm'] ?? '';

    if ($current_username === '') {
        http_response_code(401);
        echo 'Error: 登录状态已失效，请重新登录';
        exit;
    }
    if ($current_password === '' || $new_password === '' || $new_password_confirm === '') {
        echo 'Error: 请完整填写当前密码和新密码';
        exit;
    }
    if (strlen($new_password) < 6) {
        echo 'Error: 新密码长度至少为 6 位';
        exit;
    }
    if (strlen($new_password) > 255) {
        echo 'Error: 新密码长度不能超过 255 位';
        exit;
    }
    if ($new_password !== $new_password_confirm) {
        echo 'Error: 两次输入的新密码不一致';
        exit;
    }

    $pdo = get_db_connection();
    $account_stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
    $account_stmt->execute([$current_username]);
    $account = $account_stmt->fetch();

    if (!$account || !password_verify($current_password, $account['password'])) {
        http_response_code(403);
        echo 'Error: 当前密码不正确';
        exit;
    }
    if (password_verify($new_password, $account['password'])) {
        echo 'Error: 新密码不能与当前密码相同';
        exit;
    }

    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
    if ($new_password_hash === false) {
        http_response_code(500);
        echo 'Error: 密码加密失败，请稍后重试';
        exit;
    }

    $update_stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
    $update_stmt->execute([$new_password_hash, $account['id']]);

    $account['password'] = $new_password_hash;
    remember_account_session($account);

    // 修改敏感凭证后刷新 Session ID，并让前端重新加载新的 CSRF Token。
    rotate_authenticated_session();
    echo 'Success';
    exit;
}
