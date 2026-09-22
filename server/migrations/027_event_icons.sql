-- A plugin may give each event its own icon (a host icon name or a single
-- glyph, validated as decoration icons are): a weather plugin says "rain"
-- on the rainy day and "sun" on the sunny one. Context tokens
-- (docs/relationships.md, "Where context is drawn") show it; feeds have
-- none and fall back to what the title says.
ALTER TABLE events ADD COLUMN icon VARCHAR(40) NULL;
