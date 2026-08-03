-- Person availability (docs/design-availability.md): activates the dormant
-- availability table from 001. show_on_calendar gates whether a person's
-- away/busy spans render as calendar bands (per-person opt-in, toggled from
-- the sidebar People folder and persisted here so it round-trips to the
-- People page).
SET NAMES utf8mb4;

ALTER TABLE people ADD COLUMN show_on_calendar TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX idx_avail_person_time ON availability (person_id, end_utc);
