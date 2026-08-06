-- Activity log gains a 'refuse' op: an action that was attempted and blocked.
-- First user is iMIP forgery/replay rejection (BC-07/08/09) — an emailed CANCEL
-- or REQUEST that did not match the organizer bound to the event. Nothing is
-- mutated, so these rows carry no snapshot and are never undoable; they exist
-- so a blocked tamper attempt is visible to the owner rather than living only
-- in the worker's stdout.
SET NAMES utf8mb4;

ALTER TABLE mutations
  MODIFY COLUMN op ENUM('create','update','delete','refuse') NOT NULL;
