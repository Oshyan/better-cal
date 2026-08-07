-- Plugin interop, tiers 1 and 2 (docs/plugins/authoring.md).
--
-- Two plugins wanting the same upstream data (the weather/environment plugin
-- and the trip planner both want Open-Meteo climate normals) had no way to
-- share it: kv is namespaced per plugin, and there is no cross-plugin call. The
-- answer here is deliberately data-only. No plugin ever executes inside
-- another's job, so there is no budget attribution, no error propagation across
-- a boundary, and no permission inheritance to reason about.
SET NAMES utf8mb4;

-- A plugin may mark one of its own kv entries public, optionally with an
-- expiry. Public entries are readable by any other enabled plugin; everything
-- else in plugin_kv stays private exactly as before. Uninstall already purges
-- plugin_kv wholesale, so published data disappears with its publisher.
ALTER TABLE plugin_kv
  ADD COLUMN is_public TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN expires_at DATETIME NULL DEFAULT NULL;

-- Lets readPublished/publishedKeys scan one plugin's public surface without
-- walking its private keys.
CREATE INDEX idx_plugin_kv_public ON plugin_kv (plugin_id, is_public);

-- Shared response cache for outbound GETs, keyed by URL. This is the part of
-- "sharing an API" that actually costs something: two plugins asking Open-Meteo
-- for the same coordinates should pay for one fetch. Caching at the host rather
-- than in a provider plugin keeps it free of dependency graphs — nothing breaks
-- when a plugin is disabled, because no plugin owns the cache.
--
-- Not user data: it is a cache of public third-party responses, safe to drop.
CREATE TABLE http_cache (
  url_hash CHAR(64) NOT NULL PRIMARY KEY,
  url VARCHAR(2048) NOT NULL,
  status SMALLINT UNSIGNED NOT NULL,
  body MEDIUMBLOB NOT NULL,
  fetched_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_http_cache_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
