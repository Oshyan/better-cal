-- Public mail is an unauthenticated, recurring source of durable events and
-- paid model calls. Keep a persistent account-wide admission ledger so changing
-- Message-ID, UID, or From cannot reset those budgets. A transport key records
-- an IMAP message before any attacker-controlled display header is decoded.
SET NAMES utf8mb4;

ALTER TABLE mail_ingest
  ADD COLUMN transport_key VARCHAR(80) NULL AFTER id,
  ADD UNIQUE KEY uq_mail_ingest_transport (transport_key);

ALTER TABLE events
  ADD KEY idx_events_mail_active (user_id, created_via, deleted_at, end_utc);

CREATE TABLE mail_admissions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(16) NOT NULL,                    -- event | llm
  message_key CHAR(64) NOT NULL,                -- sha256; do not duplicate attacker text
  sender_key CHAR(64) NOT NULL,                 -- sha256; secondary fairness bucket only
  admitted_at DATETIME NOT NULL,
  UNIQUE KEY uq_mail_admission_message (user_id, kind, message_key),
  KEY idx_mail_admission_account (user_id, kind, admitted_at),
  KEY idx_mail_admission_sender (user_id, kind, sender_key, admitted_at),
  CONSTRAINT fk_mail_admission_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
