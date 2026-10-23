<?php
/**
 * 页面格式化、分享统计、标题和角色显示上下文。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

function formatSize($size) {
    $units = array('B', 'KB', 'MB', 'GB');
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) { $size /= 1024; $unit++; }
    return round($size, 2) . ' ' . $units[$unit];
}

$visible_shares = [];
foreach ($shares_data as $share_token => $share_info) {
    if ($is_admin || ($share_info['owner'] ?? '') === $current_username) {
        $visible_shares[$share_token] = $share_info;
    }
}
$visible_share_views = array_sum(array_map(function($share) { return (int)($share['views'] ?? 0); }, $visible_shares));
$visible_share_downloads = array_sum(array_map(function($share) { return (int)($share['downloads'] ?? 0); }, $visible_shares));
$active_visible_share_count = count(array_filter($visible_shares, function($share) { return empty($share['expiry']) || time() <= (int)$share['expiry']; }));
$view_titles = [
    'home' => ['首页', '文件上传、搜索与目录管理'],
    'users' => ['用户管理', '账号、角色与权限管理'],
    'shares' => ['分享管理', $is_admin ? '全站分享链接与访问数据' : '我的分享链接与访问数据'],
];
$role_label = $is_super_admin ? '超级管理员' : ($is_admin ? '管理员' : '普通用户');
$nav_dir_suffix = $current_sub !== '' ? '&dir=' . urlencode($current_sub) : '';
