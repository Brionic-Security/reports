-- 0009_index_urls — remembers the operator's edited list of URLs to (re)index per site.
ALTER TABLE sites ADD COLUMN index_urls TEXT NULL;
