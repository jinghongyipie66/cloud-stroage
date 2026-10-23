<?php
/**
 * 为 users 表增加最后登录时间字段。
 * 仅允许在服务器命令行运行：php migrate_last_login.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db_config.php';

try {
    $pdo = get_db_connection();
    $check = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'last_login_at'"
    );

    if ((int)$check->fetchColumn() > 0) {
        echo "last_login_at 字段已存在，无需重复迁移。\n";
        exit(0);
    }

    $pdo->exec(
        'ALTER TABLE users
         ADD COLUMN last_login_at INT UNSIGNED NULL DEFAULT NULL AFTER created_at'
    );

    echo "迁移完成：users.last_login_at 已创建。\n";
} catch (Throwable $error) {
    fwrite(STDERR, "迁移失败：" . $error->getMessage() . "\n");
    exit(1);
}

