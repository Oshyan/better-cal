-- Plugin system v1 (docs/plugins/prd-v1.md). Plugins run only in worker jobs;
-- request paths read what jobs wrote. These tables are that boundary:
--   plugins          install/enable state + plugin-scope settings + health
--   plugin_kv        namespaced storage (uninstall drops a plugin's keys)
--   plugin_ranges    overlay bands, written by jobs, read by one indexed query
--   plugin_runs      per-job run history for the ops page
--   plugin_warnings  audit findings (lint etc.), optionally tied to an event
-- Calendars gain kind 'plugin' (feed-like: read-only content, owned by a
-- plugin via plugin_id, deletable/archivable as a unit on uninstall).
SET NAMES utf8mb4;

ALTER TABLE calendars
  MODIFY COLUMN kind ENUM('local','subscribed','plugin') NOT NULL DEFAULT 'local',
  ADD COLUMN plugin_id VARCHAR(64) NULL;

CREATE TABLE plugins (
  id VARCHAR(64) PRIMARY KEY,
  version VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  settings_json JSON NULL,
  consecutive_failures INT NOT NULL DEFAULT 0,
  disabled_reason VARCHAR(300) NULL,
  installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE plugin_kv (
  plugin_id VARCHAR(64) NOT NULL,
  k VARCHAR(160) NOT NULL,
  v_json JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (plugin_id, k)
) ENGINE=InnoDB;

CREATE TABLE plugin_ranges (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plugin_id VARCHAR(64) NOT NULL,
  source_key VARCHAR(160) NOT NULL,
  start_utc DATETIME NOT NULL,
  end_utc DATETIME NOT NULL,
  label VARCHAR(200) NOT NULL DEFAULT '',
  color CHAR(7) NULL,
  detail_html TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_plugin_range (plugin_id, source_key),
  KEY idx_ranges_window (plugin_id, start_utc, end_utc)
) ENGINE=InnoDB;

CREATE TABLE plugin_runs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plugin_id VARCHAR(64) NOT NULL,
  job_id VARCHAR(64) NOT NULL,
  started_at DATETIME NOT NULL,
  duration_ms INT NULL,
  outcome ENUM('ok','error','timeout') NOT NULL,
  log_tail TEXT NULL,
  KEY idx_runs_plugin (plugin_id, id)
) ENGINE=InnoDB;

CREATE TABLE plugin_warnings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plugin_id VARCHAR(64) NOT NULL,
  event_id BIGINT UNSIGNED NULL,
  severity ENUM('info','warn') NOT NULL DEFAULT 'warn',
  message VARCHAR(500) NOT NULL,
  fix_text VARCHAR(300) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dismissed_at DATETIME NULL,
  KEY idx_warnings_plugin (plugin_id, dismissed_at)
) ENGINE=InnoDB;
