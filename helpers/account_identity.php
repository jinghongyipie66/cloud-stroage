<?php
require_once __DIR__ . '/email_common.php';

function account_display_label($account) {
    $username = $account['username'] ?? '';
    return preg_match('/\Au_[a-f0-9]{24}\z/', $username) && !empty($account['recovery_email_verified_at'])
        && recovery_email($account['recovery_email'] ?? '') !== '' ? $account['recovery_email'] : $username;
}

/** 登录和找回共用：优先保持旧用户名含义，再查已验证邮箱。配置仍由入口全局加载。 */
function find_account_by_login(PDO $pdo, $identifier, $lock = false) {
    if (!is_string($identifier) || $identifier === '' || strlen($identifier) > 254) return false;
    $suffix = $lock && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ?' . $suffix);
    $stmt->execute([$identifier]);
    $account = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($account) return $account;
    $email = recovery_email($identifier);
    if ($email === '') return false;
    try {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE recovery_email = ? AND recovery_email_verified_at > 0' . $suffix);
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // 尚未执行邮箱迁移的旧站点仍能按原用户名登录；只忽略缺少邮箱字段这一类错误。
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' && (int)($e->errorInfo[1] ?? 0) === 1054) return false;
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && strpos($e->getMessage(), 'no such column: recovery_email') !== false) return false;
        throw $e;
    }
}
