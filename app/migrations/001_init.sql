-- 001_init.sql: the full Secret Santa schema for SQLite.
-- Applied automatically by bin/migrate.php when the container starts.
-- Timestamps are stored as UTC text 'YYYY-MM-DD HH:MM:SS' (what CURRENT_TIMESTAMP gives).
-- NOCASE on names/emails keeps MySQL's case-insensitive matching and sorting;
-- short codes and UUIDs stay case-sensitive on purpose.

CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid          TEXT NOT NULL UNIQUE,
    short_code    TEXT UNIQUE,
    name          TEXT NOT NULL COLLATE NOCASE,
    email         TEXT UNIQUE COLLATE NOCASE,
    phone_number  TEXT,
    creation_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE groups (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    short_code     TEXT UNIQUE,
    name           TEXT NOT NULL COLLATE NOCASE,
    reveal_message TEXT,
    gift_idea_url  TEXT,
    sms_template   TEXT,
    creation_date  TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE participants (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id              INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    group_id             INTEGER NOT NULL REFERENCES groups (id) ON DELETE CASCADE ON UPDATE CASCADE,
    current_giving_to_id INTEGER REFERENCES participants (id) ON DELETE SET NULL ON UPDATE CASCADE,
    viewed_date          TEXT,
    view_count           INTEGER NOT NULL DEFAULT 0,
    UNIQUE (user_id, group_id)
);
CREATE INDEX idx_participants_group ON participants (group_id);
CREATE INDEX idx_participants_giving_to ON participants (current_giving_to_id);

CREATE TABLE blocked_pairings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id_1     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    user_id_2     INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    creation_date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id_1, user_id_2)
);
CREATE INDEX idx_blocked_user2 ON blocked_pairings (user_id_2);

CREATE TABLE assignment_history (
    id                      INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id                INTEGER NOT NULL REFERENCES groups (id) ON DELETE CASCADE ON UPDATE CASCADE,
    year                    INTEGER NOT NULL,
    giver_participant_id    INTEGER NOT NULL REFERENCES participants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    receiver_participant_id INTEGER NOT NULL REFERENCES participants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    assignment_date         TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_history_group ON assignment_history (group_id);
CREATE INDEX idx_history_giver ON assignment_history (giver_participant_id);
CREATE INDEX idx_history_receiver ON assignment_history (receiver_participant_id);

CREATE TABLE participant_views (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    participant_id INTEGER NOT NULL REFERENCES participants (id) ON DELETE CASCADE ON UPDATE CASCADE,
    view_timestamp TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address     TEXT
);
CREATE INDEX idx_views_participant ON participant_views (participant_id);

CREATE TABLE sms_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    group_id     INTEGER NOT NULL REFERENCES groups (id) ON DELETE CASCADE ON UPDATE CASCADE,
    user_id      INTEGER NOT NULL REFERENCES users (id) ON DELETE CASCADE ON UPDATE CASCADE,
    phone_number TEXT NOT NULL,          -- number actually texted (the test number in test mode)
    message      TEXT NOT NULL,
    test_mode    INTEGER NOT NULL DEFAULT 0,
    success      INTEGER NOT NULL,
    provider_id  TEXT,                   -- VoIP.ms id, or 'phone' when sent from the admin's phone
    error        TEXT,
    sent_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sms_group_user ON sms_log (group_id, user_id);
CREATE INDEX idx_sms_user ON sms_log (user_id);

-- --- Admin accounts ---

CREATE TABLE admins (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    username            TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash       TEXT NOT NULL,
    -- Bumped when the password changes, which signs out every other session of this admin.
    session_epoch       INTEGER NOT NULL DEFAULT 1,
    created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    password_changed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at       TEXT,
    last_login_ip       TEXT
);

-- Failed logins, for lockouts by IP and by username (old rows are pruned on each attempt).
CREATE TABLE login_failures (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address   TEXT NOT NULL,
    username     TEXT NOT NULL COLLATE NOCASE,
    attempted_at INTEGER NOT NULL      -- Unix time
);
CREATE INDEX idx_login_failures_ip ON login_failures (ip_address, attempted_at);
CREATE INDEX idx_login_failures_user ON login_failures (username, attempted_at);

-- Small key/value store (e.g. the one-time setup code used before the first admin exists).
CREATE TABLE app_settings (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
