<?php
/** 页面头部、侧边导航和账号区域。 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN" data-cloud-theme="azure" data-cloud-theme-scope="<?php echo htmlspecialchars(hash('sha256', (string)($current_username ?? $_SESSION['username'] ?? '')), ENT_QUOTES, 'UTF-8'); ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
  <title>瑞锦云存储中心 - 管理后台</title>
  <script src="assets/cloud-theme.js?v=20260905-ac1"></script>
  <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
  <link rel="stylesheet" href="assets/app.css?v=20260731_mobile">
  <link rel="stylesheet" href="assets/cloud-theme.css?v=20260906-layout2">
</head>
<body class="cs-app">
  <div class="cs-shell">
    <aside class="cs-sidebar">
      <div class="cs-sidebar-inner">
        <a class="cs-brand" href="index.php"><span class="cs-logo" aria-hidden="true">☁</span><span><strong>瑞锦云存储</strong><small>RUIJIN CLOUD</small></span></a>
        <p class="cs-nav-label">工作空间</p>
        <nav class="cs-nav" aria-label="主导航">
          <a href="index.php?view=home<?php echo $nav_dir_suffix; ?>"<?php echo $current_view === 'home' ? ' aria-current="page"' : ''; ?>><span aria-hidden="true">▦</span><span>我的文件</span></a>
          <?php if ($is_admin): ?>
          <a href="index.php?view=users<?php echo $nav_dir_suffix; ?>"<?php echo $current_view === 'users' ? ' aria-current="page"' : ''; ?>><span aria-hidden="true">♙</span><span>用户管理</span></a>
          <?php endif; ?>
          <a href="index.php?view=shares<?php echo $nav_dir_suffix; ?>"<?php echo $current_view === 'shares' ? ' aria-current="page"' : ''; ?>><span aria-hidden="true">↗</span><span>分享管理</span></a>
        </nav>
        <p class="cs-sidebar-note">每一份文件，安心存放。</p>
      </div>
    </aside>
    <main class="cs-main">
      <div class="cs-content">
        <header class="cs-page-header">
          <div class="cs-page-heading">
            <h2><?php echo $current_view === 'home' ? '我的文件' : htmlspecialchars($view_titles[$current_view][0]); ?></h2>
            <p><?php echo htmlspecialchars($view_titles[$current_view][1]); ?></p>
            <?php if ($current_view === 'home' && $current_sub !== ''): ?>
              <p class="cs-location">当前位置：<?php echo htmlspecialchars($current_sub); ?></p>
            <?php endif; ?>
          </div>
          <div class="cs-account-area">
            <label class="cs-theme-picker"><span>外观</span><select data-cloud-theme-picker aria-label="切换页面主题"><option value="azure">清爽云蓝 · 浅色</option><option value="graphite">深色石墨 · 深色</option></select></label>
            <div class="cs-account">
              <span class="cs-account-avatar" aria-hidden="true">♙</span>
              <div class="cs-account-identity">
                <strong><?php echo htmlspecialchars($_SESSION['_account_label'] ?? ($current_username ?: 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></strong>
                <span class="<?php echo $is_super_admin ? 'cs-role-super' : ($is_admin ? 'cs-role-admin' : 'cs-role-user'); ?>"><?php echo htmlspecialchars($role_label); ?></span>
              </div>
              <div class="cs-account-actions">
                <button type="button" onclick="openOwnPasswordModal()">修改密码</button>
                <a href="account_email.php">绑定邮箱</a>
                <form method="POST" action="logout.php">
                  <?php echo csrf_field(); ?>
                  <button type="submit" class="cs-logout">退出</button>
                </form>
              </div>
            </div>
          </div>
        </header>
        <p class="cs-theme-feedback" data-cloud-theme-status role="status" aria-live="polite"></p>
