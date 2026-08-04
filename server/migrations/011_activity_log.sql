-- Activity log (unified action journal): the existing mutations table (undo
-- snapshots) grows source/summary/details so it doubles as the user-facing
-- activity feed. Automated writers (feed polls, mail ingest, imports) now
-- record too — snapshotless rows are log-only entries, rows with snapshots
-- are undoable for 7 days (worker strips old snapshots; rows age out at 90d).
SET NAMES utf8mb4;

ALTER TABLE mutations
  ADD COLUMN source VARCHAR(24) NOT NULL DEFAULT 'web',
  ADD COLUMN summary VARCHAR(300) NULL,
  ADD COLUMN details_json JSON NULL;

CREATE INDEX idx_mut_user_id_desc ON mutations (user_id, id);
