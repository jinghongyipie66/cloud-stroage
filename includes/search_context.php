<?php
/**
 * 搜索结果、分页上下文及分页 URL 生成函数。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../helpers/file_list_helpers.php';
$file_sort = file_list_normalize_sort($_GET['sort'] ?? 'default');
$file_sort_options = file_list_sort_options();

if ($is_searching) {
    $search_scope_dir = $is_admin ? $base_dir : ($base_dir . $current_username . '/');
    $search_scope_relative = $is_admin ? '' : $current_username;
    if (is_dir($search_scope_dir)) {
        $search_results = searchFilesRecursive($search_scope_dir, $search_scope_relative, $search_query, $search_time, $search_size, $search_type);
        // 默认保留搜索结果的新到旧顺序；显式排序使用同一比较规则。
        $search_results = file_list_sort_records($search_results, $file_sort === 'default' ? 'mtime_desc' : $file_sort);
    }
} else {
    // 文件夹维持置顶及原名称顺序，不递归计算目录大小。
    $files_list = file_list_sort_names($files_list, $target_dir, $file_sort);
}

// 分享生成接口（仅管理员可操作）
// 分页：普通目录和搜索结果共用同一套页码，每页可选 30、50 或 100 项。
$allowed_per_page = [30, 50, 100];
$items_per_page = (int)($_GET['per_page'] ?? 30);
if (!in_array($items_per_page, $allowed_per_page, true)) {
    $items_per_page = 30;
}

$requested_page = max(1, (int)($_GET['page'] ?? 1));
$pagination_total_items = $is_searching
    ? count($search_results)
    : count($folders_list) + count($files_list);
$pagination_total_pages = max(1, (int)ceil($pagination_total_items / $items_per_page));
$current_page = min($requested_page, $pagination_total_pages);
$pagination_offset = ($current_page - 1) * $items_per_page;
$pagination_from = $pagination_total_items > 0 ? $pagination_offset + 1 : 0;
$pagination_to = min($pagination_total_items, $pagination_offset + $items_per_page);

$paged_search_results = [];
$paged_folders_list = [];
$paged_files_list = [];

if ($is_searching) {
    $paged_search_results = array_slice($search_results, $pagination_offset, $items_per_page);
} else {
    $directory_entries = [];
    foreach ($folders_list as $folder) {
        $directory_entries[] = ['type' => 'folder', 'name' => $folder];
    }
    foreach ($files_list as $file) {
        $directory_entries[] = ['type' => 'file', 'name' => $file];
    }

    $paged_directory_entries = array_slice($directory_entries, $pagination_offset, $items_per_page);
    foreach ($paged_directory_entries as $entry) {
        if ($entry['type'] === 'folder') {
            $paged_folders_list[] = $entry['name'];
        } else {
            $paged_files_list[] = $entry['name'];
        }
    }
}

function buildPaginationUrl($page, $per_page = null) {
    global $items_per_page, $file_sort;
    $params = $_GET;
    if ($file_sort === 'default') unset($params['sort']);
    else $params['sort'] = $file_sort;
    $target_per_page = $per_page === null ? $items_per_page : (int)$per_page;

    if ($page <= 1) {
        unset($params['page']);
    } else {
        $params['page'] = $page;
    }

    if ($target_per_page === 30) {
        unset($params['per_page']);
    } else {
        $params['per_page'] = $target_per_page;
    }

    $query = http_build_query($params);
    return 'index.php' . ($query === '' ? '' : '?' . $query);
}
