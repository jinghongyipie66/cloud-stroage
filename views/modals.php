<?php
/** 分享、改密、删除、移动、加密、提示和预览弹窗。 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}
?>

  <!-- 分享配置弹窗 -->
  <div id="shareModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closeShareModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm relative z-10 animate-fade-in overflow-hidden">
      <div class="p-6 border-b border-slate-50 bg-gradient-to-r from-blue-50 to-indigo-50">
        <h3 class="text-lg font-bold text-slate-900">配置分享链接</h3>
        <p class="text-xs text-slate-500 mt-1 truncate" id="modalFileName"></p>
      </div>
      <div class="p-6 space-y-6">
        <input type="hidden" id="modalFilePath">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-3">链接有效期</label>
          <select id="expirySelect" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 text-sm font-medium outline-none focus:ring-2 focus:ring-blue-500">
            <option value="1">1 天后失效</option>
            <option value="7" selected>7 天后失效</option>
            <option value="30">30 天后失效</option>
            <option value="0">永久有效</option>
          </select>
        </div>
        <button onclick="createShare()" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-3 rounded-lg shadow-lg shadow-blue-200/50 transition-all">
          生成分享链接
        </button>
      </div>
    </div>
  </div>

  <!-- 当前用户修改自己的密码 -->
  <div id="ownPasswordModal" role="dialog" aria-modal="true" aria-labelledby="ownPasswordModalTitle" class="fixed inset-0 z-[120] hidden flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm" onclick="closeOwnPasswordModal()"></div>
    <div class="bg-white rounded-3xl shadow-2xl shadow-slate-900/20 border border-white/70 w-full max-w-md relative z-10 animate-fade-in overflow-hidden">
      <div class="relative overflow-hidden bg-gradient-to-br from-blue-600 via-blue-700 to-indigo-700 px-6 py-6 text-white">
        <div class="absolute -right-10 -top-10 w-36 h-36 rounded-full bg-white/10"></div>
        <div class="absolute right-12 -bottom-10 w-24 h-24 rounded-full bg-cyan-300/10"></div>
        <div class="relative flex items-start gap-4">
          <div class="w-12 h-12 rounded-2xl bg-white/15 border border-white/20 flex items-center justify-center text-2xl shadow-inner">🔐</div>
          <div class="flex-1 min-w-0">
            <h3 id="ownPasswordModalTitle" class="text-xl font-bold tracking-tight">修改登录密码</h3>
            <p class="text-blue-100 text-xs mt-1 leading-5">验证当前密码后即可设置新密码</p>
          </div>
          <button type="button" aria-label="关闭修改密码弹窗" onclick="closeOwnPasswordModal()" class="w-9 h-9 rounded-xl bg-white/10 hover:bg-white/20 border border-white/10 text-white text-lg transition-all">×</button>
        </div>
      </div>

      <form onsubmit="submitOwnPassword(event)" class="p-6 space-y-5">
        <div class="flex items-center gap-3 rounded-2xl bg-blue-50/80 border border-blue-100 px-4 py-3">
          <div class="w-10 h-10 rounded-xl bg-white text-blue-700 flex items-center justify-center shadow-sm">👤</div>
          <div class="min-w-0">
            <p class="text-[11px] text-slate-500">正在修改此账号</p>
            <p class="text-sm font-bold text-slate-900 truncate"><?php echo htmlspecialchars($current_username); ?> <span class="ml-1 text-xs font-medium text-blue-600"><?php echo htmlspecialchars($role_label); ?></span></p>
          </div>
        </div>

        <div class="space-y-4">
          <div>
            <label for="ownCurrentPassword" class="block text-xs font-semibold text-slate-700 mb-2">当前密码</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">🔑</span>
              <input type="password" id="ownCurrentPassword" autocomplete="current-password" required class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-11 pr-16 py-3 text-sm outline-none focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="输入当前登录密码">
              <button type="button" onclick="toggleOwnPasswordVisibility('ownCurrentPassword', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-500 hover:text-blue-700 px-2 py-1 rounded-lg">显示</button>
            </div>
          </div>

          <div>
            <label for="ownNewPassword" class="block text-xs font-semibold text-slate-700 mb-2">新密码</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">🛡️</span>
              <input type="password" id="ownNewPassword" autocomplete="new-password" minlength="6" maxlength="255" required oninput="updateOwnPasswordStrength()" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-11 pr-16 py-3 text-sm outline-none focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="至少 6 位，建议包含数字和符号">
              <button type="button" onclick="toggleOwnPasswordVisibility('ownNewPassword', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-500 hover:text-blue-700 px-2 py-1 rounded-lg">显示</button>
            </div>
            <div class="mt-2 flex items-center gap-2">
              <div class="h-1.5 flex-1 rounded-full bg-slate-100 overflow-hidden"><div id="ownPasswordStrengthBar" class="h-full w-0 rounded-full bg-slate-300 transition-all duration-300"></div></div>
              <span id="ownPasswordStrengthText" class="text-[11px] text-slate-400 w-12 text-right">未输入</span>
            </div>
          </div>

          <div>
            <label for="ownNewPasswordConfirm" class="block text-xs font-semibold text-slate-700 mb-2">确认新密码</label>
            <div class="relative">
              <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">✓</span>
              <input type="password" id="ownNewPasswordConfirm" autocomplete="new-password" minlength="6" maxlength="255" required oninput="updateOwnPasswordStrength()" class="w-full bg-slate-50 border border-slate-200 rounded-xl pl-11 pr-16 py-3 text-sm outline-none focus:bg-white focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" placeholder="再次输入新密码">
              <button type="button" onclick="toggleOwnPasswordVisibility('ownNewPasswordConfirm', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-semibold text-slate-500 hover:text-blue-700 px-2 py-1 rounded-lg">显示</button>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 text-[11px]">
          <div id="ownPasswordLengthHint" class="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2 text-slate-500">○ 至少 6 位</div>
          <div id="ownPasswordMatchHint" class="rounded-xl bg-slate-50 border border-slate-100 px-3 py-2 text-slate-500">○ 两次输入一致</div>
        </div>

        <div id="ownPasswordErrorMsg" role="alert" class="hidden bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium px-4 py-3 rounded-xl text-center"></div>

        <div class="flex gap-3 pt-1">
          <button type="button" onclick="closeOwnPasswordModal()" class="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 rounded-xl transition-all">取消</button>
          <button id="ownPasswordSubmitBtn" type="submit" class="flex-[1.35] bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-700 hover:to-indigo-700 text-white font-semibold py-3 rounded-xl shadow-lg shadow-blue-200/70 transition-all disabled:opacity-60 disabled:cursor-not-allowed">确认修改</button>
        </div>
        <p class="text-center text-[11px] text-slate-400">修改后页面会刷新安全凭证，不会影响已存储的文件</p>
      </form>
    </div>
  </div>
  <?php if ($is_admin): ?>
  <!-- 删除用户确认弹窗 -->
  <div id="deleteUserModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closeDeleteUserModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md relative z-10 animate-fade-in overflow-hidden">
      <div class="p-6 border-b border-rose-100 bg-gradient-to-r from-rose-50 to-amber-50">
        <h3 class="text-lg font-bold text-slate-900">删除用户</h3>
        <p class="text-sm text-slate-600 mt-2">即将删除账号：<span id="deleteUserName" class="font-bold text-rose-700"></span></p>
      </div>
      <div class="p-6 space-y-4">
        <div class="bg-rose-50 border border-rose-200 text-rose-700 text-sm px-4 py-3 rounded-xl">
          ⚠️ 如果选择删除全部文件，该用户目录下的所有文件和子目录都会永久删除，无法撤回。
        </div>
        <div class="bg-blue-50 border border-blue-200 text-blue-700 text-xs px-4 py-3 rounded-xl">
          选择保留时，文件会移到程序目录外的 <strong>upload_backup_retained_users</strong> 归档目录，不会被同名新账号继承。
        </div>
        <button type="button" onclick="submitDeleteUser(false)" class="w-full bg-amber-100 hover:bg-amber-200 text-amber-900 font-semibold py-3 rounded-xl transition-all">
          仅删除账号，将文件归档保留
        </button>
        <button type="button" onclick="submitDeleteUser(true)" class="w-full bg-rose-600 hover:bg-rose-700 text-white font-semibold py-3 rounded-xl transition-all shadow-lg shadow-rose-200">
          删除账号及全部文件
        </button>
        <button type="button" onclick="closeDeleteUserModal()" class="w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 rounded-xl transition-all">
          取消
        </button>
      </div>
    </div>
  </div>

  <!-- 修改用户密码弹窗 -->
  <div id="changePasswordModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closeChangePasswordModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm relative z-10 animate-fade-in overflow-hidden">
      <div class="p-6 border-b border-slate-50 bg-gradient-to-r from-blue-50 to-indigo-50">
        <h3 class="text-lg font-bold text-slate-900">修改密码</h3>
        <p class="text-xs text-slate-500 mt-1" id="changePasswordUsername"></p>
      </div>
      <div class="p-6 space-y-4">
        <input type="password" id="changePasswordInput" placeholder="输入新密码" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-blue-500 transition-all">
        <div id="changePasswordErrorMsg" class="hidden bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium px-4 py-2 rounded-lg text-center"></div>
        <button onclick="submitChangePassword()" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-3 rounded-lg shadow-lg shadow-blue-200/50 transition-all">
          🔑 确认修改
        </button>
      </div>
    </div>
  </div>

  <?php endif; ?>

  <!-- 移动文件弹窗 -->
  <div id="moveModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closeMoveModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm relative z-10 animate-fade-in overflow-hidden">
      <div class="p-6 border-b border-slate-50 bg-gradient-to-r from-indigo-50 to-blue-50">
        <h3 class="text-lg font-bold text-slate-900">移动文件</h3>
        <p class="text-xs text-slate-500 mt-1 truncate" id="moveModalFileName"></p>
      </div>
      <div class="p-6 space-y-6">
        <div>
          <label class="block text-xs font-semibold text-slate-600 mb-3">选择目标位置</label>
          <select id="moveDestSelect" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 text-sm font-medium outline-none focus:ring-2 focus:ring-indigo-500">
            <?php if ($is_admin): ?>
              <option value="">🏠 根目录</option>
            <?php endif; ?>
            <?php foreach ($all_folders as $folder_opt): ?>
              <option value="<?php echo htmlspecialchars($folder_opt, ENT_QUOTES); ?>">📁 <?php echo htmlspecialchars($folder_opt); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="bg-amber-50 border border-amber-200 text-amber-700 text-xs font-medium px-4 py-2.5 rounded-lg">
          ⚠️ 移动后，该文件已生成的分享链接将失效
        </div>
        <div id="moveErrorMsg" class="hidden bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium px-4 py-2 rounded-lg text-center"></div>
        <button onclick="submitMoveFile()" class="w-full bg-gradient-to-r from-indigo-600 to-blue-600 hover:from-indigo-700 hover:to-blue-700 text-white font-semibold py-3 rounded-lg shadow-lg shadow-indigo-200/50 transition-all">
          📦 确认移动
        </button>
      </div>
    </div>
  </div>

  <!-- 文件加密/改密弹窗 -->
  <div id="encryptModal" class="fixed inset-0 z-50 hidden flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closeEncryptModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm relative z-10 animate-fade-in overflow-hidden">
      <div class="p-6 border-b border-slate-50 bg-gradient-to-r from-purple-50 to-indigo-50">
        <h3 class="text-lg font-bold text-slate-900">文件加密</h3>
        <p class="text-xs text-slate-500 mt-1 truncate" id="encryptModalFileName"></p>
      </div>
      <div class="p-6 space-y-4">
        <input type="password" id="encryptPasswordInput" placeholder="设置提取密码" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-4 py-3 text-sm outline-none focus:ring-2 focus:ring-purple-500 transition-all">
        <div id="encryptErrorMsg" class="hidden bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium px-4 py-2 rounded-lg text-center"></div>
        <button onclick="submitEncryptFile()" class="w-full bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-700 hover:to-indigo-700 text-white font-semibold py-3 rounded-lg shadow-lg shadow-purple-200/50 transition-all">
          🔐 保存密码
        </button>
        <button id="removePasswordBtn" onclick="submitRemovePassword()" class="hidden w-full bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold py-3 rounded-lg transition-all">
          🔓 取消加密（移除密码）
        </button>
      </div>
    </div>
  </div>

  <!-- 全站主题提示/确认弹窗 -->
  <div id="appDialogModal" role="dialog" aria-modal="true" aria-labelledby="appDialogTitle" class="fixed inset-0 z-[400] hidden flex items-center justify-center p-4">
    <span aria-hidden="true" class="hidden from-emerald-500 to-teal-600 hover:from-emerald-600 hover:to-teal-700 shadow-emerald-200/70 from-amber-500 to-orange-600 hover:from-amber-600 hover:to-orange-700 shadow-amber-200/70 from-rose-600 to-red-700 hover:from-rose-700 hover:to-red-800 shadow-rose-200/70"></span>
    <div class="absolute inset-0 bg-slate-950/45 backdrop-blur-sm" onclick="resolveAppDialog(false)"></div>
    <div class="relative z-10 w-full max-w-sm overflow-hidden rounded-3xl border border-white/70 bg-white shadow-2xl shadow-slate-900/20 animate-fade-in">
      <div id="appDialogHeader" class="relative overflow-hidden bg-gradient-to-br from-blue-600 to-indigo-700 px-6 py-5 text-white">
        <div class="absolute -right-8 -top-8 h-28 w-28 rounded-full bg-white/10"></div>
        <div class="relative flex items-start gap-4">
          <div id="appDialogIconWrap" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/20 bg-white/15 text-xl shadow-inner">
            <span id="appDialogIcon">ℹ</span>
          </div>
          <div class="min-w-0 flex-1 pt-0.5">
            <h3 id="appDialogTitle" class="text-lg font-bold tracking-tight">提示</h3>
            <p id="appDialogSubtitle" class="mt-1 text-xs text-white/75">瑞锦云存储安全提示</p>
          </div>
          <button type="button" aria-label="关闭提示弹窗" onclick="resolveAppDialog(false)" class="flex h-9 w-9 items-center justify-center rounded-xl border border-white/10 bg-white/10 text-lg text-white transition-all hover:bg-white/20">×</button>
        </div>
      </div>
      <div class="px-6 py-6">
        <p id="appDialogMessage" class="whitespace-pre-line break-words text-sm leading-6 text-slate-600"></p>
        <div id="appDialogNotice" class="mt-4 hidden rounded-xl border px-4 py-3 text-xs leading-5"></div>
        <div class="mt-6 flex gap-3">
          <button id="appDialogCancelBtn" type="button" onclick="resolveAppDialog(false)" class="hidden flex-1 rounded-xl bg-slate-100 px-4 py-3 text-sm font-semibold text-slate-700 transition-all hover:bg-slate-200">取消</button>
          <button id="appDialogConfirmBtn" type="button" onclick="resolveAppDialog(true)" class="flex-1 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 px-4 py-3 text-sm font-semibold text-white shadow-lg shadow-blue-200/70 transition-all hover:from-blue-700 hover:to-indigo-700">确定</button>
        </div>
      </div>
    </div>
  </div>

  <!-- 成功提示 -->
  <div id="toast" class="fixed bottom-8 left-1/2 -translate-x-1/2 z-[500] hidden">
    <div class="bg-slate-900 text-white text-xs font-semibold px-6 py-3 rounded-full shadow-2xl animate-fade-in">✅ 链接已复制到剪贴板</div>
  </div>

  <!-- 预览弹窗 -->
  <div id="previewModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-4">
    <div class="modal-overlay absolute inset-0" onclick="closePreviewModal()"></div>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] relative z-10 animate-fade-in flex flex-col overflow-hidden">
      <div class="p-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
        <h3 class="text-sm font-bold text-slate-800 truncate pr-4" id="previewTitle">文件预览</h3>
        <button onclick="closePreviewModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold">✕</button>
      </div>
      <div class="flex-1 overflow-auto p-4 bg-white" id="previewContent">
        <!-- 预览内容将在这里动态生成 -->
      </div>
    </div>
  </div>

