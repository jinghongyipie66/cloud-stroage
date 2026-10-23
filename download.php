<?php
require_once __DIR__ . '/security.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
}

/**
 * 核心下载逻辑
 * 支持：
 * 1. 管理员直接下载（需 Basic Auth）
 * 2. 分享链接下载（需提供有效 token，免 Basic Auth）
 */

$base_dir = __DIR__ . "/data/";
if (!is_dir($base_dir)) {
    mkdir($base_dir, 0755, true);
}
$passwords_file = __DIR__ . "/" . ".passwords.json";
$shares_file = __DIR__ . "/" . ".shares.json";

// ======= 1. 身份验证策略 =======
$token = $_GET['token'] ?? '';
$is_valid_share = false;

if (!empty($token)) {
    // 检查分享令牌是否有效
    if (file_exists($shares_file)) {
        $shares = json_decode(file_get_contents($shares_file), true) ?: [];
        if (isset($shares[$token])) {
            $share_info = $shares[$token];
            // 检查有效期
            if ($share_info['expiry'] === null || time() <= $share_info['expiry']) {
                $is_valid_share = true;
                // 确保请求的文件确实是该分享指向的文件
                $_GET['file'] = $share_info['file']; 
            }
        }
    }
}

// 如果不是有效的分享下载，则需要 Session 认证
if (!$is_valid_share) {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        http_response_code(401);
        echo '未授权，拒绝访问！';
        exit;
    }

    // ===== 目录隔离限制 =====
    // 普通用户只能下载自己专属目录（data/用户名/...）下的文件，管理员不受限制
    $is_admin_dl = ($_SESSION['role'] ?? 'user') === 'admin';
    $current_username_dl = $_SESSION['username'] ?? '';
    $requested_file = $_GET['file'] ?? '';
    $is_own_file_dl = ($current_username_dl !== '' && (strpos($requested_file, $current_username_dl . '/') === 0 || $requested_file === $current_username_dl));

    if (!$is_admin_dl && !$is_own_file_dl) {
        http_response_code(403);
        echo '无权访问该文件。';
        exit;
    }
}

// ======= 2. 文件路径解析与安全检查 =======
if (!isset($_GET['file'])) {
    http_response_code(400);
    echo "缺少文件参数。";
    exit;
}

$file_param = $_GET['file'];
if (strpos($file_param, '..') !== false || strpos($file_param, './') !== false) {
    http_response_code(403);
    echo "非法文件路径。";
    exit;
}

$file_path = $base_dir . $file_param;
if (strpos(realpath($file_path), realpath($base_dir)) !== 0) {
    http_response_code(403);
    echo "非法文件路径。";
    exit;
}

if (!file_exists($file_path) || !is_file($file_path)) {
    http_response_code(404);
    echo "文件不存在。";
    exit;
}

// ======= 3. 提取密码验证 (如果文件设有密码) =======
$passwords_data = file_exists($passwords_file) ? json_decode(file_get_contents($passwords_file), true) : [];
$has_password = isset($passwords_data[$file_param]);
$password_verified = false;
$error_msg = '';

if ($has_password) {
    // 如果是通过分享页验证过的
    if ($is_valid_share && isset($_SESSION['verified_shares'][$token])) {
        $password_verified = true;
    } 
    // 如果是管理员在后台验证过的
    elseif (isset($_SESSION['verified_files'][$file_param])) {
        $password_verified = true;
    }
    // 处理提交的密码
    elseif ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['file_password'])) {
        if (password_verify($_POST['file_password'], $passwords_data[$file_param])) {
            $password_verified = true;
            if ($is_valid_share) $_SESSION['verified_shares'][$token] = true;
            else $_SESSION['verified_files'][$file_param] = true;
        } else {
            $error_msg = "密码错误，请重试。";
        }
    }
} else {
    $password_verified = true;
}

// 如果未通过密码验证，显示验证页面
if ($has_password && !$password_verified) {
    ?>
    <!DOCTYPE html>
    <html lang="zh-CN">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>文件提取验证</title>
      <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    </head>
    <body class="bg-gradient-to-br from-slate-50 via-blue-50 to-slate-50 text-slate-800 min-h-screen flex items-center justify-center py-6 px-4">
      <div class="w-full max-w-md bg-white rounded-2xl shadow-lg border border-slate-100 p-8 animate-fade-in">
        <div class="text-center mb-6">
          <div class="text-5xl mb-3">🔐</div>
          <h1 class="text-2xl font-extrabold text-slate-900">文件已受保护</h1>
          <p class="text-sm text-slate-500 mt-2">请输入提取密码以继续下载</p>
        </div>
        <div class="bg-slate-50 rounded-lg p-4 mb-6 border border-slate-100 text-center">
          <p class="text-sm text-slate-600 font-semibold truncate">📄 <?php echo htmlspecialchars(basename($file_param)); ?></p>
        </div>
        <?php if ($error_msg): ?>
          <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium px-4 py-3 rounded-lg text-center"><?php echo $error_msg; ?></div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
          <?php echo csrf_field(); ?>
          <input type="password" name="file_password" required placeholder="输入密码" autofocus
                 class="w-full border border-slate-200 rounded-lg text-sm px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
          <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg shadow-lg transition-all">🔓 验证并下载</button>
        </form>
      </div>
    </body>
    </html>
    <?php
    exit;
}

// ======= 4. 执行文件传输 =======
$file_size = filesize($file_path);
$file_name = basename($file_path);
$file_mime = mime_content_type($file_path) ?: 'application/octet-stream';

header('Content-Type: ' . $file_mime);
header('Content-Disposition: attachment; filename="' . $file_name . '"');
header('Content-Length: ' . $file_size);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($file_path);
exit;
?>
