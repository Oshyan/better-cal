-- Moving a local calendar to Google (0.9.4, #55). Better-Cal creates a
-- calendar in the connected Google account (or uses one the person picks),
-- uploads every event, then turns the calendar into a Google-backed one that
-- is edited in place (write-through), the same as any Google calendar here.
-- Uploading runs as a background job and can take a while for a big calendar,
-- so its progress lives here. One row per attempt; a failed attempt can be
-- retried and continues where it stopped.
CREATE TABLE calendar_moves (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  calendar_id BIGINT UNSIGNED NOT NULL,
  google_account_id BIGINT UNSIGNED NOT NULL,
  -- NULL until Better-Cal has created the Google calendar (create_new = 1).
  google_calendar_id VARCHAR(255) NULL,
  create_new TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued | running | done | failed
  total INT NOT NULL DEFAULT 0,
  done_count INT NOT NULL DEFAULT 0,
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  KEY idx_calendar_moves_calendar (calendar_id, id),
  CONSTRAINT fk_calendar_moves_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_calendar_moves_calendar FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
  CONSTRAINT fk_calendar_moves_account FOREIGN KEY (google_account_id) REFERENCES google_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
