<?php
/** 只由 CLI 迁移显式调用；普通网页请求绝不执行 DDL。 */
function recovery_migrate(PDO $pdo) {
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        throw new RuntimeException('迁移仅支持 MySQL/MariaDB。');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $engine = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchColumn();
    if (strtolower((string)$engine) !== 'innodb') {
        throw new RuntimeException('users 必须使用 InnoDB；本迁移不会自动转换存储引擎。');
    }
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $known = array_column($columns, null, 'Field');
    if (!isset($known['recovery_email'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN recovery_email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NULL DEFAULT NULL');
    } elseif (strtolower($known['recovery_email']['Type']) !== 'varchar(254)' || $known['recovery_email']['Null'] !== 'YES') {
        throw new RuntimeException('已有 recovery_email 列与预期不同，请先人工核对。');
    }
    if (!isset($known['recovery_email_verified_at'])) {
        $pdo->exec('ALTER TABLE users ADD COLUMN recovery_email_verified_at BIGINT UNSIGNED NULL DEFAULT NULL');
    }
    $indexes = $pdo->query('SHOW INDEX FROM users')->fetchAll(PDO::FETCH_ASSOC);
    $email_index = array_filter($indexes, function($index) { return $index['Key_name'] === 'uq_users_recovery_email'; });
    if (!$email_index) {
        $pdo->exec('ALTER TABLE users ADD UNIQUE INDEX uq_users_recovery_email (recovery_email)');
    } elseif (count($email_index) !== 1 || (int)reset($email_index)['Non_unique'] !== 0
        || reset($email_index)['Column_name'] !== 'recovery_email') {
        throw new RuntimeException('已有邮箱索引与预期不同，请先人工核对。');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_challenges (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        user_id BIGINT UNSIGNED NOT NULL,
        purpose VARCHAR(8) NOT NULL,
        email VARCHAR(254) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
        email_before VARCHAR(254) NOT NULL DEFAULT '',
        account_signature CHAR(64) NOT NULL,
        client_context CHAR(64) NOT NULL,
        code_hash CHAR(64) NOT NULL,
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        consumed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        created_at BIGINT NOT NULL,
        expires_at BIGINT NOT NULL,
        INDEX idx_email_user_purpose (user_id, purpose),
        INDEX idx_email_challenge_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_rate_limits (
        bucket_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        hits INT UNSIGNED NOT NULL,
        expires_at BIGINT NOT NULL,
        INDEX idx_email_rate_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS email_outbox (
        id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        challenge_id CHAR(32) NULL,
        payload MEDIUMTEXT NULL,
        state VARCHAR(12) NOT NULL DEFAULT 'pending',
        attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        available_at BIGINT NOT NULL,
        lease_token CHAR(32) NULL,
        created_at BIGINT NOT NULL,
        expires_at BIGINT NOT NULL,
        INDEX idx_email_outbox_claim (state, available_at),
        INDEX idx_email_outbox_challenge (challenge_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['email_challenges', 'email_rate_limits', 'email_outbox'] as $table) {
        $stmt = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        if (strtolower((string)$stmt->fetchColumn()) !== 'innodb') throw new RuntimeException('邮箱表必须使用 InnoDB。');
    }
}
