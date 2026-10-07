-- One marker per user that every successful write moves (#16). The change
-- cursor includes it, so each open device refreshes after a change made
-- anywhere, including ones that touch no calendar (people, thumbs, filters).
ALTER TABLE users ADD COLUMN change_seq BIGINT UNSIGNED NOT NULL DEFAULT 0;
