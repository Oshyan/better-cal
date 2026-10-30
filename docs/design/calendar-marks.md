# Marking an Event's Calendar

Ways to show which calendar an event comes from on a month chip, an agenda row or a day's list, weighed when plugin and web feed calendars got their own icons (2026-10-30). The sidebar and the event's details use the same mark, so an event and its calendar look alike everywhere.

The mockup is [calendar-marks.html](calendar-marks.html) (open it in a browser to compare the options side by side, light and dark). The calendars and events in it are invented.

## The Options

- **A: icon alone (1.4.2 to 1.4.3).** A 7px dot for a plain calendar; a plugin's icon or a web feed's link drawn alone in the calendar color with a hairline; a 7px ring for Google. The icons read, but carry little of the calendar's color, and Google is an odd one out.
- **B: discs for marked calendars.** Plain calendars keep the 7px dot; plugin, web feed and Google calendars get an 11px disc in the calendar color with the icon (or a G) in white, or black on pale colors.
- **C: discs for every calendar (chosen).** The same disc on every row: empty for a plain calendar, with the icon or a G for the rest. One size and rhythm down every list, and the color always shows. The few extra pixels are already spent on the marked calendars in B, so giving them to all is no real loss.
- **D: rings for marked calendars.** Plain calendars keep a filled dot; marked ones get a ring (about 2px) in the calendar color with the icon inside in the same color. Lighter than a disc and a little playful; a possible future variation, or a setting if discs ever feel heavy.

## Notes for C

- Keep the disc at about 10px on chips and grow the icon inside slightly, so it reads without the disc getting bigger.
- Fine icons suffer inside a disc. The sunset icon (sun, horizon, arrow and rays) was already hard to read small and is harder in white on color; it wants redrawing or replacing with something simpler before C ships.
- Week and day blocks are filled with the calendar color, so a disc would vanish there: they keep the icon alone, in the block's text color, before the title.
- The phone month pills are only 13px tall; check the disc fits there or fall back to the icon alone.
