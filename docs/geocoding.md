# Geocoding and Place Search

Better-Cal turns locations into coordinates for two jobs with different needs. This page covers which services do each, why those, and what to set up after installing.

## The Two Jobs

**Place search (the location dropdown).** As you type a location (in an event, the quick add strip or the Home location setting), Better-Cal offers matching places (`GET /geocode/search`). Picking one stores its coordinates on the event. This job has to be *fast*, since it runs on every pause in typing.

**Single-pin lookup (behind the scenes).** Events from feeds, Google calendars, imports, email, plugins, or a location typed without picking from the list arrive with text and no coordinates. Better-Cal resolves the text to one best point (`GET /geocode`):

- A background sweep runs every minute and places events that have a location but no coordinates, upcoming first, at most one network lookup per second.
- Past events are skipped unless Settings, Location & maps, "Look up addresses for past events too" is on (`placePastEvents`), since an imported history would cost a lookup per address for maps nobody opens. A repeating event that hasn't ended counts as upcoming, and a past event you open is placed then.
- The event panel looks it up on open if the sweep hasn't reached it. Plugins can ask for it too.

Answers are cached, so the same text is looked up once per region. Speed matters less here than getting the *right* single answer, since nobody is there to pick from a list.

Each event's coordinates record where they came from (`locationSource`: picked, lookup or supplied) and which service answered (`locationProvider`), so you can tell a place you chose from one Better-Cal guessed. See the [API contract](api-contract.md).

## What I Optimized For

- **Free, or close to it.** One person's calendar makes a few hundred place searches a month, so a generous free tier beats a better service that bills per request.
- **No geocoder to run yourself.** Self-hosting Nominatim, Photon or Pelias means tens of gigabytes of data and regular imports, which is too much upkeep for a calendar. Every option here is a hosted service.
- **No key required to start.** A new install searches places out of the box; keys are an optional upgrade.
- **Keeping coordinates must be allowed.** Better-Cal saves the coordinates of places you pick, indefinitely. A service whose terms forbid that is out, whatever its quality.
- **Speed and reliability first, then result quality.** A dropdown that answers in a tenth of a second and is right most of the time beats a slightly smarter one that takes two seconds.

## What Ships, and What to Set Up

**Set your Home location** (Settings, Location & maps) whatever else you do. Every service ranks nearby places first, and without a Home or device location Better-Cal can only guess your region from your time zone.

