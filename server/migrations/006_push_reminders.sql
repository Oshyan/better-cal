-- Web Push subscriptions, per-event reminder overrides, and sent-reminder dedup.
SET NAMES utf8mb4;

CREATE TABLE push_subscriptions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint TEXT NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  p256dh VARCHAR(255) NOT NULL,
  auth VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at DATETIME NULL,
  failing_since DATETIME NULL,
  UNIQUE KEY uq_push_endpoint (endpoint_hash),
  KEY idx_push_user (user_id),
  CONSTRAINT fk_push_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-event reminder override: NULL = inherit (calendar default, then global
-- default); [] = explicitly no reminders; else a list of {"minutes": int}.
ALTER TABLE events ADD COLUMN reminders_json JSON NULL AFTER style_json;

-- One row per sent reminder: instance_key = eventId:occurrenceStartUtc:offsetMinutes.
CREATE TABLE notified_instances (
  instance_key VARCHAR(120) PRIMARY KEY,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notified_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
