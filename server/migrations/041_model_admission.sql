-- Quick Add and prompt filters can both reach paid Gemini calls from recurring
-- or token-authenticated entry points. Record every admitted attempt before it
-- is dispatched so rotating API tokens, calendars, jobs or event ids cannot
-- reset account-wide rolling limits. finished_at/lease_until additionally cap
-- concurrent Quick Add calls without permanently consuming a slot after a PHP
-- process dies; completed and failed attempts still count in rolling budgets.
SET NAMES utf8mb4;

CREATE TABLE model_admissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  operation VARCHAR(32) NOT NULL,              -- quickadd | prompt_filter
  principal_kind VARCHAR(16) NOT NULL,         -- session | token | calendar
  principal_key CHAR(64) NOT NULL,             -- sha256; never stores credentials
  admitted_at DATETIME NOT NULL,
  lease_until DATETIME NOT NULL,
  finished_at DATETIME NULL,
  KEY idx_model_admission_account (user_id, operation, admitted_at),
  KEY idx_model_admission_principal (user_id, operation, principal_kind, principal_key, admitted_at),
  KEY idx_model_admission_active (user_id, operation, finished_at, lease_until),
  CONSTRAINT fk_model_admission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
