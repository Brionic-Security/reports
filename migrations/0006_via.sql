-- 0006_via — records how the tracker was installed (wordpress | html | ...).
ALTER TABLE events ADD COLUMN via VARCHAR(20) NULL;
