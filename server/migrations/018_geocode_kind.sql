-- What KIND of place a geocode result is: 'city', 'state', 'country',
-- 'restaurant', and so on, straight from the provider.
--
-- Callers routinely need to know they were handed a region rather than a spot.
-- The trip planner says so out loud ("Hawaii is a region, so these climate
-- figures are for the largest town inside it"), and it used to learn that by
-- running a second geocoder of its own. Answering it from the host means one
-- geocoder for everyone.
--
-- Cached alongside the coordinates so a cache hit and a fresh lookup return the
-- same shape; a null kind simply means "the provider did not say".
SET NAMES utf8mb4;

ALTER TABLE geocode_cache
  ADD COLUMN kind VARCHAR(32) NULL DEFAULT NULL;

-- This cache is permanent, so rows written before the column existed would keep
-- a null kind forever and silently switch off anything keyed to it — the trip
-- planner's "that is a region, not a destination" notice would simply never
-- appear for any place already looked up. Drop the cache instead: it holds
-- nothing but re-fetchable answers about public places, and it refills itself.
--
-- This also re-resolves everything through the new significance ranking and the
-- secondary provider, so previously cached wrong answers (Lisbon, Iowa) correct
-- themselves rather than persisting.
DELETE FROM geocode_cache;
