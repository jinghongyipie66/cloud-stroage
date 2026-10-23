<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/helpers/email_recovery.php';
require_once dirname(__DIR__) . '/helpers/email_transport.php';
$failed = 0;
$sent = 0;
try {
    $config = recovery_config();
    if (empty($config['enabled'])) { echo "邮箱功能未开启。\n"; exit; }
    require_once dirname(__DIR__) . '/db_config.php';
    $recovery = new EmailRecovery(get_db_connection(), recovery_key($config), $config['daily_send_limit'] ?? 150);
    // 一次最多处理 20 个任务，适合每分钟运行一次 cron，无须常驻服务。
    for ($i = 0; $i < 20; $i++) {
        $job = $recovery->claimMail();
        if (!$job || !empty($job['limited'])) break;
        if (!empty($job['discarded'])) continue;
        try {
            recovery_send_mail($config, $recovery->mailPayload($job));
            $recovery->finishMail($job, true);
            $sent++;
        } catch (Throwable $e) {
            $recovery->finishMail($job, false);
            $failed++;
            // 不输出异常原文、收件人、验证码、邮箱授权码或 SMTP 对话。
            fwrite(STDERR, "邮件发送失败，已安排有限次数重试。请检查 SMTP 配置、证书、网络及发信限制。\n");
        }
    }
    $recovery->prune();
    echo '邮件处理完成：成功 ' . $sent . '，失败 ' . $failed . PHP_EOL;
    exit($failed ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, "邮件任务失败，请检查 PHP 扩展、数据库迁移和邮件配置。\n");
    exit(1);
}
