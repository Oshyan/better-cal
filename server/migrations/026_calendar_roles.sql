-- What a calendar IS decides what its events are to the person by default.
--
--   mine           things I do: events are planned unless marked maybe
--   opportunities  things I could do: events are available until I pick
--                  maybe, planned or hidden
--   context        information (sunset, tides, weather, holidays, someone
--                  else's schedule): never planned, never busy, drawn quietly
--
-- The per-event relationship (Events::relationship) is derived from this role
-- plus the row's attendance and status; the role only sets the default. Local
-- calendars were implicitly "mine" and subscriptions implicitly
-- "opportunities" until now; plugin calendars inject information.
SET NAMES utf8mb4;

ALTER TABLE calendars
  ADD COLUMN role ENUM('mine','opportunities','context') NOT NULL DEFAULT 'mine';

UPDATE calendars SET role = 'opportunities' WHERE kind = 'subscribed';
UPDATE calendars SET role = 'context' WHERE kind = 'plugin';
