-- What each reminder device is, in words: "Android phone · Chrome app",
-- "Laptop · Firefox". The push endpoint alone only names the push service, and
-- every Chromium browser on every platform shares Google's, so a phone and a
-- desktop could be indistinguishable in Settings. The browser reports it when it
-- signs up and again each time the app opens (0.6.7). NULL until then.
ALTER TABLE push_subscriptions ADD COLUMN device_label VARCHAR(80) NULL;
