<?php
require_once __DIR__ . '/email_common.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/src/Exception.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/src/SMTP.php';
require_once dirname(__DIR__) . '/vendor/phpmailer/src/PHPMailer.php';

function recovery_send_mail($config, $payload) {
    $sender = recovery_email($config['from_email'] ?? '');
    $username = recovery_email($config['smtp_username'] ?? '');
    $recipient = recovery_email($payload['to'] ?? '');
    if ($sender === '' || $sender !== $username || $recipient === '' || empty($config['smtp_password'])) {
        throw new RuntimeException('邮件发送配置不完整。');
    }
    if (($config['smtp_host'] ?? '') !== 'smtp.163.com' || (int)($config['smtp_port'] ?? 0) !== 465) {
        throw new RuntimeException('请使用网易 smtp.163.com 的 465 加密端口。');
    }
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.163.com';
    $mail->Port = 465;
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->SMTPAuth = true;
    $mail->Username = $username;
    $mail->Password = $config['smtp_password'];
    $mail->SMTPDebug = 0;
    $mail->Timeout = 15;
    $mail->Timelimit = 30;
    $mail->CharSet = 'UTF-8';
    $mail->SMTPOptions = ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]];
    $mail->setFrom($sender, $config['from_name'] ?? '瑞锦云存储');
    $mail->addAddress($recipient);
    $mail->isHTML(false);
    $mail->Subject = $payload['subject'];
    $mail->Body = $payload['body'];
    $mail->send();
}
