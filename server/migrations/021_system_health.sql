-- One place background work reports whether it is working.
--
-- Until now a failed job ended as status='failed' in the jobs table, which
-- nothing outside the queue reads, and was pruned a week later. Feed polls
-- set a status on the calendar (visible while you look at the sidebar, no
-- history); push delivery failures went to error_log; prompt filters and
-- ranking had no failure handling at all. Five subsystems failed into a
-- table nobody reads.
--
-- system_health holds one row per subject: a job type ('job:filter_eval'),
-- a feed calendar ('feed:31'), or a push device ('push:12'). The domain
-- records TRANSITIONS (working -> failing, failing -> working) into
-- Activity, so history exists without a line per failure, and the alert job
-- emails once per failing streak once it has lasted long enough to be real.
SET NAMES utf8mb4;

CREATE TABLE system_health (
  subject VARCHAR(80) NOT NULL PRIMARY KEY,
  kind ENUM('job','feed','push') NOT NULL,
  user_id BIGINT UNSIGNED NULL,               -- NULL: system-wide, the owner's concern
  label VARCHAR(160) NOT NULL,
  status ENUM('ok','failing') NOT NULL DEFAULT 'ok',
  first_failed_at DATETIME NULL,              -- start of the current failing streak
  last_failed_at DATETIME NULL,
  last_ok_at DATETIME NULL,
  consecutive_failures INT NOT NULL DEFAULT 0,
  last_error TEXT NULL,
  alerted_at DATETIME NULL,                   -- failure email sent for the current streak
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_health_user (user_id),
  KEY idx_health_status (status)
) ENGINE=InnoDB;
