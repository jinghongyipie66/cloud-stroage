<?php
require_once __DIR__ . '/email_common.php';
require_once __DIR__ . '/account_identity.php';

/** 邮箱绑定、找回密码。所有写操作用同一事务消费验证码并更新账号。 */
class EmailRecovery {
    private $pdo;
    private $key;
    private $daily_limit;
    private $clock;
    private $mysql;
    private $limit_retry_after = 0;
    private $limit_window = 0;
    private $limit_reasons = [];

    public function __construct(PDO $pdo, $key, $daily_limit = 150, $clock = null) {
        if (!is_string($key) || strlen($key) !== 32) throw new InvalidArgumentException('无效密钥。');
        $this->pdo = $pdo;
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->key = $key;
        $this->daily_limit = max(1, min(1000, (int)$daily_limit));
        $this->clock = $clock ?: function() { return time(); };
        $this->mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    }

    private function now() { return (int)call_user_func($this->clock); }
    private function lock() { return $this->mysql ? ' FOR UPDATE' : ''; }
    private function query($sql, $params = []) {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    private function transaction($fn) {
        // 回调内只有数据库操作，无发信等外部副作用；遇到死锁可整体回滚后有限重试。
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->pdo->beginTransaction();
            try {
                $result = $fn();
                $this->pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                $transient = $this->mysql && $e instanceof PDOException
                    && in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true);
                if (!$transient || $attempt === 2) throw $e;
                usleep(random_int(25000, 75000));
            }
        }
    }

    public function account($username) {
        return $this->query('SELECT id, username, password, recovery_email, recovery_email_verified_at FROM users WHERE username = ?', [$username])->fetch(PDO::FETCH_ASSOC);
    }

    /** 必须在事务内调用；固定顺序锁行，避免并发绕过计数。仅存 HMAC，不存明文 IP。 */
    private function limits($rules) {
        $now = $this->now();
        $this->limit_retry_after = 0;
        $this->limit_window = 0;
        $this->limit_reasons = [];
        $buckets = [];
        foreach ($rules as $rule) {
            list($scope, $value, $seconds, $limit) = $rule;
            $key = recovery_digest($this->key, 'rate:' . $scope, (string)$value);
            $buckets[$key] = [$seconds, $limit, $scope];
        }
        ksort($buckets);
        $allowed = true;
        foreach ($buckets as $key => $rule) {
            list($seconds, $limit, $scope) = $rule;
            // MySQL 用无副作用的 UPSERT 直接获得排他锁，避免 INSERT IGNORE 共享锁升级死锁。
            $insert = $this->mysql
                ? 'INSERT INTO email_rate_limits (bucket_key, hits, expires_at) VALUES (?, 0, ?) ON DUPLICATE KEY UPDATE bucket_key = VALUES(bucket_key)'
                : 'INSERT OR IGNORE INTO email_rate_limits (bucket_key, hits, expires_at) VALUES (?, 0, ?)';
            $this->query($insert, [$key, $now + $seconds]);
            $row = $this->query('SELECT hits, expires_at FROM email_rate_limits WHERE bucket_key = ?' . $this->lock(), [$key])->fetch(PDO::FETCH_ASSOC);
            $expired = (int)$row['expires_at'] <= $now;
            $hits = $expired ? 1 : min(1000000, (int)$row['hits'] + 1);
            $until = $expired ? $now + $seconds : (int)$row['expires_at'];
            $this->query('UPDATE email_rate_limits SET hits = ?, expires_at = ? WHERE bucket_key = ?', [$hits, $until, $key]);
            if ($hits > $limit) {
                $allowed = false;
                // 所有限制都恢复后才可重试；只返回等待时间，不公开账号是否存在或具体计数。
                $this->limit_retry_after = max($this->limit_retry_after, $until - $now);
                $this->limit_window = max($this->limit_window, $seconds);
                $labels = [
                    'send-ip-hour' => '当前网络每小时最多申请 30 次验证码',
                    'send-ip-day' => '当前网络 24 小时内最多申请 100 次验证码',
                    'send-name-cooldown' => '同一账号重发至少间隔 60 秒',
                    'send-name-day' => '同一账号 24 小时内最多申请 10 次验证码',
                    'send-email-cooldown' => '同一邮箱重发至少间隔 60 秒',
                    'send-email-day' => '同一邮箱 24 小时内最多申请 10 次验证码',
                    'register-ip-hour' => '当前网络每小时最多申请 10 次注册验证码',
                    'register-ip-day' => '当前网络 24 小时内最多申请 20 次注册验证码',
                ];
                if (isset($labels[$scope])) $this->limit_reasons[$scope] = $labels[$scope];
            }
        }
        return $allowed;
    }

    private function sendLimitResult() {
        return ['limited' => true, 'retry_after' => max(1, $this->limit_retry_after), 'limit_window' => $this->limit_window,
            'limit_reasons' => array_values($this->limit_reasons)];
    }

    /** 只读预检查；真正的系统发送额度仍由 worker 事务限流。不可承诺邮件已送达。 */
    private function deliveryLimitResult() {
        $bucket = recovery_digest($this->key, 'rate:global-delivery-day', 'all');
        $row = $this->query('SELECT hits, expires_at FROM email_rate_limits WHERE bucket_key = ?', [$bucket])->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['expires_at'] > $this->now() && (int)$row['hits'] >= $this->daily_limit) {
            return ['limited' => true, 'retry_after' => (int)$row['expires_at'] - $this->now(), 'limit_window' => 86400,
                'limit_reasons' => ['系统邮件发送额度暂已用完']];
        }
        return null;
    }

    private function verificationLimitFailure($registration = false) {
        $seconds = max(1, $this->limit_retry_after);
        $minutes = (int)ceil($seconds / 60);
        $wait = $seconds <= 60 ? $seconds . '秒' : ($minutes < 60 ? $minutes . '分钟'
            : (int)floor($minutes / 60) . '小时' . ($minutes % 60 ? ($minutes % 60) . '分钟' : ''));
        $reason = $registration ? '当前网络 24 小时内的注册提交次数已达上限（5 次）'
            : '当前网络的验证码验证请求过多（每小时最多 100 次）';
        return ['ok' => false, 'limited' => true, 'message' => $reason . '，请在约 ' . $wait . '后重试；验证码过期后需重新获取。'];
    }

    private function queue($to, $subject, $body, $challenge_id = null, $expires = null) {
        $id = bin2hex(random_bytes(16));
        $payload = recovery_encrypt($this->key, $id, ['to' => $to, 'subject' => $subject, 'body' => $body]);
        $this->query('INSERT INTO email_outbox (id, challenge_id, payload, state, attempts, available_at, created_at, expires_at) VALUES (?, ?, ?, ?, 0, ?, ?, ?)',
            [$id, $challenge_id, $payload, 'pending', $this->now(), $this->now(), $expires ?: $this->now() + 86400]);
    }

    public function request($purpose, $username, $email, $current_password, $context, $ip) {
        if (!in_array($purpose, ['bind', 'reset'], true)) throw new InvalidArgumentException('无效用途。');
        $email = recovery_email($email);
        if ($email === '' || !is_string($username) || strlen($username) > 254 || $username === '') {
            throw new InvalidArgumentException('请填写正确的账号和邮箱。');
        }
        return $this->transaction(function() use ($purpose, $username, $email, $current_password, $context, $ip) {
            $allowed = $this->limits([
                ['send-ip-hour', $ip, 3600, 30], ['send-ip-day', $ip, 86400, 100],
                ['send-name-cooldown', strtolower($username), 60, 1], ['send-name-day', strtolower($username), 86400, 10],
                ['send-email-cooldown', $email, 60, 1], ['send-email-day', $email, 86400, 10],
            ]);
            if (!$allowed) return $this->sendLimitResult();
            // 找回密码的系统限额预检查也在账号查询之前，未知账号与已知账号行为一致。
            if ($delivery_limit = $this->deliveryLimitResult()) return $delivery_limit;
            $account = $purpose === 'reset'
                ? find_account_by_login($this->pdo, $username, true)
                : $this->query('SELECT id, username, password, recovery_email, recovery_email_verified_at FROM users WHERE username = ?' . $this->lock(), [$username])->fetch(PDO::FETCH_ASSOC);
            if ($purpose === 'bind') {
                if (!$account || !is_string($current_password) || strlen($current_password) > 255
                    || !password_verify($current_password, $account['password'])) {
                    // 不抛异常回滚限流计数，避免猜测当前密码绕过限制。
                    return ['error' => '当前登录密码不正确，请核对后重试。'];
                }
                $owner = $this->query('SELECT id FROM users WHERE recovery_email = ? AND id <> ?', [$email, $account['id']])->fetchColumn();
                if ($owner) return ['error' => '该邮箱已被其他账号使用，不能重复绑定，请更换邮箱。', 'clear_flow' => true];
                if ($account['recovery_email'] === $email && $account['recovery_email_verified_at']) {
                    return ['error' => '该邮箱已绑定到当前账号，无需重复验证。更换邮箱时请输入新的邮箱地址。', 'clear_flow' => true];
                }
            } elseif (!$account || !$account['recovery_email_verified_at'] || $account['recovery_email'] !== $email) {
                // 与成功分支使用相同的公开提示；不调用 SMTP，避免网络延迟泄露账号状态。
                return ['id' => bin2hex(random_bytes(16))];
            }
            $now = $this->now();
            $id = bin2hex(random_bytes(16));
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->query('UPDATE email_challenges SET consumed = 1 WHERE user_id = ? AND purpose = ? AND consumed = 0', [$account['id'], $purpose]);
            $this->query('INSERT INTO email_challenges (id, user_id, purpose, email, email_before, account_signature, client_context, code_hash, attempts, consumed, created_at, expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)', [
                $id, $account['id'], $purpose, $email, $account['recovery_email'] ?? '', account_session_signature($account),
                $context, recovery_digest($this->key, 'code:' . $id, $code), $now, $now + 600,
            ]);
            $action = $purpose === 'bind' ? '绑定找回邮箱' : '重置登录密码';
            $this->queue($email, '瑞锦云存储｜' . $action . '验证码',
                '你的验证码是：' . $code . "\n\n用于" . $action . "，10 分钟内有效，仅能使用一次。\n请在发起操作的浏览器中输入。请勿将验证码或新密码告诉任何人。\n如果不是本人操作，请忽略此邮件。", $id, $now + 600);
            return ['id' => $id];
        });
    }

    public function complete($purpose, $id, $code, $context, $ip, $new_password = '', $confirmation = '', $current_user_id = null) {
        if (!in_array($purpose, ['bind', 'reset'], true)) throw new InvalidArgumentException('无效用途。');
        if ($purpose === 'reset' && ($error = recovery_password_error($new_password, $confirmation)) !== '') {
            return ['ok' => false, 'message' => $error];
        }
        return $this->transaction(function() use ($purpose, $id, $code, $context, $ip, $new_password, $current_user_id) {
            $failure = ['ok' => false, 'message' => '验证码无效、已过期或尝试次数过多，请重新获取。'];
            if (!$this->limits([['verify-ip-hour', $ip, 3600, 100]])) return $this->verificationLimitFailure();
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) return $failure;
            // 先读取 user_id，再按「账号 -> 验证码」统一顺序锁行，消费时会重新核对全部条件。
            $user_id = $this->query('SELECT user_id FROM email_challenges WHERE id = ?', [$id])->fetchColumn();
            if ($user_id === false) return $failure;
            $account = $this->query('SELECT id, username, password, recovery_email, recovery_email_verified_at FROM users WHERE id = ?' . $this->lock(), [$user_id])->fetch(PDO::FETCH_ASSOC);
            $challenge = $this->query('SELECT * FROM email_challenges WHERE id = ?' . $this->lock(), [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$account || !$challenge || $challenge['purpose'] !== $purpose || (int)$challenge['consumed'] !== 0
                || (int)$challenge['expires_at'] <= $this->now() || (int)$challenge['attempts'] >= 5
                || !hash_equals($challenge['client_context'], $context)
                || !account_session_matches($account, $challenge['account_signature'])
                || ($account['recovery_email'] ?? '') !== $challenge['email_before']
                || ($purpose === 'bind' && (string)$current_user_id !== (string)$account['id'])
                || ($purpose === 'reset' && (!$account['recovery_email_verified_at'] || $account['recovery_email'] !== $challenge['email']))) return $failure;
            $this->query('UPDATE email_challenges SET attempts = attempts + 1 WHERE id = ?', [$id]);
            if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/', $code)
                || !hash_equals($challenge['code_hash'], recovery_digest($this->key, 'code:' . $id, $code))) return $failure;

            if ($purpose === 'bind') {
                $owner = $this->query('SELECT id FROM users WHERE recovery_email = ? AND id <> ?', [$challenge['email'], $account['id']])->fetchColumn();
                if ($owner) {
                    $this->query('UPDATE email_challenges SET consumed = 1 WHERE id = ?', [$id]);
                    return ['ok' => false, 'message' => '该邮箱无法绑定，请更换邮箱后重试。'];
                }
                $this->query('UPDATE users SET recovery_email = ?, recovery_email_verified_at = ? WHERE id = ?', [$challenge['email'], $this->now(), $account['id']]);
                if ($account['recovery_email'] && $account['recovery_email_verified_at']) {
                    $this->queue($account['recovery_email'], '瑞锦云存储｜找回邮箱已变更', '你的账号已更换找回邮箱。如果不是本人操作，请立即联系管理员。此邮件不包含任何密码。');
                }
                $message = '邮箱绑定成功，现在可以使用邮箱找回密码。';
            } else {
                if (password_verify($new_password, $account['password'])) return ['ok' => false, 'message' => '新密码不能与当前密码相同。'];
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                if ($hash === false) throw new RuntimeException('密码处理失败。');
                $this->query('UPDATE users SET password = ? WHERE id = ?', [$hash, $account['id']]);
                $this->queue($challenge['email'], '瑞锦云存储｜密码已重置', '你的账号密码已通过邮箱验证重置，旧登录状态已失效。如果不是本人操作，请立即联系管理员。此邮件不包含任何密码。');
                $message = '密码重置成功，请使用新密码重新登录。';
            }
            // 任何成功绑定/重置都会废弃该账号的全部未用验证码。
            $this->query('UPDATE email_challenges SET consumed = 1 WHERE user_id = ?', [$account['id']]);
            return ['ok' => true, 'message' => $message];
        });
    }

    /** 注册复用邮件表，但 user_id=0 表示尚无账号；用途单独校验，绝不提前创建用户。 */
    public function requestRegistration($email, $context, $ip) {
        $email = recovery_email($email);
        if ($email === '') throw new InvalidArgumentException('请填写有效邮箱。');
        return $this->transaction(function() use ($email, $context, $ip) {
            if (!$this->limits([
                ['send-ip-hour', $ip, 3600, 30], ['send-ip-day', $ip, 86400, 100],
                ['send-email-cooldown', $email, 60, 1], ['send-email-day', $email, 86400, 10],
                ['register-ip-hour', $ip, 3600, 10], ['register-ip-day', $ip, 86400, 20],
            ])) return $this->sendLimitResult();
            if ($delivery_limit = $this->deliveryLimitResult()) return $delivery_limit;
            $id = bin2hex(random_bytes(16));
            // 也避免与管理员历史创建的邮箱形用户名冲突，保留旧账号优先的登录语义。
            // 注册页按产品要求明确显示占用状态；仍先限流。找回密码页继续使用统一提示。
            if ($this->query('SELECT id FROM users WHERE recovery_email = ? OR username = ?', [$email, $email])->fetchColumn()) {
                return ['error' => '该邮箱已注册或已被账号使用，不能重复注册。请登录或找回密码，或更换邮箱。', 'clear_flow' => true];
            }
            $now = $this->now();
            $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $this->query("UPDATE email_challenges SET consumed = 1 WHERE user_id = 0 AND purpose = 'register' AND email = ? AND consumed = 0", [$email]);
            $this->query('INSERT INTO email_challenges (id, user_id, purpose, email, email_before, account_signature, client_context, code_hash, attempts, consumed, created_at, expires_at) VALUES (?, 0, ?, ?, ?, ?, ?, ?, 0, 0, ?, ?)',
                [$id, 'register', $email, '', str_repeat('0', 64), $context, recovery_digest($this->key, 'code:' . $id, $code), $now, $now + 600]);
            $this->queue($email, '瑞锦云存储｜邮箱注册验证码', '你的验证码是：' . $code . "\n\n用于注册瑞锦云存储普通用户，10 分钟内有效，仅能使用一次。\n请在发起操作的浏览器中输入，并设置你自己的密码。\n如果不是本人操作，请忽略此邮件。", $id, $now + 600);
            return ['id' => $id];
        });
    }

    public function completeRegistration($id, $code, $context, $ip, $password, $confirmation, $username) {
        if (($error = recovery_password_error($password, $confirmation)) !== '') return ['ok' => false, 'message' => $error];
        if (!is_string($username) || !preg_match('/\Au_[a-f0-9]{24}\z/', $username)) throw new InvalidArgumentException('无效内部账号。');
        return $this->transaction(function() use ($id, $code, $context, $ip, $password, $username) {
            $failure = ['ok' => false, 'message' => '验证码无效，请在发送验证码的浏览器中重新获取并完成注册。', 'clear_flow' => true];
            if (!$this->limits([['verify-ip-hour', $ip, 3600, 100]])) return $this->verificationLimitFailure();
            if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/', $id)) return $failure;
            $challenge = $this->query('SELECT * FROM email_challenges WHERE id = ?' . $this->lock(), [$id])->fetch(PDO::FETCH_ASSOC);
            if (!$challenge || $challenge['purpose'] !== 'register' || (int)$challenge['user_id'] !== 0
                || !hash_equals($challenge['client_context'], $context)) return $failure;
            if ((int)$challenge['consumed'] !== 0) return ['ok' => false, 'message' => '验证码已使用或已被新验证码替换，请重新获取。', 'clear_flow' => true];
            if ((int)$challenge['expires_at'] <= $this->now()) return ['ok' => false, 'message' => '验证码已过期（有效期 10 分钟），请重新获取。', 'clear_flow' => true];
            if ((int)$challenge['attempts'] >= 5) return ['ok' => false, 'message' => '验证码尝试次数已用完（最多 5 次），请重新获取。', 'clear_flow' => true];
            $this->query('UPDATE email_challenges SET attempts = attempts + 1 WHERE id = ?', [$id]);
            if (!is_string($code) || !preg_match('/\A[0-9]{6}\z/', $code)
                || !hash_equals($challenge['code_hash'], recovery_digest($this->key, 'code:' . $id, $code))) {
                $remaining = 4 - (int)$challenge['attempts'];
                return ['ok' => false, 'message' => $remaining > 0 ? '验证码不正确，还可尝试 ' . $remaining . ' 次。'
                    : '验证码错误次数已达 5 次，请重新获取。', 'clear_flow' => $remaining <= 0];
            }
            if (!$this->limits([['register-create-ip-day', $ip, 86400, 5]])) return $this->verificationLimitFailure(true);
            if ($this->query('SELECT id FROM users WHERE recovery_email = ? OR username = ?', [$challenge['email'], $challenge['email']])->fetchColumn()) {
                $this->query('UPDATE email_challenges SET consumed = 1 WHERE id = ?', [$id]);
                return ['ok' => false, 'message' => '该邮箱已注册或已被账号使用，不能重复注册。请登录或找回密码，或更换邮箱。', 'clear_flow' => true];
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            if ($hash === false) throw new RuntimeException('密码处理失败。');
            try {
                $this->query('INSERT INTO users (username, password, role, created_at, recovery_email, recovery_email_verified_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [$username, $hash, 'user', $this->now(), $challenge['email'], $this->now()]);
            } catch (PDOException $e) {
                // 并发绑定/注册仍由唯一索引兜底；不返回 SQL，也不覆盖已有账号。
                if ($e->getCode() !== '23000') throw $e;
                $this->query('UPDATE email_challenges SET consumed = 1 WHERE id = ?', [$id]);
                return ['ok' => false, 'message' => '账号创建发生冲突，请尝试登录或重新获取验证码。', 'clear_flow' => true];
            }
            $this->query("UPDATE email_challenges SET consumed = 1 WHERE user_id = 0 AND purpose = 'register' AND email = ?", [$challenge['email']]);
            return ['ok' => true, 'username' => $username, 'email' => $challenge['email'], 'message' => '注册成功，邮箱已验证。请使用邮箱和密码登录。'];
        });
    }

    /** 原子领取；租期 5 分钟，恢复被中断的任务，最多尝试 3 次。 */
    public function claimMail() {
        return $this->transaction(function() {
            $now = $this->now();
            $row = $this->query("SELECT * FROM email_outbox WHERE (state = 'pending' OR state = 'sending') AND available_at <= ? ORDER BY created_at, id LIMIT 1" . $this->lock(), [$now])->fetch(PDO::FETCH_ASSOC);
            if (!$row) return null;
            if ((int)$row['expires_at'] <= $now || (int)$row['attempts'] >= 3) {
                $this->query("UPDATE email_outbox SET state = 'discarded', payload = NULL, lease_token = NULL WHERE id = ?", [$row['id']]);
                return ['discarded' => true];
            }
            if ($row['challenge_id']) {
                $challenge = $this->query('SELECT * FROM email_challenges WHERE id = ?', [$row['challenge_id']])->fetch(PDO::FETCH_ASSOC);
                $valid = $challenge && !$challenge['consumed'] && (int)$challenge['expires_at'] > $now && (int)$challenge['attempts'] < 5;
                if ($valid && $challenge['purpose'] === 'register') {
                    $valid = (int)$challenge['user_id'] === 0
                        && !$this->query('SELECT id FROM users WHERE recovery_email = ? OR username = ?', [$challenge['email'], $challenge['email']])->fetchColumn();
                } elseif ($valid && in_array($challenge['purpose'], ['bind', 'reset'], true)) {
                    $account = $this->query('SELECT id, password, recovery_email, recovery_email_verified_at FROM users WHERE id = ?', [$challenge['user_id']])->fetch(PDO::FETCH_ASSOC);
                    $valid = $account && account_session_matches($account, $challenge['account_signature'])
                        && ($account['recovery_email'] ?? '') === $challenge['email_before'];
                } else {
                    $valid = false;
                }
                if (!$valid) {
                    $this->query("UPDATE email_outbox SET state = 'discarded', payload = NULL, lease_token = NULL WHERE id = ?", [$row['id']]);
                    return ['discarded' => true];
                }
            }
            if (!$this->limits([['global-delivery-day', 'all', 86400, $this->daily_limit]])) {
                $this->query("UPDATE email_outbox SET state = 'pending', available_at = ? WHERE id = ?", [$now + 3600, $row['id']]);
                return ['limited' => true];
            }
            $lease = bin2hex(random_bytes(16));
            $this->query("UPDATE email_outbox SET state = 'sending', attempts = attempts + 1, available_at = ?, lease_token = ? WHERE id = ?", [$now + 300, $lease, $row['id']]);
            $row['lease_token'] = $lease;
            return $row;
        });
    }

    public function mailPayload($job) {
        return recovery_decrypt($this->key, $job['id'], $job['payload']);
    }

    public function finishMail($job, $success) {
        if ($success) {
            $this->query("UPDATE email_outbox SET state = 'sent', payload = NULL, lease_token = NULL WHERE id = ? AND state = 'sending' AND lease_token = ?", [$job['id'], $job['lease_token']]);
        } else {
            $this->query("UPDATE email_outbox SET state = 'pending', available_at = ?, lease_token = NULL WHERE id = ? AND state = 'sending' AND lease_token = ?", [$this->now() + 60, $job['id'], $job['lease_token']]);
        }
    }

    /** 仅清理邮箱功能自己的过期记录，绝不触碰 users 或用户文件。 */
    public function prune() {
        $now = $this->now();
        $this->query('DELETE FROM email_challenges WHERE expires_at < ?', [$now - 86400]);
        $this->query('DELETE FROM email_rate_limits WHERE expires_at < ?', [$now - 86400]);
        $this->query('DELETE FROM email_outbox WHERE expires_at < ?', [$now - 86400]);
    }
}
