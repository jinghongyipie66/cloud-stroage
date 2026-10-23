<?php
/** 分享管理视图。 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}
?>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6 animate-fade-in">
          <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm"><p class="text-xs text-slate-500">活跃分享</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo $active_visible_share_count; ?></p></div>
          <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm"><p class="text-xs text-slate-500">累计查看</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo $visible_share_views; ?></p></div>
          <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm"><p class="text-xs text-slate-500">累计下载</p><p class="text-2xl font-bold text-slate-900 mt-1"><?php echo $visible_share_downloads; ?></p></div>
        </div>

        <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden animate-fade-in">
          <div class="px-5 sm:px-6 py-5 border-b border-slate-100 flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div><h3 class="text-lg font-bold text-slate-900"><?php echo $is_admin ? '全站分享列表' : '我的分享列表'; ?></h3><p class="text-xs text-slate-500 mt-1">管理链接有效期、查看次数与下载次数</p></div>
            <input type="search" id="shareFileSearch" name="share_file_search" value="" autocomplete="off" autocapitalize="off" spellcheck="false" readonly data-lpignore="true" data-1p-ignore="true" aria-label="搜索分享文件" onfocus="activateShareSearch(this)" onpointerdown="activateShareSearch(this)" onkeydown="activateShareSearch(this)" oninput="filterShareRows(this.value)" placeholder="搜索分享文件" class="w-full lg:w-64 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-blue-500">
          </div>

          <?php if (empty($visible_shares)): ?>
            <div class="text-center py-16 text-slate-400"><div class="text-4xl mb-3">🔗</div><p class="text-sm">暂无分享记录</p></div>
          <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-slate-50 text-slate-500 text-xs"><tr><th class="text-left font-semibold px-5 sm:px-6 py-3">文件</th><th class="text-left font-semibold px-4 py-3">所有者</th><th class="text-left font-semibold px-4 py-3">有效期</th><th class="text-left font-semibold px-4 py-3">查看 / 下载</th><th class="text-right font-semibold px-5 sm:px-6 py-3">操作</th></tr></thead>
              <tbody class="divide-y divide-slate-100">
              <?php foreach ($visible_shares as $token => $share):
                  $is_expired = !empty($share['expiry']) && time() > $share['expiry'];
              ?>
                <tr data-share-row data-share-name="<?php echo htmlspecialchars(strtolower($share['name'] ?? ''), ENT_QUOTES); ?>" class="hover:bg-slate-50/70 transition-colors">
                  <td class="px-5 sm:px-6 py-4"><p class="font-semibold text-slate-900 max-w-xs truncate"><?php echo htmlspecialchars($share['name'] ?? '未命名文件'); ?></p><p class="text-xs text-slate-400 mt-1 max-w-xs truncate"><?php echo htmlspecialchars($share['file'] ?? ''); ?></p></td>
                  <td class="px-4 py-4 text-slate-600"><?php echo htmlspecialchars($share['owner'] ?? 'unknown'); ?></td>
                  <td class="px-4 py-4 whitespace-nowrap"><?php if ($is_expired): ?><span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold bg-rose-100 text-rose-700">已过期</span><?php elseif (!empty($share['expiry'])): ?><span class="text-slate-600">至 <?php echo date('Y-m-d H:i', $share['expiry']); ?></span><?php else: ?><span class="text-emerald-700">永久有效</span><?php endif; ?></td>
                  <td class="px-4 py-4 text-slate-600 whitespace-nowrap"><?php echo (int)($share['views'] ?? 0); ?> / <?php echo (int)($share['downloads'] ?? 0); ?></td>
                  <td class="px-5 sm:px-6 py-4"><div class="flex justify-end gap-2 whitespace-nowrap"><button type="button" onclick="copyShareLink('<?php echo htmlspecialchars($token, ENT_QUOTES); ?>')" class="text-blue-700 text-xs font-semibold bg-blue-50 hover:bg-blue-100 px-3 py-2 rounded-lg transition-all">复制链接</button><button type="button" onclick="deleteShare('<?php echo htmlspecialchars($token, ENT_QUOTES); ?>')" class="text-rose-700 text-xs font-semibold bg-rose-50 hover:bg-rose-100 px-3 py-2 rounded-lg transition-all">取消分享</button></div></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>
        </section>




