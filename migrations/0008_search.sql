-- 0008_search — Google Search Console + Bing Webmaster integration.

-- Operator-level OAuth / API credentials for a search provider (usually one
-- row per provider — the single admin's connected Google account).
CREATE TABLE search_credentials (
    __ID__,
    provider      VARCHAR(20)  NOT NULL,
    account       VARCHAR(190) NOT NULL DEFAULT '',
    access_token  TEXT         NULL,
    refresh_token TEXT         NULL,
    expires_at    VARCHAR(25)  NOT NULL DEFAULT '',
    scope         TEXT         NULL,
    created_at    VARCHAR(25)  NOT NULL,
    updated_at    VARCHAR(25)  NOT NULL
);
CREATE UNIQUE INDEX idx_search_cred_provider ON search_credentials (provider);

-- Per-site connection to a search-engine property.
CREATE TABLE site_search_connections (
    __ID__,
    site_id       __FK__       NOT NULL,
    provider      VARCHAR(20)  NOT NULL,
    property      VARCHAR(255) NOT NULL DEFAULT '',
    property_type VARCHAR(20)  NOT NULL DEFAULT 'url',
    verification  VARCHAR(20)  NOT NULL DEFAULT '',
    verify_token  VARCHAR(255) NOT NULL DEFAULT '',
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending',
    detail        VARCHAR(255) NOT NULL DEFAULT '',
    verified_at   VARCHAR(25)  NOT NULL DEFAULT '',
    synced_at     VARCHAR(25)  NOT NULL DEFAULT '',
    created_at    VARCHAR(25)  NOT NULL,
    updated_at    VARCHAR(25)  NOT NULL
);
CREATE UNIQUE INDEX idx_ssc_site_provider ON site_search_connections (site_id, provider);

-- Log of indexing / sitemap submissions.
CREATE TABLE index_requests (
    __ID__,
    site_id    __FK__       NOT NULL,
    provider   VARCHAR(20)  NOT NULL,
    kind       VARCHAR(20)  NOT NULL DEFAULT 'url',
    target     VARCHAR(500) NOT NULL DEFAULT '',
    status     VARCHAR(20)  NOT NULL DEFAULT '',
    detail     VARCHAR(500) NOT NULL DEFAULT '',
    created_at VARCHAR(25)  NOT NULL
);
CREATE INDEX idx_index_requests_site ON index_requests (site_id, created_at);

-- Daily search-performance metrics pulled from GSC / Bing.
CREATE TABLE search_metrics (
    __ID__,
    site_id     __FK__       NOT NULL,
    provider    VARCHAR(20)  NOT NULL,
    day         VARCHAR(10)  NOT NULL,
    dimension   VARCHAR(10)  NOT NULL DEFAULT 'total',
    label       VARCHAR(255) NOT NULL DEFAULT '',
    clicks      INTEGER      NOT NULL DEFAULT 0,
    impressions INTEGER      NOT NULL DEFAULT 0,
    ctr         DOUBLE       NOT NULL DEFAULT 0,
    position    DOUBLE       NOT NULL DEFAULT 0,
    created_at  VARCHAR(25)  NOT NULL
);
CREATE INDEX idx_search_metrics ON search_metrics (site_id, provider, day, dimension);
CREATE UNIQUE INDEX idx_search_metrics_uq ON search_metrics (site_id, provider, day, dimension, label);
