# Reading a Location's Text

Before any service is asked about a location, Better-Cal reads its text once, with one function (`LocationText::read`), and every place job acts on that reading: the background lookup (`Geocode::lookup`, which the sweep, its retry of misses after 30 days and the event panel all call), the place dropdown (`PlaceSearch::search`), and quick add's place. This page is the model; the shapes it was written from are in #328, and the reasons for each choice are in [decisions.md](decisions.md), Places.

## The Grammar

A location is an optional name, an optional address and an optional rest, over parts.

- **Parts.** The text splits into parts at a separator: a comma or semicolon; a dash of any kind with a space on each side, or an em dash with or without; `@`, `/` or `|` with a space on each side. A hyphen inside a word or between two numbers ("100-148") is not a separator, and neither is a slash inside a link. An `@` that opens the text is dropped.
- **The rest** is where the place ends: at a with-clause ("with Alex", "w/ Sam"), or at the separator before a later part that asks a question ("tickets at the door?"). The rest is never sent to a service. Quick add finds the end of the place it reads after "at" with the same function (`LocationText::placeLength`).
- **The start of an address**, stated once (`LocationText::addressStart`): a house number or a number range followed by a street name. Where the number opens a part, the separator already marks where the name ended, so any word after it is the street ("12 Rua Augusta"). Inside a part, with no separator to say where the name ends, the street name must end in a street word within five words of the number ("Sample Hall 75 Example Way"; the street words are the dropdown's, `PlaceSearch::SHORT_WORDS`, and a few that have no short form, such as Way). Or an intersection: two street names joined by "and", "&" or "at", each an ordinal ("5th") or a word and a street word ("Example Blvd."), the second perhaps plural ("5th and Example Streets"). What a pin must hold for that address is the address up to its street word (the whole part when the part opens with the number and no street word follows), or the whole intersection.
- **The name** is what comes before the start of the address. A text that opens with its address has no name.
- **A room** is a part that is a room word (Room, Rm, Suite, Ste, Floor, Fl, Level, Lvl, Unit, Apt, Booth) with its number as people number rooms: one to three digits with up to two letters after ("Room 12B", "Fl 3", "Apt 2B"), or one letter with up to three digits ("Conference Room B"); or an ordinal and a floor ("2nd floor"). A postcode has four or more digits, so a region code and its postcode ("FL 32202") is never a room, and neither are two letters ("Escape Room LA") or a floor with no number ("Top Floor"); a four-digit suite ("Suite 1200") is not read as one either, and stays in the forms. A room word with no number or letter after it is a name ("The Sample Room"), and so is a part with more than one word before the room word ("Sample Hall Room 5" is looked up as written). A room part is left out of every form that drops the name. A text whose parts are all rooms is a room, which has no place by itself.
- **Not a place.** A part is not a place when nothing that names somewhere is left once these are taken out: links, with or without their scheme; phone and conference numbers; words with digits in them (meeting ids, passcodes); meeting services and meeting words; words that say the place comes later; dates and times (quick add's own readers, so there is no second list of time words) and meal words; words that hedge a time ("sometime", "around", "early"); and filler (articles, "link", "details"). At least one thing taken out must be a sign (a link, a number of seven or more digits, a meeting service, "online" or "virtual", a later-word such as TBD or RSVP, a word that hedges a time), so "The Venue" stays a place, and so does a time word alone ("Noon" may be a venue). A whole text is not a place when it says the address comes later (the phrases in `web/src/lib/patterns.js`, shared with the app since 0.9.15), when nothing is left before its rest ("with Alex, time to be decided"), or when every part is not a place.
- **Coordinates** typed as the text ("12.3456, -65.4321", both in range, each with decimals) are a place that needs no lookup.

## What the Reading Returns

| Result | What it carries |
|---|---|
| `place` | the text up to its rest; the name, the address and the part a pin must hold when there is an address |
| `coordinates` | latitude and longitude |
| `room` | nothing more |
| `none` | why: `link`, `meeting`, `phone`, `later` or `note` |

## What Each Job Does With It

| Result | Background lookup and sweep | Place dropdown | Event panel |
|---|---|---|---|
| `place` | Asks its forms in order until one gives a pin it keeps (below) | Searches the text up to its rest | As before |
| `coordinates` | Answers the coordinates, no service asked; the sweep stores them as a lookup with no provider | One row at the coordinates, no service asked | Map at the coordinates |
| `room` | Asks nothing; the sweep marks the event tried, as for a link, and lists it nowhere | Asks nothing; says a room has no place by itself | The text as words, no pin, no Directions; the full view says a room has no place by itself |
| `none` | Asks nothing; the sweep marks the event tried and lists it nowhere | Asks nothing; says why | The text as words, with no pin and no Directions on any surface; a later phrase or a link keeps its own row |

**The forms** (`Geocode::forms`), each tried only when the one before finds nothing the lookup keeps, a repeat of an earlier form never asked twice:

1. The text up to its rest. When there is no rest, this is the text as written, so the first request is the one made before.
2. That text without its bracketed parts.
3. From the start of the address, after a name, rooms left out, a number range kept as written. Kept only when the pin is a spot holding the address up to its street word (or the intersection), never a town or an area (`Geocode::keeps`, unchanged).
4. From the part after the name's first part, rooms left out. Kept on the same terms, holding that part.

A text that opens with its address makes no form 3 or 4. This is the 1.5.8 rule (`Geocode::variants`) with its comma replaced by any separator and its "part that starts with a street number" replaced by the start of an address; there is no second rule beside it.

## What Is Stored

Nothing new. An event whose text is not a place, or is a room, is stamped `geocoded_at` with no coordinates, as a link has been since 1.0, which is also what "looked up and found nothing" stores. The two are told apart by reading the text again, which the lookup does on every call; nothing in a row says which it was. Typed coordinates are stored as `location_source = 'lookup'` with `location_provider` null.

## What This Does Not Decide

- **A town alone after a title** ("Sunny Sample Cottage by the Lake, Harbortown") stays unplaced, by the rule that a form without the name keeps a spot and never an area. Whether such an event may be placed at the town, marked approximate, is the owner's question (#328).
- **A venue's name alone** is still asked as written; that is what a places database could answer (#150, #326).
- **A private home** ("Alex's place in Springfield") is read as a name and asked as written.

Every surface that shows a location (the event panel, compact and full, the phone sheet, a trip's panel, agenda rows, the hover card, month and week chips) asks one client function, `locationKind` in `web/src/lib/maps.js`, which reads the server's `noPlace` on the occurrence. None decides on its own.
