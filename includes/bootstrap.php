<?php
/**
 * 会话认证、预览请求、目录隔离及当前目录数据初始化。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

// ======= 1. Session 认证 =======
require_once CLOUD_STORAGE_ROOT . '/security.php';
start_secure_session();

require_once CLOUD_STORAGE_ROOT . '/db_config.php';
require_once CLOUD_STORAGE_ROOT . '/user_helpers.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    header('Location: login.php');
    exit;
}

// 所有改变状态的请求统一进行 CSRF 校验。
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
}

// 获取用户角色
$user_role = $_SESSION['role'] ?? 'user';
$is_admin = ($user_role === 'admin');
$current_username = $_SESSION['username'] ?? '';
$is_super_admin = $is_admin && $current_username === 'admin';

$allowed_views = $is_admin ? ['home', 'users', 'shares'] : ['home', 'shares'];
$current_view = $_GET['view'] ?? 'home';
if (!in_array($current_view, $allowed_views, true)) {
    $current_view = 'home';
}

// 确保当前用户的专属目录存在（防止目录被误删后无法访问）
if ($current_username !== '') {
    ensure_user_folders($current_username);
}

// ======= 2. 核心逻辑 =======
// 数据存储目录（与程序文件分离）。注意：每个用户的文件都隔离存放在 data/<用户名>/ 下，
// 管理员可以浏览所有用户目录，普通用户只能停留在自己的目录范围内（见下方"目录隔离限制"）。
$base_dir = CLOUD_STORAGE_ROOT . "/data/";

// 配置文件位于程序目录（不映射到网站根目录）
// 注意：这里提前定义，因为下面的"预览功能逻辑"需要在此之前就用到 $passwords_file
$config_dir = CLOUD_STORAGE_ROOT . "/";
$passwords_file = $config_dir . ".passwords.json";
$shares_file = $config_dir . ".shares.json";

// --- 预览功能逻辑 ---
$is_preview_request = (isset($_GET['preview']) && isset($_GET['file']))
    || (isset($_POST['preview']) && isset($_POST['file']));
if ($is_preview_request) {
    $preview_file = $_POST['file'] ?? $_GET['file'];
    $preview_pwd = $_POST['pwd'] ?? '';
    
    // 安全检查：防止路径穿越
    if (strpos($preview_file, '..') === false) {
        $preview_path = realpath($base_dir . $preview_file);
        // 目录隔离：普通用户只能预览自己目录（data/用户名/...）下的文件，管理员不受限制
        $is_own_path = ($current_username !== '' && (strpos($preview_file, $current_username . '/') === 0 || $preview_file === $current_username));
        if (!$is_admin && !$is_own_path) {
            http_response_code(403);
            exit('无权访问该文件。');
        }
        if ($preview_path && strpos($preview_path, realpath($base_dir)) === 0 && is_file($preview_path)) {
            
            // 密码检查逻辑
            $passwords_data = file_exists($passwords_file) ? (json_decode(@file_get_contents($passwords_file), true) ?: []) : [];
            if (isset($passwords_data[$preview_file])) {
                // 检查 Session 中是否已有授权（统一使用 verified_files 键名）
                $is_verified = isset($_SESSION['verified_files'][$preview_file]) && $_SESSION['verified_files'][$preview_file] === true;
                
                if (empty($preview_pwd)) {
                    if (!$is_verified) {
                        http_response_code(401);
                        exit('NEED_PASSWORD');
                    }
                } else {
                    // 提供了密码，进行校验（支持明文或哈希，建议与 download.php 保持一致使用 password_verify）
                    $stored_pwd = $passwords_data[$preview_file];
                    if ($preview_pwd === $stored_pwd || password_verify($preview_pwd, $stored_pwd)) {
                        // 校验通过，存入 Session
                        $_SESSION['verified_files'][$preview_file] = true;
                        if (isset($_POST['check_only'])) {
                            exit('OK');
                        }
                    } else {
                        http_response_code(401);
                        exit('WRONG_PASSWORD');
                    }
                }
            }

            $size = filesize($preview_path);
            if ($size < 10 * 1024 * 1024) { // 限制 10MB
                $ext = strtolower(pathinfo($preview_path, PATHINFO_EXTENSION));
                $mime = 'application/octet-stream';

                // 简单的 MIME 类型判断
                $text_exts = ['txt', 'md', 'log', 'csv', 'json', 'xml', 'html', 'htm', 'css', 'js', 'ts', 'ini', 'conf', 'yml', 'yaml', 'sh', 'py', 'java', 'c', 'cpp'];
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) $mime = 'image/' . ($ext === 'jpg' ? 'jpeg' : $ext);
                elseif ($ext === 'pdf') $mime = 'application/pdf';
                elseif (in_array($ext, ['mp4', 'webm'])) $mime = 'video/' . $ext;
                elseif (in_array($ext, ['mp3', 'wav'])) $mime = 'audio/' . ($ext === 'mp3' ? 'mpeg' : $ext);
                elseif (in_array($ext, $text_exts)) $mime = 'text/plain';
                
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . $size);
                readfile($preview_path);
                exit;
            }
        }
    }
    http_response_code(403);
    exit('预览不可用或文件过大');
}
// --- 预览功能逻辑结束 ---
if (!is_dir($base_dir)) {
    @mkdir($base_dir, 0755, true);
}

$current_sub = isset($_GET['dir']) ? trim($_GET['dir'], '/') : '';
if (strpos($current_sub, '..') !== false) $current_sub = '';

// ===== 目录隔离限制 =====
// 普通用户只能浏览自己的专属目录（data/用户名/ 及其子目录），无法访问其他用户的目录。
// 管理员不受限制，可以浏览所有用户目录（根目录下按用户名分类显示）。
if (!$is_admin) {
    $own_prefix = $current_username . '/';
    if ($current_sub !== $current_username && strpos($current_sub, $own_prefix) !== 0) {
        // 默认进入自己的专属目录（首次进入或试图越权访问时，都会被拉回自己的空间）
        $current_sub = $current_username;
    }
}

$target_dir = $base_dir;
if ($current_sub !== '') $target_dir = $base_dir . $current_sub . "/";

if (!is_dir($target_dir)) {
    $current_sub = $is_admin ? '' : $current_username;
    $target_dir = $base_dir . ($current_sub !== '' ? $current_sub . '/' : '');
}

$scanned_items = @scandir($target_dir);
$items = array_diff($scanned_items !== false ? $scanned_items : [], array('.', '..', 'index.php', 'upload.php', 'download.php', 'share.php', 'share_api.php', 'login.php', 'logout.php', '.passwords.json', '.shares.json', 'init_db.sql'));

// 额外过滤：隐藏所有以点开头的隐藏文件
$items = array_filter($items, function($item) {
    if (substr($item, 0, 1) === '.') return false;
    return true;
});

$folders_list = [];
$files_list = [];
$total_size = 0;

foreach ($items as $item) {
    $item_path = $target_dir . $item;
    if (is_dir($item_path)) {
        $folders_list[] = $item;
    } else {
        $files_list[] = $item;
        $total_size += @filesize($item_path) ?: 0;
    }
}

$passwords_data = file_exists($passwords_file) ? (json_decode(@file_get_contents($passwords_file), true) ?: []) : [];
$shares_data = file_exists($shares_file) ? (json_decode(@file_get_contents($shares_file), true) ?: []) : [];
$owners_file = $config_dir . ".file_owners.json";
$owners_data = file_exists($owners_file) ? (json_decode(@file_get_contents($owners_file), true) ?: []) : [];

