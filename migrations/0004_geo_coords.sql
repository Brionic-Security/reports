-- 0004_geo_coords — store coarse geo coordinates on events for the map.
-- (City-centroid lat/lon from the cached geo lookup — not the visitor's exact
-- location. One ALTER per column keeps SQLite happy.)
ALTER TABLE events ADD COLUMN country_code VARCHAR(4) NOT NULL DEFAULT '';
ALTER TABLE events ADD COLUMN lat DECIMAL(10,6) NULL;
ALTER TABLE events ADD COLUMN lon DECIMAL(10,6) NULL;
