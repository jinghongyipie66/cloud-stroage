<?php
/** 所有登录入口共用的账号会话指纹，不依赖邮箱配置或迁移。 */
function account_session_signature($account) {
    return hash('sha256', (string)$account['id'] . "\0" . $account['password']);
}

function remember_account_session($account) {
    $_SESSION['_account_signature'] = account_session_signature($account);
}

function account_session_matches($account, $signature) {
    return is_array($account) && is_string($signature)
        && hash_equals(account_session_signature($account), $signature);
}

function validate_account_session() {
    if (($_SESSION['authenticated'] ?? false) !== true) return;
    $signature = $_SESSION['_account_signature'] ?? null;
    // 没有指纹的旧会话必须重新登录，不能自动信任或补签。
    if (!is_string($signature)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    try {
        // 兼容旧配置：文件顶层定义连接参数，get_db_connection() 用 global 读取。
        // 首次从本函数 require 时须绑定到全局，避免参数留在局部作用域；不记录其值。
        global $db_host, $db_port, $db_name, $db_user, $db_pass;
        require_once dirname(__DIR__) . '/db_config.php';
        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id, password, role FROM users WHERE username = ?');
        $stmt->execute([$_SESSION['username'] ?? '']);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // 不记录 SQL/连接参数；数据库异常时不放行旧会话。
        http_response_code(503);
        header('Cache-Control: no-store');
        exit('账号状态暂时无法验证，请稍后重试。');
    }
    if (!account_session_matches($account, $signature)) {
        $_SESSION = [];
        session_regenerate_id(true);
        return;
    }
    $_SESSION['role'] = $account['role'] ?? 'user';
}
