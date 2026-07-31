-- Prompt-filter evaluation cache (background LLM verdicts) and rank-scoring
-- freshness state. Verdicts are per (filter, event); a missing row means
-- "not evaluated yet" and is treated as pass.
SET NAMES utf8mb4;

CREATE TABLE filter_evals (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filter_id BIGINT UNSIGNED NOT NULL,
  event_id BIGINT UNSIGNED NOT NULL,
  verdict ENUM('pass','fail') NOT NULL,
  score DOUBLE NULL,
  evaluated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fe_filter_event (filter_id, event_id),
  KEY idx_fe_event (event_id),
  CONSTRAINT fk_fe_filter FOREIGN KEY (filter_id) REFERENCES filters(id) ON DELETE CASCADE,
  CONSTRAINT fk_fe_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE events ADD COLUMN scored_at DATETIME NULL AFTER score;
