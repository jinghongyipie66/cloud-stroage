<?php if (!defined('CLOUD_EMAIL_PAGE')) { http_response_code(404); exit; } ?>
<!doctype html>
<html lang="zh-CN" data-cloud-theme="azure" data-cloud-theme-lock="<?php echo $is_binding ? '' : 'azure'; ?>" data-cloud-theme-scope="<?php echo $is_binding ? htmlspecialchars(hash('sha256', (string)($_SESSION['username'] ?? '')), ENT_QUOTES, 'UTF-8') : ''; ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $is_binding ? '绑定找回邮箱' : '忘记密码'; ?> · 瑞锦云存储</title>
  <script src="assets/cloud-theme.js?v=20260905-ac1"></script>
  <link rel="stylesheet" href="assets/account-email.css?v=20260902-countdown">
  <link rel="stylesheet" href="assets/cloud-theme.css?v=20260905-ac1">
  <script src="assets/account-email.js?v=20260903-limit-notice" defer></script>
</head>
<body class="cs-email-page">
  <main class="email-shell">
    <a class="back-link" href="<?php echo $is_binding ? 'index.php' : 'login.php'; ?>">← <?php echo $is_binding ? '返回我的文件' : '返回登录'; ?></a>
    <?php if ($is_binding): ?>
    <div class="cs-email-toolbar"><label class="cs-theme-picker"><span>外观</span><select data-cloud-theme-picker aria-label="切换页面主题"><option value="azure">清爽云蓝 · 浅色</option><option value="graphite">深色石墨 · 深色</option></select></label></div>
    <p class="cs-theme-feedback" data-cloud-theme-status role="status" aria-live="polite"></p>
    <?php endif; ?>
    <section class="email-card" aria-labelledby="page-title">
      <header class="email-header">
        <span class="email-icon" aria-hidden="true">✉</span>
        <p class="brand">瑞锦云存储 · 账号安全</p>
        <h1 id="page-title"><?php echo $is_binding ? '绑定找回邮箱' : '找回登录密码'; ?></h1>
        <p><?php echo $is_binding ? '验证邮箱后，忘记密码也能安全找回账号。' : '通过已绑定并验证的邮箱，重新设置登录密码。'; ?></p>
      </header>
      <div class="email-content">
        <?php if ($is_binding && $account): ?>
          <div class="account-summary <?php echo $bound_masked !== '' ? 'is-bound' : 'is-unbound'; ?>">
            <span class="account-status-icon" aria-hidden="true"><?php echo $bound_masked !== '' ? '✓' : '!'; ?></span>
            <div class="account-summary-content">
              <span class="account-summary-label"><?php echo $bound_masked !== '' ? '已绑定找回邮箱' : '尚未绑定找回邮箱'; ?></span>
              <?php if ($bound_masked !== ''): ?><strong class="account-summary-email"><?php echo recovery_escape($bound_masked); ?></strong><?php endif; ?>
              <span class="account-summary-name">账号：<?php echo recovery_escape(preg_match('/\Au_[a-f0-9]{24}\z/', $account['username']) ? '邮箱注册用户' : $account['username']); ?></span>
            </div>
          </div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
          <div class="notice notice-<?php echo recovery_escape($message_type); ?>" role="status"><?php echo recovery_escape($message); ?></div>
        <?php endif; ?>
        <?php if (!$available): ?>
          <div class="notice notice-info" role="status"><?php echo recovery_escape($unavailable_message); ?></div>
        <?php elseif ($done): ?>
          <a class="button primary" href="<?php echo $is_binding ? 'index.php' : 'login.php'; ?>"><?php echo $is_binding ? '返回我的文件' : '使用新密码登录'; ?></a>
        <?php else: ?>
          <form method="post" action="<?php echo $page_path; ?>" class="email-form" autocomplete="off">
            <?php echo csrf_field(); ?>
            <input type="hidden" name="action" value="send">
            <h2><span class="step-number">1</span><?php echo $is_binding ? '验证身份并发送验证码' : '获取邮箱验证码'; ?></h2>
            <?php if (!$is_binding): ?>
              <label for="recovery-username">邮箱或原账号</label>
              <input id="recovery-username" name="username" maxlength="254" required autocomplete="username" value="<?php echo recovery_escape($flow['username'] ?? ''); ?>" placeholder="邮箱注册用户可填写注册邮箱">
            <?php else: ?>
              <label for="recovery-current">当前登录密码</label>
              <input type="password" id="recovery-current" name="current_password" maxlength="255" required autocomplete="current-password" placeholder="验证是你本人操作">
            <?php endif; ?>
            <label for="recovery-email"><?php echo $is_binding ? '要绑定的邮箱' : '已绑定的邮箱'; ?></label>
            <input type="email" id="recovery-email" name="email" maxlength="254" required autocomplete="off" value="<?php echo recovery_escape($flow['email'] ?? ''); ?>" placeholder="例如 name@163.com">
            <button type="submit" class="button secondary" data-resend-button data-resend-seconds="<?php echo $resend_seconds; ?>" data-ready-label="<?php echo recovery_escape($resend_ready_label); ?>"<?php echo $resend_seconds > 0 ? ' disabled aria-disabled="true"' : ''; ?>><?php echo recovery_escape($resend_button_label); ?></button>
            <p class="hint">验证码有效期为 10 分钟。请保持在当前浏览器完成操作。</p>
          </form>
          <?php if (!empty($flow['id'])): ?>
            <form method="post" action="<?php echo $page_path; ?>" class="email-form verify-form" autocomplete="off">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="action" value="verify">
              <h2><span class="step-number">2</span><?php echo $is_binding ? '完成邮箱绑定' : '验证并设置新密码'; ?></h2>
              <label for="recovery-code">邮箱验证码</label>
              <input id="recovery-code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" minlength="6" maxlength="6" required autocomplete="one-time-code" placeholder="6 位数字">
              <?php if (!$is_binding): ?>
                <label for="recovery-new">新密码</label>
                <input type="password" id="recovery-new" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="8～72 字节">
                <label for="recovery-confirm">确认新密码</label>
                <input type="password" id="recovery-confirm" name="new_password_confirm" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="再次输入新密码">
              <?php endif; ?>
              <button type="submit" class="button primary"><?php echo $is_binding ? '验证并绑定邮箱' : '重置密码'; ?></button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
        <p class="security-note"><?php echo $is_binding ? '更换邮箱需要当前密码并验证新邮箱，原邮箱会收到变更通知。' : '未绑定邮箱或无法收信？请联系管理员协助重置。重置成功后，其他设备需要重新登录。'; ?></p>
      </div>
    </section>
    <p class="email-footer">验证码仅用于本人操作，请勿向任何人透露。</p>
  </main>
</body>
</html>
