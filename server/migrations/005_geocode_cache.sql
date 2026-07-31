-- Geocode result cache for GET /api/v1/geocode (photon.komoot.io proxy).
-- Permanent cache keyed by a hash of the normalized query. Rows with NULL
-- lat/lng are cached negative results (the provider answered "not found");
-- transport failures are never cached.
SET NAMES utf8mb4;

CREATE TABLE geocode_cache (
  query_hash CHAR(64) PRIMARY KEY,
  query VARCHAR(500) NOT NULL,
  lat DOUBLE NULL,
  lng DOUBLE NULL,
  display VARCHAR(500) NULL,
  resolved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
