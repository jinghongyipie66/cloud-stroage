<?php
require_once __DIR__ . '/security.php';
start_secure_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
}

/**
 * 公开分享页
 * 无需管理员 Basic Auth 认证
 */

$base_dir = __DIR__ . "/data/";
if (!is_dir($base_dir)) {
    mkdir($base_dir, 0755, true);
}
$shares_file = __DIR__ . "/" . ".shares.json";
$passwords_file = __DIR__ . "/" . ".passwords.json";

$token = $_GET['id'] ?? '';
if (empty($token)) die("无效的分享链接");

if (!file_exists($shares_file)) die("分享系统初始化中...");
$shares = json_decode(file_get_contents($shares_file), true) ?: [];

if (!isset($shares[$token])) die("分享链接已失效或不存在");

$share_info = &$shares[$token];
$file_path = $base_dir . $share_info['file'];

// 1. 检查有效期
if ($share_info['expiry'] !== null && time() > $share_info['expiry']) {
    die("该分享链接已过期");
}

// 2. 检查文件是否存在
if (!file_exists($file_path)) die("源文件已被移动或删除");

// 3. 增加查看次数统计 (简单防刷新)
if (!isset($_SESSION['viewed_shares'][$token])) {
    $share_info['views']++;
    file_put_contents($shares_file, json_encode($shares, JSON_PRETTY_PRINT));
    $_SESSION['viewed_shares'][$token] = true;
}

// 4. 检查文件提取密码
$passwords_data = file_exists($passwords_file) ? json_decode(file_get_contents($passwords_file), true) : [];
$has_password = isset($passwords_data[$share_info['file']]);
$password_verified = false;
$error_msg = '';

if ($has_password) {
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['share_password'])) {
        if (password_verify($_POST['share_password'], $passwords_data[$share_info['file']])) {
            $password_verified = true;
            $_SESSION['verified_shares'][$token] = true;
        } else {
            $error_msg = "密码错误，请重试。";
        }
    } elseif (isset($_SESSION['verified_shares'][$token])) {
        $password_verified = true;
    }
} else {
    $password_verified = true;
}

// 5. 处理下载跳转
if (isset($_GET['download']) && $password_verified) {
    // 增加下载次数统计
    $share_info['downloads']++;
    file_put_contents($shares_file, json_encode($shares, JSON_PRETTY_PRINT));
    
    // 使用 token 参数进行下载，download.php 会识别此 token 并跳过 Basic Auth
    header('Location: download.php?token=' . urlencode($token));
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>文件分享 - <?php echo htmlspecialchars($share_info['name']); ?></title>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <style>
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade-in { animation: fadeIn 0.4s ease-out; }
  </style>
</head>
<body class="bg-gradient-to-br from-slate-50 via-blue-50 to-slate-50 text-slate-800 min-h-screen flex items-center justify-center p-4">
  <div class="w-full max-w-md bg-white rounded-3xl shadow-xl border border-slate-100 overflow-hidden animate-fade-in">
    <!-- 头部视觉 -->
    <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-8 py-10 text-center text-white">
      <div class="text-5xl mb-4">🎁</div>
      <h1 class="text-xl font-bold truncate px-4"><?php echo htmlspecialchars($share_info['name']); ?></h1>
      <p class="text-blue-100 text-xs mt-2 opacity-80 uppercase tracking-widest">Secure File Share</p>
    </div>

    <div class="p-8">
      <?php if ($has_password && !$password_verified): ?>
        <div class="text-center mb-6">
          <p class="text-sm text-slate-500 font-medium">此分享已受密码保护</p>
        </div>
        <?php if ($error_msg): ?>
          <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-xs py-2 rounded-lg text-center font-medium"><?php echo $error_msg; ?></div>
        <?php endif; ?>
        <form method="POST" class="space-y-4">
          <?php echo csrf_field(); ?>
          <input type="password" name="share_password" placeholder="请输入提取密码" required autofocus
                 class="w-full border border-slate-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 transition-all">
          <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl transition-all shadow-lg shadow-blue-200">
            🔓 验证并提取文件
          </button>
        </form>
      <?php else: ?>
        <div class="space-y-6">
          <!-- 统计信息 -->
          <div class="flex justify-around text-center border-b border-slate-50 pb-6">
            <div>
              <p class="text-[10px] text-slate-400 font-bold uppercase">查看次数</p>
              <p class="text-lg font-black text-slate-700"><?php echo $share_info['views']; ?></p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 font-bold uppercase">下载次数</p>
              <p class="text-lg font-black text-slate-700"><?php echo $share_info['downloads']; ?></p>
            </div>
            <div>
              <p class="text-[10px] text-slate-400 font-bold uppercase">链接状态</p>
              <p class="text-[10px] font-black text-emerald-600 bg-emerald-50 px-2 py-1 rounded-full mt-1">有效</p>
            </div>
          </div>
          
          <div class="text-center space-y-4">
            <div class="text-xs text-slate-400 font-medium">
              有效期至：<?php echo $share_info['expiry'] ? date('Y-m-d H:i', $share_info['expiry']) : '永久有效'; ?>
            </div>
            <a href="?id=<?php echo $token; ?>&download=1" class="block w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-bold py-4 rounded-2xl transition-all shadow-xl shadow-blue-200 text-center">
              🚀 立即下载文件
            </a>
            <p class="text-[10px] text-slate-400">如无法下载，请点击右上角选择“在浏览器打开”</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
    
    <div class="bg-slate-50 px-8 py-4 text-center border-t border-slate-100">
      <p class="text-[10px] text-slate-400 tracking-widest uppercase font-bold">Powered by Cloud Storage Center</p>
    </div>
  </div>
</body>
</html>

