<?php
/**
 * 目录遍历、路径边界、递归删除及元数据清理函数。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

// 递归列出 data 目录下所有子文件夹（用于"移动文件"目标位置选择）
function listAllFolders($dir, $relative = '') {
    $result = [];
    $scan = @scandir($dir);
    if ($scan === false) return $result;
    foreach ($scan as $item) {
        if ($item === '.' || $item === '..') continue;
        if (substr($item, 0, 1) === '.') continue;
        $full = $dir . $item;
        if (is_dir($full)) {
            $rel = $relative === '' ? $item : $relative . '/' . $item;
            $result[] = $rel;
            $result = array_merge($result, listAllFolders($full . '/', $rel));
        }
    }
    return $result;
}

/**
 * 判断真实路径是否严格位于存储根目录内。
 */
function isStoragePathInsideBase($path, $base) {
    $path_normalized = rtrim(str_replace('\\', '/', $path), '/');
    $base_normalized = rtrim(str_replace('\\', '/', $base), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $path_normalized = strtolower($path_normalized);
        $base_normalized = strtolower($base_normalized);
    }
    return $path_normalized !== $base_normalized
        && strpos($path_normalized, $base_normalized . '/') === 0;
}

/**
 * 用户根目录和系统分类目录不能通过普通目录删除功能移除。
 */
function isProtectedStorageFolder($relative_path) {
    $parts = array_values(array_filter(explode('/', trim($relative_path, '/')), 'strlen'));
    if (count($parts) === 1) return true;
    return count($parts) === 2 && in_array($parts[1], USER_CATEGORY_FOLDERS, true);
}

function directoryHasContents($directory) {
    $scan = @scandir($directory);
    return $scan !== false && count(array_diff($scan, ['.', '..'])) > 0;
}

/**
 * 安全递归删除目录；不会跟随符号链接。
 */
function deleteDirectoryTree($directory, $base_dir) {
    if (is_link($directory)) {
        throw new RuntimeException('不允许递归删除符号链接目录。');
    }
    $base_real = realpath($base_dir);
    $directory_real = realpath($directory);
    if (!$base_real || !$directory_real || !isStoragePathInsideBase($directory_real, $base_real) || !is_dir($directory_real)) {
        throw new RuntimeException('非法或不存在的目录。');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory_real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item_path = $item->getPathname();
        $ok = ($item->isLink() || $item->isFile()) ? @unlink($item_path) : @rmdir($item_path);
        if (!$ok) {
            throw new RuntimeException('无法删除：' . $item->getFilename());
        }
    }
    if (!@rmdir($directory_real)) {
        throw new RuntimeException('无法删除目标目录。');
    }
}

/**
 * 删除某个文件/目录前缀关联的密码、所有者及分享记录。
 */
function removeMetadataForPath($relative_path, &$passwords_data, &$owners_data, &$shares_data, $passwords_file, $owners_file, $shares_file) {
    $relative_path = trim($relative_path, '/');
    $prefix = $relative_path . '/';

    foreach (array_keys($passwords_data) as $path) {
        if ($path === $relative_path || strpos($path, $prefix) === 0) unset($passwords_data[$path]);
    }
    foreach (array_keys($owners_data) as $path) {
        if ($path === $relative_path || strpos($path, $prefix) === 0) unset($owners_data[$path]);
    }
    foreach ($shares_data as $token => $share) {
        $path = $share['file'] ?? '';
        if ($path === $relative_path || strpos($path, $prefix) === 0) unset($shares_data[$token]);
    }

    file_put_contents($passwords_file, json_encode($passwords_data, JSON_PRETTY_PRINT), LOCK_EX);
    file_put_contents($owners_file, json_encode($owners_data, JSON_PRETTY_PRINT), LOCK_EX);
    file_put_contents($shares_file, json_encode($shares_data, JSON_PRETTY_PRINT), LOCK_EX);
}

