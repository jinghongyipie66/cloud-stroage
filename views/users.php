<?php
/** 管理员用户管理视图。 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
        <?php
          $display_pdo = get_db_connection();
          try {
              if ($is_super_admin) {
                  $all_users_stmt = $display_pdo->query("SELECT username, role, created_at, last_login_at FROM users ORDER BY CASE WHEN username = 'admin' THEN 0 WHEN role = 'admin' THEN 1 ELSE 2 END, id ASC");
              } else {
                  $all_users_stmt = $display_pdo->query("SELECT username, role, created_at, last_login_at FROM users WHERE role = 'user' ORDER BY id ASC");
              }
          } catch (PDOException $last_login_error) {
              // 兼容先上传代码、后执行数据库迁移的短暂窗口。
              error_log('读取最后登录时间失败：' . $last_login_error->getMessage());
              if ($is_super_admin) {
                  $all_users_stmt = $display_pdo->query("SELECT username, role, created_at, NULL AS last_login_at FROM users ORDER BY CASE WHEN username = 'admin' THEN 0 WHEN role = 'admin' THEN 1 ELSE 2 END, id ASC");
              } else {
                  $all_users_stmt = $display_pdo->query("SELECT username, role, created_at, NULL AS last_login_at FROM users WHERE role = 'user' ORDER BY id ASC");
              }
          }
          $users_for_display = $all_users_stmt->fetchAll();
          $verified_emails = [];
          try {
              $email_rows = $display_pdo->query("SELECT username, recovery_email FROM users WHERE recovery_email_verified_at > 0" . ($is_super_admin ? '' : " AND role = 'user'"));
              foreach ($email_rows as $email_row) $verified_emails[$email_row['username']] = $email_row['recovery_email'];
          } catch (PDOException $email_column_error) {
              // 旧数据库没有邮箱字段时仍能使用原来的用户管理。
          }
          $display_admin_count = count(array_filter($users_for_display, function($user) { return ($user['role'] ?? 'user') === 'admin'; }));
          $display_user_count = count($users_for_display) - $display_admin_count;
        ?>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6 animate-fade-in">
          <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm"><p class="text-xs text-slate-500">可见账号</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo count($users_for_display); ?></p></div>
          <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm"><p class="text-xs text-slate-500">普通用户</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo $display_user_count; ?></p></div>
          <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm"><p class="text-xs text-slate-500"><?php echo $is_super_admin ? '管理员账号' : '权限范围'; ?></p><p class="text-lg font-bold text-blue-700 mt-1"><?php echo $is_super_admin ? $display_admin_count : '仅普通用户'; ?></p></div>
        </div>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden animate-fade-in">
          <div class="px-5 sm:px-6 py-5 border-b border-slate-100 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
              <h3 class="text-lg font-bold text-slate-900">账号列表</h3>
              <p class="text-xs text-slate-500 mt-1"><?php echo $is_super_admin ? '超级管理员可以管理普通管理员和普通用户' : '普通管理员只能查看和管理普通用户'; ?></p>
            </div>
            <input type="search" oninput="filterUserRows(this.value)" placeholder="搜索账号或邮箱" class="w-full lg:w-64 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500">
          </div>

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-slate-500 text-xs">
                <tr><th class="text-left font-semibold px-5 sm:px-6 py-3">账号</th><th class="text-left font-semibold px-4 py-3">角色</th><th class="text-left font-semibold px-4 py-3">创建时间</th><th class="text-left font-semibold px-4 py-3">最后登录时间</th><th class="text-right font-semibold px-5 sm:px-6 py-3">操作</th></tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
              <?php foreach ($users_for_display as $user_info):
                  $username = $user_info['username'];
                  $verified_email = $verified_emails[$username] ?? '';
                  $target_role = $user_info['role'] ?? 'user';
                  $target_is_super = $username === 'admin' && $target_role === 'admin';
              ?>
                <tr data-user-row data-username="<?php echo htmlspecialchars(strtolower($username . ' ' . $verified_email), ENT_QUOTES, 'UTF-8'); ?>" class="hover:bg-slate-50/70 transition-colors">
                  <td class="px-5 sm:px-6 py-4">
                    <div class="flex items-center gap-3"><span class="w-9 h-9 rounded-xl bg-blue-50 text-blue-700 flex items-center justify-center font-bold">👤</span><div><p class="font-semibold text-slate-900"><?php echo htmlspecialchars($username); ?></p><?php if ($verified_email !== ''): ?><p class="text-xs text-slate-500 break-all"><?php echo htmlspecialchars($verified_email, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?><?php if ($username === $current_username): ?><p class="text-xs text-slate-400">当前登录账号</p><?php endif; ?></div></div>
                  </td>
                  <td class="px-4 py-4">
                    <?php if ($target_is_super): ?><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">超级管理员</span>
                    <?php elseif ($target_role === 'admin'): ?><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800">管理员</span>
                    <?php else: ?><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-slate-100 text-slate-700">普通用户</span><?php endif; ?>
                  </td>
                  <td class="px-4 py-4 text-slate-500 whitespace-nowrap"><?php echo !empty($user_info['created_at']) ? date('Y-m-d', (int)$user_info['created_at']) : '—'; ?></td>
                  <td class="px-4 py-4 whitespace-nowrap">
                    <?php if (!empty($user_info['last_login_at'])): ?>
                      <span class="text-slate-600"><?php echo (new DateTimeImmutable('@' . (int)$user_info['last_login_at']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d H:i:s'); ?></span>
                    <?php else: ?>
                      <span class="text-slate-400">从未登录</span>
                    <?php endif; ?>
                  </td>
                  <td class="px-5 sm:px-6 py-4">
                    <div class="flex items-center justify-end gap-2 whitespace-nowrap">
                      <button type="button" onclick="openChangePasswordModal(<?php echo htmlspecialchars(json_encode($username, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" class="text-blue-700 text-xs font-semibold bg-blue-50 hover:bg-blue-100 px-3 py-2 rounded-lg transition-all">修改密码</button>
                      <?php if ($username !== $current_username && !$target_is_super): ?>
                        <button type="button" onclick="openDeleteUserModal(<?php echo htmlspecialchars(json_encode($username, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)" class="text-rose-700 text-xs font-semibold bg-rose-50 hover:bg-rose-100 px-3 py-2 rounded-lg transition-all">删除</button>
                      <?php else: ?>
                        <span class="text-xs text-slate-400 px-2"><?php echo $target_is_super ? '受保护' : '当前账号'; ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <div class="border-t border-slate-100 bg-slate-50/60 px-5 sm:px-6 py-5">
            <h4 class="text-sm font-bold text-slate-900 mb-3">创建账号</h4>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
              <input type="text" id="newUsername" placeholder="账号" class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500">
              <input type="password" id="newPassword" placeholder="初始密码" class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500">
              <select id="newRole" class="bg-white border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500">
                <option value="user">普通用户</option>
                <?php if ($is_super_admin): ?><option value="admin">管理员</option><?php endif; ?>
              </select>
              <button type="button" onclick="createUser()" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 px-4 rounded-xl transition-all text-sm">创建账号</button>
            </div>
          </div>
        </section>






