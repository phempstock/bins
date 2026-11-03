-- SQLite equivalent of migrations/mysql/001_bins.sql (tests and local trials).

CREATE TABLE IF NOT EXISTS settings (
    name   TEXT NOT NULL PRIMARY KEY,
    value  TEXT NULL
);

CREATE TABLE IF NOT EXISTS collections (
    street      TEXT NOT NULL,
    day         TEXT NOT NULL,
    bin         TEXT NOT NULL,
    fetched_at  TEXT NOT NULL,
    PRIMARY KEY (street, day, bin)
);

CREATE TABLE IF NOT EXISTS sync_runs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    started_at   TEXT    NOT NULL,
    finished_at  TEXT    NULL,
    status       TEXT    NOT NULL,
    streets_ok   INTEGER NULL,
    streets_bad  INTEGER NULL,
    message      TEXT    NULL
);

CREATE TABLE IF NOT EXISTS subscribers (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    email            TEXT    NOT NULL UNIQUE,
    street           TEXT    NOT NULL,
    timing           TEXT    NOT NULL,
    garden           INTEGER NOT NULL DEFAULT 0,
    status           TEXT    NOT NULL,
    confirm_hash     TEXT    NULL,
    confirm_sent_at  TEXT    NULL,
    created_at       TEXT    NOT NULL,
    confirmed_at     TEXT    NULL,
    unsubscribed_at  TEXT    NULL
);
CREATE INDEX IF NOT EXISTS idx_subscribers_status ON subscribers (status);

CREATE TABLE IF NOT EXISTS signup_attempts (
    id       INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_hash  TEXT    NOT NULL,
    at       TEXT    NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_signup_attempts ON signup_attempts (ip_hash, at);

CREATE TABLE IF NOT EXISTS reminder_sends (
    subscriber_id  INTEGER NOT NULL,
    day            TEXT    NOT NULL,
    attempts       INTEGER NOT NULL DEFAULT 0,
    sent_at        TEXT    NULL,
    error          TEXT    NULL,
    PRIMARY KEY (subscriber_id, day)
);
