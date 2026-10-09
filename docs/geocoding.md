# Geocoding and place search

Better-Cal turns locations into coordinates for two different jobs, and they have different needs. This page covers what each job does, which services do it, why those services and not others, what ships out of the box, and what I'd change after setting up a new install.

## The two jobs

**Place search (the location dropdown).** As you type in an event's location, the quick add strip or the Home location setting, Better-Cal offers places that match (`GET /geocode/search`). Picking one stores its coordinates on the event right away. This is the job that has to be *fast*: it runs on every pause in typing, and a slow answer is a dropdown that lags behind you.

**Single-pin lookup (behind the scenes).** Plenty of events arrive with a location as text and no coordinates: feeds, Google calendars, imports, email, plugins, or a location you typed without picking from the list. For those, Better-Cal resolves the text to one best point (`GET /geocode`):

- A background sweep runs every minute and places any event that has a location but no coordinates, upcoming events first, one network lookup per second.
- The event panel looks it up on open if the sweep hasn't reached it yet.
- Plugins can ask for it.

Answers are cached permanently, so the same text is only looked up once per region. Speed matters much less here; getting the *right* single answer matters more, since nobody is there to pick from a list.

Every event's coordinates also record where they came from (`locationSource`: picked, lookup or supplied) and which service answered (`locationProvider`), so you can always tell a place you chose from one Better-Cal guessed. See the [API contract](api-contract.md).

## What I optimized for

Better-Cal is a personal calendar you host yourself, so the choices follow from that:

- **Free, or very close to it.** One person's calendar makes a few hundred place searches a month, not millions. A free tier that covers that comfortably beats a better service that bills per request.
- **No geocoder to run yourself.** Self-hosting Nominatim, Photon or Pelias means tens of gigabytes of data and regular imports. That's a lot of server and upkeep for a calendar, so every option here is a hosted service.
- **No key required to start.** A new install should search places out of the box. Keys are an optional upgrade.
- **Keeping coordinates must be allowed.** Better-Cal saves the coordinates of places you pick, indefinitely. Some services forbid that on free plans (or at all), and that rules them out regardless of quality.
- **Speed and reliability first, then result quality.** A dropdown that answers in a tenth of a second and is right most of the time is more useful than one that's slightly smarter and takes two seconds.

## What ships, and what I'd change after setup

