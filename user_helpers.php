<?php
/**
 * 共享辅助函数
 */

// 每个用户的专属分类文件夹
define('USER_CATEGORY_FOLDERS', ['全部文件', '图片', '视频', '文档', '音频', '压缩包']);

/**
 * 确保某个用户的专属目录及分类子文件夹都存在
 * 在用户注册 / 管理员新建用户 / 用户登录 时调用，保证目录始终存在
 */
function ensure_user_folders($username) {
    $base = __DIR__ . '/data/' . $username . '/';
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    foreach (USER_CATEGORY_FOLDERS as $sf) {
        $p = $base . $sf . '/';
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
    }
}

/**
 * 根据文件扩展名判断应该自动归类到哪个分类文件夹
 * 找不到匹配类型时返回 null（表示放在"全部文件"或用户手动选择的目录）
 */
function detect_category_folder($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    $map = [
        '图片' => ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp', 'svg', 'heic', 'tiff'],
        '视频' => ['mp4', 'avi', 'mov', 'wmv', 'flv', 'mkv', 'webm', 'm4v', '3gp'],
        '音频' => ['mp3', 'wav', 'flac', 'aac', 'ogg', 'm4a', 'wma'],
        '文档' => ['doc', 'docx', 'pdf', 'txt', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'md'],
        '压缩包' => ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'],
    ];

    foreach ($map as $folder => $exts) {
        if (in_array($ext, $exts)) {
            return $folder;
        }
    }
    return null;
}

/**
 * 加载汉字->拼音映射表（只加载一次）
 */
function get_pinyin_map() {
    static $map = null;
    if ($map === null) {
        $map = file_exists(__DIR__ . '/pinyin_data.php') ? include __DIR__ . '/pinyin_data.php' : [];
    }
    return $map;
}

/**
 * 生成文件名的全拼和首字母。两个结果在同一次遍历中完成，避免重复转换。
 */
function build_pinyin_forms($text) {
    $map = get_pinyin_map();
    $full = '';
    $initials = '';
    $len = mb_strlen($text, 'UTF-8');

    for ($i = 0; $i < $len; $i++) {
        $char = mb_substr($text, $i, 1, 'UTF-8');
        if (isset($map[$char])) {
            $pinyin = $map[$char];
            $full .= $pinyin;
            $initials .= substr($pinyin, 0, 1);
        } else {
            $lower = mb_strtolower($char, 'UTF-8');
            $full .= $lower;
            $initials .= $lower;
        }
    }

    return ['full' => $full, 'initials' => $initials];
}

/**
 * 获取文件名拼音缓存。
 * 优先使用请求内缓存和 APCu；磁盘缓存写入失败时自动回退实时转换，不影响搜索。
 */
function get_cached_pinyin_forms($text) {
    static $request_cache = [];

    $text = (string)$text;
    $cache_key = hash('sha256', "pinyin-v1\0" . $text);
    if (isset($request_cache[$cache_key])) {
        return $request_cache[$cache_key];
    }

    $apcu_key = 'cloud-storage:' . $cache_key;
    if (function_exists('apcu_fetch')) {
        $success = false;
        $cached = @apcu_fetch($apcu_key, $success);
        if ($success && is_array($cached) && isset($cached['full'], $cached['initials'])) {
            return $request_cache[$cache_key] = $cached;
        }
    }

    $cache_namespace = substr(hash('sha256', realpath(__DIR__) ?: __DIR__), 0, 16);
    $cache_root = rtrim(sys_get_temp_dir(), '/\\') . '/cloud_storage_cache_' . $cache_namespace . '/pinyin';
    $cache_file = $cache_root . '/' . substr($cache_key, 0, 2) . '/' . $cache_key . '.json';
    if (is_file($cache_file)) {
        $cached = json_decode((string)@file_get_contents($cache_file), true);
        if (is_array($cached) && isset($cached['full'], $cached['initials'])
            && is_string($cached['full']) && is_string($cached['initials'])) {
            if (function_exists('apcu_store')) {
                @apcu_store($apcu_key, $cached, 2592000);
            }
            return $request_cache[$cache_key] = $cached;
        }
    }

    $forms = build_pinyin_forms($text);
    $cache_dir = dirname($cache_file);
    if (is_dir($cache_dir) || @mkdir($cache_dir, 0700, true)) {
        $temporary = @tempnam($cache_dir, '.pinyin-');
        if ($temporary !== false) {
            $json = json_encode($forms, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json !== false && @file_put_contents($temporary, $json, LOCK_EX) !== false) {
                @chmod($temporary, 0600);
                if (!@rename($temporary, $cache_file)) {
                    @unlink($temporary);
                }
            } else {
                @unlink($temporary);
            }
        }
    }

    if (function_exists('apcu_store')) {
        @apcu_store($apcu_key, $forms, 2592000);
    }
    return $request_cache[$cache_key] = $forms;
}

/**
 * 把字符串转换为纯拼音（汉字转拼音拼接，非汉字字符原样保留并转小写）
 * 例如 "照片2024" -> "zhaopian2024"
 */
function to_pinyin($text) {
    $forms = get_cached_pinyin_forms($text);
    return $forms['full'];
}

/**
 * 把字符串转换为拼音首字母（汉字取拼音首字母，非汉字字符原样保留并转小写）
 * 例如 "照片2024" -> "zp2024"
 */
function to_pinyin_initials($text) {
    $forms = get_cached_pinyin_forms($text);
    return $forms['initials'];
}

/**
 * 智能匹配：支持模糊搜索（不区分大小写的子串匹配）和拼音搜索（全拼/首字母）
 */
function smart_name_matches($filename, $query) {
    $query = trim(mb_strtolower($query, 'UTF-8'));
    if ($query === '') return true;

    $name_lower = mb_strtolower($filename, 'UTF-8');
    // 1. 普通模糊匹配（子串包含）
    if (mb_strpos($name_lower, $query) !== false) {
        return true;
    }
    // 2. 拼音全拼匹配（全拼与首字母共用同一份缓存）
    $pinyin = get_cached_pinyin_forms($filename);
    if (strpos($pinyin['full'], $query) !== false) {
        return true;
    }
    // 3. 拼音首字母匹配
    if (strpos($pinyin['initials'], $query) !== false) {
        return true;
    }
    return false;
}

/**
 * 根据文件扩展名判断所属的格式筛选分类（用于智能搜索的"格式"筛选）
 * 返回值与 USER_CATEGORY_FOLDERS 中的分类名对应，不属于任何已知分类则返回 "其他"
 */
function get_format_category($filename) {
    $category = detect_category_folder($filename);
    return $category ?? '其他';
}
