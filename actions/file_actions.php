<?php
/**
 * 删除、新建目录、移动、加密及批量文件操作。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

$error_msg = '';
// 文件/文件夹删除接口（管理员可删除任意文件；普通用户只能删除自己的文件和自建目录）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_item'])) {
    $item_to_delete = trim($_POST['delete_item'], '/');
    $recursive_delete = ($_POST['delete_recursive'] ?? '0') === '1';
    $is_within_own_dir = ($current_username !== '' && strpos($item_to_delete, $current_username . '/') === 0);

    if (strpos($item_to_delete, '..') !== false || (!$is_admin && !$is_within_own_dir)) {
        $error_msg = '操作失败：你没有权限执行此操作。';
    } else {
        $base_real = realpath($base_dir);
        $delete_path = realpath($base_dir . $item_to_delete);

        if (!$base_real || !$delete_path || !isStoragePathInsideBase($delete_path, $base_real)) {
            $error_msg = '操作失败：非法路径。';
        } elseif (is_dir($delete_path)) {
            if (isProtectedStorageFolder($item_to_delete)) {
                $error_msg = '操作失败：用户根目录和系统分类目录不能删除。';
            } elseif (directoryHasContents($delete_path) && !$recursive_delete) {
                $error_msg = '该文件夹不为空，请确认删除全部内容后重试。';
            } else {
                try {
                    if ($recursive_delete) deleteDirectoryTree($delete_path, $base_dir);
                    elseif (!@rmdir($delete_path)) throw new RuntimeException('无法删除空目录。');
                    removeMetadataForPath($item_to_delete, $passwords_data, $owners_data, $shares_data, $passwords_file, $owners_file, $shares_file);
                    header("Location: index.php?dir=" . urlencode($current_sub));
                    exit;
                } catch (Throwable $e) {
                    $error_msg = '操作失败：' . $e->getMessage();
                }
            }
        } elseif (is_file($delete_path)) {
            if (@unlink($delete_path)) {
                removeMetadataForPath($item_to_delete, $passwords_data, $owners_data, $shares_data, $passwords_file, $owners_file, $shares_file);
                header("Location: index.php?dir=" . urlencode($current_sub));
                exit;
            }
            $error_msg = '操作失败：无法删除文件。';
        } else {
            $error_msg = '操作失败：目标不存在。';
        }
    }
}

// 新建文件夹接口（管理员可在任意目录新建；普通用户可在自己专属目录范围内新建子文件夹）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_folder'])) {
    $new_folder_name = preg_replace('/[^a-zA-Z0-9_\-\x{4e00}-\x{9fa5}]/u', '', $_POST['new_folder']);
    if (!empty($new_folder_name)) {
        $create_path = $target_dir . $new_folder_name;
        if (file_exists($create_path)) {
            $error_msg = "操作失败：同名文件夹或文件已存在。";
        } else {
            if (@mkdir($create_path, 0755, true)) {
                header("Location: index.php?dir=" . urlencode($current_sub));
                exit;
            } else {
                $error_msg = "操作失败：无法创建目录。";
            }
        }
    }
}

// 文件移动接口（管理员可移动任意文件；普通用户只能移动自己上传的文件）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['move_file'])) {
    $move_file = trim($_POST['move_file']);
    $move_dest = isset($_POST['move_dest']) ? trim($_POST['move_dest'], '/') : '';

    if (strpos($move_file, '..') !== false || strpos($move_dest, '..') !== false) {
        $error_msg = "操作失败：非法路径。";
    } else {
        $source_real = realpath($base_dir . $move_file);

        if (!$source_real || strpos($source_real, realpath($base_dir)) !== 0 || !is_file($source_real)) {
            $error_msg = "操作失败：源文件不存在。";
        } else {
            // 权限检查：管理员可移动任意文件；普通用户只能移动自己专属目录下的文件
            $file_owner = $owners_data[$move_file] ?? null;
            $is_within_own_dir = ($current_username !== '' && (strpos($move_file, $current_username . '/') === 0 || $move_file === $current_username));
            $can_move = $is_admin || $is_within_own_dir || ($file_owner !== null && $file_owner === $_SESSION['username']);

            // 普通用户的移动目标也必须在自己专属目录范围内
            if (!$is_admin && $can_move && $current_username !== '' && strpos($move_dest, $current_username) !== 0) {
                $can_move = false;
            }

            if (!$can_move) {
                $error_msg = "操作失败：你没有权限移动此文件。";
            } else {
                // 目标目录必须是已存在的目录（'' 表示根目录）
                $dest_dir_full = $base_dir . ($move_dest === '' ? '' : $move_dest . '/');
                $dest_dir_real = realpath($dest_dir_full);

                if ($move_dest !== '' && (!$dest_dir_real || strpos($dest_dir_real, realpath($base_dir)) !== 0 || !is_dir($dest_dir_real))) {
                    $error_msg = "操作失败：目标目录不存在。";
                } else {
                    $file_basename = basename($move_file);
                    $new_relative_path = $move_dest === '' ? $file_basename : $move_dest . '/' . $file_basename;
                    $new_full_path = $base_dir . $new_relative_path;

                    if ($new_relative_path === $move_file) {
                        $error_msg = "操作失败：目标位置与当前位置相同。";
                    } elseif (file_exists($new_full_path)) {
                        $error_msg = "操作失败：目标位置已存在同名文件。";
                    } else {
                        if (@rename($source_real, $new_full_path)) {
                            // 同步更新密码记录
                            if (isset($passwords_data[$move_file])) {
                                $passwords_data[$new_relative_path] = $passwords_data[$move_file];
                                unset($passwords_data[$move_file]);
                                file_put_contents($passwords_file, json_encode($passwords_data, JSON_PRETTY_PRINT));
                            }
                            // 同步更新所有者记录
                            if (isset($owners_data[$move_file])) {
                                $owners_data[$new_relative_path] = $owners_data[$move_file];
                                unset($owners_data[$move_file]);
                                file_put_contents($owners_file, json_encode($owners_data, JSON_PRETTY_PRINT));
                            }
                            // 同步更新分享链接指向的文件路径
                            $shares_changed = false;
                            foreach ($shares_data as $tok => $info) {
                                if (($info['file'] ?? '') === $move_file) {
                                    $shares_data[$tok]['file'] = $new_relative_path;
                                    $shares_changed = true;
                                }
                            }
                            if ($shares_changed) {
                                file_put_contents($shares_file, json_encode($shares_data, JSON_PRETTY_PRINT));
                            }

                            header("Location: index.php?dir=" . urlencode($current_sub));
                            exit;
                        } else {
                            $error_msg = "操作失败：移动文件时发生错误。";
                        }
                    }
                }
            }
        }
    }
}

// 文件加密/改密/解除加密接口（管理员或文件所有者可操作）
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['encrypt_file'])) {
    $encrypt_file = trim($_POST['encrypt_file']);
    $new_file_password = $_POST['new_file_password'] ?? '';
    $remove_password = isset($_POST['remove_password']) && $_POST['remove_password'] === '1';

    if (strpos($encrypt_file, '..') !== false) {
        $error_msg = "操作失败：非法路径。";
    } else {
        $target_real = realpath($base_dir . $encrypt_file);
        $is_own_target = ($current_username !== '' && (strpos($encrypt_file, $current_username . '/') === 0));
        $can_encrypt = $is_admin || $is_own_target;

        if (!$target_real || strpos($target_real, realpath($base_dir)) !== 0 || !is_file($target_real)) {
            $error_msg = "操作失败：文件不存在。";
        } elseif (!$can_encrypt) {
            $error_msg = "操作失败：你没有权限操作此文件。";
        } else {
            if ($remove_password) {
                unset($passwords_data[$encrypt_file]);
            } else {
                if (empty($new_file_password)) {
                    $error_msg = "操作失败：密码不能为空。";
                }
                if (empty($error_msg)) {
                    $passwords_data[$encrypt_file] = password_hash($new_file_password, PASSWORD_DEFAULT);
                }
            }
            if (empty($error_msg)) {
                file_put_contents($passwords_file, json_encode($passwords_data, JSON_PRETTY_PRINT));
                header("Location: index.php?dir=" . urlencode($current_sub));
                exit;
            }
        }
    }
}

// ===== 批量删除 =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batch_action']) && $_POST['batch_action'] === 'delete') {
    $batch_items = $_POST['items'] ?? [];
    $deleted_count = 0;
    $skipped_count = 0;

    foreach ($batch_items as $item_path) {
        $item_path = trim($item_path);
        if (strpos($item_path, '..') !== false) { $skipped_count++; continue; }

        $is_within_own_dir = ($current_username !== '' && (strpos($item_path, $current_username . '/') === 0 || $item_path === $current_username));
        if (!$is_admin && !$is_within_own_dir) { $skipped_count++; continue; }

        $real_path = realpath($base_dir . $item_path);
        if (!$real_path || strpos($real_path, realpath($base_dir)) !== 0) { $skipped_count++; continue; }

        if (is_file($real_path)) {
            if (@unlink($real_path)) {
                $deleted_count++;
                unset($passwords_data[$item_path]);
                unset($owners_data[$item_path]);
            } else {
                $skipped_count++;
            }
        } else {
            $skipped_count++; // 批量操作仅支持文件，文件夹请用单独的删除按钮
        }
    }

    file_put_contents($passwords_file, json_encode($passwords_data, JSON_PRETTY_PRINT));
    file_put_contents($owners_file, json_encode($owners_data, JSON_PRETTY_PRINT));

    $_SESSION['batch_msg'] = "批量删除完成：成功 {$deleted_count} 个" . ($skipped_count > 0 ? "，跳过 {$skipped_count} 个（无权限或不是文件）" : '');
    header("Location: index.php?" . http_build_query($_GET));
    exit;
}

// ===== 批量移动 =====
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['batch_action']) && $_POST['batch_action'] === 'move') {
    $batch_items = $_POST['items'] ?? [];
    $batch_dest = isset($_POST['dest']) ? trim($_POST['dest'], '/') : '';
    $moved_count = 0;
    $skipped_count = 0;

    foreach ($batch_items as $item_path) {
        $item_path = trim($item_path);
        if (strpos($item_path, '..') !== false || strpos($batch_dest, '..') !== false) { $skipped_count++; continue; }

        $is_within_own_dir = ($current_username !== '' && (strpos($item_path, $current_username . '/') === 0 || $item_path === $current_username));
        $can_move_item = $is_admin || $is_within_own_dir;
        if (!$is_admin && $can_move_item && $current_username !== '' && strpos($batch_dest, $current_username) !== 0) {
            $can_move_item = false;
        }
        if (!$can_move_item) { $skipped_count++; continue; }

        $source_real = realpath($base_dir . $item_path);
        if (!$source_real || strpos($source_real, realpath($base_dir)) !== 0 || !is_file($source_real)) { $skipped_count++; continue; }

        $dest_dir_full = $base_dir . ($batch_dest === '' ? '' : $batch_dest . '/');
        $dest_dir_real = realpath($dest_dir_full);
        if ($batch_dest !== '' && (!$dest_dir_real || strpos($dest_dir_real, realpath($base_dir)) !== 0 || !is_dir($dest_dir_real))) { $skipped_count++; continue; }

        $file_basename = basename($item_path);
        $new_relative_path = $batch_dest === '' ? $file_basename : $batch_dest . '/' . $file_basename;
        $new_full_path = $base_dir . $new_relative_path;

        if ($new_relative_path === $item_path || file_exists($new_full_path)) { $skipped_count++; continue; }

        if (@rename($source_real, $new_full_path)) {
            $moved_count++;
            if (isset($passwords_data[$item_path])) {
                $passwords_data[$new_relative_path] = $passwords_data[$item_path];
                unset($passwords_data[$item_path]);
            }
            if (isset($owners_data[$item_path])) {
                $owners_data[$new_relative_path] = $owners_data[$item_path];
                unset($owners_data[$item_path]);
            }
            foreach ($shares_data as $tok => $info) {
                if (($info['file'] ?? '') === $item_path) {
                    $shares_data[$tok]['file'] = $new_relative_path;
                }
            }
        } else {
            $skipped_count++;
        }
    }

    file_put_contents($passwords_file, json_encode($passwords_data, JSON_PRETTY_PRINT));
    file_put_contents($owners_file, json_encode($owners_data, JSON_PRETTY_PRINT));
    file_put_contents($shares_file, json_encode($shares_data, JSON_PRETTY_PRINT));

    $_SESSION['batch_msg'] = "批量移动完成：成功 {$moved_count} 个" . ($skipped_count > 0 ? "，跳过 {$skipped_count} 个（无权限/目标目录不存在/已有同名文件）" : '');
    header("Location: index.php?" . http_build_query($_GET));
    exit;
}

