-- ===========================================================================
-- 0001_init — core schema for Brionic Reports.
--
-- Portability: the migration runner replaces two tokens per driver:
--   __ID__  -> auto-increment primary key column
--   __FK__  -> integer type used for foreign-key columns
-- Timestamps are stored as ISO strings so the schema behaves identically on
-- SQLite (dev) and MySQL (production).
--
-- Privacy: events store NO raw IP. Uniqueness uses a daily-rotating
-- visitor_hash; coarse country/city come from the cached ip_geo lookup.
-- ===========================================================================

-- Tracked websites.
CREATE TABLE sites (
    __ID__,
    public_id    VARCHAR(40)  NOT NULL,
    name         VARCHAR(150) NOT NULL,
    domain       VARCHAR(190) NOT NULL,
    report_email VARCHAR(190) NULL,
    tz           VARCHAR(64)  NOT NULL DEFAULT 'UTC',
    created_at   VARCHAR(25)  NOT NULL
);
CREATE UNIQUE INDEX idx_sites_public_id ON sites (public_id);

-- Page views and custom events.
CREATE TABLE events (
    __ID__,
    site_id      __FK__       NOT NULL,
    type         VARCHAR(20)  NOT NULL DEFAULT 'pageview',
    name         VARCHAR(80)  NULL,
    path         VARCHAR(255) NOT NULL DEFAULT '/',
    referer_host VARCHAR(190) NOT NULL DEFAULT '',
    is_bot       INTEGER      NOT NULL DEFAULT 0,
    bot_name     VARCHAR(60)  NOT NULL DEFAULT '',
    browser      VARCHAR(40)  NOT NULL DEFAULT '',
    os           VARCHAR(40)  NOT NULL DEFAULT '',
    device       VARCHAR(20)  NOT NULL DEFAULT '',
    country      VARCHAR(60)  NOT NULL DEFAULT '',
    city         VARCHAR(120) NOT NULL DEFAULT '',
    visitor_hash VARCHAR(64)  NOT NULL DEFAULT '',
    created_at   VARCHAR(25)  NOT NULL
);
CREATE INDEX idx_events_site_created ON events (site_id, created_at);
CREATE INDEX idx_events_site_bot ON events (site_id, is_bot);
CREATE INDEX idx_events_visitor ON events (visitor_hash);

-- Cached IP geolocation (the only place an IP transits, briefly).
CREATE TABLE ip_geo (
    __ID__,
    ip           VARCHAR(45)  NOT NULL,
    country      VARCHAR(80)  NOT NULL DEFAULT '',
    country_code VARCHAR(4)   NOT NULL DEFAULT '',
    region       VARCHAR(120) NOT NULL DEFAULT '',
    city         VARCHAR(120) NOT NULL DEFAULT '',
    lat          DECIMAL(10,6) NULL,
    lon          DECIMAL(10,6) NULL,
    looked_up_at VARCHAR(25)  NOT NULL
);
CREATE UNIQUE INDEX idx_ip_geo_ip ON ip_geo (ip);
