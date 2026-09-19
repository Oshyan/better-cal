-- Google Calendar connector, write side (GH #42, write-through).
--
-- Writing an event back needs Google's own id for it (the iCalUID we key on
-- is not what the API addresses), and whether the connected account may
-- write to the calendar at all (its access role, as Google reported it when
-- the calendar was added). Sync tokens are cleared so the next poll of every
-- Google calendar is a full list, which is what fills the new id column.
SET NAMES utf8mb4;

ALTER TABLE events
  ADD COLUMN google_event_id VARCHAR(255) NULL;

ALTER TABLE calendars
  ADD COLUMN google_access_role VARCHAR(32) NULL;

UPDATE calendars SET google_sync_token = NULL WHERE provider = 'google';
