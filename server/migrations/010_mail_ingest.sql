-- Email ingest (docs/email-ingest.md): invites forwarded to the calendar@
-- mailbox become events. invite_json carries iMIP identity (method,
-- organizer, attendees, sequence, myPartstat) for the detail view's
-- invitation panel and RSVP replies. mail_ingest logs every processed
-- message (message_id-keyed) so a message is never ingested twice.
SET NAMES utf8mb4;

ALTER TABLE events ADD COLUMN invite_json JSON NULL;

CREATE TABLE mail_ingest (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id VARCHAR(255) NOT NULL,
  subject VARCHAR(500) NULL,
  from_addr VARCHAR(255) NULL,
  tier VARCHAR(16) NULL,          -- imip | markup | llm | none
  outcome VARCHAR(32) NOT NULL,   -- created | updated | cancelled | skipped | error
  event_id BIGINT UNSIGNED NULL,
  error VARCHAR(500) NULL,
  processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_mail_ingest_msgid (message_id)
) ENGINE=InnoDB;
