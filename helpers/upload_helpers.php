<?php
/** Upload-only helpers. No database or configuration is loaded here. */
function upload_path_is_within($path, $root) {
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $root = rtrim(str_replace('\\', '/', $root), '/');
    if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
    return $path === $root || strpos($path, $root . '/') === 0;
}

function upload_target_exists($path) {
    clearstatcache(true, $path);
    return file_exists($path) || is_link($path);
}

/** Validate the existing ancestor before mkdir, including symlink destinations. */
function upload_directory_is_allowed($directory, $base, $username, $is_admin) {
    $base_real = realpath($base);
    $ancestor = rtrim($directory, '/\\');
    while (!file_exists($ancestor) && !is_link($ancestor)) {
        $parent = dirname($ancestor);
        if ($parent === $ancestor) return false;
        $ancestor = $parent;
    }
    $ancestor_real = realpath($ancestor);
    if (!$base_real || !$ancestor_real || !is_dir($ancestor_real) || !upload_path_is_within($ancestor_real, $base_real)) return false;
    if ($is_admin) return true;
    $own_real = $username !== '' ? realpath(rtrim($base, '/\\') . '/' . $username) : false;
    return $own_real && upload_path_is_within($ancestor_real, $own_real);
}

/** Publish a completed staging file without replacing ANY existing directory entry. */
function upload_publish_exclusive($staged, $target) {
    if (upload_target_exists($target)) return 'exists';
    if (function_exists('link') && @link($staged, $target)) return 'stored';
    return upload_target_exists($target) ? 'exists' : 'error';
}

function upload_store_exclusive($source, $target) {
    if (upload_target_exists($target)) return 'exists';
    if (!is_uploaded_file($source)) return 'error';
    $directory = realpath(dirname($target));
    if ($directory === false) return 'error';
    $staged = @tempnam($directory, '.upload-');
    if ($staged === false) return 'error';
    try {
        // tempnam can fall back to the OS temp directory; fail closed in that case.
        if (realpath(dirname($staged)) !== $directory || !@move_uploaded_file($source, $staged)) return 'error';
        return upload_publish_exclusive($staged, $target);
    } finally {
        // Only this request's generated staging name is removed, never the destination.
        @unlink($staged);
    }
}
