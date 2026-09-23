-- Standing channels out of the account (a push device, an outbound feed)
-- created with an API token belong to that token: revoking the token removes
-- them, and one that has expired stops working. This replaces refusing
-- tokens outright (0.1.2 to 0.2.0), so agents keep full capability while a
-- leaked token still cannot leave anything behind that outlives it.
-- NULL = created by the signed-in owner, which revoking a token never touches.
ALTER TABLE push_subscriptions
  ADD COLUMN created_by_token_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_push_token FOREIGN KEY (created_by_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE;
ALTER TABLE out_feeds
  ADD COLUMN created_by_token_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_outfeed_token FOREIGN KEY (created_by_token_id) REFERENCES api_tokens(id) ON DELETE CASCADE;
