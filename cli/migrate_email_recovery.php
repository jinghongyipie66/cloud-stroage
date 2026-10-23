<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!in_array('--apply', $argv, true)) {
    echo "本操作新增 users 的两个可空邮箱字段、唯一索引和三张邮箱功能表。\n不改密码、不改文件。DDL 不能整体事务回滚，请先备份数据库；可恢复旧代码并保留新增结构。\n确认备份后执行：php cli/migrate_email_recovery.php --apply\n";
    exit;
}
try {
    require_once dirname(__DIR__) . '/db_config.php';
    require_once dirname(__DIR__) . '/helpers/email_schema.php';
    recovery_migrate(get_db_connection());
    echo "邮箱功能数据库结构准备完成。重复执行不会重复添加字段。\n";
} catch (Throwable $e) {
    fwrite(STDERR, "迁移失败。请检查数据库权限、users 的 InnoDB 引擎及是否有同名字段/表冲突。不要把包含凭据的配置或完整异常截图发到聊天。\n");
    exit(1);
}
