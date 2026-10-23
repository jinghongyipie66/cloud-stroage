<?php
/**
 * 图片/视频缩略图懒生成接口。
 * 列表页通过独立请求加载，生成失败时前端自动回退到原文件类型图标。
 */
require_once __DIR__ . '/security.php';
start_secure_session();

function thumbnail_fail($status) {
    http_response_code($status);
    header('Cache-Control: no-store');
    exit;
}

function thumbnail_is_inside($path, $base) {
    $path = str_replace('\\', '/', $path);
    $base = rtrim(str_replace('\\', '/', $base), '/') . '/';
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $base = strtolower($base);
    }
    return strpos($path, $base) === 0;
}

function thumbnail_serve($file, $etag) {
    $quoted_etag = '"' . $etag . '"';
    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $quoted_etag) {
        http_response_code(304);
        header('ETag: ' . $quoted_etag);
        exit;
    }

    header('Content-Type: image/jpeg');
    header('Content-Length: ' . (string)filesize($file));
    header('Cache-Control: private, max-age=86400');
    header('ETag: ' . $quoted_etag);
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

function thumbnail_image_source($source, $image_type) {
    switch ($image_type) {
        case IMAGETYPE_JPEG:
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($source) : false;
        case IMAGETYPE_PNG:
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($source) : false;
        case IMAGETYPE_GIF:
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($source) : false;
        case 18: // IMAGETYPE_WEBP
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false;
        case 6: // IMAGETYPE_BMP
            return function_exists('imagecreatefrombmp') ? @imagecreatefrombmp($source) : false;
        default:
            return false;
    }
}

function thumbnail_generate_image($source, $destination) {
    if (!function_exists('getimagesize') || !function_exists('imagecreatetruecolor')
        || !function_exists('imagecopyresampled') || !function_exists('imagejpeg')) {
        return false;
    }

    $info = @getimagesize($source);
    if (!$info || empty($info[0]) || empty($info[1])) {
        return false;
    }
    $width = (int)$info[0];
    $height = (int)$info[1];
    // 避免异常超大图片在解码时耗尽内存。
    if ($width <= 0 || $height <= 0 || ($width * $height) > 60000000) {
        return false;
    }

    $source_image = thumbnail_image_source($source, (int)$info[2]);
    if (!$source_image) {
        return false;
    }

    $max_width = 320;
    $max_height = 240;
    $scale = min($max_width / $width, $max_height / $height, 1);
    $target_width = max(1, (int)round($width * $scale));
    $target_height = max(1, (int)round($height * $scale));
    $thumbnail = @imagecreatetruecolor($target_width, $target_height);
    if (!$thumbnail) {
        imagedestroy($source_image);
        return false;
    }

    $white = imagecolorallocate($thumbnail, 255, 255, 255);
    imagefill($thumbnail, 0, 0, $white);
    $copied = @imagecopyresampled(
        $thumbnail,
        $source_image,
        0,
        0,
        0,
        0,
        $target_width,
        $target_height,
        $width,
        $height
    );
    if (function_exists('imageinterlace')) {
        @imageinterlace($thumbnail, true);
    }
    $written = $copied && @imagejpeg($thumbnail, $destination, 82);
    imagedestroy($thumbnail);
    imagedestroy($source_image);
    return $written && is_file($destination) && filesize($destination) > 0;
}

function thumbnail_exec_available() {
    if (!function_exists('exec')) {
        return false;
    }
    $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
    return !in_array('exec', $disabled, true);
}

function thumbnail_generate_video($source, $destination) {
    if (!thumbnail_exec_available()) {
        return false;
    }

    $ffmpeg = getenv('THUMBNAIL_FFMPEG_BINARY');
    if ($ffmpeg === false || trim($ffmpeg) === '') {
        $ffmpeg = 'ffmpeg';
    }
    $filter = 'scale=320:240:force_original_aspect_ratio=decrease,pad=320:240:(ow-iw)/2:(oh-ih)/2:color=white';

    foreach (['1', '0'] as $seek_second) {
        $command = escapeshellarg($ffmpeg)
            . ' -hide_banner -loglevel error -nostdin -y -threads 1'
            . ' -ss ' . $seek_second
            . ' -i ' . escapeshellarg($source)
            . ' -frames:v 1 -vf ' . escapeshellarg($filter)
            . ' -q:v 4 -f image2 -vcodec mjpeg ' . escapeshellarg($destination)
            . ' 2>&1';
        $output = [];
        $exit_code = 1;
        @exec($command, $output, $exit_code);
        if ($exit_code === 0 && is_file($destination) && filesize($destination) > 0) {
            return true;
        }
        @unlink($destination);
    }
    return false;
}

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    thumbnail_fail(403);
}

