-- The events.tzid column default was 'America/Los_Angeles' — a guess about
-- where the operator lives, baked into the schema. Every write path now
-- supplies a tzid explicitly (the client sends the browser's zone; server-side
-- creators resolve the user's stored tz setting), so this default is close to
-- unreachable, but a wrong guess is worse than an honest unknown: UTC at least
-- announces itself, where a plausible-but-wrong region silently shifts all-day
-- boundaries and DST-crossing recurrences for anyone who lives elsewhere.
--
-- Existing rows are untouched: their tzid is real data, not a default.
SET NAMES utf8mb4;

ALTER TABLE events
  ALTER COLUMN tzid SET DEFAULT 'UTC';
