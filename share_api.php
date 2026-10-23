<?php
require_once __DIR__ . '/security.php';
start_secure_session();

// 旧分享 API 仅保留给已登录管理员，并统一使用 Session 与 CSRF 认证。
if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true || ($_SESSION['role'] ?? 'user') !== 'admin') {
    http_response_code(403);
    exit('Access Denied');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
}

$base_dir = __DIR__ . "/";
$shares_file = $base_dir . ".shares.json";

function get_shares() {
    global $shares_file;
    if (!file_exists($shares_file)) return [];
    return json_decode(file_get_contents($shares_file), true) ?: [];
}

function save_shares($shares) {
    global $shares_file;
    file_put_contents($shares_file, json_encode($shares, JSON_PRETTY_PRINT));
}

// 处理 API 请求
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $file_path = $_POST['file'] ?? '';
        $days = intval($_POST['expiry'] ?? 0);
        
        if (empty($file_path)) {
            http_response_code(400);
            echo 'Error: File path is empty';
            exit;
        }
        
        $shares = get_shares();
        $token = bin2hex(random_bytes(16));
        
        $expiry_time = ($days > 0) ? time() + ($days * 86400) : null;
        
        $shares[$token] = [
            'file' => $file_path,
            'name' => basename($file_path),
            'expiry' => $expiry_time,
            'views' => 0,
            'downloads' => 0,
            'created_at' => time()
        ];
        
        save_shares($shares);
        // 只返回 token，确保前端拼接正确
        echo $token;
        exit;
    }
    
    if ($action === 'delete') {
        $token = $_POST['token'] ?? '';
        if ($token) {
            $shares = get_shares();
            unset($shares[$token]);
            save_shares($shares);
            echo 'ok';
        }
        exit;
    }
}
?>

