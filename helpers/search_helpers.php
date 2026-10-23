<?php
/**
 * 递归搜索函数及搜索参数初始化。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

// ===== 智能搜索 =====
// 递归扫描指定目录下所有文件（跳过隐藏文件/系统文件），按关键字（模糊/拼音）及时间/大小/格式筛选
function searchFilesRecursive($dir, $relative, $query, $time_filter, $size_filter, $type_filter) {
    $result = [];
    $scan = @scandir($dir);
    if ($scan === false) return $result;

    $excluded = ['.', '..', 'index.php', 'upload.php', 'download.php', 'share.php', 'share_api.php', 'login.php', 'logout.php', 'register.php', 'db_config.php', 'user_helpers.php', 'pinyin_data.php', 'migrate_users.php', 'init_db.sql'];

    foreach ($scan as $item) {
        if (in_array($item, $excluded)) continue;
        if (substr($item, 0, 1) === '.') continue;

        $full = $dir . $item;
        $rel = $relative === '' ? $item : $relative . '/' . $item;

        if (is_dir($full)) {
            $result = array_merge($result, searchFilesRecursive($full . '/', $rel, $query, $time_filter, $size_filter, $type_filter));
            continue;
        }

        // 关键字匹配（模糊 + 拼音）
        if (!smart_name_matches($item, $query)) continue;

        $mtime = @filemtime($full) ?: 0;
        $size = @filesize($full) ?: 0;

        // 时间筛选
        if ($time_filter !== 'all') {
            $now = time();
            if ($time_filter === 'today' && $mtime < strtotime('today')) continue;
            if ($time_filter === 'week' && $mtime < $now - 7 * 86400) continue;
            if ($time_filter === 'month' && $mtime < $now - 30 * 86400) continue;
        }

        // 大小筛选
        if ($size_filter !== 'all') {
            $mb = $size / (1024 * 1024);
            if ($size_filter === 'small' && $mb >= 1) continue;
            if ($size_filter === 'medium' && ($mb < 1 || $mb >= 10)) continue;
            if ($size_filter === 'large' && ($mb < 10 || $mb >= 100)) continue;
            if ($size_filter === 'huge' && $mb < 100) continue;
        }

        // 格式筛选
        if ($type_filter !== 'all' && get_format_category($item) !== $type_filter) continue;

        $result[] = [
            'path' => $rel,
            'name' => $item,
            'size' => $size,
            'mtime' => $mtime,
        ];
    }
    return $result;
}

$search_query = trim($_GET['q'] ?? '');
$search_time = $_GET['time'] ?? 'all';
$search_size = $_GET['size'] ?? 'all';
$search_type = $_GET['type'] ?? 'all';
$is_searching = ($search_query !== '' || $search_time !== 'all' || $search_size !== 'all' || $search_type !== 'all');
$search_results = [];

