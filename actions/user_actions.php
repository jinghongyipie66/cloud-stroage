<?php
/**
 * 管理员创建、改密和删除用户。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

// 管理员用户管理接口
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['admin_action'])) {
    header('Content-Type: text/plain; charset=utf-8');
    
    if (!$is_admin) {
        echo 'Error';
        exit;
    }

    $pdo = get_db_connection();
    
    if ($_POST['admin_action'] === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($role === 'admin' && !$is_super_admin) {
            http_response_code(403);
            echo 'Error: 只有超级管理员可以创建管理员';
            exit;
        }

        if (empty($username) || empty($password)) {
            echo 'Error: 账号或密码不能为空';
            exit;
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $check->execute([$username]);
        if ($check->fetch()) {
            echo 'User exists';
            exit;
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare('INSERT INTO users (username, password, role, created_at) VALUES (?, ?, ?, ?)');
        $insert->execute([$username, $hashed, $role, time()]);

        // 创建该用户的专属隔离目录及分类文件夹（仅该用户与管理员可见）
        ensure_user_folders($username);

        echo 'Success';
        exit;
    }

    if ($_POST['admin_action'] === 'change_password') {
        $username = trim($_POST['username'] ?? '');
        $new_password = $_POST['password'] ?? '';

        if (empty($username) || empty($new_password)) {
            echo 'Error: 账号或新密码不能为空';
            exit;
        }

        $check = $pdo->prepare('SELECT id, role FROM users WHERE username = ?');
        $check->execute([$username]);
        $target_user = $check->fetch();
        if (!$target_user) {
            echo 'Error: 用户不存在';
            exit;
        }
        if (!$is_super_admin && ($target_user['role'] ?? 'user') !== 'user') {
            http_response_code(403);
            echo 'Error: 普通管理员只能管理普通用户';
            exit;
        }

        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE users SET password = ? WHERE username = ?');
        $update->execute([$hashed, $username]);

        if ($username === $current_username) {
            remember_account_session(['id' => $target_user['id'], 'password' => $hashed]);
            // 此管理弹窗成功后不刷新页面，保留当前 CSRF，避免后续操作误报失效。
            session_regenerate_id(true);
        }

        echo 'Success';
        exit;
    }
    
    if ($_POST['admin_action'] === 'delete_user') {
        $username = trim($_POST['username'] ?? '');
        $delete_user_data = ($_POST['delete_data'] ?? '0') === '1';

        if ($username === '' || $username === $_SESSION['username']) {
            echo 'Error: 不能删除当前登录账号';
            exit;
        }
        if (basename($username) !== $username || strpos($username, '..') !== false || strpos($username, '/') !== false || strpos($username, '\\') !== false) {
            echo 'Error: 非法用户名';
            exit;
        }
        if ($username === 'admin') {
            http_response_code(403);
            echo 'Error: 超级管理员账号受保护，不能删除';
            exit;
        }

        $target_check = $pdo->prepare('SELECT id, role FROM users WHERE username = ?');
        $target_check->execute([$username]);
        $target_user = $target_check->fetch();
        if (!$target_user) {
            echo 'Error: 用户不存在';
            exit;
        }
        if (!$is_super_admin && ($target_user['role'] ?? 'user') !== 'user') {
            http_response_code(403);
            echo 'Error: 普通管理员只能管理普通用户';
            exit;
        }

        $moved_user_dir = null;
        $archive_name = '';
        $user_dir = $base_dir . $username;
        if (is_link($user_dir)) {
            echo 'Error: 用户目录不能是符号链接';
            exit;
        }
        if (is_dir($user_dir)) {
            $base_real = realpath($base_dir);
            $user_real = realpath($user_dir);
            if (!$base_real || !$user_real || !isStoragePathInsideBase($user_real, $base_real)) {
                echo 'Error: 用户目录路径校验失败';
                exit;
            }

            if ($delete_user_data) {
                $moved_user_dir = $base_dir . '.delete_pending_' . bin2hex(random_bytes(8));
            } else {
                $archive_root = dirname(CLOUD_STORAGE_ROOT) . DIRECTORY_SEPARATOR . 'upload_backup_retained_users';
                if (!is_dir($archive_root) && !@mkdir($archive_root, 0700, true)) {
                    echo 'Error: 无法创建用户文件归档目录';
                    exit;
                }
                $safe_username = preg_replace('/[^\p{L}\p{N}_-]/u', '_', $username) ?: 'user';
                $archive_name = $safe_username . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
                $moved_user_dir = $archive_root . DIRECTORY_SEPARATOR . $archive_name;
            }

            if (!@rename($user_real, $moved_user_dir)) {
                echo 'Error: 无法移动用户目录，请检查文件占用或目录权限';
                exit;
            }

            if (!$delete_user_data) {
                $prefix = $username . '/';
                $archive_passwords = array_filter($passwords_data, function($path) use ($prefix) { return strpos($path, $prefix) === 0; }, ARRAY_FILTER_USE_KEY);
                $archive_owners = array_filter($owners_data, function($path) use ($prefix) { return strpos($path, $prefix) === 0; }, ARRAY_FILTER_USE_KEY);
                $archive_shares = array_filter($shares_data, function($share) use ($prefix) { return strpos($share['file'] ?? '', $prefix) === 0; });
                $archive_info = [
                    'username' => $username,
                    'archived_at' => date(DATE_ATOM),
                    'archived_by' => $_SESSION['username'] ?? 'unknown',
                    'original_path' => 'data/' . $username,
                    'passwords' => $archive_passwords,
                    'owners' => $archive_owners,
                    'shares' => $archive_shares,
                ];
                $manifest_written = @file_put_contents(
                    $moved_user_dir . DIRECTORY_SEPARATOR . '.archive_info.json',
                    json_encode($archive_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    LOCK_EX
                );
                if ($manifest_written === false) {
                    @rename($moved_user_dir, $user_dir);
                    echo 'Error: 无法写入归档说明，账号未删除';
                    exit;
                }
            }
        }

        try {
            $delete = $pdo->prepare('DELETE FROM users WHERE username = ?');
            $delete->execute([$username]);
            if ($delete->rowCount() === 0) {
                throw new RuntimeException('用户不存在');
            }
        } catch (Throwable $e) {
            if ($moved_user_dir && is_dir($moved_user_dir)) {
                @unlink($moved_user_dir . DIRECTORY_SEPARATOR . '.archive_info.json');
                @rename($moved_user_dir, $user_dir);
            }
            echo 'Error: ' . $e->getMessage();
            exit;
        }

        removeMetadataForPath($username, $passwords_data, $owners_data, $shares_data, $passwords_file, $owners_file, $shares_file);

        if ($delete_user_data) {
            $cleanup_warning = '';
            if ($moved_user_dir && is_dir($moved_user_dir)) {
                try {
                    deleteDirectoryTree($moved_user_dir, $base_dir);
                } catch (Throwable $e) {
                    $cleanup_warning = '；账号已删除，但暂存目录清理失败，请管理员手动检查';
                }
            }
            echo $cleanup_warning === '' ? 'Success' : 'SuccessWithWarning' . $cleanup_warning;
        } else {
            echo 'SuccessRetained:' . ($archive_name !== '' ? $archive_name : '未找到用户文件目录');
        }
        exit;
    }
}
