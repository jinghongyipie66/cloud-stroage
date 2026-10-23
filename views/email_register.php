<?php if (!defined('CLOUD_EMAIL_PAGE')) { http_response_code(404); exit; } ?>
<!doctype html>
<html lang="zh-CN" data-cloud-theme="azure" data-cloud-theme-lock="azure">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>邮箱注册 · 瑞锦云存储</title>
  <link rel="stylesheet" href="assets/account-email.css?v=20260902-countdown">
  <link rel="stylesheet" href="assets/cloud-theme.css?v=20260905-ac1">
  <script src="assets/account-email.js?v=20260903-limit-notice" defer></script>
</head>
<body class="cs-email-page">
  <main class="email-shell">
    <a class="back-link" href="login.php">← 已有账号？返回登录</a>
    <section class="email-card" aria-labelledby="page-title">
      <header class="email-header">
        <span class="email-icon" aria-hidden="true">✉</span>
        <p class="brand">瑞锦云存储 · 欢迎加入</p>
        <h1 id="page-title">邮箱注册</h1>
        <p>验证你的邮箱，开启专属文件空间。</p>
      </header>
      <div class="email-content">
        <?php if ($message !== ''): ?>
          <div class="notice notice-<?php echo recovery_escape($message_type); ?>" role="status"><?php echo recovery_escape($message); ?></div>
        <?php endif; ?>
        <?php if (!$available): ?>
          <div class="notice notice-info" role="status"><?php echo recovery_escape($unavailable_message); ?></div>
        <?php elseif ($done): ?>
          <a class="button primary" href="login.php">使用邮箱登录</a>
        <?php else: ?>
          <form method="post" action="register.php" class="email-form">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send">
            <h2><span class="step-number">1</span>验证注册邮箱</h2>
            <label for="register-email">邮箱地址</label>
            <input type="email" id="register-email" name="email" maxlength="254" required autocomplete="email" value="<?php echo recovery_escape($flow['email'] ?? ''); ?>" placeholder="例如 name@163.com">
            <button type="submit" class="button secondary" data-resend-button data-resend-seconds="<?php echo $resend_seconds; ?>" data-ready-label="<?php echo recovery_escape($resend_ready_label); ?>"<?php echo $resend_seconds > 0 ? ' disabled aria-disabled="true"' : ''; ?>><?php echo recovery_escape($resend_button_label); ?></button>
            <p class="hint">验证码有效期为 10 分钟，重发至少间隔 60 秒。请在当前浏览器完成注册；已有账号无需重新注册。</p>
          </form>
          <?php if (!empty($flow['id'])): ?>
            <form method="post" action="register.php" class="email-form verify-form">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="verify">
              <h2><span class="step-number">2</span>验证并设置密码</h2>
              <p class="hint">注册邮箱：<?php echo recovery_escape($flow['email'] ?? ''); ?></p>
              <label for="register-code">邮箱验证码</label>
              <input id="register-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code" placeholder="6 位数字">
              <label for="register-password">登录密码</label>
              <input type="password" id="register-password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="8～72 字节">
              <label for="register-confirm">确认密码</label>
              <input type="password" id="register-confirm" name="new_password_confirm" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="再次输入密码">
              <button type="submit" class="button primary">验证并注册</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <p class="security-note">验证成功后才创建普通用户，邮箱自动绑定，可用于登录和找回密码。请勿向他人透露验证码。</p>
      </div>
    </section>
    <p class="email-footer"><a class="back-link" href="forgot_password.php">已有账号但忘记密码？</a></p>
  </main>
</body>
</html>