**Out of the box: Photon** ([photon.komoot.io](https://photon.komoot.io)), run by komoot on OpenStreetMap data. Free, no key, no sign-up. It does both jobs. On its own, Photon's ranking was poor for a local search box (a half-typed street near home could come back as one on another continent), so Better-Cal wraps it in a fair number of workarounds: it searches a region around you first, asks the world only when that finds too little, finds addresses from a bare house number through its reverse lookup, asks in your browser's language, and pins a city when you type its exact name. With those, its results are genuinely good. Its weakness is speed: 1 to 2 seconds per request, and a search sometimes needs two.

**Set your Home location** (Settings, Location & maps) whatever else you do. Every service ranks nearby places first, and without a Home location or a device location, Better-Cal can only guess your region from your time zone.

**Recommended: a free LocationIQ key.** [LocationIQ](https://locationiq.com) answered in about 0.06 seconds (median) in testing, roughly twenty times faster than Photon, and was as good or better on places, venues, cities and landmarks. To use it:

1. Create a free account and an access token at locationiq.com.
2. In the server's `.env`, set `BETTERCAL_PLACE_SEARCH=locationiq` and `BETTERCAL_LOCATIONIQ_KEY=` your token.
3. Optionally restrict the token to your server's address. LocationIQ only accepts IPv4 addresses there, so Better-Cal always reaches it over IPv4; use your server's IPv4 address.

Photon stays behind it automatically. LocationIQ can't find an address from a house number with only a few letters of the street (it once offered a different street outright), so those queries go to Photon, as does anything LocationIQ can't answer within 1.2 seconds, or when it's rate limited or finds nothing. You don't configure any of that. Settings, Location & maps shows which service is in use.

**Also supported: Stadia Maps.** [Stadia Maps](https://stadiamaps.com) had the best results of anything tested on addresses (it carries far more US house numbers than OpenStreetMap alone) and was fast. Set `BETTERCAL_PLACE_SEARCH=stadia` and `BETTERCAL_STADIA_KEY`. **But note:** Better-Cal saves the coordinates of places you pick. Stadia Maps' terms allow keeping them only on a paid plan that includes storing results, so check [Stadia's current plans](https://stadiamaps.com/pricing/). Making sure your plan fits is your responsibility; Better-Cal does not check. Settings says the same wherever Stadia is mentioned.

**The background lookup follows the same choice** unless you set `BETTERCAL_PLACE_LOOKUP` to something else. With LocationIQ, it uses LocationIQ's full-text search (built for complete text like "Venue, 12 Main Street, City") and falls back to Photon when that finds nothing or doesn't answer within 1.2 seconds. In testing that was five to fifteen times faster than Photon, and it fixed wrong pins Photon makes when the lookup leans toward your home area ("Munich" came back as a local Munich Street, "Lisbon" as a town in Iowa). Its 5,000 requests a day are shared with the dropdown; working through a large import can use a good share of them, and Photon answers whatever is left over.

## The services, compared

These were tested in October 2026 from a small US-hosted server, with each service's documented options (and a few plausible undocumented ones) tried after reading its full API reference. Speeds are per request, measured from the server.

| Service | Free allowance | Speed | Keeping results | In Better-Cal | Why |
|---|---|---|---|---|---|
| **Photon** (komoot) | Free, no key; fair use | 1-2 s, up to 3 s | OpenStreetMap data, with credit | **Default**, and behind every other service | Works with no setup and handles bare house numbers, which nothing else did; slow |
| **LocationIQ** | 5,000 requests/day, 2 per second, 60 per minute | 0.06 s median | Allowed (credit link required on the free plan) | **Recommended** | Fastest by far; strong on places, cities and landmarks; weak on short partial addresses, which go to Photon |
| **Stadia Maps** | 200,000 credits/month; autocomplete costs 20 a request, so about 10,000 | 0.15-0.2 s | Free plan is non-commercial; keeping results needs a paid plan that includes it | **Supported** | Best on addresses and venues (OpenAddresses house numbers); its terms are the catch |
| **Geoapify** | 3,000 requests/day, 5 per second | 1.1 s median, spikes to 7 s | Allowed, with credit | Not supported | Very good on partial addresses once configured, but as slow as Photon and less predictable |
| **CSV2GEO** | 3,000 requests/day, 300 per minute; place search 20/day | 0.2 s | Allowed for your own use | Not supported | Its autocomplete has no location bias at all: results come from anywhere in the country |
| **Open-Meteo** geocoder | Free, no key, non-commercial | Fast | Allowed, with credit | Single-pin lookup only | Knows English names and population for cities; used to settle "which Paris" |
| **OurAirports** table | Public domain, bundled | Instant | Yes | Both jobs | A three-letter airport code (SFO) always means the airport |

How each did, by kind of search (✓ right result first or near it, ~ plausible but not the one, ✗ wrong or nothing):

| Kind of search | Photon (tuned) | LocationIQ | Stadia | Geoapify | CSV2GEO |
|---|---|---|---|---|---|
| A bare house number | ✓ | ✗ | ✗ | ✗ | ✗ |
| Number plus 1-3 letters of the street | ✓ (mostly) | ✗ | ✓ | ✓ | ✗ |
| Number plus most of the street | ✓ | ✓ | ✓ | ✓ | ~ |
| Part of a local venue's name | ✓ | ✓ | ✓ | ✓ | ✗ |
| A park or venue by its full name | ~ | ✓ | ✓ | ~ | ✗ |
| A world city by name | ✓ | ✓ | ✓ | ✓ | ✗ |
| A famous landmark with replicas elsewhere | ✓ | ✓ | ~ | ✓ | - |
| A local airport by name | ✗ | ✓ | ~ | ✓ | - |

No single service won every row, which is why Better-Cal pairs a fast keyed service with Photon instead of picking one.

**Also tested, not supported:**

- **Open Places API** (Overture place data, free 10,000 a month, results may be kept). Its name search finds local venues by partial name in about 0.3 s, but it ranks by relevance before distance (a chain's locations 10 to 15 miles away came before one 1.5 miles away), has no address search, and says itself that it isn't meant for autocomplete. It could become a venue companion for businesses OpenStreetMap lacks, if we find enough of them that it has.
- **Overture Maps API** (overturemapsapi.com) has no partial-text search at all: places match an exact full name, addresses only by distance from a point.

A small restaurant that OpenStreetMap had under the wrong name was missing from Overture's data too; only Stadia (which also uses Foursquare's open place data) had it right.

**Ruled out without testing:**

- **Radar** no longer has a free tier (plans are annual, by sales call), and its terms limit storing geocoding and search results to 30 days.
- **Geocode Earth** (Pelias, like Stadia) starts at $100 a month, too much for a personal tool.

**Not tested yet:** MapTiler's geocoding. Its terms explicitly allow keeping results permanently, on the free plan too, and its US addresses include OpenAddresses and county data, which makes it a promising choice for the background lookup. For the dropdown it's less clear: its terms ask that people's typing go to MapTiler directly rather than through a server, and on the free plan searches share one monthly allowance with map tiles, with maps suspended if it runs out. The big platforms (Google, Mapbox, HERE, TomTom) generally limit how long you may keep their results or charge per request beyond a small allowance, which is a poor fit here, but their current terms haven't been checked in detail.

## Why the same map data gives different results

Most of these services say they're built on OpenStreetMap, and they still behave very differently. Two reasons:

- **Ranking and query handling.** How a service matches half-typed words, and whether it ranks by distance from you, matters as much as the data. LocationIQ and Geoapify both have options that change results dramatically (and aren't on their getting-started pages).
- **Address data.** OpenStreetMap's US house numbers are patchy. Services that add government address data (OpenAddresses, the US Department of Transportation's National Address Database, Overture's address data) find far more addresses from a few typed characters. Stadia's results name their source, and most house-number hits came from OpenAddresses. The authoritative US list, the USPS address database, is licensed rather than open, which is why shipping checkout forms (usually built on it, or on Google) often do better on addresses than any free service.

## Time limits

A dropdown shouldn't wait long. LocationIQ gets 1.2 seconds (its slowest normal answer in testing was 0.9) and Stadia 2 seconds; past that, asking Photon is faster than waiting. Photon gets 3 seconds per request, since nothing stands behind it.

## Adding a service

Place search services implement `PlaceProvider` (`server/src/Domain/PlaceProvider.php`). Everything specific to a service, including its workarounds, stays in its own class (`PhotonPlaces`, `LocationIqPlaces`, `StadiaPlaces`); `PlaceSearch` holds the rules that apply to all of them (a typed house number must match, addresses nearest first, airport codes). A keyed service implements `SelectivePlaceProvider`, saying which queries it takes, and runs inside `FallbackPlaces` with Photon behind it. Its API host and time limit go in `PoliciedGeocoderTransport`, and `PlaceProviders` wires it to `BETTERCAL_PLACE_SEARCH`. Rows carry the service's name, which becomes the event's `locationProvider`, and `credits()` says how the service asks to be credited.

Before writing one, evaluate the service properly:

1. **Read its whole API reference first**: the full reference and any OpenAPI spec, not just the getting-started page, plus the source of its official client libraries, its pricing and its terms. Every service tested here had options that only appeared in the reference, and some changed the results completely.
2. **Check the terms for keeping results**, and for commercial use on the free plan.
3. **Test every relevant option** from the server, on the same kinds of search as the table above, and measure speed there too.
4. **Write it down**, here, with the reasons.

Reports from other regions are especially welcome: all of this testing was done from one place in the US, and services may rank very differently elsewhere.
