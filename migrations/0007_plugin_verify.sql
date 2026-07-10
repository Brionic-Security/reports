-- 0007_plugin_verify — records when a site's WordPress plugin last checked in.
ALTER TABLE sites ADD COLUMN plugin_verified_at VARCHAR(25) NULL;
