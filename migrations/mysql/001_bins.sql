-- BINS schema.

-- Small key/value settings: the signing secret, whether sign-up is open.
CREATE TABLE IF NOT EXISTS settings (
    name   VARCHAR(50)  NOT NULL PRIMARY KEY,
    value  TEXT         NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Upcoming collections for each street, refreshed by bin/sync.php.
CREATE TABLE IF NOT EXISTS collections (
    street      VARCHAR(60)  NOT NULL,             -- slug from src/Park/Streets.php
    day         DATE         NOT NULL,             -- UK date of the collection
    bin         VARCHAR(20)  NOT NULL,             -- waste | recycling | garden | food
    fetched_at  DATETIME     NOT NULL,
    PRIMARY KEY (street, day, bin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_runs (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    started_at   DATETIME      NOT NULL,
    finished_at  DATETIME      NULL,
    status       VARCHAR(12)   NOT NULL,           -- running | ok | partial | failed
    streets_ok   INT           NULL,
    streets_bad  INT           NULL,
    message      TEXT          NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscribers (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email            VARCHAR(254)  NOT NULL,
    street           VARCHAR(60)   NOT NULL,
    timing           VARCHAR(10)   NOT NULL,       -- evening (the day before) | morning (on the day)
    garden           TINYINT(1)    NOT NULL DEFAULT 0,
    status           VARCHAR(12)   NOT NULL,       -- pending | active | unsubscribed
    confirm_hash     CHAR(64)      NULL,           -- sha256 of the emailed confirmation token
    confirm_sent_at  DATETIME      NULL,
    created_at       DATETIME      NOT NULL,
    confirmed_at     DATETIME      NULL,           -- evidence of consent (double opt-in)
    unsubscribed_at  DATETIME      NULL,
    UNIQUE KEY uq_subscribers_email (email),
    INDEX idx_subscribers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sign-up attempts by hashed IP address, kept for a day to limit abuse of the form.
CREATE TABLE IF NOT EXISTS signup_attempts (
    id       INT UNSIGNED  NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip_hash  CHAR(64)      NOT NULL,
    at       DATETIME      NOT NULL,
    INDEX idx_signup_attempts (ip_hash, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per subscriber per collection day, so nobody is reminded twice.
CREATE TABLE IF NOT EXISTS reminder_sends (
    subscriber_id  INT UNSIGNED  NOT NULL,
    day            DATE          NOT NULL,
    attempts       INT           NOT NULL DEFAULT 0,
    sent_at        DATETIME      NULL,             -- null while it has only failed
    error          TEXT          NULL,
    PRIMARY KEY (subscriber_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
