<?php
/**
 * 创建和取消分享请求。
 */
if (!defined('CLOUD_STORAGE_ENTRY')) {
    http_response_code(404);
    exit;
}

function rejectShareRequest($status_code, $message) {
    http_response_code($status_code);
    echo 'Error: ' . $message;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['share_action'])) {
    header('Content-Type: text/plain; charset=utf-8');
    
    $current_user = $_SESSION['username'] ?? '';
    if ($current_user === '') {
        rejectShareRequest(403, '登录状态无效');
    }
    
    if ($_POST['share_action'] === 'create') {
        $file_path = str_replace('\\', '/', trim((string)($_POST['share_file'] ?? '')));
        $file_path = ltrim($file_path, '/');
        $days = intval($_POST['share_expiry'] ?? 0);
        
        if ($file_path === '' || strpos($file_path, "\0") !== false) {
            rejectShareRequest(400, '文件路径无效');
        }
        if (!in_array($days, [0, 1, 7, 30], true)) {
            rejectShareRequest(400, '分享有效期无效');
        }

        $base_real = realpath($base_dir);
        $file_real = realpath($base_dir . $file_path);
        if ($base_real === false || $file_real === false || !is_file($file_real)) {
            rejectShareRequest(404, '文件不存在');
        }
        if (!isStoragePathInsideBase($file_real, $base_real)) {
            rejectShareRequest(403, '无权分享该文件');
        }

        // 普通用户只能分享自己专属目录中的真实文件；realpath 可阻止 ../ 和符号链接逃逸。
        if (!$is_admin) {
            $user_root_real = realpath($base_dir . $current_user);
            if ($user_root_real === false || !isStoragePathInsideBase($file_real, $user_root_real)) {
                rejectShareRequest(403, '无权分享该文件');
            }
        }

        // 保存经过 realpath 校验后的规范相对路径，不信任前端原始字符串。
        $base_normalized = rtrim(str_replace('\\', '/', $base_real), '/');
        $file_normalized = str_replace('\\', '/', $file_real);
        $file_path = ltrim(substr($file_normalized, strlen($base_normalized)), '/');
        
        $shares = file_exists($shares_file) ? (json_decode(@file_get_contents($shares_file), true) ?: []) : [];
        $token = bin2hex(random_bytes(16));
        $expiry_time = ($days > 0) ? time() + ($days * 86400) : null;
        
        $shares[$token] = [
            'file' => $file_path,
            'name' => basename($file_path),
            'owner' => $current_user,
            'expiry' => $expiry_time,
            'views' => 0,
            'downloads' => 0,
            'created_at' => time()
        ];
        
        $shares_json = json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($shares_json === false || file_put_contents($shares_file, $shares_json, LOCK_EX) === false) {
            rejectShareRequest(500, '分享记录保存失败');
        }
        echo $token;
        exit;
    }
    
    if ($_POST['share_action'] === 'delete') {
        $token = $_POST['share_token'] ?? '';
        $shares = file_exists($shares_file) ? (json_decode(@file_get_contents($shares_file), true) ?: []) : [];
        
        if (isset($shares[$token])) {
            // 权限检查：管理员可以删除任何分享，普通用户只能删除自己的
            if ($is_admin || ($shares[$token]['owner'] ?? '') === $current_user) {
                unset($shares[$token]);
                file_put_contents($shares_file, json_encode($shares, JSON_PRETTY_PRINT));
                echo 'ok';
            } else {
                echo 'Error';
            }
        } else {
            echo 'Error';
        }
        exit;
    }
    
    if ($_POST['share_action'] === 'delete_old') {
        $token = $_POST['share_token'] ?? '';
        $shares = file_exists($shares_file) ? (json_decode(@file_get_contents($shares_file), true) ?: []) : [];
        if (!isset($shares[$token]) || (!$is_admin && ($shares[$token]['owner'] ?? '') !== $current_user)) {
            rejectShareRequest(403, '无权删除该分享');
        }

        unset($shares[$token]);
        $shares_json = json_encode($shares, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($shares_json === false || file_put_contents($shares_file, $shares_json, LOCK_EX) === false) {
            rejectShareRequest(500, '分享记录保存失败');
        }
        echo 'ok';
        exit;
    }
}



