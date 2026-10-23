<?php
/** 完全隔离：只使用内存 SQLite、虚构账号，不加载生产 db_config.php，不发邮件。 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/helpers/email_recovery.php';
require_once dirname(__DIR__) . '/helpers/email_transport.php';
require_once dirname(__DIR__) . '/security.php';

$passed = 0;
function check($condition, $name) {
    global $passed;
    if (!$condition) throw new RuntimeException('FAIL: ' . $name);
    $passed++;
    echo 'PASS: ' . $name . PHP_EOL;
}
function throws_check($fn, $name) {
    try { $fn(); } catch (Throwable $e) { check(true, $name); return; }
    check(false, $name);
}
function fixture() {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(file_get_contents(__DIR__ . '/fixtures/schema.sql'));
    $insert = $pdo->prepare('INSERT INTO users (id, username, password, role, recovery_email, recovery_email_verified_at) VALUES (?, ?, ?, ?, ?, ?)');
    $insert->execute([1, 'alice', password_hash('Fixture-old-123', PASSWORD_DEFAULT), 'user', 'alice@example.test', 1000]);
    $insert->execute([2, 'bob', password_hash('Fixture-old-123', PASSWORD_DEFAULT), 'user', null, null]);
    $insert->execute([3, 'admin', password_hash('Fixture-old-123', PASSWORD_DEFAULT), 'admin', 'admin@example.test', 1000]);
    $clock = (object)['now' => 200000];
    $key = hash('sha256', 'PUBLIC TEST FIXTURE KEY - NEVER USE IN PRODUCTION', true);
    $svc = new EmailRecovery($pdo, $key, 150, function() use ($clock) { return $clock->now; });
    return [$pdo, $svc, $clock, $key];
}
function issued_code($pdo, $key, $id) {
    $q = $pdo->prepare('SELECT id, payload FROM email_outbox WHERE challenge_id = ?');
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $payload = recovery_decrypt($key, $row['id'], $row['payload']);
    preg_match('/[0-9]{6}/', $payload['body'], $m);
    return $m[0];
}
function reset_request($svc, $context = 'browser-A', $ip = '192.0.2.1') {
    return $svc->request('reset', 'alice', 'alice@example.test', '', $context, $ip)['id'];
}

try {
    check(recovery_email(' Alice@EXAMPLE.TEST ') === 'alice@example.test', 'email normalization');
    check(recovery_email("x@example.test\r\nBcc:evil@example.test") === '', 'header injection rejected');
    check(recovery_email(['x']) === '', 'array email rejected');
    check(recovery_email(str_repeat('x', 260) . '@example.test') === '', 'email length bounded');
    check(recovery_password_error('short', 'short') !== '', 'weak password rejected');
    check(recovery_password_error(str_repeat('x', 73), str_repeat('x', 73)) !== '', 'bcrypt truncation prevented');
    check(recovery_password_error("hello\0world", "hello\0world") !== '', 'null byte rejected');
    check(recovery_password_error('New-fixture-456', 'Different-456') !== '', 'confirmation required');
    check(recovery_password_error('New-fixture-456', 'New-fixture-456') === '', 'valid password accepted');
    $_POST = ['field' => ['injected']];
    check(recovery_post('field') === '', 'array POST input normalized safely');
    $_SESSION = [];
    $csrf = csrf_token();
    check(strlen($csrf) === 64 && strpos(csrf_field(), $csrf) !== false, 'existing CSRF field preserved');
    $_POST = ['_csrf' => $csrf];
    verify_csrf_or_fail();
    check(true, 'valid CSRF token accepted');

    list($pdo, $svc, $clock, $key) = fixture();
    $encrypted = recovery_encrypt($key, 'test-message', ['code' => '123456']);
    check(recovery_decrypt($key, 'test-message', $encrypted)['code'] === '123456', 'authenticated encryption round trip');
    throws_check(function() use ($key, $encrypted) { recovery_decrypt($key, 'other-message', $encrypted); }, 'outbox ciphertext tied to message id');
    throws_check(function() use ($key, $encrypted) { $raw = base64_decode($encrypted); $raw[29] = chr(ord($raw[29]) ^ 1); recovery_decrypt($key, 'test-message', base64_encode($raw)); }, 'tampered payload rejected');
    $account = $svc->account('alice');
    $signature = account_session_signature($account);
    check(account_session_matches($account, $signature), 'valid session signature');
    check(!account_session_matches($account, null), 'legacy session requires login');
    check(!account_session_matches(false, $signature), 'deleted account invalidates session');

    $unknown = $svc->request('reset', 'unknown', 'none@example.test', '', 'browser-A', '192.0.2.2');
    check(strlen($unknown['id']) === 32 && (int)$pdo->query('SELECT COUNT(*) FROM email_challenges')->fetchColumn() === 0, 'unknown account gets dummy flow without mail');
    $unbound = $svc->request('reset', 'bob', 'bob@example.test', '', 'browser-B', '192.0.2.3');
    check(strlen($unbound['id']) === 32 && (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, 'unbound email cannot reset');
    $id = reset_request($svc);
    $code = issued_code($pdo, $key, $id);
    $stored = $pdo->query('SELECT code_hash FROM email_challenges')->fetchColumn();
    check($stored !== $code && strlen($stored) === 64, 'code stored as keyed digest');
    $again = $svc->request('reset', 'alice', 'alice@example.test', '', 'browser-A', '192.0.2.1');
    check(!empty($again['limited']) && $again['retry_after'] === 60 && $again['limit_window'] === 60
        && (int)$pdo->query('SELECT COUNT(*) FROM email_challenges')->fetchColumn() === 1, '60-second resend returns public wait metadata without new mail');
    $bad = $svc->complete('reset', $id, $code, 'browser-B', '192.0.2.1', 'New-fixture-456', 'New-fixture-456');
    check(!$bad['ok'], 'code cannot cross browser context');
    $bad = $svc->complete('bind', $id, $code, 'browser-A', '192.0.2.1', '', '', 1);
    check(!$bad['ok'], 'reset code cannot bind email');
    $ok = $svc->complete('reset', $id, $code, 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456');
    check($ok['ok'] && password_verify('New-fixture-456', $svc->account('alice')['password']), 'verified reset writes password hash');
    check(!account_session_matches($svc->account('alice'), $signature), 'reset invalidates old device signature');
    check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox WHERE challenge_id IS NULL')->fetchColumn() === 1, 'reset notification queued without password');
    check(!$svc->complete('reset', $id, $code, 'browser-A', '192.0.2.1', 'Other-fixture-789', 'Other-fixture-789')['ok'], 'single-use code cannot replay');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = reset_request($svc); $code = issued_code($pdo, $key, $id);
    $clock->now += 600;
    check(!$svc->complete('reset', $id, $code, 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456')['ok'], 'expired code rejected exactly at expiry');
    check(!empty($svc->claimMail()['discarded']), 'expired queued code never sent');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = reset_request($svc); $code = issued_code($pdo, $key, $id);
    for ($i = 0; $i < 5; $i++) $svc->complete('reset', $id, 'bad-code', 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456');
    check(!$svc->complete('reset', $id, $code, 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456')['ok'], 'five wrong attempts lock challenge');
    check(password_verify('Fixture-old-123', $svc->account('alice')['password']), 'wrong codes do not change account');

    list($pdo, $svc, $clock, $key) = fixture();
    $old_id = reset_request($svc); $old_code = issued_code($pdo, $key, $old_id);
    $clock->now += 61;
    $new_id = reset_request($svc);
    check($old_id !== $new_id && !$svc->complete('reset', $old_id, $old_code, 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456')['ok'], 'resend invalidates earlier code');
    check($svc->complete('reset', $new_id, issued_code($pdo, $key, $new_id), 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456')['ok'], 'latest code remains usable');

    list($pdo, $svc, $clock, $key) = fixture();
    $bad_bind = $svc->request('bind', 'bob', 'bob@example.test', 'wrong', 'browser-B', '192.0.2.4');
    check(isset($bad_bind['error']) && (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === 0, 'binding requires current password');
    $limited_bind = $svc->request('bind', 'bob', 'bob@example.test', 'wrong', 'browser-B', '192.0.2.4');
    check(!empty($limited_bind['limited']) && $limited_bind['retry_after'] === 60, 'failed reauthentication still consumes rate budget with safe wait metadata');
    $clock->now += 61;
    $binding = $svc->request('bind', 'bob', 'bob@example.test', 'Fixture-old-123', 'browser-B', '192.0.2.4');
    $bind_code = issued_code($pdo, $key, $binding['id']);
    check(!$svc->complete('bind', $binding['id'], $bind_code, 'browser-B', '192.0.2.4', '', '', 1)['ok'], 'binding cannot target another account');
    check($svc->complete('bind', $binding['id'], $bind_code, 'browser-B', '192.0.2.4', '', '', 2)['ok'], 'binding writes verified email');
    check($svc->account('bob')['recovery_email'] === 'bob@example.test' && $svc->account('bob')['recovery_email_verified_at'] > 0, 'binding timestamp recorded');
    $clock->now += 61;
    $occupied = $svc->request('bind', 'bob', 'alice@example.test', 'Fixture-old-123', 'browser-B', '192.0.2.4');
    check(isset($occupied['error']) && !empty($occupied['clear_flow']) && !isset($occupied['id']), 'one email cannot bind two accounts and shows an explicit error');

    list($pdo, $svc, $clock, $key) = fixture();
    $reset_id = reset_request($svc);
    $clock->now += 61;
    $change = $svc->request('bind', 'alice', 'newalice@example.test', 'Fixture-old-123', 'browser-A', '192.0.2.1');
    check($svc->complete('bind', $change['id'], issued_code($pdo, $key, $change['id']), 'browser-A', '192.0.2.1', '', '', 1)['ok'], 'change mailbox succeeds with password and new-mail proof');
    check((int)$pdo->query('SELECT consumed FROM email_challenges WHERE purpose = \'reset\'')->fetchColumn() === 1, 'changing email revokes pending password reset');
    check((int)$pdo->query('SELECT COUNT(*) FROM email_outbox WHERE challenge_id IS NULL')->fetchColumn() === 1, 'old mailbox gets change notice');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = reset_request($svc); $code = issued_code($pdo, $key, $id);
    $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = 1');
    $stmt->execute([password_hash('Admin-changed-789', PASSWORD_DEFAULT)]);
    check(!$svc->complete('reset', $id, $code, 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456')['ok'], 'admin or self password change invalidates outstanding code');
    check(!empty($svc->claimMail()['discarded']), 'stale code discarded before delivery');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = reset_request($svc); $code = issued_code($pdo, $key, $id);
    $pdo->exec('DELETE FROM users WHERE id = 1'); // In-memory fixture only.
    check(!$svc->complete('reset', $id, $code, 'browser-A', '192.0.2.1', 'New-fixture-456', 'New-fixture-456')['ok'], 'deleted user cannot reset');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = reset_request($svc);
    $job = $svc->claimMail();
    check($job && $svc->claimMail() === null, 'leased job not double-claimed');
    check(recovery_email($svc->mailPayload($job)['to']) === 'alice@example.test', 'worker decrypts intended recipient');
    $svc->finishMail($job, false); $clock->now += 61;
    $retry = $svc->claimMail();
    check($retry['id'] === $job['id'] && $retry['lease_token'] !== $job['lease_token'], 'failed mail retries with new lease');
    $svc->finishMail($job, true);
    check($pdo->query('SELECT state FROM email_outbox')->fetchColumn() === 'sending', 'old worker cannot complete a new lease');
    $svc->finishMail($retry, true);
    check($pdo->query('SELECT payload FROM email_outbox')->fetchColumn() === null, 'sent code payload erased');

    list($pdo, $svc, $clock, $key) = fixture();
    reset_request($svc);
    for ($i = 0; $i < 3; $i++) { $job = $svc->claimMail(); $svc->finishMail($job, false); $clock->now += 61; }
    check(!empty($svc->claimMail()['discarded']), 'mail retry limit enforced');

    list($pdo, $svc, $clock, $key) = fixture();
    $small = new EmailRecovery($pdo, $key, 1, function() use ($clock) { return $clock->now; });
    reset_request($small);
    $small->request('reset', 'admin', 'admin@example.test', '', 'browser-admin', '192.0.2.8');
    $job = $small->claimMail(); $small->finishMail($job, true);
    check(!empty($small->claimMail()['limited']), 'global daily delivery cap enforced');
    $clock->now += 172801;
    $small->prune();
    check((int)$pdo->query('SELECT COUNT(*) FROM email_challenges')->fetchColumn() === 0, 'expired challenge cleanup');
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'cleanup preserves all accounts');

    // 邮箱注册新增回归场景：全部内存库，不创建真实账号或发信。
    list($pdo, $svc, $clock, $key) = fixture();
    $name = 'u_' . str_repeat('a', 24);
    $pw = 'Register-fixture-123';
    $id = $svc->requestRegistration(' NEW@example.test ', 'browser-A', '192.0.2.20')['id'];
    $code = issued_code($pdo, $key, $id);
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'sending registration code creates no account');
    $row = $pdo->query("SELECT * FROM email_challenges WHERE purpose = 'register'")->fetch();
    check($row['email'] === 'new@example.test' && (int)$row['user_id'] === 0 && $row['code_hash'] !== $code, 'registration is normalized and pending with hashed code');
    $limited_registration = $svc->requestRegistration('new@example.test', 'browser-A', '192.0.2.20');
    check(!empty($limited_registration['limited']) && $limited_registration['retry_after'] === 60, 'registration resend cooldown returns safe wait metadata');
    check(!$svc->completeRegistration($id, $code, 'browser-B', '192.0.2.20', $pw, $pw, $name)['ok'], 'registration code tied to browser');
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.20', 'short', 'short', $name)['ok'], 'registration rejects weak password');
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.20', $pw, 'different', $name)['ok'], 'registration requires password confirmation');
    throws_check(function() use ($svc, $id, $code, $pw) { $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.20', $pw, $pw, '../admin'); }, 'registration rejects external account path');
    check(!$svc->complete('reset', $id, $code, 'browser-A', '192.0.2.20', $pw, $pw)['ok'], 'registration code cannot reset existing account');
    $job = $svc->claimMail();
    check(isset($job['id']) && $svc->mailPayload($job)['to'] === 'new@example.test', 'worker accepts registration before user exists');
    $result = $svc->completeRegistration($id, $code, 'browser-A', '192.0.2.20', $pw, $pw, $name);
    $created = find_account_by_login($pdo, 'NEW@EXAMPLE.TEST');
    check($result['ok'] && $created['username'] === $name && $created['role'] === 'user', 'verification creates ordinary user with internal name');
    check($created['recovery_email_verified_at'] == $clock->now && password_verify($pw, $created['password']) && $created['password'] !== $pw, 'verified email and hashed password committed');
    check(account_display_label($created) === 'new@example.test' && account_display_label(find_account_by_login($pdo, 'admin')) === 'admin', 'email label does not rename legacy administrator');
    check(find_account_by_login($pdo, $name)['id'] === $created['id'] && find_account_by_login($pdo, 'alice')['id'] == 1, 'internal and old username login preserved');
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.20', $pw, $pw, 'u_' . str_repeat('b', 24))['ok'], 'successful registration code cannot replay');
    $svc->finishMail($job, true);
    check($pdo->query('SELECT payload FROM email_outbox')->fetchColumn() === null, 'registration delivery clears encrypted code');
    $clock->now += 61;
    $before = (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn();
    $duplicate = $svc->requestRegistration('NEW@example.test', 'browser-A', '192.0.2.20');
    check(isset($duplicate['error']) && !isset($duplicate['id']) && (int)$pdo->query('SELECT COUNT(*) FROM email_outbox')->fetchColumn() === $before, 'existing email gets an explicit error without a fake flow or mail');
    $clock->now += 61;
    $reset = $svc->request('reset', 'NEW@example.test', 'new@example.test', '', 'browser-A', '192.0.2.20');
    check($svc->complete('reset', $reset['id'], issued_code($pdo, $key, $reset['id']), 'browser-A', '192.0.2.20', 'Another-fixture-123', 'Another-fixture-123')['ok'], 'new user can recover by email identifier');
    $clock->now += 61;
    $bind = $svc->request('bind', $name, 'renamed@example.test', 'Another-fixture-123', 'browser-A', '192.0.2.20');
    check($svc->complete('bind', $bind['id'], issued_code($pdo, $key, $bind['id']), 'browser-A', '192.0.2.20', '', '', $created['id'])['ok'], 'email registered account can change bound email');
    check(find_account_by_login($pdo, 'new@example.test') === false && find_account_by_login($pdo, 'renamed@example.test')['username'] === $name, 'mailbox change preserves internal identity and revokes old email login');

    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('expiry@example.test', 'browser-A', '192.0.2.21')['id'];
    $code = issued_code($pdo, $key, $id); $clock->now += 600;
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.21', $pw, $pw, $name)['ok'], 'registration expires at ten minutes');
    check(!empty($svc->claimMail()['discarded']), 'expired registration never sent');
    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('locked@example.test', 'browser-A', '192.0.2.22')['id'];
    $code = issued_code($pdo, $key, $id);
    for ($i = 0; $i < 5; $i++) $svc->completeRegistration($id, 'bad', 'browser-A', '192.0.2.22', $pw, $pw, $name);
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.22', $pw, $pw, $name)['ok'], 'five wrong registration attempts lock challenge');
    check((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 3, 'invalid registration never changes users');
    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('resend@example.test', 'browser-A', '192.0.2.23')['id'];
    $code = issued_code($pdo, $key, $id); $clock->now += 61;
    $new = $svc->requestRegistration('resend@example.test', 'browser-A', '192.0.2.23')['id'];
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.23', $pw, $pw, $name)['ok'] && $svc->completeRegistration($new, issued_code($pdo, $key, $new), 'browser-A', '192.0.2.23', $pw, $pw, $name)['ok'], 'registration resend invalidates old and allows newest code');
    list($pdo, $svc, $clock, $key) = fixture();
    $id = reset_request($svc);
    check(!$svc->completeRegistration($id, issued_code($pdo, $key, $id), 'browser-A', '192.0.2.1', $pw, $pw, $name)['ok'], 'reset code cannot register');
    $pdo->exec("UPDATE users SET recovery_email = 'unverified@example.test' WHERE username = 'bob'");
    check(find_account_by_login($pdo, 'unverified@example.test') === false, 'unverified email cannot log in');
    $pdo->exec("UPDATE users SET username = 'alice@example.test' WHERE username = 'bob'");
    check(find_account_by_login($pdo, 'alice@example.test')['id'] == 2, 'historical email-shaped username keeps priority');
    list($pdo, $svc, $clock, $key) = fixture();
    $id = $svc->requestRegistration('claimed@example.test', 'browser-A', '192.0.2.24')['id'];
    $code = issued_code($pdo, $key, $id);
    $pdo->exec("UPDATE users SET recovery_email = 'claimed@example.test', recovery_email_verified_at = 1 WHERE username = 'bob'");
    check(!$svc->completeRegistration($id, $code, 'browser-A', '192.0.2.24', $pw, $pw, $name)['ok'], 'email claimed after code issue cannot be overwritten');
    check(!empty($svc->claimMail()['discarded']), 'worker discards now-occupied mailbox registration');
    $legacy = new PDO('sqlite::memory:');
    $legacy->exec("CREATE TABLE users (id INTEGER, username TEXT); INSERT INTO users VALUES (1, 'admin')");
    check(find_account_by_login($legacy, 'admin')['id'] == 1 && find_account_by_login($legacy, 'missing@example.test') === false, 'old schema keeps username lookup usable');

    throws_check(function() { recovery_send_mail(['from_email' => 'a@example.test'], []); }, 'incomplete SMTP config fails before network');
    throws_check(function() { recovery_send_mail(['from_email' => 'a@example.test', 'smtp_username' => 'a@example.test', 'smtp_password' => 'synthetic-test-only', 'smtp_host' => 'insecure.example.test', 'smtp_port' => 25], ['to' => 'b@example.test']); }, 'plaintext/non-NetEase SMTP rejected');
    echo "\nAll $passed checks passed. No real email sent; no production database accessed.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
