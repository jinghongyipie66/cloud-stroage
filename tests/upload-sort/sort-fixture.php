<?php
define('CLOUD_STORAGE_ENTRY', true);
$input = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
$_GET = $input['get'];
$is_searching = $input['search'] ?? false;
$is_admin = false; $current_username = 'fixture';
$base_dir = $input['base']; $target_dir = $input['directory'];
$folders_list = $input['folders']; $files_list = $input['files']; $search_results=[];
$search_query='sample'; $search_time=$search_size=$search_type='all';
function searchFilesRecursive(...$unused) { global $input; return $input['records']; }
require dirname(__DIR__, 2) . '/includes/search_context.php';
echo json_encode(['folders'=>$paged_folders_list,'files'=>$paged_files_list,'results'=>$paged_search_results,'order'=>$file_sort,'page'=>$current_page,'total'=>$pagination_total_items,'next'=>buildPaginationUrl(2),'resize'=>buildPaginationUrl(1,50)],JSON_THROW_ON_ERROR);
