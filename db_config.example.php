<?php
/** Empty deployment template; this is not the existing server configuration.
 * Copy to db_config.php only for a NEW deployment, then configure locally.
 * Existing deployments must retain their own db_config.php.
 */
$db_host = getenv('CLOUD_STORAGE_DB_HOST') ?: '127.0.0.1';
$db_port = (int)(getenv('CLOUD_STORAGE_DB_PORT') ?: 3306);
$db_name = getenv('CLOUD_STORAGE_DB_NAME') ?: 'cloud_storage';
$db_user = getenv('CLOUD_STORAGE_DB_USER') ?: '';
$db_pass = getenv('CLOUD_STORAGE_DB_PASSWORD') ?: '';

function get_db_connection() {
    global $db_host, $db_port, $db_name, $db_user, $db_pass;
    static $connection = null;
    if ($connection instanceof PDO) return $connection;
    if ($db_user === '' || $db_pass === '') {
        throw new RuntimeException('请在服务器本地配置数据库账号和密码。');
    }
    try {
        $connection = new PDO(
            "mysql:host={$db_host};port={$db_port};dbname={$db_name};charset=utf8mb4",
            $db_user, $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
             PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
             PDO::ATTR_EMULATE_PREPARES => false]
        );
        return $connection;
    } catch (PDOException $error) {
        throw new RuntimeException('数据库连接失败，请在服务器检查本地连接配置。');
    }
}
