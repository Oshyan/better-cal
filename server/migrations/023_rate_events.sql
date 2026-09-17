-- One small table behind every rate limit: a row per counted event, keyed by a
-- bucket string, counted over a sliding window.
--
-- First users: password sign-in (REST login and CalDAV Basic auth share the
-- same buckets, BC-05/BC-06) and the "send a test" endpoints (BC-19). In the
-- database rather than in memory because there is nothing else to put it in:
-- PHP-FPM workers share no memory, and adding Redis to a single-user calendar
-- to count to ten would be a poor trade for anyone self-hosting it.
--
-- Buckets look like "auth-fail:ip:203.0.113.7", "auth-fail:all",
-- "auth-ok:ip:203.0.113.7" (a source that has signed in successfully, kept for
-- 30 days) and "test-send:user:1". Rows are pruned opportunistically.
SET NAMES utf8mb4;

CREATE TABLE rate_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  bucket VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL,
  KEY idx_rate_bucket (bucket, created_at),
  KEY idx_rate_age (created_at)
) ENGINE=InnoDB;
