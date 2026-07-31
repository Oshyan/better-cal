-- Filter action gains 'highlight': matching occurrences carry highlighted:true
-- (accent emphasis client-side). Appended after existing values so stored
-- enum ordinals are untouched.
SET NAMES utf8mb4;

ALTER TABLE filters
  MODIFY action ENUM('hide','dim','score','highlight') NOT NULL DEFAULT 'hide';
