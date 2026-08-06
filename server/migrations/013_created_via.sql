-- Which entry point created each event (the ActivityContext source at insert
-- time). isNew becomes source-based: the "new" pill marks automated arrivals
-- (feed polls, mail ingest, agent API, imports), never the user's own
-- creations, and a calendar move can no longer resurrect it — the old formula
-- compared created_at against the CURRENT calendar's initial-load window, so
-- moving an event out of a young calendar stripped its bulk suppression.
-- Existing rows default to 'web' (their pills were already expired or wrong).
SET NAMES utf8mb4;

ALTER TABLE events
  ADD COLUMN created_via VARCHAR(24) NOT NULL DEFAULT 'web';
