-- 0003_alerts — log of traffic alerts sent (dedupe: one per site/day/kind).
CREATE TABLE alerts (
    __ID__,
    site_id    __FK__       NOT NULL,
    kind       VARCHAR(20)  NOT NULL,
    day        VARCHAR(12)  NOT NULL,
    detail     VARCHAR(255) NOT NULL DEFAULT '',
    created_at VARCHAR(25)  NOT NULL
);
CREATE INDEX idx_alerts_site_day ON alerts (site_id, day);
