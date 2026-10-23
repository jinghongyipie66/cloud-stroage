<?php
/**
 * 移动目标目录上下文。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

// 移动文件的目标目录列表：管理员可见全部用户目录；普通用户只能看到自己目录下的子文件夹
if ($is_admin) {
    $all_folders = listAllFolders($base_dir);
} else {
    $own_root = $base_dir . $current_username . '/';
    $all_folders = is_dir($own_root) ? array_map(function($f) use ($current_username) {
        return $current_username . '/' . $f;
    }, listAllFolders($own_root)) : [];
    // 加上用户自己的根目录作为可选目标
    array_unshift($all_folders, $current_username);
}

