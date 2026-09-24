-- Browsers that have signed in with the password before (issue #59). Each
-- holds a long-lived random cookie, stored here only as its sha256. While the
-- overall sign-in brake is on (failures from many addresses at once), a
-- sign-in carrying a valid one is let past the brake from any network; the
-- password and the per-address limit still apply. Replaced on every
-- successful sign-in; a password reset forgets them all.
CREATE TABLE trusted_devices (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  UNIQUE KEY uq_trusted_devices_token (token_hash),
  KEY idx_trusted_devices_user (user_id, id),
  CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
