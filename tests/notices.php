<?php
// Reuse the existing in-memory fixtures and rerun their regression suite first.
require __DIR__ . '/run.php';
$existing_checks = $passed;
function seed_notice_limit($pdo, $key, $scope, $value, $hits, $until) {
    $q = $pdo->prepare('INSERT OR REPLACE INTO email_rate_limits (bucket_key, hits, expires_at) VALUES (?, ?, ?)');
    $q->execute([recovery_digest($key, 'rate:' . $scope, $value), $hits, $until]);
}
function text_has($text, $part) { return strpos($text, $part) !== false; }
try {
    list($pdo, $svc, $clock, $key) = fixture();
    $r = $svc->requestRegistration(' ALICE@EXAMPLE.TEST ', 'browser-A', '192.0.2.90');
    check(text_has($r['error'], '该邮箱已注册') && !isset($r['id']) && $r['clear_flow'], 'occupied email normalized and reported explicitly');
    check((int)$pdo->query('SELECT COUNT(*) FROM email_challenges')->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, 'occupied email creates neither challenge nor outbox entry');
    $r = $svc->requestRegistration('alice@example.test', 'browser-A', '192.0.2.90');
    check(!empty($r['limited']) && $r['retry_after'] === 60, 'occupied-email enumeration remains subject to cooldown');

    list($pdo, $svc, $clock, $key) = fixture();
    $pdo->exec("UPDATE users SET username = 'legacy@example.test' WHERE username = 'bob'");
    check(isset($svc->requestRegistration('legacy@example.test', 'browser-A', '192.0.2.90')['error']), 'legacy email-shaped username cannot be duplicated');
    $pdo->exec("UPDATE users SET recovery_email = 'unverified@example.test' WHERE id = 2");
    check(isset($svc->requestRegistration('unverified@example.test', 'browser-A', '192.0.2.91')['error']), 'reserved unverified mailbox is still unavailable without claiming it is verified');

    list($pdo, $svc, $clock, $key) = fixture();
    $r = $svc->request('bind', 'alice', 'alice@example.test', 'Fixture-old-123', 'browser-A', '192.0.2.90');
    check(text_has($r['error'], '无需重复验证') && !isset($r['id']), 'same-account mailbox reports already bound');
    $r = $svc->request('bind', 'bob', 'admin@example.test', 'Fixture-old-123', 'browser-B', '192.0.2.91');
    check(text_has($r['error'], '其他账号') && !isset($r['id']), 'other-account mailbox reports occupied after password verification');
    check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, 'neither duplicate binding queues mail');

    $rules = [
        ['send-ip-hour', '192.0.2.90', 30, 3600, '当前网络每小时'],
        ['send-ip-day', '192.0.2.90', 100, 86400, '当前网络 24 小时'],
        ['send-email-cooldown', 'new@example.test', 1, 60, '同一邮箱重发'],
        ['send-email-day', 'new@example.test', 10, 86400, '同一邮箱 24 小时'],
        ['register-ip-hour', '192.0.2.90', 10, 3600, '10 次注册'],
        ['register-ip-day', '192.0.2.90', 20, 86400, '20 次注册'],
    ];
    foreach ($rules as $rule) {
        list($pdo, $svc, $clock, $key) = fixture();
        list($scope, $value, $limit, $seconds, $label) = $rule;
        seed_notice_limit($pdo, $key, $scope, $value, $limit, $clock->now + $seconds);
        $r = $svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90');
        check(!empty($r['limited']) && $r['retry_after'] === $seconds && text_has(implode(' ', $r['limit_reasons']), $label)
            && (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, $scope . ': correct reason/deadline and no mail');
    }
    foreach ([['send-name-cooldown', 1, 60], ['send-name-day', 10, 86400]] as $rule) {
        list($pdo, $svc, $clock, $key) = fixture();
        seed_notice_limit($pdo, $key, $rule[0], 'alice', $rule[1], $clock->now + $rule[2]);
        $r = $svc->request('reset', 'alice', 'alice@example.test', '', 'browser-A', '192.0.2.90');
        check(!empty($r['limited']) && text_has(implode(' ', $r['limit_reasons']), '同一账号'), $rule[0] . ': account restriction is identified without account data');
    }
    list($pdo, $svc, $clock, $key) = fixture();
    seed_notice_limit($pdo, $key, 'send-email-day', 'new@example.test', 9, $clock->now + 86400);
    check(isset($svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90')['id']), 'tenth email request remains allowed');
    $clock->now += 61;
    check(!empty($svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90')['limited']), 'eleventh email request remains blocked');

    list($pdo, $svc, $clock, $key) = fixture();
    seed_notice_limit($pdo, $key, 'global-delivery-day', 'all', 150, $clock->now + 3600);
    $r = $svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90');
    check(!empty($r['limited']) && $r['retry_after'] === 3600 && text_has(implode(' ', $r['limit_reasons']), '系统邮件'), 'exhausted system delivery quota is reported before queueing');
    $known = $svc->request('reset', 'alice', 'alice@example.test', '', 'browser-A', '192.0.2.91');
    $unknown = $svc->request('reset', 'missing', 'missing@example.test', '', 'browser-B', '192.0.2.92');
    check($known === $unknown && (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, 'system quota response is identical for known and unknown reset accounts');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90')['id'];
    $code = issued_code($pdo, $key, $id); $name = 'u_' . str_repeat('c', 24); $pw = 'Fixture-notice-123';
    $r = $svc->completeRegistration($id, 'wrong', 'browser-B', '192.0.2.90', $pw, $pw, $name);
    check(!text_has($r['message'], '还可尝试') && (int)$pdo->query('SELECT attempts FROM email_challenges')->fetchColumn() === 0, 'foreign browser cannot inspect or consume challenge attempts');
    $r = $svc->completeRegistration($id, 'wrong', 'browser-A', '192.0.2.90', 'short', 'short', $name);
    check(text_has($r['message'], '8～72') && (int)$pdo->query('SELECT attempts FROM email_challenges')->fetchColumn() === 0, 'password length message remains explicit without consuming code');
    $r = $svc->completeRegistration($id, 'wrong', 'browser-A', '192.0.2.90', $pw, 'different', $name);
    check(text_has($r['message'], '不一致'), 'password confirmation message remains explicit');
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $r = $svc->completeRegistration($id, 'wrong', 'browser-A', '192.0.2.90', $pw, $pw, $name);
        check(!$r['ok'] && ($attempt < 5 ? text_has($r['message'], '还可尝试 ' . (5 - $attempt)) && !$r['clear_flow'] : $r['clear_flow'] && text_has($r['message'], '5 次')), 'incorrect registration code attempt ' . $attempt . ' has the right notice');
    }
    $r = $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.90', $pw, $pw, $name);
    check(!$r['ok'] && text_has($r['message'], '尝试次数已用完') && $r['clear_flow'], 'locked challenge cannot succeed using the correct code');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90')['id'];
    $code = issued_code($pdo, $key, $id); $clock->now += 600;
    $r = $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.90', $pw, $pw, $name);
    check(!$r['ok'] && text_has($r['message'], '已过期') && $r['clear_flow'], 'expiry notice is explicit at the ten-minute boundary');
    $pdo->exec('UPDATE email_challenges SET consumed = 1');
    $r = $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.90', $pw, $pw, $name);
    check(!$r['ok'] && text_has($r['message'], '已使用或已被新验证码替换'), 'consumed/replaced code explains why it cannot be reused');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.90')['id'];
    $code = issued_code($pdo, $key, $id);
    seed_notice_limit($pdo, $key, 'register-create-ip-day', '192.0.2.90', 5, $clock->now + 86400);
    $r = $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.90', $pw, $pw, $name);
    check(!$r['ok'] && !empty($r['limited']) && text_has($r['message'], '24小时') && text_has($r['message'], '5 次'), 'registration submission quota displays reason and remaining wait');
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'registration quota does not create an account');
    seed_notice_limit($pdo, $key, 'verify-ip-hour', '192.0.2.90', 100, $clock->now + 3600);
    $r = $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.90', $pw, $pw, $name);
    check(!$r['ok'] && !empty($r['limited']) && text_has($r['message'], '100 次'), 'verification request quota is not misreported as an invalid code');

    list($pdo, $svc, $clock, $key) = fixture();
    $real = $svc->request('reset', 'alice', 'alice@example.test', '', 'browser-A', '192.0.2.90');
    $dummy = $svc->request('reset', 'missing', 'missing@example.test', '', 'browser-B', '192.0.2.91');
    $realFailure = $svc->complete('reset', $real['id'], 'wrong', 'browser-A', '192.0.2.90', $pw, $pw);
    $dummyFailure = $svc->complete('reset', $dummy['id'], 'wrong', 'browser-B', '192.0.2.91', $pw, $pw);
    check($realFailure === $dummyFailure, 'password reset still hides account/mailbox matching in verification responses');
    echo '\nAdditional notice checks passed: ' . ($passed - $existing_checks) . PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . PHP_EOL); exit(1); }
