-- 0005_uptime — periodic HTTP health checks per site + monitoring settings.
CREATE TABLE uptime_checks (
    __ID__,
    site_id     __FK__       NOT NULL,
    up          INTEGER      NOT NULL DEFAULT 0,
    status_code INTEGER      NOT NULL DEFAULT 0,
    response_ms INTEGER      NOT NULL DEFAULT 0,
    error       VARCHAR(255) NOT NULL DEFAULT '',
    checked_at  VARCHAR(25)  NOT NULL
);
CREATE INDEX idx_uptime_site_time ON uptime_checks (site_id, checked_at);

ALTER TABLE sites ADD COLUMN monitor_url VARCHAR(255) NULL;
ALTER TABLE sites ADD COLUMN monitor_enabled INTEGER NOT NULL DEFAULT 1;
