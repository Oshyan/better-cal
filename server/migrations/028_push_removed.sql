-- A push device the owner removed in Settings is remembered, so the quiet
-- re-registration a browser does on sign-in (after a password reset has
-- cleared every device) does not bring it back. Enabling push again on that
-- device, by hand, clears the mark.
CREATE TABLE push_removed (
  user_id BIGINT UNSIGNED NOT NULL,
  endpoint_hash CHAR(64) NOT NULL,
  removed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, endpoint_hash),
  CONSTRAINT fk_push_removed_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
