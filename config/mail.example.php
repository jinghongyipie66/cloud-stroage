<?php
/** 示例：管理员复制到 /etc/cloud-storage/mail.php 后手工填写，不覆盖 db_config.php。 */
return [
    'enabled' => false,
    'app_key' => '', // 用 php cli/generate_mail_key.php 在服务器本地生成，仅自己保存。
    'smtp_host' => 'smtp.163.com',
    'smtp_port' => 465,
    'smtp_username' => '',
    'smtp_password' => '', // 网易客户端授权码，不是登录密码；不要发给助手。
    'from_email' => '', // 与 smtp_username 相同的完整 163 邮箱。
    'from_name' => '瑞锦云存储',
    'daily_send_limit' => 150, // 网站自己的保守限额，并非网易承诺的额度。
];
