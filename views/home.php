<?php
/** 首页文件管理视图。 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
        <!-- 统计卡片 -->
    <div class="cs-stats grid grid-cols-2 md:grid-cols-4 gap-3 mb-8 animate-fade-in">
      <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:shadow-md transition-all">
        <p class="text-xs text-slate-500 font-medium">文件夹</p>
        <p class="text-2xl font-bold text-slate-900 mt-1"><?php echo count($folders_list); ?></p>
      </div>
      <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:shadow-md transition-all">
        <p class="text-xs text-slate-500 font-medium">文件数</p>
        <p class="text-2xl font-bold text-slate-900 mt-1"><?php echo count($files_list); ?></p>
      </div>
      <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:shadow-md transition-all">
        <p class="text-xs text-slate-500 font-medium">总大小</p>
        <p class="text-lg font-bold text-slate-900 mt-1"><?php echo formatSize($total_size); ?></p>
      </div>
      <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-xl border border-blue-200 p-4 shadow-sm hover:shadow-md transition-all">
        <p class="text-xs text-blue-600 font-medium">活跃分享</p>
        <p class="text-lg font-bold text-blue-900 mt-1"><?php echo $active_visible_share_count; ?></p>
      </div>
    </div>

    <!-- 错误提示 -->
    <?php if ($error_msg): ?>
    <div class="mb-4 bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium px-4 py-3 rounded-xl text-center animate-fade-in">
        ⚠️ <?php echo htmlspecialchars($error_msg); ?>
    </div>
    <?php endif; ?>

    <!-- 批量操作结果提示 -->
    <?php if (isset($_SESSION['batch_msg'])): ?>
    <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium px-4 py-3 rounded-xl text-center animate-fade-in">
        ✅ <?php echo htmlspecialchars($_SESSION['batch_msg']); unset($_SESSION['batch_msg']); ?>
    </div>
    <?php endif; ?>

    <div class="animate-fade-in">
      
      <!-- 左侧：文件管理 (占2列) -->
      <div class="cs-workbench space-y-6">
        <div class="cs-files-panel bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">

          <!-- 导航栏 -->
          <div class="cs-folder-toolbar bg-gradient-to-r from-slate-50 to-blue-50 border-b border-slate-200 px-6 py-4">
            <div class="flex items-center justify-between gap-4">
              <div class="flex items-center gap-2 text-sm font-medium text-slate-700 min-w-0">
                <a href="index.php" class="text-blue-600 hover:text-blue-700 font-semibold transition-colors">🏠 首页</a>
                <?php if ($current_sub !== ''): ?>
                  <span class="text-slate-300">/</span>
                  <span class="text-slate-600 truncate"><?php echo htmlspecialchars($current_sub); ?></span>
                <?php endif; ?>
              </div>

              <form action="" method="POST" class="cs-new-folder flex items-center gap-2 shrink-0">
                <?php echo csrf_field(); ?>
                <input type="text" name="new_folder" placeholder="输入文件夹名称" required 
                       class="bg-white border border-slate-200 rounded-lg text-sm px-3 py-2 w-32 sm:w-40 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold text-sm px-4 py-2 rounded-lg transition-all shadow-sm hover:shadow-md cursor-pointer">
                  ➕ 新建
                </button>
              </form>
            </div>
          </div>

          <!-- 上传区 -->
          <details class="cs-upload-panel" id="upload-panel"><summary><span>↑ 上传文件</span><small>点击展开，选择或拖拽文件</small><span aria-hidden="true">⌄</span></summary><div class="cs-upload-body">
            <form id="uploadForm" class="space-y-4">
              <?php echo csrf_field(); ?>
              <input type="hidden" id="currentDirParam" value="<?php echo htmlspecialchars($current_sub); ?>">

              <div class="relative group border-2 border-dashed border-blue-200 rounded-xl p-6 sm:p-8 text-center hover:border-blue-400 transition-all cursor-pointer bg-blue-50/30 hover:bg-blue-50/60" id="dropZone">
                <input type="file" name="file" id="file" class="absolute inset-0 w-full h-full opacity-0 cursor-pointer" required onchange="updateFileNameHint()">
                <div class="space-y-3">
                  <div class="text-3xl">📤</div>
                  <div class="text-sm font-semibold text-slate-700" id="fileNameHint">
                    点击或拖拽文件到这里上传
                  </div>
                  <div class="text-xs text-slate-500">单个文件最大 100MB；同名文件会提示，不会覆盖。</div>
                </div>
              </div>

              <!-- 密码设置选项 -->
              <div class="bg-amber-50 border border-amber-200 rounded-xl p-4">
                <label class="flex items-center gap-3 cursor-pointer">
                  <input type="checkbox" id="enablePassword" name="enable_password" class="w-4 h-4 text-amber-600 rounded focus:ring-2 focus:ring-amber-500">
                  <span class="text-sm font-medium text-amber-900">为此文件设置下载密码 🔐</span>
                </label>
                <input type="password" id="passwordInput" name="password" placeholder="输入下载密码（可选）" 
                       class="hidden mt-3 w-full border border-amber-300 rounded-lg text-sm px-3 py-2 bg-white focus:outline-none focus:ring-2 focus:ring-amber-500 focus:border-transparent transition-all">
              </div>

              <div class="space-y-2">
                <div class="hidden bg-rose-50 border border-rose-200 text-rose-700 text-sm font-medium px-4 py-3 rounded-xl text-center" id="error-message"></div>
                <div class="hidden bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-medium px-4 py-3 rounded-xl text-center" id="success-message"></div>
                <div class="hidden space-y-2 bg-slate-50 p-4 rounded-xl border border-slate-200" id="progressContainer">
                  <div class="flex justify-between text-xs font-semibold text-slate-600 mb-2">
                    <span id="progressStatus">正在上传...</span>
                    <span id="progressPercent">0%</span>
                  </div>
                  <div class="w-full bg-slate-200 rounded-full h-2 overflow-hidden"><div id="progressBar" class="bg-gradient-to-r from-blue-500 to-blue-600 h-2 rounded-full transition-all" style="width: 0%"></div></div>
                </div>
              </div>

              <button type="submit" id="submitBtn" class="w-full bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white font-semibold py-3 px-4 rounded-xl shadow-md shadow-blue-200/50 transition-all cursor-pointer text-sm">
                🚀 立即上传
              </button>
            </form>
          </div></details>

          <!-- 智能搜索 -->
          <div class="cs-search-panel border-b border-slate-200 bg-gradient-to-b from-purple-50/40 to-white px-6 py-5">
            <form method="GET" action="index.php" class="space-y-3">
              <input type="hidden" name="dir" value="<?php echo htmlspecialchars($current_sub, ENT_QUOTES, 'UTF-8'); ?>">
              <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
              <input type="hidden" name="sort" value="<?php echo htmlspecialchars($file_sort, ENT_QUOTES, 'UTF-8'); ?>">
              <div class="flex items-center gap-2">
                <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="🔍 搜索文件名（支持模糊搜索 / 拼音搜索，如输入 zhaopian 匹配“照片”）"
                       class="flex-1 bg-white border border-slate-200 rounded-lg text-sm px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent transition-all">
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold text-sm px-5 py-2.5 rounded-lg transition-all shadow-sm cursor-pointer shrink-0">搜索</button>
              </div>
              <div class="flex items-center gap-2 flex-wrap">
                <select name="time" class="bg-white border border-slate-200 rounded-lg text-xs px-3 py-2 outline-none focus:ring-2 focus:ring-purple-500">
                  <option value="all" <?php echo $search_time === 'all' ? 'selected' : ''; ?>>📅 全部时间</option>
                  <option value="today" <?php echo $search_time === 'today' ? 'selected' : ''; ?>>今天</option>
                  <option value="week" <?php echo $search_time === 'week' ? 'selected' : ''; ?>>最近一周</option>
                  <option value="month" <?php echo $search_time === 'month' ? 'selected' : ''; ?>>最近一月</option>
                </select>
                <select name="size" class="bg-white border border-slate-200 rounded-lg text-xs px-3 py-2 outline-none focus:ring-2 focus:ring-purple-500">
                  <option value="all" <?php echo $search_size === 'all' ? 'selected' : ''; ?>>💾 全部大小</option>
                  <option value="small" <?php echo $search_size === 'small' ? 'selected' : ''; ?>>小于 1MB</option>
                  <option value="medium" <?php echo $search_size === 'medium' ? 'selected' : ''; ?>>1MB ~ 10MB</option>
                  <option value="large" <?php echo $search_size === 'large' ? 'selected' : ''; ?>>10MB ~ 100MB</option>
                  <option value="huge" <?php echo $search_size === 'huge' ? 'selected' : ''; ?>>大于 100MB</option>
                </select>
                <select name="type" class="bg-white border border-slate-200 rounded-lg text-xs px-3 py-2 outline-none focus:ring-2 focus:ring-purple-500">
                  <option value="all" <?php echo $search_type === 'all' ? 'selected' : ''; ?>>📁 全部格式</option>
                  <option value="图片" <?php echo $search_type === '图片' ? 'selected' : ''; ?>>图片</option>
                  <option value="视频" <?php echo $search_type === '视频' ? 'selected' : ''; ?>>视频</option>
                  <option value="音频" <?php echo $search_type === '音频' ? 'selected' : ''; ?>>音频</option>
                  <option value="文档" <?php echo $search_type === '文档' ? 'selected' : ''; ?>>文档</option>
                  <option value="压缩包" <?php echo $search_type === '压缩包' ? 'selected' : ''; ?>>压缩包</option>
                  <option value="其他" <?php echo $search_type === '其他' ? 'selected' : ''; ?>>其他</option>
                </select>
                <?php if ($is_searching): ?>
                  <a href="index.php?dir=<?php echo urlencode($current_sub); ?>&amp;sort=<?php echo rawurlencode($file_sort); ?>&amp;per_page=<?php echo $items_per_page; ?>" class="text-xs text-slate-500 hover:text-slate-700 font-semibold px-3 py-2">✕ 清除搜索</a>
                <?php endif; ?>
              </div>
            </form>
          </div>

          <!-- 文件列表 -->
          <div class="p-6">
            <div class="mb-4 flex items-center justify-between gap-3 flex-wrap">
              <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                <?php if ($is_searching): ?>
                  🔍 搜索结果
                  <span class="text-xs font-normal text-slate-500 bg-slate-100 px-2 py-1 rounded-full"><?php echo count($search_results); ?> 项</span>
                <?php else: ?>
                  📂 内容管理
                  <span class="text-xs font-normal text-slate-500 bg-slate-100 px-2 py-1 rounded-full">
                    <?php echo count($folders_list) + count($files_list); ?> 项
                  </span>
                <?php endif; ?>
              </h2>
              <form method="GET" action="index.php" class="flex items-center gap-2 flex-wrap max-w-full" id="fileSortForm">
                <input type="hidden" name="dir" value="<?php echo htmlspecialchars($current_sub, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="per_page" value="<?php echo $items_per_page; ?>">
                <input type="hidden" name="q" value="<?php echo htmlspecialchars($search_query, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="time" value="<?php echo htmlspecialchars($search_time, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="size" value="<?php echo htmlspecialchars($search_size, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="type" value="<?php echo htmlspecialchars($search_type, ENT_QUOTES, 'UTF-8'); ?>">
                <label for="fileSortSelect" class="text-sm text-slate-500">排序</label>
                <select id="fileSortSelect" name="sort" onchange="this.form.submit()" class="min-w-0 max-w-full bg-white border border-slate-200 rounded-lg text-sm px-3 py-2 outline-none focus:ring-2 focus:ring-indigo-500">
                  <?php foreach ($file_sort_options as $sort_value => $sort_label): ?>
                    <option value="<?php echo $sort_value; ?>" <?php echo $file_sort === $sort_value ? 'selected' : ''; ?>><?php echo $sort_label; ?></option>
                  <?php endforeach; ?>
                </select>
                <noscript><button type="submit" class="text-sm text-blue-700 px-3 py-2">应用排序</button></noscript>
              </form>
            </div>
            <?php if (!$is_searching): ?><p class="text-xs text-slate-500 mb-3">文件夹置顶，排序仅作用于文件；时间为最后修改时间。</p><?php endif; ?>

            <?php if ($is_searching): ?>
            <!-- ===== 搜索结果 + 批量操作 ===== -->
            <div class="flex items-center justify-between gap-2 mb-3 bg-slate-50 border border-slate-200 rounded-lg px-4 py-2.5 flex-wrap">
              <label class="flex items-center gap-2 text-xs font-semibold text-slate-600 cursor-pointer">
                <input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll(this)" class="w-4 h-4">
                全选
              </label>
              <div class="flex items-center gap-2">
                <select id="batchMoveDestSelect" class="bg-white border border-slate-200 rounded-lg text-xs px-2 py-1.5 outline-none focus:ring-2 focus:ring-indigo-500">
                  <?php if ($is_admin): ?><option value="">🏠 根目录</option><?php endif; ?>
                  <?php foreach ($all_folders as $folder_opt): ?>
                    <option value="<?php echo htmlspecialchars($folder_opt, ENT_QUOTES); ?>">📁 <?php echo htmlspecialchars($folder_opt); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" onclick="batchMove()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 py-1.5 px-3 rounded-lg transition-all">📦 批量移动</button>
                <button type="button" onclick="batchDelete()" class="text-xs font-semibold text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 py-1.5 px-3 rounded-lg transition-all">🗑️ 批量删除</button>
              </div>
            </div>

            <div class="space-y-2">
              <?php if (empty($search_results)): ?>
                <div class="text-center py-12 text-slate-400">
                  <div class="text-4xl mb-2">🔍</div>
                  <div class="text-sm">没有找到匹配的文件</div>
                </div>
              <?php else: ?>
                <?php foreach ($paged_search_results as $r):
                  $rp = $r['path'];
                  $r_has_password = isset($passwords_data[$rp]);
                  $r_owner = $owners_data[$rp] ?? null;
                  $r_is_own = ($current_username !== '' && strpos($rp, $current_username . '/') === 0);
                  $r_can_manage = $is_admin || $r_is_own || ($r_owner !== null && $r_owner === ($_SESSION['username'] ?? ''));
                  $r_ext = strtolower(pathinfo($r['name'], PATHINFO_EXTENSION));
                  $r_icon = '📄';
                  if (in_array($r_ext, ['jpg','jpeg','png','gif','webp','svg'])) $r_icon = '🖼️';
                  elseif (in_array($r_ext, ['mp4','avi','mov','mkv','webm'])) $r_icon = '🎬';
                  elseif (in_array($r_ext, ['mp3','wav','flac','aac','m4a'])) $r_icon = '🎵';
                  elseif ($r_ext === 'pdf') $r_icon = '📕';
                  elseif (in_array($r_ext, ['zip','rar','7z','tar','gz'])) $r_icon = '📦';
                  $r_can_thumbnail = in_array($r_ext, ['jpg','jpeg','png','gif','webp','bmp','mp4','avi','mov','mkv','webm','m4v','3gp'], true);
                  $r_thumbnail_url = (!$r_has_password && $r_can_thumbnail)
                    ? 'thumbnail.php?file=' . rawurlencode($rp)
                    : '';
                ?>
                <div class="cs-file-row flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-white hover:bg-slate-50/50 rounded-lg border border-slate-200 transition-all gap-3">
                  <div class="flex items-center gap-3 min-w-0 flex-1">
                    <input type="checkbox" class="batch-checkbox w-4 h-4 shrink-0" value="<?php echo htmlspecialchars($rp, ENT_QUOTES); ?>" <?php echo $r_can_manage ? '' : 'disabled title="无权限"'; ?>>
                    <?php if ($r_thumbnail_url !== ''): ?>
                      <span class="w-12 h-12 shrink-0 rounded-lg overflow-hidden bg-slate-100 border border-slate-200 flex items-center justify-center">
                        <img src="<?php echo htmlspecialchars($r_thumbnail_url, ENT_QUOTES); ?>" alt="" loading="lazy" decoding="async" class="w-full h-full object-cover" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                        <span hidden class="text-xl"><?php echo $r_icon; ?></span>
                      </span>
                    <?php else: ?>
                      <span class="text-xl shrink-0"><?php echo $r_icon; ?></span>
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                      <p class="text-slate-800 font-medium truncate"><?php echo htmlspecialchars($r['name']); ?></p>
                      <div class="flex items-center gap-2 mt-0.5 flex-wrap">
                        <span class="text-xs text-slate-400">📍 <?php echo htmlspecialchars($rp); ?></span>
                        <span class="text-xs text-slate-300">•</span>
                        <span class="text-xs text-slate-400"><?php echo formatSize($r['size']); ?></span>
                        <span class="text-xs text-slate-300">•</span>
                        <span class="text-xs text-slate-400"><?php echo date('Y-m-d', $r['mtime']); ?></span>
                        <?php if ($r_has_password): ?><span class="text-xs bg-amber-500 text-amber-700 px-2 py-0.5 rounded-full font-semibold">🔐 已保护</span><?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="cs-file-actions flex items-center gap-2 self-end sm:self-center shrink-0">
                    <a href="download.php?file=<?php echo urlencode($rp); ?>" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 py-2 px-3 rounded-lg transition-all">⬇️ 下载</a>
                    <?php if ($r_can_manage): ?>
                      <button type="button" onclick="openMoveModal('<?php echo htmlspecialchars($rp, ENT_QUOTES); ?>')" class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 py-2 px-3 rounded-lg transition-all">📦 移动</button>
                      <button type="button" onclick="deleteFile('<?php echo htmlspecialchars($rp); ?>')" class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 py-2 px-3 rounded-lg transition-all">🗑️ 删除</button>
                    <?php endif; ?>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
            <?php else: ?>
              <div class="space-y-2">
              <?php if ($current_sub !== ''):
                  $parent_dir = dirname($current_sub);
                  $parent_link = ($parent_dir === '.' || $parent_dir === '/') ? '' : $parent_dir;
              ?>
                <div class="flex items-center justify-between p-4 bg-blue-50/50 hover:bg-blue-50 rounded-lg border border-blue-500 transition-all group hover:shadow-sm">
                  <div class="flex items-center gap-3 min-w-0 flex-1">
                    <span class="text-xl shrink-0">🔙</span>
                    <a href="index.php?dir=<?php echo urlencode($parent_link); ?>&amp;sort=<?php echo rawurlencode($file_sort); ?>&amp;per_page=<?php echo $items_per_page; ?>" class="text-blue-600 font-semibold truncate hover:text-blue-800 transition-colors">返回上级目录</a>
                  </div>
                </div>
              <?php endif; ?>

              <!-- 文件夹列表 -->
              <div class="cs-folder-grid">
              <?php foreach ($paged_folders_list as $folder):
                  $next_link = $current_sub === '' ? $folder : $current_sub . '/' . $folder;
                  $folder_full_path = $target_dir . $folder;
                  $folder_has_contents = directoryHasContents($folder_full_path);
                  $folder_is_protected = isProtectedStorageFolder($next_link);
                  $folder_path_js = htmlspecialchars(json_encode($next_link, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8');
              ?>
                <div class="cs-folder-card flex items-center justify-between p-4 bg-slate-50/50 hover:bg-slate-50 rounded-lg border border-slate-200 transition-all group hover:shadow-sm gap-3">
                  <div class="flex items-center gap-3 min-w-0 flex-1">
                    <span class="text-xl shrink-0">📁</span>
                    <a href="index.php?dir=<?php echo urlencode($next_link); ?>&amp;sort=<?php echo rawurlencode($file_sort); ?>&amp;per_page=<?php echo $items_per_page; ?>" class="text-slate-900 font-semibold truncate hover:text-blue-600 transition-colors">
                      <?php echo htmlspecialchars($folder); ?>
                    </a>
                  </div>
                  <div class="flex items-center gap-2 shrink-0">
                    <span class="text-xs bg-slate-200 text-slate-600 py-1 px-2.5 rounded-full font-medium">文件夹</span>
                    <?php if (!$folder_is_protected): ?>
                      <button type="button" onclick="deleteFolder(<?php echo $folder_path_js; ?>, <?php echo $folder_has_contents ? 'true' : 'false'; ?>)" class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 py-1.5 px-2.5 rounded-lg transition-all">🗑️ 删除</button>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
              </div>

              <!-- 文件列表 -->
              <?php
                foreach ($paged_files_list as $file) {
                    $relative_file_path = $current_sub === '' ? $file : $current_sub . '/' . $file;
                    $file_full_path = $target_dir . $file;
                    
                    if (file_exists($file_full_path)) {
                        $fileSize = formatSize(@filesize($file_full_path) ?: 0);
                        $fileTime = date('Y-m-d', @filemtime($file_full_path) ?: time());
                    } else {
                        $fileSize = '未知大小';
                        $fileTime = '未知';
                    }

                    $has_password = isset($passwords_data[$relative_file_path]);

                    // 获取文件类型图标
                    $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $icon = '📄';
                    if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'])) $icon = '🖼️';
                    elseif (in_array($file_ext, ['mp4', 'avi', 'mov', 'mkv', 'webm'])) $icon = '🎬';
                    elseif (in_array($file_ext, ['mp3', 'wav', 'flac', 'aac', 'm4a'])) $icon = '🎵';
                    elseif (in_array($file_ext, ['pdf'])) $icon = '📕';
                    elseif (in_array($file_ext, ['zip', 'rar', '7z', 'tar', 'gz'])) $icon = '📦';
                    elseif (in_array($file_ext, ['doc', 'docx', 'txt', 'md'])) $icon = '📝';
                    elseif (in_array($file_ext, ['xls', 'xlsx', 'csv'])) $icon = '📊';
                    $can_thumbnail = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'mp4', 'avi', 'mov', 'mkv', 'webm', 'm4v', '3gp'], true);
                    $thumbnail_url = (!$has_password && $can_thumbnail)
                        ? 'thumbnail.php?file=' . rawurlencode($relative_file_path)
                        : '';

                    echo '<div class="cs-file-row flex flex-col sm:flex-row sm:items-center justify-between p-4 bg-white hover:bg-slate-50/50 rounded-lg border border-slate-200 transition-all group hover:shadow-sm gap-3">';
                    echo '  <div class="flex items-center gap-3 min-w-0 flex-1">';
                    if ($thumbnail_url !== '') {
                        echo '    <span class="w-12 h-12 shrink-0 rounded-lg overflow-hidden bg-slate-100 border border-slate-200 flex items-center justify-center">';
                        echo '      <img src="' . htmlspecialchars($thumbnail_url, ENT_QUOTES) . '" alt="" loading="lazy" decoding="async" class="w-full h-full object-cover" onerror="this.hidden=true;this.nextElementSibling.hidden=false">';
                        echo '      <span hidden class="text-xl">' . $icon . '</span>';
                        echo '    </span>';
                    } else {
                        echo '    <span class="text-xl shrink-0">' . $icon . '</span>';
                    }
                    echo '    <div class="min-w-0 flex-1">';
                    echo '      <p class="text-slate-800 font-medium truncate">' . htmlspecialchars($file) . '</p>';
                    echo '      <div class="flex items-center gap-2 mt-0.5">';
                    echo '        <span class="text-xs text-slate-400">' . $fileSize . '</span>';
                    echo '        <span class="text-xs text-slate-300">•</span>';
                    echo '        <span class="text-xs text-slate-400">' . $fileTime . '</span>';
                    if ($has_password) {
                        echo '        <span class="text-xs bg-amber-500 text-amber-700 px-2 py-0.5 rounded-full font-semibold">🔐 已保护</span>';
                    }
                    echo '      </div>';
                    echo '    </div>';
                    echo '  </div>';
                    echo '  <div class="cs-file-actions flex items-center gap-2 self-end sm:self-center shrink-0">';
                    // 所有用户都可以分享
                    echo '    <button type="button" onclick="openShareModal(\'' . htmlspecialchars($relative_file_path) . '\')" class="inline-flex items-center gap-1 text-xs font-semibold text-green-600 hover:text-green-800 bg-green-50 hover:bg-green-100 py-2 px-3 rounded-lg transition-all">🔗 分享</button>';
                    
                    // 预览功能：仅支持 10MB 以下文件
                    $raw_size = @filesize($file_full_path) ?: 0;
                    if ($raw_size < 10 * 1024 * 1024) {
                        echo '    <button type="button" onclick="previewFile(\'' . htmlspecialchars($relative_file_path) . '\')" class="inline-flex items-center gap-1 text-xs font-semibold text-amber-600 hover:text-amber-800 bg-amber-50 hover:bg-amber-100 py-2 px-3 rounded-lg transition-all">👁️ 预览</button>';
                    }
                    
                    echo '    <a href="download.php?file=' . urlencode($relative_file_path) . '" class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 py-2 px-3 rounded-lg transition-all">⬇️ 下载</a>';
                    $file_owner = $owners_data[$relative_file_path] ?? null;
                    $is_own_file = ($current_username !== '' && strpos($relative_file_path, $current_username . '/') === 0);
                    $can_manage_file = $is_admin || $is_own_file || ($file_owner !== null && $file_owner === ($_SESSION['username'] ?? ''));
                    if ($can_manage_file) {
                        echo '    <button type="button" onclick="openMoveModal(\'' . htmlspecialchars($relative_file_path, ENT_QUOTES) . '\')" class="inline-flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 bg-indigo-50 hover:bg-indigo-100 py-2 px-3 rounded-lg transition-all">📦 移动</button>';
                        echo '    <button type="button" onclick="openEncryptModal(\'' . htmlspecialchars($relative_file_path, ENT_QUOTES) . '\', ' . ($has_password ? 'true' : 'false') . ')" class="inline-flex items-center gap-1 text-xs font-semibold text-purple-600 hover:text-purple-800 bg-purple-50 hover:bg-purple-100 py-2 px-3 rounded-lg transition-all">' . ($has_password ? '🔒 改密' : '🔐 加密') . '</button>';
                    }
                    if ($is_admin || $is_own_file) {
                        echo '    <button type="button" onclick="deleteFile(\'' . htmlspecialchars($relative_file_path) . '\')" class="inline-flex items-center gap-1 text-xs font-semibold text-rose-600 hover:text-rose-800 bg-rose-50 hover:bg-rose-100 py-2 px-3 rounded-lg transition-all">🗑️ 删除</button>';
                    }
                    echo '  </div>';
                    echo '</div>';
                }

                if (empty($folders_list) && empty($files_list)) {
                    echo '<div class="text-center py-12 text-slate-400">';
                    echo '  <div class="text-4xl mb-2">📭</div>';
                    echo '  <div class="text-sm">当前目录下空空如也</div>';
                    echo '</div>';
                }
              ?>
              </div>
            <?php endif; ?>

            <?php if ($pagination_total_items > 0): ?>
            <div class="mt-5 flex flex-col lg:flex-row lg:items-center justify-between gap-3 border-t border-slate-100 pt-4">
              <div class="text-xs text-slate-500">
                显示第 <?php echo $pagination_from; ?>–<?php echo $pagination_to; ?> 项，共 <?php echo $pagination_total_items; ?> 项
              </div>
              <div class="flex flex-wrap items-center gap-2">
                <label for="perPageSelect" class="text-xs text-slate-500">每页</label>
                <select id="perPageSelect" onchange="window.location.href=this.value" class="bg-white border border-slate-200 rounded-lg text-xs px-2 py-1.5 outline-none focus:ring-2 focus:ring-indigo-500">
                  <?php foreach ($allowed_per_page as $per_page_option): ?>
                    <option value="<?php echo htmlspecialchars(buildPaginationUrl(1, $per_page_option), ENT_QUOTES); ?>" <?php echo $items_per_page === $per_page_option ? 'selected' : ''; ?>><?php echo $per_page_option; ?> 项</option>
                  <?php endforeach; ?>
                </select>

                <?php if ($pagination_total_pages > 1): ?>
                  <a href="<?php echo htmlspecialchars(buildPaginationUrl(max(1, $current_page - 1)), ENT_QUOTES); ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 <?php echo $current_page <= 1 ? 'pointer-events-none text-slate-300 bg-slate-50' : 'text-slate-600 hover:bg-slate-50'; ?>">上一页</a>
                  <?php
                    $page_start = max(1, $current_page - 2);
                    $page_end = min($pagination_total_pages, $current_page + 2);
                  ?>
                  <?php if ($page_start > 1): ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl(1), ENT_QUOTES); ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">1</a>
                    <?php if ($page_start > 2): ?><span class="text-xs text-slate-400">…</span><?php endif; ?>
                  <?php endif; ?>
                  <?php for ($page_number = $page_start; $page_number <= $page_end; $page_number++): ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($page_number), ENT_QUOTES); ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg border <?php echo $page_number === $current_page ? 'border-indigo-500 bg-indigo-500 text-white' : 'border-slate-200 text-slate-600 hover:bg-slate-50'; ?>"><?php echo $page_number; ?></a>
                  <?php endfor; ?>
                  <?php if ($page_end < $pagination_total_pages): ?>
                    <?php if ($page_end < $pagination_total_pages - 1): ?><span class="text-xs text-slate-400">…</span><?php endif; ?>
                    <a href="<?php echo htmlspecialchars(buildPaginationUrl($pagination_total_pages), ENT_QUOTES); ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50"><?php echo $pagination_total_pages; ?></a>
                  <?php endif; ?>
                  <a href="<?php echo htmlspecialchars(buildPaginationUrl(min($pagination_total_pages, $current_page + 1)), ENT_QUOTES); ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-slate-200 <?php echo $current_page >= $pagination_total_pages ? 'pointer-events-none text-slate-300 bg-slate-50' : 'text-slate-600 hover:bg-slate-50'; ?>">下一页</a>
                <?php endif; ?>
              </div>
            </div>
            <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
