CREATE TABLE IF NOT EXISTS scheduled_scans (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    target_url    VARCHAR(500) NOT NULL,
    profile       VARCHAR(20)  DEFAULT 'quick',
    frequency     ENUM('daily','weekly','monthly') DEFAULT 'weekly',
    alert_threshold INT DEFAULT 70,
    email_alerts  TINYINT DEFAULT 1,
    last_run_at   DATETIME,
    next_run_at   DATETIME NOT NULL,
    last_score    INT,
    created_at    DATETIME DEFAULT NOW(),
    active        TINYINT DEFAULT 1,
    INDEX (user_id),
    INDEX (next_run_at),
    INDEX (active)
);
