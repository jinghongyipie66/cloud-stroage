<?php
/** 当前页面主体视图调度。 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

if ($current_view === 'home') {
    require __DIR__ . '/home.php';
} elseif ($current_view === 'users' && $is_admin) {
    require __DIR__ . '/users.php';
} elseif ($current_view === 'shares') {
    require __DIR__ . '/shares.php';
}