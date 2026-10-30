# Marking an Event's Calendar

Ways to show which calendar an event comes from on a month chip, an agenda row or a day's list, weighed when plugin and web feed calendars got their own icons (2026-10-30). The sidebar and the event's details use the same mark, so an event and its calendar look alike everywhere.

The mockup is [calendar-marks.html](calendar-marks.html) (open it in a browser to compare the options side by side, light and dark). The calendars and events in it are invented.

## The Options

- **A: icon alone (1.4.2 to 1.4.3).** A 7px dot for a plain calendar; a plugin's icon or a web feed's link drawn alone in the calendar color with a hairline; a 7px ring for Google. The icons read, but carry little of the calendar's color, and Google is an odd one out.
- **B: discs for marked calendars.** Plain calendars keep the 7px dot; plugin, web feed and Google calendars get an 11px disc in the calendar color with the icon (or a G) in white, or black on pale colors.
- **C: discs for every calendar (chosen).** The same disc on every row: empty for a plain calendar, with the icon or a G for the rest. One size and rhythm down every list, and the color always shows. The few extra pixels are already spent on the marked calendars in B, so giving them to all is no real loss.
- **D: rings for marked calendars.** Plain calendars keep a filled dot; marked ones get a ring (about 2px) in the calendar color with the icon inside in the same color. Lighter than a disc and a little playful; a possible future variation, or a setting if discs ever feel heavy.

## C as Built (1.4.3)

- Discs are 10px on chips, 14px in the sidebar and 12px elsewhere (the event panel's calendar line 16px). Every calendar gets one; a plain calendar's is empty.
- The glyph fills 80% of the disc (up from about 75% in the mockup) with a 0.75px line at any size: the mockup's heavier line made fine icons like the sunset harder to read.
- Google's disc carries a stroked G in the glyphs' style; the colored Google logo stays after the calendar's name in the sidebar.
- The sunrise and sunset icons were redrawn as horizon, half sun and arrow; the side rays ran together at mark size.
- Week and day blocks and month bars keep the icon alone, in the text color, before the title.
- Phone month pills hide the disc for plain calendars, as they hid the dot, and show a 9px disc for marked ones.
