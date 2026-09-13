-- When a subscribed feed's content last CHANGED, as observed by the poll's own
-- diff (anything added, updated or removed).
--
-- "Stale" used to mean "the feed contained zero events on every poll in the
-- window, or the last poll errored". That is presence, not change: a dead
-- calendar that keeps serving the same fifty events from 2023 was never stale,
-- and a freshly subscribed empty feed was stale on its first poll. Staleness
-- is now judged against this column: unchanged for stale_after_days AND
-- nothing upcoming. Empty and went-empty are reported as their own states.
SET NAMES utf8mb4;

ALTER TABLE calendars
  ADD COLUMN content_changed_at DATETIME NULL DEFAULT NULL;

-- Baseline for feeds that already exist: the newest write the sync ever made
-- to one of their events is the last time we saw the content change. Falls
-- back to the last poll, then subscription time, so nothing flips to stale on
-- deploy for lack of history.
UPDATE calendars
   SET content_changed_at = COALESCE(
         (SELECT MAX(GREATEST(e.created_at, e.updated_at)) FROM events e WHERE e.calendar_id = calendars.id),
         last_polled_at,
         created_at)
 WHERE kind = 'subscribed';
