-- The Review queue's own items: changes that arrived from outside and wait for
-- the owner's decision instead of being applied.
--
-- First (and so far only) kind: 'invite_change'. An emailed REQUEST or CANCEL
-- for an invitation the owner already has is an unauthenticated message: the
-- organizer check compares addresses that any sender can write (BC-07). Such
-- a change used to be applied to the calendar on arrival. It is now held here,
-- with what it would change, until the owner accepts or dismisses it.
--
-- Plugin proposals and invitations awaiting an RSVP also appear in the Review
-- page, but they live where they always did (plugin_proposals, events
-- .invite_json); this table is only for things with no other home.
SET NAMES utf8mb4;

CREATE TABLE review_items (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  kind VARCHAR(32) NOT NULL,                  -- 'invite_change'
  event_id BIGINT UNSIGNED NULL,              -- the event it would change
  source_key VARCHAR(255) NOT NULL,           -- stable per subject (the iCalendar UID): a newer change supersedes an open older one
  title VARCHAR(300) NOT NULL,
  summary VARCHAR(1000) NULL,
  payload_json JSON NOT NULL,                 -- everything needed to apply it later, and to show what it changes
  status ENUM('open','accepted','dismissed','superseded','gone') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_at DATETIME NULL,
  KEY idx_review_open (user_id, status, id),
  KEY idx_review_subject (user_id, kind, source_key, status),
  CONSTRAINT fk_review_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