**Out of the box: Photon** ([photon.komoot.io](https://photon.komoot.io)), run by komoot on OpenStreetMap data. Free, no key, no sign-up, and it does both jobs. Better-Cal tunes it heavily for a local search box (nearby first, house numbers, exact city names), and its results are good. Its weakness is speed: 1 to 2 seconds per request, and a search sometimes needs two.

**Recommended: free LocationIQ and MapTiler keys, LocationIQ first.** [LocationIQ](https://locationiq.com) answered in about 0.06 seconds (median) in testing, roughly twenty times faster than Photon, and was as good or better on places, venues, cities and landmarks, but it can't find an address from a house number plus a few letters of the street, so it hands those on. [MapTiler](https://www.maptiler.com) finds addresses from a number plus a letter or two of the street in about a third of a second, and from a bare number when that exact number exists nearby. Together, with Photon behind them, every kind of search in the tables below came out right, at a median of 0.1 seconds.

1. Create a free account and an access token at locationiq.com, and a free account and an API key at maptiler.com. The MapTiler key must be one the server may use: a key locked to your site's address (as a map-tile key often is) is refused without a browser origin.
2. In the server's `.env.php`, set `BETTERCAL_PLACE_SEARCH=locationiq,maptiler`, `BETTERCAL_LOCATIONIQ_KEY` and `BETTERCAL_MAPTILER_GEOCODING_KEY`.
3. Optionally restrict the LocationIQ token to your server's address. LocationIQ only accepts IPv4 addresses there (Better-Cal reaches it over IPv4), so use the server's IPv4 address.

Either one alone works too (`locationiq` or `maptiler`). Settings, Location & maps shows what's in use. **MapTiler's free plan** shares one monthly allowance (100,000 requests) between place searches and its map tiles, and maps are suspended for the rest of the month if it runs out; a personal calendar uses a small fraction of it.

**Also supported: Stadia Maps.** [Stadia Maps](https://stadiamaps.com) had the best address results of anything tested (it carries far more US house numbers than OpenStreetMap alone) and was fast. Set `BETTERCAL_PLACE_SEARCH=stadia` and `BETTERCAL_STADIA_KEY`. **But:** Better-Cal saves the coordinates of places you pick, and Stadia Maps' terms allow keeping them only on a paid plan that includes storing results, so check [Stadia's current plans](https://stadiamaps.com/pricing/). Making sure your plan fits is your responsibility; Better-Cal does not check. Settings says the same wherever Stadia is mentioned.

**Or none: `BETTERCAL_PLACE_SEARCH=off`.** Places are typed as plain text with no suggestions, and events nobody picked a place for get no map pin. No service is ever asked, by Better-Cal or its plugins.

**Order and fallback.** Both settings take a comma-separated list, tried in order, with Photon always last: `locationiq,maptiler` asks LocationIQ, then MapTiler when LocationIQ can't answer, then Photon. Photon also answers whenever a keyed service fails, is rate limited or finds nothing. Empty or `photon` means Photon alone.

**The background lookup** (`BETTERCAL_PLACE_LOOKUP`) follows `BETTERCAL_PLACE_SEARCH` unless you give it a list (or `off`) of its own. With LocationIQ it uses LocationIQ's full-text search, falling back to Photon when that finds nothing or takes over 1.2 seconds. In testing that was five to fifteen times faster than Photon and fixed wrong pins Photon makes when it leans toward your home area (a world city's name returned as a local street of that name). LocationIQ's 5,000 requests a day are shared with the dropdown, so a large import can use a good share; Photon answers the rest. MapTiler is right on cities and full street addresses, but for a venue with its address it often finds only the town, so Better-Cal passes those to the next service.

## The Services, Compared

Tested in October 2026 from a small US-hosted server, after reading each service's full API reference and trying its documented options. Speeds are per request, measured from the server.

| Service | Free allowance | Speed | Keeping results | In Better-Cal | Why |
|---|---|---|---|---|---|
| **Photon** (komoot) | Free, no key; fair use | 1-2 s, up to 3 s | OpenStreetMap data, with credit | **Default**, and behind every other service | Works with no setup and handles bare house numbers; slow |
| **LocationIQ** | 5,000 requests/day, 2 per second, 60 per minute | 0.06 s median | Allowed (credit link required on the free plan) | **Recommended**, first | Fastest by far; strong on places, cities and landmarks; weak on short partial addresses, which it hands on |
| **MapTiler** | 100,000 requests/month, shared with its map tiles; personal and non-commercial on free | 0.3 s for addresses, up to 1.4 s when venues are included | Explicitly allowed, permanently | **Recommended**, second | Finds addresses from a number and a letter or two of the street, and some bare numbers; good on venues and cities |
| **Stadia Maps** | 200,000 credits/month; autocomplete costs 20 a request, so about 10,000 | 0.15-0.2 s | Free plan is non-commercial; keeping results needs a paid plan that includes it | **Supported** | Best on addresses and venues (OpenAddresses house numbers); its terms are the catch |
| **Geoapify** | 3,000 requests/day, 5 per second | 1.1 s median, spikes to 7 s | Allowed, with credit | Not supported | Very good on partial addresses once configured, but as slow as Photon and less predictable |
| **CSV2GEO** | 3,000 requests/day, 300 per minute; place search 20/day | 0.2 s | Allowed for your own use | Not supported | Its autocomplete has no location bias: results come from anywhere in the country |
| **Open-Meteo** geocoder | Free, no key, non-commercial | Fast | Allowed, with credit | Single-pin lookup only | Knows English names and population for cities; used to settle "which Paris" |
| **OurAirports** table | Public domain, bundled | Instant | Yes | Both jobs | A three-letter airport code (SFO) always means the airport |

By kind of search (✓ right result first or near it, ~ plausible but not the one, ✗ wrong or nothing):

| Kind of search | Photon (tuned) | LocationIQ | MapTiler | Stadia | Geoapify | CSV2GEO |
|---|---|---|---|---|---|---|
| A bare house number | ✓ | ✗ | ~ | ✗ | ✗ | ✗ |
| Number plus 1-3 letters of the street | ✓ (mostly) | ✗ | ✓ | ✓ | ✓ | ✗ |
| Number plus most of the street | ✓ | ✓ | ✓ | ✓ | ✓ | ~ |
| Part of a local venue's name | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| A park or venue by its full name | ~ | ✓ | ~ | ✓ | ~ | ✗ |
| A world city by name | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| A famous landmark with replicas elsewhere | ✓ | ✓ | ✓ | ~ | ✓ | - |
| A local airport by name | ✗ | ✓ | ✓ | ~ | ✓ | - |

No single service won every row, which is why Better-Cal takes a list. LocationIQ then MapTiler, with Photon behind them, covers every row.

**Tested, not supported:**

- **Open Places API** (Overture place data, free 10,000 a month, results may be kept) finds local venues by partial name in about 0.3 s, but ranks relevance before distance (a chain's locations 10 to 15 miles away came before one 1.5 miles away), has no address search, and says itself it isn't meant for autocomplete. It could become a companion for venues OpenStreetMap lacks.
- **Overture Maps API** (overturemapsapi.com) has no working partial-text search: its documented `name_prefix` filter was ignored by the live API in testing, uncached responses took 1.5 to 3.3 s, much of Foursquare's coverage is missing, and the free tier is non-commercial.

**Ruled out without testing:** Radar (no free tier, and its terms limit storing results to 30 days) and Geocode Earth (from $100 a month). Google's terms rule its geocoding out on any plan as Better-Cal uses it: results must not be shown on a non-Google map, and coordinates may be cached for only 30 days. Mapbox, HERE and TomTom generally limit how long you may keep results or charge per request beyond a small allowance; their current terms haven't been checked in detail.

## Why the Same Map Data Gives Different Results

Most of these services build on OpenStreetMap and still behave very differently:

- **Ranking and query handling.** How a service matches half-typed words, and whether it ranks by distance from you, matters as much as the data. LocationIQ and Geoapify both have options (not on their getting-started pages) that change results dramatically.
- **Address data.** OpenStreetMap's US house numbers are patchy. Services that add government address data (OpenAddresses, the US National Address Database, Overture's addresses) find far more addresses from a few typed characters; most of Stadia's house-number hits came from OpenAddresses. The USPS address database is licensed rather than open, which is why checkout forms often do better on addresses than any free service.

## Time Limits

LocationIQ gets 1.2 seconds (its slowest normal answer in testing was 0.9), MapTiler and Stadia 2 seconds; past that, asking Photon is faster than waiting. Photon gets 3 seconds per request, since nothing stands behind it.

## Adding a Service

A place search service implements `PlaceProvider` (`server/src/Domain/PlaceProvider.php`), with everything specific to it in its own class (`PhotonPlaces`, `LocationIqPlaces`, `MapTilerPlaces`, `StadiaPlaces`). `PlaceSearch` holds the rules shared by all of them. A keyed service implements `SelectivePlaceProvider` (saying which queries it takes) and runs inside `FallbackPlaces` with Photon behind it. Its API host and time limit go in `PoliciedGeocoderTransport`, and `PlaceProviders` wires it to `BETTERCAL_PLACE_SEARCH`. Rows carry the service's name, which becomes the event's `locationProvider`, and `credits()` says how the service asks to be credited.

Before writing one:

1. **Read its whole API reference**: the full reference and any OpenAPI spec, the source of its official client libraries, its pricing and its terms. Every service tested here had options that only appeared in the reference, and some changed results completely.
2. **Check the terms** for keeping results and for commercial use on the free plan.
3. **Test every relevant option from the server**, on the kinds of search in the table above, and measure speed there.
4. **Write it down here**, with the reasons.

Reports from other regions are especially welcome: all of this testing was done from one place in the US, and services may rank very differently elsewhere.
