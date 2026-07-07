-- 0002_reports — bookkeeping for sent client report emails (dedupe + history).
CREATE TABLE report_runs (
    __ID__,
    site_id      __FK__       NOT NULL,
    period_start VARCHAR(12)  NOT NULL,
    period_end   VARCHAR(12)  NOT NULL,
    sent_to      VARCHAR(190) NOT NULL DEFAULT '',
    status       VARCHAR(20)  NOT NULL DEFAULT 'sent',
    detail       VARCHAR(255) NOT NULL DEFAULT '',
    created_at   VARCHAR(25)  NOT NULL
);
CREATE INDEX idx_report_runs_site_period ON report_runs (site_id, period_start);
