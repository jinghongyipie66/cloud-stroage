<?php
/** 邮箱配置、校验和加密；本文件不包含任何真实凭据。 */
require_once __DIR__ . '/account_session.php';

function recovery_post($name) {
    $value = $_POST[$name] ?? '';
    return is_string($value) ? $value : '';
}

function recovery_email($value) {
    if (!is_string($value)) return '';
    $value = strtolower(trim($value));
    if (strlen($value) > 254 || preg_match('/[^\x21-\x7e]/', $value)) return '';
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
}

function recovery_password_error($password, $confirmation) {
    // bcrypt 最多处理 72 字节，避免静默截断。
    if (!is_string($password) || strlen($password) < 8 || strlen($password) > 72 || strpos($password, "\0") !== false) {
        return '新密码需为 8～72 字节，中文等字符会占多个字节。';
    }
    return $password === $confirmation ? '' : '两次输入的新密码不一致。';
}

function recovery_config() {
    static $config;
    if ($config !== null) return $config;
    $path = getenv('CLOUD_STORAGE_MAIL_CONFIG') ?: '/etc/cloud-storage/mail.php';
    if (!is_file($path)) return $config = ['enabled' => false];
    $real = realpath($path);
    $root = str_replace('\\', '/', realpath(dirname(__DIR__))) . '/';
    if (stripos(str_replace('\\', '/', $real ?: ''), $root) === 0) {
        throw new RuntimeException('邮件配置必须存放在网站目录外。');
    }
    $loaded = require $real;
    if (!is_array($loaded)) throw new RuntimeException('邮件配置格式错误。');
    return $config = $loaded;
}

function recovery_key($config) {
    $hex = $config['app_key'] ?? '';
    if (!is_string($hex) || !preg_match('/\A[a-f0-9]{64}\z/i', $hex)) {
        throw new RuntimeException('请先配置邮件功能的独立随机密钥。');
    }
    return hex2bin($hex);
}

function recovery_digest($key, $purpose, $value) {
    return hash_hmac('sha256', $purpose . "\0" . $value, $key);
}

function recovery_encrypt($key, $id, $payload) {
    $iv = random_bytes(12);
    $tag = '';
    $encryption_key = hash_hmac('sha256', 'mail-outbox-encryption-v1', $key, true);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $cipher = openssl_encrypt($json, 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA, $iv, $tag, $id, 16);
    if ($cipher === false) throw new RuntimeException('邮件队列加密失败。');
    return base64_encode($iv . $tag . $cipher);
}

function recovery_decrypt($key, $id, $payload) {
    $raw = is_string($payload) ? base64_decode($payload, true) : false;
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('无效邮件队列。');
    $encryption_key = hash_hmac('sha256', 'mail-outbox-encryption-v1', $key, true);
    $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $encryption_key, OPENSSL_RAW_DATA,
        substr($raw, 0, 12), substr($raw, 12, 16), $id);
    if ($json === false) throw new RuntimeException('邮件队列完整性验证失败。');
    return json_decode($json, true, 16, JSON_THROW_ON_ERROR);
}

function recovery_client_context($key) {
    if (empty($_SESSION['_email_client_secret'])) {
        $_SESSION['_email_client_secret'] = bin2hex(random_bytes(32));
    }
    return recovery_digest($key, 'browser-context', $_SESSION['_email_client_secret']);
}

function recovery_ip() {
    // 代理须在 Web 服务器层配置可信 real IP，不信任客户端传来的 X-Forwarded-For。
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function recovery_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
