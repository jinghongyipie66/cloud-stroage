<?php
if (!defined('CLOUD_EMAIL_PAGE')) { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/security.php';
start_secure_session();
require_once dirname(__DIR__) . '/helpers/email_recovery.php';
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");

$is_binding = CLOUD_EMAIL_PAGE === 'bind';
$is_registration = CLOUD_EMAIL_PAGE === 'register';
$page_path = $is_registration ? 'register.php' : ($is_binding ? 'account_email.php' : 'forgot_password.php');
if ($is_binding && ($_SESSION['authenticated'] ?? false) !== true) {
    header('Location: login.php'); exit;
}
if (!$is_binding && ($_SESSION['authenticated'] ?? false) === true) {
    header('Location: ' . ($is_registration ? 'index.php' : 'account_email.php')); exit;
}
$flow_key = '_email_flow_' . CLOUD_EMAIL_PAGE;
$flash_key = '_email_flash_' . CLOUD_EMAIL_PAGE;
$flow = $_SESSION[$flow_key] ?? [];
$flash = $_SESSION[$flash_key] ?? [];
unset($_SESSION[$flash_key]);
$message = $flash['message'] ?? '';
$message_type = $flash['type'] ?? 'info';
$done = !empty($flash['done']);
$available = false;
$account = null;
$unavailable_message = '管理员尚未开启邮箱功能，请联系管理员。已有账号仍可正常登录。';
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf_or_fail();
try {
    $config = recovery_config();
    if (!empty($config['enabled'])) {
        if (PHP_VERSION_ID < 70400 || !extension_loaded('openssl')) throw new RuntimeException('运行环境不满足要求。');
        $key = recovery_key($config);
        require_once dirname(__DIR__) . '/db_config.php';
        $pdo = get_db_connection();
        $recovery = new EmailRecovery($pdo, $key, $config['daily_send_limit'] ?? 150);
        // 只查询检查：缺少迁移时仅关闭新功能，不在 HTTP 请求中自动建表。
        $pdo->query('SELECT recovery_email, recovery_email_verified_at FROM users LIMIT 0');
        $pdo->query('SELECT id FROM email_challenges LIMIT 0');
        $pdo->query('SELECT id FROM email_outbox LIMIT 0');
        $pdo->query('SELECT bucket_key FROM email_rate_limits LIMIT 0');
        $context = recovery_client_context($key);
        if ($is_binding) {
            $account = $recovery->account($_SESSION['username']);
            if (!$account) { header('Location: login.php'); exit; }
        }
        $available = true;
    }
} catch (Throwable $e) {
    $unavailable_message = '邮箱功能暂不可用，请联系管理员检查配置、迁移和定时任务。';
    http_response_code(503);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $available) {
    try {
        $action = recovery_post('action');
        if ($action === 'send') {
            $username = $is_registration ? '' : ($is_binding ? $_SESSION['username'] : trim(recovery_post('username')));
            $email = recovery_email(recovery_post('email'));
            $result = $is_registration ? $recovery->requestRegistration($email, $context, recovery_ip())
                : $recovery->request(CLOUD_EMAIL_PAGE, $username, $email, recovery_post('current_password'), $context, recovery_ip());
            if (isset($result['error'])) {
                $flash = ['type' => 'error', 'message' => $result['error']];
                if (!empty($result['clear_flow'])) {
                    // 未发验证码时不展示假的验证步骤，也不继续显示上一次邮箱的验证码表单。
                    $_SESSION[$flow_key] = ['username' => $username, 'email' => $email, 'resend_at' => 0];
                }
            } elseif (!empty($result['limited'])) {
                $same_flow = ($flow['username'] ?? '') === $username && ($flow['email'] ?? '') === $email;
                $_SESSION[$flow_key] = [
                    // 未发出新码；保留同一操作之前已收到的验证码，不能伪造一个新的验证步骤。
                    'id' => $same_flow ? ($flow['id'] ?? '') : '',
                    'username' => $username, 'email' => $email,
                    'resend_at' => time() + max(1, min(86400, (int)$result['retry_after'])),
                    'limit_window' => (int)$result['limit_window'],
                    'limit_reasons' => $result['limit_reasons'] ?? [],
                ];
                // GET 根据剩余时间渲染警告，刷新页面不会丢失提示或重新开始计时。
                $flash = [];
            } else {
                $same_flow = ($flow['username'] ?? '') === $username && ($flow['email'] ?? '') === $email;
                $_SESSION[$flow_key] = [
                    'id' => $result['id'] ?? ($same_flow ? ($flow['id'] ?? '') : bin2hex(random_bytes(16))),
                    'username' => $username, 'email' => $email,
                    // 前端倒计时以服务端写入时间为准；真正的重发限制仍由数据库限流保证。
                    'resend_at' => time() + 60,
                ];
                $flash = ['type' => 'info', 'message' => $is_registration
                    ? '注册验证码已加入发送队列，通常约一分钟内送达。请检查收件箱和垃圾箱；重发至少间隔 60 秒。若长时间未收到，请联系管理员检查邮件发送状态。'
                    : ($is_binding
                    ? '若邮箱可绑定且未达到发送限制，验证码将于约一分钟内送达。请检查收件箱和垃圾箱；重发至少间隔 60 秒。'
                    : '若账号与已验证邮箱匹配且未达到发送限制，验证码将于约一分钟内送达。请检查收件箱和垃圾箱；重发至少间隔 60 秒。')];
            }
        } elseif ($action === 'verify') {
            if ($is_registration) {
                require_once dirname(__DIR__) . '/user_helpers.php';
                require_once dirname(__DIR__) . '/helpers/registration_storage.php';
                $result = $recovery->completeRegistration($flow['id'] ?? '', trim(recovery_post('code')), $context, recovery_ip(),
                    recovery_post('new_password'), recovery_post('new_password_confirm'), registration_new_username(dirname(__DIR__)));
            } else {
                $result = $recovery->complete(CLOUD_EMAIL_PAGE, $flow['id'] ?? '', trim(recovery_post('code')), $context, recovery_ip(),
                    recovery_post('new_password'), recovery_post('new_password_confirm'), $is_binding ? $account['id'] : null);
            }
            $flash = ['type' => $result['ok'] ? 'success' : (!empty($result['limited']) ? 'warning' : 'error'), 'message' => $result['message'], 'done' => $result['ok']];
            if (!$result['ok'] && !empty($result['clear_flow'])) unset($_SESSION[$flow_key]['id']);
            if ($result['ok']) {
                if ($is_registration) {
                    // 建号已经提交：目录失败不能误报注册失败，也不能删除已创建账号。
                    try { $space_ready = registration_provision_space(dirname(__DIR__), $result['username']); }
                    catch (Throwable $storage_error) { $space_ready = false; }
                    if (!$space_ready) {
                        $flash['type'] = 'warning';
                        $flash['message'] = '账号和邮箱已注册成功，但个人文件空间暂不可用，请联系管理员检查目录权限。无需重复注册。';
                    }
                } elseif ($is_binding) {
                    $_SESSION['_account_label'] = account_display_label($recovery->account($_SESSION['username']));
                }
                unset($_SESSION[$flow_key]);
                rotate_authenticated_session();
                if (!$is_binding) unset($_SESSION['_email_client_secret']);
            }
        } else {
            $flash = ['type' => 'error', 'message' => '无效操作，请刷新页面。'];
        }
    } catch (InvalidArgumentException $e) {
        $flash = ['type' => 'error', 'message' => $is_registration ? '请填写有效的邮箱地址。' : '请填写正确的账号和邮箱。'];
    } catch (Throwable $e) {
        // 不将异常原文（含 SQL、邮箱、SMTP 信息等）输出给用户或日志。
        $flash = ['type' => 'error', 'message' => '操作暂时失败，请稍后重试或联系管理员。'];
    }
    $_SESSION[$flash_key] = $flash;
    header('Location: ' . $page_path, true, 303); exit;
}

$bound_email = !empty($account['recovery_email_verified_at']) ? ($account['recovery_email'] ?? '') : '';
$resend_seconds = max(0, min(86400, (int)($flow['resend_at'] ?? 0) - time()));
$wait_label = static function($seconds) {
    if ($seconds <= 60) return $seconds . '秒';
    $minutes = (int)ceil($seconds / 60);
    if ($minutes < 60) return $minutes . '分钟';
    $hours = (int)floor($minutes / 60);
    return $hours . '小时' . ($minutes % 60 ? ($minutes % 60) . '分钟' : '');
};
if ($message === '' && $resend_seconds > 0 && !empty($flow['limit_window'])) {
    $reason = $flow['limit_window'] >= 86400 ? '24 小时发送额度已用完'
        : ($flow['limit_window'] >= 3600 ? '一小时内发送请求过多' : '发送过于频繁');
    if (!empty($flow['limit_reasons']) && is_array($flow['limit_reasons'])) {
        $reason .= '（' . implode('；', $flow['limit_reasons']) . '）';
    }
    $message = $reason . '，本次未发送验证码。请在约 ' . $wait_label($resend_seconds) . '后重试，请勿重复提交。';
    if (!empty($flow['id'])) $message .= '已收到的验证码在有效期内仍可使用。';
    $message_type = 'warning';
}
$resend_ready_label = empty($flow['id']) ? '发送验证码' : '重新发送验证码';
$resend_button_label = $resend_ready_label . ($resend_seconds > 0 ? '（' . $wait_label($resend_seconds) . '）' : '');
$bound_masked = '';
if ($bound_email !== '') {
    list($local_part, $domain) = explode('@', $bound_email, 2);
    $bound_masked = substr($local_part, 0, 2) . '***@' . $domain;
}
require dirname(__DIR__) . ($is_registration ? '/views/email_register.php' : '/views/email_account.php');
