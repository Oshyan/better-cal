-- Plugin system v2 + proposals (docs/plugins/prd-v1.md "Staged next").
--
--   event_plugin_data  per-event keyed values (C7). Read on the DETAIL fetch
--                      only — never joined into the events window, whose
--                      payload budget is the reason this table exists at all.
--   mutations.run_id   groups every mutation a single plugin run made (C10),
--                      so one "Undo this run" reverses the whole batch in
--                      reverse order. Indexed rather than JSON-extracted.
--   plugin_proposals   the C11 seam: a plugin PROPOSES, the user accepts, and
--                      acceptance atomically materializes events (optionally
--                      wrapped in a Trip). Regenerating never touches the
--                      calendar; only accept writes.
SET NAMES utf8mb4;

CREATE TABLE event_plugin_data (
  event_id BIGINT UNSIGNED NOT NULL,
  plugin_id VARCHAR(64) NOT NULL,
  k VARCHAR(120) NOT NULL,
  v_json JSON NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, plugin_id, k),
  KEY idx_epd_plugin (plugin_id),
  CONSTRAINT fk_epd_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE mutations
  ADD COLUMN run_id VARCHAR(32) NULL,
  ADD KEY idx_mut_run (run_id);

ALTER TABLE plugin_runs
  ADD COLUMN run_id VARCHAR(32) NULL,
  ADD KEY idx_runs_runid (run_id);

CREATE TABLE plugin_proposals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  plugin_id VARCHAR(64) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  source_key VARCHAR(160) NOT NULL,  -- stable per subject: regenerating replaces
  title VARCHAR(300) NOT NULL,
  summary VARCHAR(1000) NULL,        -- one-line "why this"
  rationale_html TEXT NULL,          -- sanitized longer explanation
  plan_json JSON NOT NULL,           -- {trip?: {...}, events: [...]} the host executes
  status ENUM('open','accepted','rejected') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  accepted_run_id VARCHAR(32) NULL,  -- undo the acceptance as one group
  UNIQUE KEY uq_proposal (plugin_id, source_key),
  KEY idx_proposals_status (user_id, status, id)
) ENGINE=InnoDB;
