<?php
/**
 * 瑞锦云存储管理后台入口。
 * 这里只负责定义项目根目录、按原顺序加载请求模块并组合页面视图。
 */
define('CLOUD_STORAGE_ENTRY', true);
define('CLOUD_STORAGE_ROOT', __DIR__);

require_once CLOUD_STORAGE_ROOT . '/includes/bootstrap.php';
require_once CLOUD_STORAGE_ROOT . '/helpers/storage_helpers.php';
require_once CLOUD_STORAGE_ROOT . '/includes/navigation_context.php';
require_once CLOUD_STORAGE_ROOT . '/helpers/search_helpers.php';
require_once CLOUD_STORAGE_ROOT . '/includes/search_context.php';
require_once CLOUD_STORAGE_ROOT . '/actions/share_actions.php';
require_once CLOUD_STORAGE_ROOT . '/actions/account_actions.php';
require_once CLOUD_STORAGE_ROOT . '/actions/user_actions.php';
require_once CLOUD_STORAGE_ROOT . '/actions/file_actions.php';
require_once CLOUD_STORAGE_ROOT . '/includes/view_context.php';

require CLOUD_STORAGE_ROOT . '/views/layout/header.php';
require CLOUD_STORAGE_ROOT . '/views/content.php';
require CLOUD_STORAGE_ROOT . '/views/layout/main_end.php';
require CLOUD_STORAGE_ROOT . '/views/modals.php';
require CLOUD_STORAGE_ROOT . '/views/layout/footer.php';
