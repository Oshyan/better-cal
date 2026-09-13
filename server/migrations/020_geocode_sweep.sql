-- Background geocoding. Coordinates used to be resolved only by the detail
-- view, on open, and persisted only for local events: a feed event with an
-- address re-geocoded on every open, and nothing ever resolved ahead of time.
-- Production held 2,214 events with a location and 4 with coordinates.
--
-- geocoded_at records the last attempt, success or not, so an address the
-- provider cannot place is retried on a long interval rather than every
-- minute forever. Cleared when the location text changes.
SET NAMES utf8mb4;

ALTER TABLE events
  ADD COLUMN geocoded_at DATETIME NULL DEFAULT NULL,
  ADD KEY idx_events_geocode (location_lat, geocoded_at);