$username = (string)($_SESSION['username'] ?? '');
$is_admin = (($_SESSION['role'] ?? 'user') === 'admin');
$relative = str_replace('\\', '/', trim((string)($_GET['file'] ?? '')));

if ($relative === '' || strpos($relative, "\0") !== false || $relative[0] === '/'
    || preg_match('#(^|/)\.\.(/|$)#', $relative)) {
    thumbnail_fail(400);
}
$base = realpath(__DIR__ . '/data');
$source = realpath(__DIR__ . '/data/' . $relative);
if ($base === false || $source === false || !is_file($source) || !thumbnail_is_inside($source, $base)) {
    thumbnail_fail(404);
}
$normalized_base = rtrim(str_replace('\\', '/', $base), '/');
$normalized_source = str_replace('\\', '/', $source);
$relative = ltrim(substr($normalized_source, strlen($normalized_base)), '/');
if (!$is_admin && ($username === '' || strpos($relative, $username . '/') !== 0)) {
    thumbnail_fail(403);
}

// 加密文件不生成缩略图，避免在未输入密码前泄露内容。
$passwords_file = __DIR__ . '/.passwords.json';
$passwords = is_file($passwords_file)
    ? (json_decode((string)@file_get_contents($passwords_file), true) ?: [])
    : [];
if (isset($passwords[$relative])) {
    thumbnail_fail(403);
}

$extension = strtolower(pathinfo($source, PATHINFO_EXTENSION));
$image_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
$video_extensions = ['mp4', 'avi', 'mov', 'mkv', 'webm', 'm4v', '3gp'];
$kind = in_array($extension, $image_extensions, true)
    ? 'image'
    : (in_array($extension, $video_extensions, true) ? 'video' : '');
if ($kind === '') {
    thumbnail_fail(415);
}

// 释放 Session 锁，避免多个缩略图请求相互排队。
session_write_close();

$mtime = (int)@filemtime($source);
$size = (int)@filesize($source);
$cache_key = hash('sha256', "thumbnail-v1\0" . $relative . "\0" . $mtime . "\0" . $size);
$cache_namespace = substr(hash('sha256', realpath(__DIR__) ?: __DIR__), 0, 16);
$cache_root = rtrim(sys_get_temp_dir(), '/\\') . '/cloud_storage_cache_' . $cache_namespace . '/thumbnails';
$cache_dir = $cache_root . '/' . substr($cache_key, 0, 2);
$cache_file = $cache_dir . '/' . $cache_key . '.jpg';

if (is_file($cache_file) && filesize($cache_file) > 0) {
    thumbnail_serve($cache_file, $cache_key);
}
if (!is_dir($cache_dir) && !@mkdir($cache_dir, 0700, true)) {
    thumbnail_fail(503);
}

$lock = @fopen($cache_file . '.lock', 'c');
if ($lock === false || !@flock($lock, LOCK_EX)) {
    if (is_resource($lock)) fclose($lock);
    thumbnail_fail(503);
}

if (!is_file($cache_file) || filesize($cache_file) <= 0) {
    $temporary_base = @tempnam($cache_dir, '.thumb-');
    if ($temporary_base === false) {
        @flock($lock, LOCK_UN);
        fclose($lock);
        thumbnail_fail(503);
    }
    @unlink($temporary_base);
    $temporary = $temporary_base . '.jpg';
    $generated = $kind === 'image'
        ? thumbnail_generate_image($source, $temporary)
        : thumbnail_generate_video($source, $temporary);
    if (!$generated || !@rename($temporary, $cache_file)) {
        @unlink($temporary);
        @flock($lock, LOCK_UN);
        fclose($lock);
        thumbnail_fail(503);
    }
    @chmod($cache_file, 0600);
}

@flock($lock, LOCK_UN);
fclose($lock);
thumbnail_serve($cache_file, $cache_key);
