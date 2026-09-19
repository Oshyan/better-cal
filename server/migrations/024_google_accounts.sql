-- Google Calendar connector, read side (GH #42, tier 1).
--
-- A connected Google account holds one encrypted refresh token; a subscribed
-- Google calendar is an ordinary kind='subscribed' calendar (so folders,
-- colours, visibility, health and the read-only treatment all apply
-- unchanged) whose source is the Calendar API rather than an ICS URL. The
-- sync token makes each poll one cheap request ("anything since last time?")
-- instead of a full download.
SET NAMES utf8mb4;

CREATE TABLE google_accounts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  refresh_token_enc TEXT NOT NULL,          -- Infra\Secrets, keyed from the session secret
  scopes TEXT NOT NULL,
  status ENUM('ok','error') NOT NULL DEFAULT 'ok',
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_google_accounts_user_email (user_id, email),
  CONSTRAINT fk_google_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE calendars
  ADD COLUMN provider ENUM('ics','google') NOT NULL DEFAULT 'ics',
  ADD COLUMN google_account_id BIGINT UNSIGNED NULL,
  ADD COLUMN google_calendar_id VARCHAR(255) NULL,
  ADD COLUMN google_sync_token VARCHAR(255) NULL,
  ADD CONSTRAINT fk_calendars_google_account FOREIGN KEY (google_account_id) REFERENCES google_accounts(id) ON DELETE SET NULL;
