<?php
require_once __DIR__ . '/security.php';
start_secure_session();

require_once __DIR__ . '/db_config.php';
require_once __DIR__ . '/user_helpers.php';
require_once __DIR__ . '/helpers/account_identity.php';

$error_msg = '';

// 如果已登录，重定向到首页
if (isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true) {
    header('Location: index.php');
    exit;
}

// 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    verify_csrf_or_fail();

    $input_user = trim(recovery_post('username'));
    $input_pass = recovery_post('password');

    $pdo = get_db_connection();
    $account = find_account_by_login($pdo, $input_user);

    if ($account && password_verify($input_pass, $account['password'])) {
        rotate_authenticated_session();
        $_SESSION['authenticated'] = true;
        $_SESSION['username'] = $account['username'];
        $_SESSION['_account_label'] = account_display_label($account);
        $_SESSION['role'] = $account['role'] ?? 'user';
        remember_account_session($account);

        // 仅在密码验证成功后记录最后登录时间；迁移未执行时不能影响原登录流程。
        try {
            $last_login_stmt = $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?');
            $last_login_stmt->execute([time(), $account['id']]);
        } catch (PDOException $last_login_error) {
            error_log('更新最后登录时间失败：' . $last_login_error->getMessage());
        }

        // 确保该用户的专属目录及分类文件夹存在
        ensure_user_folders($account['username']);

        header('Location: index.php');
        exit;
    } else {
        $error_msg = '账号或密码错误，请重试。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-cloud-theme="azure" data-cloud-theme-lock="azure">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>登录 · 瑞锦云存储</title>
  <link rel="stylesheet" href="assets/cloud-theme.css?v=20260905-ac1">
</head>
<body class="cs-login-page">
  <main class="cs-login-shell">
    <section class="cs-login-story" aria-label="瑞锦云存储">
      <a class="cs-brand" href="login.php"><span class="cs-logo" aria-hidden="true">☁</span><span><strong>瑞锦云存储</strong><small>RUIJIN CLOUD</small></span></a>
      <div class="cs-login-copy"><p class="cs-eyebrow">YOUR PERSONAL CLOUD</p><h1>重要的文件，<br>值得好好安放。</h1><p>工作资料、生活影像，<br>都在属于你的云端空间。</p></div>
      <div class="cs-login-features"><span>私密存储</span><span>分类管理</span><span>安全分享</span></div>
    </section>
    <section class="cs-login-form-side" aria-labelledby="login-heading">
      <div class="cs-login-form-wrap">
        <p class="cs-eyebrow">WELCOME BACK</p><h2 id="login-heading">欢迎回来</h2>
        <p class="cs-login-subtitle">登录你的账号，继续整理与分享。</p>
        <?php if ($error_msg): ?>
          <div class="cs-login-error" role="alert"><?php echo htmlspecialchars($error_msg); ?></div>
        <?php endif; ?>
        <form method="POST" class="cs-login-form">
          <?php echo csrf_field(); ?>
          <label for="username">邮箱或原账号</label>
          <input type="text" id="username" name="username" required autofocus autocomplete="username" placeholder="输入已验证邮箱或原账号">
          <label for="password">登录密码</label>
          <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="输入密码">
          <a class="cs-forgot-link" href="forgot_password.php">忘记密码？</a>
          <button type="submit" class="cs-primary">进入我的空间 <span aria-hidden="true">→</span></button>
        </form>
        <p class="cs-signup-link">还没有账号？ <a href="register.php">使用邮箱注册</a></p>
        <p class="cs-login-note">登录后，可在右上角切换浅色与深色外观。</p>
      </div>
    </section>
  </main>
</body>
</html>
