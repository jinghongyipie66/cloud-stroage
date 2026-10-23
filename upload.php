<?php
// ======= 1. Session 认证 =======
require_once __DIR__ . '/security.php';
start_secure_session();

require_once __DIR__ . '/user_helpers.php';
require_once __DIR__ . '/helpers/upload_helpers.php';

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    http_response_code(401);
    echo '未授权，拒绝访问！';
    exit;
}

$current_username = $_SESSION['username'] ?? '';
$is_admin = ($_SESSION['role'] ?? 'user') === 'admin';

// 确保当前用户的专属目录存在
if ($current_username !== '') {
    ensure_user_folders($current_username);
}

$check_only = ($_POST['upload_check'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_FILES['file']) || $check_only)) {
    verify_csrf_or_fail();
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    $base_dir = __DIR__ . "/data/";
    if (!is_dir($base_dir)) {
        mkdir($base_dir, 0755, true);
    }
    $passwords_file = __DIR__ . "/" . ".passwords.json";
    $owners_file = __DIR__ . "/" . ".file_owners.json";

    // 接收并清洗前端发来的目标子文件夹参数
    if (isset($_POST['dir']) && !is_string($_POST['dir'])) {
        http_response_code(400); exit('无效的上传目录。');
    }
    $sub_dir = isset($_POST['dir']) ? trim($_POST['dir'], '/') : '';
    
    // 深度路径安全检查
    if (strpos($sub_dir, '..') !== false || strpos($sub_dir, './') !== false || strpos($sub_dir, '\\') !== false || strpos($sub_dir, "\0") !== false) {
        http_response_code(400); exit('安全拦截：非法存储路径。');
    }

    // ===== 目录隔离限制 =====
    // 普通用户只能把文件上传到自己的专属目录（data/用户名/...）之内
    if (!$is_admin) {
        $own_prefix = $current_username . '/';
        if ($sub_dir !== $current_username && strpos($sub_dir, $own_prefix) !== 0) {
            $sub_dir = $current_username;
        }
    }

    $target_dir = $base_dir;
    if ($sub_dir !== '') {
        $target_dir = $base_dir . $sub_dir . "/";
    }

    if (!upload_directory_is_allowed($target_dir, $base_dir, $current_username, $is_admin)) {
        http_response_code(400); exit('安全拦截：非法存储路径。');
    }
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0755, true);
    }

    $submitted_name = $check_only ? ($_POST['filename'] ?? null) : ($_FILES['file']['name'] ?? null);
    if (!is_string($submitted_name) || $submitted_name === '' || strpos($submitted_name, "\0") !== false) {
        http_response_code(400); exit('无效的文件名。');
    }
    $original_name = basename(str_replace('\\', '/', $submitted_name));
    $original_name = preg_replace('/[^\w\-\. \x{4e00}-\x{9fa5}]/u', '_', $original_name);
    if (!is_string($original_name) || $original_name === '') {
        http_response_code(400); exit('无效的文件名编码。');
    }
    
    $file_ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));

    $prohibited_exts = ['php', 'php5', 'php4', 'php3', 'phtml', 'sh', 'pl', 'py', 'exe', 'jsp', 'asp', 'aspx', 'dll', 'cgi', 'bat'];
    if (in_array($file_ext, $prohibited_exts) || empty($file_ext)) {
        http_response_code(400);
        echo "安全拦截：禁止上传脚本或无后缀文件。";
        exit;
    }

    if (!$check_only && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo "上传失败，请检查文件及服务器上传大小限制。";
        exit;
    }

    if (!$check_only && ($_FILES['file']['size'] ?? 0) > 100 * 1024 * 1024) {
        http_response_code(400);
        echo "超出100MB大小上限。";
        exit;
    }

    // ===== 自动分类 =====
    // 如果用户没有手动选择某个具体子文件夹（即停留在自己专属目录根部 或 "全部文件"），
    // 就根据文件扩展名自动归类到 图片/视频/文档/音频/压缩包 对应的子文件夹中
    $is_at_own_root = !$is_admin && ($sub_dir === $current_username || $sub_dir === $current_username . '/全部文件');
    if ($is_at_own_root) {
        $detected_category = detect_category_folder($original_name);
        if ($detected_category !== null) {
            $sub_dir = $current_username . '/' . $detected_category;
            $target_dir = $base_dir . $sub_dir . "/";
            if (!upload_directory_is_allowed($target_dir, $base_dir, $current_username, $is_admin)) {
                http_response_code(400); exit('安全拦截：非法存储路径。');
            }
            if (!file_exists($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
        }
    }

    $target_file = $target_dir . $original_name;

    $base_real = realpath($base_dir);
    $target_real = realpath($target_dir);
    $own_real = $current_username !== '' ? realpath($base_dir . $current_username) : false;
    if (!$base_real || !$target_real || !upload_path_is_within($target_real, $base_real)
        || (!$is_admin && (!$own_real || !upload_path_is_within($target_real, $own_real)))) {
        http_response_code(400);
        echo "安全拦截：非法存储路径。";
        exit;
    }

    $duplicate_message = '目标目录“' . ($sub_dir === '' ? '根目录' : $sub_dir) . '”中已存在同名文件或文件夹“' . $original_name . '”。本次未上传，原文件未改变；如需保留两份，请先修改本地文件名后重试。';
    if (upload_target_exists($target_file)) {
        http_response_code(409); exit($duplicate_message);
    }

    if ($check_only) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 再次检查并原子发布；两个请求同时上传同名文件也不会互相覆盖。
    $stored = upload_store_exclusive($_FILES['file']['tmp_name'] ?? '', $target_file);
    if ($stored === 'exists') { http_response_code(409); exit($duplicate_message); }
    if ($stored === 'stored') {
        $relative_path = ($sub_dir === '') ? basename($target_file) : $sub_dir . '/' . basename($target_file);

        // 记录文件所有者（用于"移动文件"等权限判断：普通用户只能操作自己上传的文件）
        $owners_data = [];
        if (file_exists($owners_file)) {
            $owners_data = json_decode(@file_get_contents($owners_file), true) ?: [];
        }
        $owners_data[$relative_path] = $_SESSION['username'] ?? 'unknown';
        file_put_contents($owners_file, json_encode($owners_data, JSON_PRETTY_PRINT));

        // 保存下载密码逻辑
        $download_password = isset($_POST['password']) ? trim($_POST['password']) : '';
        if ($download_password !== '') {
            $passwords_data = [];
            if (file_exists($passwords_file)) {
                $passwords_data = json_decode(file_get_contents($passwords_file), true) ?: [];
            }
            
            // 使用相对路径作为键存储哈希后的密码
            $passwords_data[$relative_path] = password_hash($download_password, PASSWORD_DEFAULT);
            file_put_contents($passwords_file, json_encode($passwords_data, JSON_PRETTY_PRINT));
        }
        exit;
    } else {
        http_response_code(500);
        echo "文件保存失败，原有文件未被覆盖。请检查存储目录权限、空间及硬链接支持。";
        exit;
    }
}
http_response_code($_SERVER['REQUEST_METHOD'] === 'POST' ? 400 : 405);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') header('Allow: POST');
echo '请选择文件并通过上传表单提交。';
?>
