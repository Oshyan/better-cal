-- Duplicates (#9): the same real event arriving by two routes (a Luma feed
-- and its forwarded confirmation; a Takeout import and the Google calendar
-- it came from). One row per pair of events, lower id first. `linked` pairs
-- show as one event; `possible` ones wait in Review; `dismissed` means the
-- owner said they differ, and the scan never proposes them again.
SET NAMES utf8mb4;

CREATE TABLE event_duplicates (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  event_a BIGINT UNSIGNED NOT NULL,
  event_b BIGINT UNSIGNED NOT NULL,
  status ENUM('linked','possible','dismissed') NOT NULL,
  basis VARCHAR(16) NOT NULL,     -- uid | title | similar | owner
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  UNIQUE KEY uq_dup_pair (event_a, event_b),
  KEY idx_dup_user (user_id, status),
  KEY idx_dup_b (event_b),
  CONSTRAINT fk_dup_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_dup_a FOREIGN KEY (event_a) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_dup_b FOREIGN KEY (event_b) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- The same UID on two calendars is the surest sign; find those without a scan.
ALTER TABLE events ADD KEY idx_events_user_uid (user_id, uid);
