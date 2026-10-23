CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'user',
    created_at INTEGER NOT NULL DEFAULT 0,
    last_login_at INTEGER,
    recovery_email TEXT UNIQUE,
    recovery_email_verified_at INTEGER
);
CREATE TABLE email_challenges (
    id TEXT PRIMARY KEY, user_id INTEGER NOT NULL, purpose TEXT NOT NULL,
    email TEXT NOT NULL, email_before TEXT NOT NULL, account_signature TEXT NOT NULL,
    client_context TEXT NOT NULL, code_hash TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
    consumed INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL
);
CREATE TABLE email_rate_limits (bucket_key TEXT PRIMARY KEY, hits INTEGER NOT NULL, expires_at INTEGER NOT NULL);
CREATE TABLE email_outbox (
    id TEXT PRIMARY KEY, challenge_id TEXT, payload TEXT, state TEXT NOT NULL DEFAULT 'pending',
    attempts INTEGER NOT NULL DEFAULT 0, available_at INTEGER NOT NULL, lease_token TEXT,
    created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL
);
