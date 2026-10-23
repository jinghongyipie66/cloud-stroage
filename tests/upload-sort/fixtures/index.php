<?php
// Real presentation/search/pagination over generated files, not the production DB.
define('CLOUD_STORAGE_ENTRY', true);
require __DIR__ . '/security.php'; start_secure_session();
if (empty($_SESSION['authenticated'])) { http_response_code(401); exit('test sign-in required'); }
require __DIR__ . '/user_helpers.php';
$current_username = $_SESSION['username'];
$is_admin = $_SESSION['role'] === 'admin'; $is_super_admin = false;
$role_label = $is_admin ? '管理员' : '普通用户';
$current_view = 'home'; $view_titles = ['home'=>['我的文件','隔离测试数据']];
$base_dir = __DIR__ . '/data/';
$current_sub = trim($_GET['dir'] ?? $current_username, '/');
if (str_contains($current_sub, '..') || (!$is_admin && $current_sub !== $current_username && strpos($current_sub,$current_username.'/')!==0)) $current_sub=$current_username;
$target_dir = $base_dir . $current_sub . '/';
if (!is_dir($target_dir)) { $current_sub=$current_username; $target_dir=$base_dir.$current_sub.'/'; }
$folders_list = $files_list = []; $total_size = 0;
foreach (scandir($target_dir) as $entry) {
    if ($entry[0] === '.') continue;
    if (is_dir($target_dir.$entry)) $folders_list[]=$entry;
    else { $files_list[]=$entry; $total_size+=filesize($target_dir.$entry); }
}
$nav_dir_suffix='&dir='.urlencode($current_sub); $active_visible_share_count=0; $error_msg='';
$passwords_data=[]; $owners_data=[]; $all_folders=[$current_username,$current_username.'/custom'];
function formatSize($n) { return $n.' B'; }
function directoryHasContents($p) { return count(scandir($p))>2; }
function isProtectedStorageFolder($p) { return in_array(basename($p),USER_CATEGORY_FOLDERS,true); }
require __DIR__ . '/helpers/search_helpers.php';
require __DIR__ . '/includes/search_context.php';
require __DIR__ . '/views/layout/header.php';
require __DIR__ . '/views/home.php';
echo '</div></main></div>';
require __DIR__ . '/views/modals.php';
require __DIR__ . '/views/layout/footer.php';
