-- CalDAV sync-collection support: per-calendar sync token + change journal.
SET NAMES utf8mb4;

ALTER TABLE calendars ADD COLUMN synctoken BIGINT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE dav_changes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calendar_id BIGINT UNSIGNED NOT NULL,
  uri VARCHAR(300) NOT NULL,
  operation TINYINT NOT NULL, -- 1=add 2=modify 3=delete
  synctoken BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_dav_changes_cal_token (calendar_id, synctoken),
  CONSTRAINT fk_dav_changes_cal FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
