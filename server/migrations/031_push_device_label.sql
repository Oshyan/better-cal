-- What each reminder device is, in words: "Pixel 9 Pro · Chrome app", "Mac ·
-- Chrome". The push endpoint alone only names the push service, and every
-- Chromium browser on every platform shares Google's, so the owner's phone and
-- desktop were indistinguishable in Settings. The browser reports it when it
-- signs up and again each time the app opens (0.6.7). NULL until then.
ALTER TABLE push_subscriptions ADD COLUMN device_label VARCHAR(80) NULL;
