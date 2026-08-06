# Better-Cal manual security lead validation

Date: 2026-08-04  
Repository revision: `ea1ef19a6e710ba6d91a7fddbbef3963a5ab91dc`  
Scope: all 75 candidate rows from the two incomplete Codex Security discovery ledgers  
Method: independent source/control/sink tracing, attack-path calibration, duplicate reconciliation, bounded local reproduction, existing tests, and inspection of locked dependencies in a disposable copy

## Outcome

The discovery leads reduce to:

| Final status | Unique groups | Candidate rows covered |
|---|---:|---:|
| Reportable Medium | 15 | 43 |
| Reportable Low | 5 | 8 |
| Deferred pending deployment/client evidence | 2 | 5 |
| Suppressed, duplicate-only, or hardening-only | 10 families | 19 |
| **Total** | **32 groups/families** | **75** |

There are no validated Critical or High findings. The two SSRF groups are real destination-control failures, but the review did not probe production or demonstrate a particular private/metadata target with major impact. They therefore remain Medium; raise either to High if a concrete sensitive internal service, metadata response, or consequential side effect is safely confirmed.

The most important fixes are the stored calendar-feed SSRF, Web Push endpoint SSRF, MCP REST-route escape, principal-agnostic service-worker API cache, iMIP authenticity/replay flaws, and attacker-controlled ICS availability/injection paths.

## Validation rubric

Each lead was required to establish:

1. A realistic in-scope interface and attacker-controlled source.
2. The closest effective control, including framework/dependency controls.
3. A concrete reachable sink and security-relevant effect.
4. Preconditions, existing mitigations, and the strongest counterevidence.
5. A final policy decision and severity consistent with the saved repository threat model.

Local developer-only availability failures, operator-only footguns, duplicate wrappers, and issues whose alleged impact was defeated by an exact control or browser reproduction were not retained as vulnerabilities.

## Reportable findings

### BC-01 — Stored calendar subscriptions permit SSRF to arbitrary HTTP(S) destinations

- Severity: **Medium (P2)**; conditional High if sensitive private/metadata impact is demonstrated.
- Confidence: High in destination control failure; Medium in deployment-specific impact.
- Candidates: `candidate-fb5278a4e1e52a43` (canonical), `candidate-5b249159efc8d9d5`, `candidate-afe1fe150488c6b4`, `candidate-43301e4b0a9f60c8`, `candidate-f1bc776831b2c674`, `candidate-d166a30b6a4be16c`.
- Source: authenticated `POST /api/v1/calendars/subscribe` reads and persists `url` at `server/src/Http/Controllers/CalendarsController.php:50-69` and `server/src/Domain/Calendars.php:76-90`.
- Missing control: `server/src/Domain/Feeds.php:61-84` checks only HTTP(S), follows five redirects, and does not reject loopback, link-local, private, metadata, or redirect-resolved addresses.
- Sink: cURL immediately fetches the stored URL; manual refresh and `server/bin/worker.php:61-64,121-135` repeat it.
- Impact: blind internal GETs, limited reachability/error oracle, persistent polling, and import of an internal response if it happens to contain a valid `VCALENDAR` marker.
- Existing controls: authentication, CSRF for cookie sessions, ownership on refresh, HTTP(S)-only schemes, timeout, redirect count, nominal 20 MiB cap, and post-fetch ICS validation. None constrains the destination before a request.
- Attack-path facts: remote authenticated vector; network boundary crossed; same application user but greater server-network privilege; no production probing performed.
- Fix: centralize outbound URL policy; parse and resolve every initial and redirect target; reject non-global IPs after each resolution; defend against DNS rebinding; disable redirects or validate each hop; use an application write callback for a version-independent byte cap.

### BC-02 — Web Push subscription endpoints permit blind SSRF

- Severity: **Medium (P2)**; conditional High if a sensitive internal side effect or metadata path is demonstrated.
- Confidence: High in destination control failure; Medium in deployment-specific impact.
- Candidates: `candidate-57ac0885fb711af6` (canonical), `candidate-a4ac9bcf7340342c`, `candidate-47c1127e40263f85`, `candidate-19a77ed356f61617`.
- Source: authenticated `/push/subscribe` persists an arbitrary lowercase `https://` endpoint at `server/src/Http/Controllers/PushController.php:40-44` and `server/src/Domain/PushSubscriptions.php:33-64`.
- Missing control: endpoint validation does not parse the host, resolve it, reject private addresses, or bind keys to the endpoint.
- Sink: `server/src/Infra/PushSender.php:46-66` gives the endpoint to Minishlink WebPush. Locked Minishlink 9.0.4 builds a Guzzle request from it; default Guzzle redirect handling permits five HTTP(S) hops with no host/IP policy.
- Impact: direct encrypted POSTs to private HTTPS services; attacker-controlled public HTTPS can redirect to internal HTTP(S), with 301/302/303 becoming an empty GET; `/push/test` exposes an aggregate success/failure oracle and reminder workers can trigger the stored endpoint later.
- Existing controls: authentication/CSRF, VAPID configuration, valid P-256/auth values, HTTPS on the initial endpoint, encrypted body, and no response-body disclosure. Cross-origin redirects strip credentials, but the request still crosses the network boundary.
- Attack-path facts: remote authenticated vector; VAPID and usable keys are preconditions; private destination is controllable; exact production VAPID state was not checked.
- Fix: apply the same resolved-address and redirect-hop policy as BC-01; consider disabling redirects for push delivery; reject endpoints resolving to non-global ranges immediately before connection.

### BC-03 — MCP event IDs escape the advertised tool route and invoke unrelated PAT-authorized APIs

- Severity: **Medium (P2)**.
- Confidence: High; reproduced through the real MCP adapter against a local mock API.
- Candidates: `candidate-ef685e7bc6b67195` (canonical umbrella), `candidate-8b5e2ea7523e0309`, `candidate-6437f821472d5b65`.
- Source: `tools/call` sends `params.arguments` directly to handlers at `tools/mcp/server.mjs:264-272`; advertised JSON Schema is never enforced.
- Missing control: `update_event` and `delete_event` interpolate `id` into a URL path at `tools/mcp/server.mjs:163-199`; update also forwards every undeclared property.
- Sink: `fetch` receives a bearer-authenticated normalized URL at `tools/mcp/server.mjs:19-37`.
- Reproduction: `id: "../settings"` produced `PATCH /api/v1/settings` with `{"notifyEmail":"probe@example.test"}`; `id: "../calendars/17"` produced `DELETE /api/v1/calendars/17`, both with the configured bearer token.
- Impact: an untrusted MCP caller can expand event-only update/delete tools into same-user settings mutation or destructive deletion of calendars and related data. This crosses the explicit delegated-tool capability boundary even though the PAT itself has broader REST authority.
- Existing controls: the adapter is local and the PAT is valid; the underlying REST API still enforces the PAT user's ownership. The impact is same-user but outside the advertised tool authority.
- Fix: validate every tool call server-side; require safe integer IDs; use `encodeURIComponent` for path segments; reject undeclared properties; construct update bodies with an allowlist as `create_event` already does.

### BC-04 — Service worker replays private authenticated API data after logout

- Severity: **Medium (P2)**.
- Confidence: High; reproduced with the repository service worker in Chrome.
- Candidates: `candidate-29419666d0fdf023` (canonical), `candidate-d1be9ecd15df93c4`, `candidate-5e6fd04348c2bb4a`, `candidate-d003bfb818154b0b`.
- Source/sink: `web/sw.js:72-94,136-142` caches every successful same-origin `/api/` GET by request URL and returns it on timeout/offline failure.
- Missing control: cache identity does not incorporate the authenticated principal; JSON responses do not `Vary` on session; `web/src/app/api.js:62-65` clears only in-memory auth state on logout.
- Reproduction: a controlled `/api/v1/me` response cached while `session=user-a` was returned byte-for-byte after cookies were cleared and the origin server was offline, including a private marker.
- Impact: a later person using the same browser profile can recover prior `/me`, calendar, event, people, search, token metadata, or outbound-feed capability URLs without a current session. A cached `/me` can also make the PWA appear authenticated offline.
- Existing controls: exploitation requires the same browser profile and an offline/slow/failing network. The app is currently single-user, but logout is an explicit local confidentiality boundary and the saved threat model names this cache behavior as Medium.
- Fix: do not cache authenticated API responses; or partition cache names by a non-secret user identifier and delete them on logout/401. Add `Cache-Control: no-store` for secrets and token-bearing endpoints. Avoid using cached `/me` as proof of authentication.

### BC-05 — Public REST password login has no attempt throttling

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-2e3854741699b1ab` (canonical), `candidate-0959b2e029a92423`, `candidate-f223b0b156326882`; `candidate-cb6233f628469d60` is a duplicate umbrella shared with BC-06.
- Path: public route at `server/public/index.php:103,199-200` → `server/src/Http/Controllers/AuthController.php:17-23` → `server/src/Domain/Auth.php:23-25` password verification.
- Missing control: no per-account, per-IP, global, or progressive attempt limiter is present in the repository or documented nginx configuration.
- Impact: unlimited online password guessing against the sole Internet-facing account.
- Existing controls: generic 401 text, a computationally expensive password hash, TLS, and no signup. Those slow individual guesses but do not provide a failed-attempt budget.
- Fix: add server-side rate limits with safe proxy-IP handling, per-account plus per-source buckets, exponential delays, monitoring, and a bounded global safeguard. Avoid permanent attacker-triggered lockout.

### BC-06 — CalDAV Basic authentication has no attempt throttling

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-8c9a1d489fafc083` (canonical); `candidate-cb6233f628469d60` is the duplicate umbrella.
- Path: Internet-facing `server/public/dav.php:34-48` → `server/src/Dav/AuthBackend.php:22-37`.
- Missing control: repeated Basic-auth password/token attempts have no application attempt budget or documented edge limiter.
- Impact: a separate unlimited online guessing surface for the account password and bearer-style DAV tokens.
- Existing controls: unknown usernames use a dummy hash, tokens are high entropy, and failures are generic. Known-account password guesses still perform normal verification without throttling.
- Fix: share a durable limiter with REST login while tracking the DAV endpoint separately; rate-limit both username and source dimensions; do not weaken the unknown-user dummy verification.

### BC-07 — Forged iMIP CANCEL mutates an existing event by UID without organizer authentication

> **FIXED 2026-08-06** — see "Fixed: iMIP forgery and replay" below.

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-ce953eac5599277c` (canonical); broad duplicates `candidate-994dcf60340e2e95`, `candidate-18cdc53f86bb04b4`, `candidate-2a974cf3bc1633da`, and `candidate-b203ff52ff896b1d` also cover BC-08.
- Source: public inbound mailbox messages fetched at `server/src/Infra/MailFetcher.php:45-98`.
- Missing control: message sender and parsed `ORGANIZER` are retained but never compared to the event's trusted organizer before mutation in `server/src/Domain/MailIngest.php:288-297,366-383`.
- Sink: an active event selected by user ID plus attacker-known UID is cancelled at `MailIngest.php:372-383`.
- Impact: an external sender who knows a UID, as legitimate participants normally do, can cancel the calendar owner's matching event with a fresh Message-ID.
- Existing controls: UID knowledge, mailbox delivery, and ingest configuration are required; outer Message-ID dedup blocks exact redelivery only.
- Fix: bind organizer identity when first accepting an invitation; require subsequent CANCEL messages to match the trusted organizer and authenticated mail identity where available; quarantine mismatches for user review.

### BC-08 — Forged iMIP REQUEST overwrites a non-recurring local event by UID

> **FIXED 2026-08-06** — see "Fixed: iMIP forgery and replay" below.

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-6cb01aa6087e5e8d` (canonical); the same four broad iMIP candidates listed under BC-07 are duplicate umbrellas.
- Path: mailbox/ICS source as BC-07; attacker-controlled fields flow from `server/src/Domain/MailIngest.php:386-399` to `Events::patch` at `:401-404` after lookup by user ID plus UID.
- Impact: an external sender can replace title, time, description, location, and related fields of a matching ordinary local event.
- Existing controls: feed events are protected and recurring masters need a scope, which narrows the affected object set; organizer/sender authenticity remains unchecked for ordinary local events.
- Fix: apply the same persisted organizer and mail-authentication binding as BC-07; never let unauthenticated iMIP silently take ownership of an unrelated local UID collision.

### BC-09 — Stale iMIP SEQUENCE values can replay older updates and cancellations

> **FIXED 2026-08-06** — see "Fixed: iMIP forgery and replay" below.

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-224a2e903d690422`.
- Source/control: `SEQUENCE` is parsed and stored at `server/src/Domain/MailIngest.php:91-98`, but no comparison occurs before existing-event mutation at `:366-404`.
- Sink: a lower-sequence message with a fresh Message-ID can cancel or overwrite a newer event and replace the stored sequence metadata at `:465-477`.
- Existing controls: Message-ID dedup blocks exact duplicate messages, not semantically stale messages with a new ID.
- Fix: reject or quarantine a REQUEST/CANCEL whose sequence is lower than the stored value; define safe handling for equal sequence using DTSTAMP and organizer identity.

### BC-10 — Mail ingestion materializes unbounded MIME messages and attachments before processing

- Severity: **Medium (P2)**.
- Confidence: Medium-High; exact static path, provider limits not measured.
- Candidates: `candidate-46c10c5469e2e1bc` (canonical), `candidate-5b98fcd3b96bed46`, `candidate-a49999e6c01766ea`, `candidate-ec6c4180375beeef`, `candidate-9521cce9d2744a1e`.
- Source: public inbound email fetched at `server/src/Infra/MailFetcher.php:45-98`.
- Missing control: up to ten messages, full raw/decoded bodies, HTML/text, and every attachment are accumulated before `fetchUnseen` returns; no byte, MIME part, nesting, or attachment count cap is enforced by the application.
- Sink: the worker processes the accumulated data afterward at `server/bin/worker.php:90-95,148-152`; ICS can be parsed twice and markup/LLM preparation adds work.
- Impact: worker memory exhaustion or prolonged processing; failure before the message is marked Seen can cause repeated cron attempts.
- Existing controls: message count is ten and LLM text is truncated; provider-specific size limits may bound real input but are not repository controls.
- Fix: fetch/process one message at a time; enforce raw, decoded, part-count, attachment, and ICS byte limits before materialization; mark/quarantine poison messages after bounded failure.

### BC-11 — User regex filters multiply expensive PCRE work across events and search results

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-d45b31b8d8de887f` (window path) and `candidate-629381afdab4939f` (search path).
- Source: authenticated filters at `server/src/Http/Controllers/FiltersController.php:22-29` and `server/src/Domain/Filters.php:64-88`.
- Missing control: validation at `Filters.php:435-460` checks syntax and 500-character length, but not pattern complexity, enabled-filter count, or aggregate match budget.
- Sink: `preg_match` at `Filters.php:313-347` runs across fields for every enabled filter and event/occurrence at `server/src/Domain/Events.php:161-223`; search applies all enabled filters to up to 200 rows.
- Reproduction: a bounded pattern reached `PREG_BACKTRACK_LIMIT_ERROR` in about 2.8 ms per match. PCRE bounds one match, but repeated failures multiply across rows, fields, and filters.
- Existing controls: authenticated setup, two-year event windows, 200-row search cap, about 200-character search descriptions, and PCRE's per-match backtrack limit. No aggregate work cap exists.
- Fix: remove arbitrary regex if unnecessary; otherwise limit filter count, use a safe-regex policy, set tighter match limits, stop on `preg_last_error`, and impose an aggregate per-request evaluation budget.

### BC-12 — Multipart ICS import has no application byte, component, or insertion bound

- Severity: **Medium (P2)**.
- Confidence: Medium-High.
- Candidates: `candidate-77ba68f07b1957ae` (canonical), `candidate-00ba8be129314008`; `candidate-0eb8095185e90b1d` is a duplicate umbrella shared with BC-13.
- Source: authenticated multipart import at `server/public/index.php:108-110,197-229` and `server/src/Http/Controllers/CalendarsController.php:92-107`.
- Sink: the entire file is read and Sabre materializes all VEVENTs at `server/src/Domain/Ics.php:267-280`; all components are sorted and inserted in a transaction at `CalendarsController.php:116-155`.
- Impact: a malicious calendar file can consume parser memory/CPU and cause many thousands of database statements from a relatively small upload.
- Existing controls: session/bearer auth, CSRF for sessions, external upload limits, UID dedup, and transactional writes. None is an application-level event/work budget.
- Fix: reject by byte size before reading; count components with a strict ceiling; cap imported events and overrides; chunk database work; report partial/oversized imports cleanly.

### BC-13 — CalDAV PUT parses an unbounded object and applies every recurrence override

- Severity: **Low (P3)**.
- Confidence: Medium.
- Candidates: `candidate-528ab297f9e165ea`; `candidate-0eb8095185e90b1d` is the duplicate umbrella.
- Path: Basic-authenticated PUT through `server/public/dav.php:34-48` and `server/src/Dav/CalendarBackend.php:206-213`; full parse at `CalendarBackend.php:247-255` precedes object-shape checks, then every override can cause an insert/update at `:287-345`.
- Impact: request-scoped parser/database exhaustion by a credentialed DAV client.
- Existing controls: authentication, calendar writability, object-shape/UID/URI checks, ingress/PHP resource limits, and Sabre parser behavior. The attacker already has write authority, making this primarily same-user availability risk.
- Fix: enforce request bytes and VEVENT/override counts before database work; cap statements per calendar object.

### BC-14 — ICS line folding performs strongly superlinear work on large properties

- Severity: **Medium (P2)**.
- Confidence: High; exact helper benchmarked.
- Candidates: `candidate-a93bfb36c379c63b`.
- Source: remote feed descriptions are parsed without a field bound at `server/src/Domain/Ics.php:378-393` and persisted by `server/src/Domain/Feeds.php:135-170`.
- Sink: public outbound feeds and CalDAV serialize through `Ics::fold` at `server/src/Domain/Ics.php:44-62,81-96,120-164`, repeatedly copying the remaining suffix.
- Reproduction: exact helper timing was approximately 0.121 s at 1 MiB, 0.500 s at 2 MiB, and 7.697 s at 4 MiB, confirming strongly superlinear growth.
- Existing controls: nominal 20 MiB feed cap, at most 5,000 outbound rows, and one UID per CalDAV object. A single multi-megabyte property is sufficient.
- Fix: fold with an offset/cursor without repeatedly replacing the remaining string; cap imported property lengths before persistence and export.

### BC-15 — Extreme event spans cause viewport-independent browser allocation and repeated selector loops

- Severity: **Medium (P2)**.
- Confidence: High.
- Candidates: `candidate-36763108b64fbc67` (canonical row segmentation), `candidate-8ffbc8223a97b3f7`, `candidate-fe1264272299ec66` (reschedule sink).
- Source: external feed/import DTSTART/DTEND values at `server/src/Domain/Ics.php:296-315` persist without a maximum span at `server/src/Domain/Feeds.php:135-170`.
- Sink 1: `web/src/ui/MonthGrid.js:62-90` calls `rowSpanSegments` before viewport selection; `web/src/ui/monthmath.js:86-105` allocates one segment per calendar row between stored endpoints.
- Sink 2: `web/src/ui/RescheduleMode.js:193-201` performs one DOM selector query per day of the full span on each pointer update.
- Reproduction: a 1,000-year three-column span allocated 121,748 segment objects and about 9.9 MiB heap; dates 1000–9999 imply 1,095,728 three-day segments. The reschedule loop can perform millions of selector operations.
- Existing controls: the rendered grid is virtualized to about ten years and API occurrence windows are capped, but preprocessing is not clamped to either range. Impact is the subscribing/importing user's tab.
- Fix: crop all event spans to the requested/rendered interval before segmentation; cap accepted dates and maximum duration; highlight only visible day cells rather than iterating calendar days.

### BC-16 — Unescaped UID/URL values inject iCalendar content lines during export

- Severity: **Medium (P2)**.
- Confidence: High in injection; Medium-High in downstream impact.
- Candidates: `candidate-e0a60d1944c8a76c`.
- Source: Sabre forgiving parse returns a UID containing an escaped newline at `server/src/Domain/Ics.php:378-383`; feed sync persists it directly at `server/src/Domain/Feeds.php:135-170`. Authenticated event creation can also supply literal control characters in UID/URL.
- Missing control/sink: `server/src/Domain/Ics.php:125,169-170` serializes UID and URL without `Ics::escape` or CR/LF rejection.
- Reproduction: input `UID:escaped\\nX-UID-PWN:yes` parsed as a UID containing a real newline and re-exported as two content lines, including attacker-controlled `X-UID-PWN:yes`. Direct rows with literal CR/LF injected arbitrary UID and URL lines.
- Impact: a feed publisher can place properties/components into public filtered feeds or CalDAV output that Better-Cal did not model or intend to forward, bypassing property-level filtering and potentially reintroducing alarms, attendee metadata, or additional event semantics in downstream clients.
- Existing controls: most human-readable fields use `Ics::escape`; URL escaped `\\n` was not decoded in the tested Sabre path, but UID was. Downstream client handling varies.
- Fix: reject CR/LF in structural fields on every ingestion path; serialize UID/URL through a context-correct property writer or Sabre VObject; add round-trip tests for escaped and literal newlines.

### BC-17 — MCP setup documentation can place a full bearer token in source control or shell history

- Severity: **Low (P3)**.
- Confidence: High.
- Candidates: `candidate-2bf2e652afd830dc` (canonical), `candidate-5c4e099ef9249e73`.
- Path: `tools/mcp/README.md:10-32` shows a literal long-lived PAT in CLI arguments and a project `.mcp.json` described as “checked in or local”; `.gitignore` does not exclude `.mcp.json`.
- Impact: a user following the checked-in option can publish a credential granting calendar API access; command-line use can also retain it in shell history or process listings.
- Existing controls: the examples use placeholders and setup is operator-driven. The misleading “checked in” guidance creates a realistic accidental secret boundary crossing.
- Fix: state that credential-bearing config must never be committed; ignore `.mcp.json` or provide a safe checked-in template without secrets; use the host's external secret/env facility and avoid literal token arguments where possible.

### BC-18 — PWA share/protocol handoffs put private content and capability URLs in request URLs

- Severity: **Low (P3)**.
- Confidence: High.
- Candidates: `candidate-df1cde6e05ea02d8` (share target), `candidate-2a323032f2166007` (webcal handler).
- Source/control: `web/manifest.webmanifest:16-23` uses GET `/share?...` and `/subscribe?url=%s`.
- Sink: normal authenticated boot occurs before `history.replaceState` at `web/src/app/main.js:16-43,70-86`, so the initial URL reaches origin/proxy logs and same-origin boot requests can carry it as the Referer.
- Reproduction: a local Chrome page with `?secret=feed-token` sent the full query URL as Referer on same-origin subresource requests before cleanup.
- Impact: shared titles/text/URLs and token-bearing private ICS subscription URLs are retained in browser history, access logs, and duplicate Referer-bearing requests.
- Existing controls: same-origin only in the observed flow, client cleanup after boot, and user-initiated PWA handling. Exploitation requires access to those logs/history.
- Fix: use a POST share target; pass protocol-handler data through a fragment or another client-only handoff; set a strict Referrer-Policy early; clean the URL before authenticated boot.

### BC-19 — Authenticated test-email endpoint can send unlimited fixed messages to an unverified recipient

- Severity: **Low (P3)**.
- Confidence: Medium-High.
- Candidates: `candidate-85f0dfa06d11653c`.
- Path: settings accepts any syntactically valid `notifyEmail` at `server/src/Domain/Settings.php:145-160`; authenticated `/push/test-email` at `server/public/index.php:186` sends to it through `server/src/Http/Controllers/PushController.php:87-112` and `server/src/Infra/EmailSender.php:89-95`.
- Impact: a compromised/delegated full API token can repeatedly send the fixed test body to arbitrary valid addresses using the configured SMTP identity.
- Existing controls: authentication, session CSRF, one fixed message per request, single-user deployment, and required SMTP configuration.
- Fix: rate-limit the test endpoint; restrict it to a verified account address or require recipient verification; consider requiring a cookie session for this administrative action.

### BC-20 — IATA generator accepts non-finite coordinates and emits invalid PHP tokens

- Severity: **Low (P3)**.
- Confidence: High in the defect; low likelihood.
- Candidates: `candidate-50fba42e449ad1fd`.
- Source: operator-supplied third-party OurAirports CSV at `tools/gen-iata.py:1-19`.
- Missing control/sink: Python accepts NaN/infinity as floats; `tools/gen-iata.py:30-43` emits them unquoted into executable PHP, which is required at `server/src/Domain/Geocode.php:78-84`.
- Impact: a poisoned upstream row can make airport-code paths return request-level 500 errors after a trusted operator regenerates, reviews, commits, and deploys the table.
- Existing controls: no automated regeneration exists, operator review is required, string fields are escaped, current generated data is clean, and impact is limited to airport lookup/search availability.
- Fix: require `math.isfinite`, enforce latitude/longitude ranges, and run `php -l` plus representative lookups against generated output in CI.

## Deferred leads

### D-01 — Unknown-length feed response may bypass the intended 20 MiB cap on old libcurl

- Candidates: `candidate-328989694d17e46b` (canonical), `candidate-4da7159414378145`, `candidate-8c08273461b0f94f`, `candidate-6a5230602d5c0723`.
- Current disposition: **Deferred**, provisional Medium.
- Code path: `server/src/Domain/Feeds.php:61-100` uses `CURLOPT_MAXFILESIZE`, buffers with `curl_exec`, then performs a post-buffer `strlen` check. The cron worker persistently retriggers stored URLs.
- Dependency fact: curl documents that unknown-length ongoing transfers were not stopped by this option before libcurl 8.4.0; modern versions enforce the cap during transfer. See [official CURLOPT_MAXFILESIZE documentation](https://curl.se/libcurl/c/CURLOPT_MAXFILESIZE.html).
- Evidence: local libcurl is 8.18.0 and safe for this behavior; the production PHP-linked libcurl version is not recorded in the repository and production was not probed.
- Closure condition: if production libcurl is at least 8.4.0, suppress this candidate. Otherwise it survives as Medium. A write callback enforcing received bytes would remove the version dependency.

### D-02 — Feed HTML is re-exported verbatim as `X-ALT-DESC`

- Candidates: `candidate-15bd06fe54e618c0`.
- Current disposition: **Deferred**, provisional Low/Medium depending on client effect.
- Proven path: untrusted feed `DESCRIPTION` HTML is stored unsanitized and `server/src/Domain/Ics.php:153-164` re-exports it verbatim in `X-ALT-DESC;FMTTYPE=text/html`. A real Sabre round trip retained `<img onerror>` and `<script>` markup exactly.
- Proof gap: no supported downstream calendar client was shown to execute the active markup in a security-relevant origin or to perform another meaningful unsafe action. Propagating HTML alone does not prove XSS in Better-Cal.
- Fix regardless: sanitize feed descriptions with the server's HTML allowlist before persistence/export, or omit rich HTML for untrusted subscriptions and export plain text only.

## Suppressed and disproved lead families

### S-01 — REST account enumeration by password timing

- Candidates: `candidate-78332b856c9ebb91`, `candidate-05fde07a2696f2b5`.
- Disposition: suppress as not materially reportable in the current single-user deployment.
- The timing difference is real because an unknown email skips `password_verify`, but the sole account address is documented/operator-known, responses are generic, and the leak adds no meaningful secret. Preserve the DAV dummy-hash pattern as defense-in-depth if desired.

### S-02 — Seed password in command-line arguments

- Candidate: `candidate-b141f9a07209fd3a`.
- Disposition: suppress under the operator-CLI exclusion. `server/bin/seed.php` is already a deployment-user shell action and creates no privilege increase. Prefer a prompt/stdin secret for hygiene.

### S-03 — Client singleton state retained across logout

- Candidate: `candidate-9ed8a7c47451ef41`.
- Disposition: suppress for current scope. Stale state exists, but logout renders the login UI and the product has one account; a replacement login receives the same user's data. Reopen if supported multi-user provisioning is added. Clearing all state remains good hygiene.

### S-04 — MCP null message crash and unbounded JSON-RPC record

- Candidates: `candidate-a667c3599d576424`, `candidate-fc660a29b593b535`, `candidate-745b246c9f3ba8f3`.
- Disposition: suppress as local adapter availability/correctness under the saved threat model.
- `null` does crash the Node process and readline has no record limit, but impact is confined to the caller's local MCP process. Return JSON-RPC `-32600` and impose a record limit as hardening.

### S-05 — Retired production files can survive rsync deployment

- Candidate: `candidate-5505297eb29b45bb`.
- Disposition: suppress as an unproven deployment risk.
- `scripts/deploy.sh` intentionally omits `--delete`, but repository history shows no deleted tracked file beneath `server/public` and no stale public artifact is established. Maintain an explicit release manifest or audited delete list.

### S-06 — Event `javascript:` URL produces authenticated-origin XSS

- Candidates: `candidate-a29d6ce869de91b2`, `candidate-ef4326b1448798bf`, `candidate-81e230b1690cf8dd`, `candidate-05dafc625d038830`, `candidate-d6cec71f3c2be311`, `candidate-435e25a146c0ab3e`, `candidate-51dbee51af8e724c`.
- Disposition: suppress the alleged XSS; keep scheme allowlisting as hardening.
- The storage/rendering path is real, but all cited anchors use `target="_blank"` and `rel="noopener"`/`noreferrer`. In installed Chrome 151, clicking the exact `javascript:` anchor opened a blank `about:blank` page; neither the Better-Cal-origin source nor popup executed the script. A same-context negative control did execute, confirming the payload itself was valid.
- Non-web/custom schemes can still create confusing navigation or external-handler prompts. Allow only `https:`/`http:` for a cleaner trust boundary, but the candidate's session-impacting XSS claim was not validated.

### S-07 — Login CSRF

- Candidate: `candidate-c344a11191545e77`.
- Disposition: suppress in the current single-user/no-signup model.
- Cross-site form login and cookie replacement are mechanically possible, but there is no attacker-controlled account to log the victim into; knowing the sole account password already grants equivalent access. Reopen for multi-user provisioning.

### S-08 — Pre-auth JSON body buffering

- Candidates: `candidate-6bf1f6d1f7fe03b3`, `candidate-9aaf7266253e6d19`.
- Disposition: suppress as ordinary linear request-size hardening owned by ingress/PHP limits.
- Local 1/4/8 MiB decoding was linear and fast. Reopen only if deployed ingress accepts bodies near worker memory limits without a lower cap.

### S-09 — RRULE `fastForward` runs before Better-Cal's 500-occurrence cap

- Candidate: `candidate-4c948dce5464085d`.
- Disposition: suppress; exact dependency control defeats disproportionate work.
- Sabre VObject 4.6.1 `EventIterator::valid` enforces `Settings::$maxRecurrences = 3500` during `fastForward`. An exact Better-Cal expansion from a daily 1900 master into a 2026 window stopped at that dependency limit in about 11.57 ms and returned no occurrences. The app's later 500-result cap is not the only effective bound.

### S-10 — Combined umbrella candidates

- Candidates: `candidate-cb6233f628469d60` and `candidate-0eb8095185e90b1d`.
- Disposition: duplicate-only. The first is fully represented by BC-05 and BC-06; the second is fully represented by BC-12 and BC-13. They add no independent control or sink.

## Candidate closure ledger

Every input row is explicitly closed below.

| Candidate ID | Closure |
|---|---|
| `candidate-ef685e7bc6b67195` | BC-03 canonical umbrella |
| `candidate-05fde07a2696f2b5` | S-01 suppressed duplicate |
| `candidate-b141f9a07209fd3a` | S-02 suppressed |
| `candidate-9ed8a7c47451ef41` | S-03 suppressed |
| `candidate-fc660a29b593b535` | S-04 suppressed duplicate |
| `candidate-994dcf60340e2e95` | duplicate umbrella of BC-07/BC-08 |
| `candidate-f223b0b156326882` | BC-05 duplicate |
| `candidate-cb6233f628469d60` | S-10 duplicate umbrella of BC-05/BC-06 |
| `candidate-5c4e099ef9249e73` | BC-17 duplicate |
| `candidate-18cdc53f86bb04b4` | duplicate umbrella of BC-07/BC-08 |
| `candidate-6a5230602d5c0723` | D-01 duplicate |
| `candidate-ec6c4180375beeef` | BC-10 duplicate |
| `candidate-a93bfb36c379c63b` | BC-14 canonical |
| `candidate-36763108b64fbc67` | BC-15 canonical |
| `candidate-9aaf7266253e6d19` | S-08 suppressed duplicate |
| `candidate-0eb8095185e90b1d` | S-10 duplicate umbrella of BC-12/BC-13 |
| `candidate-9521cce9d2744a1e` | BC-10 duplicate |
| `candidate-5505297eb29b45bb` | S-05 suppressed |
| `candidate-5e6fd04348c2bb4a` | BC-04 duplicate |
| `candidate-d003bfb818154b0b` | BC-04 duplicate |
| `candidate-05dafc625d038830` | S-06 suppressed duplicate |
| `candidate-d6cec71f3c2be311` | S-06 suppressed duplicate |
| `candidate-435e25a146c0ab3e` | S-06 suppressed duplicate |
| `candidate-15bd06fe54e618c0` | D-02 deferred |
| `candidate-51dbee51af8e724c` | S-06 suppressed duplicate |
| `candidate-f1bc776831b2c674` | BC-01 duplicate |
| `candidate-19a77ed356f61617` | BC-02 duplicate |
| `candidate-d166a30b6a4be16c` | BC-01 duplicate |
| `candidate-e0a60d1944c8a76c` | BC-16 canonical |
| `candidate-d45b31b8d8de887f` | BC-11 affected instance |
| `candidate-8b5e2ea7523e0309` | BC-03 affected instance |
| `candidate-6437f821472d5b65` | BC-03 affected instance |
| `candidate-a667c3599d576424` | S-04 suppressed duplicate |
| `candidate-50fba42e449ad1fd` | BC-20 canonical |
| `candidate-d1be9ecd15df93c4` | BC-04 affected token-leak instance |
| `candidate-78332b856c9ebb91` | S-01 suppressed canonical |
| `candidate-224a2e903d690422` | BC-09 canonical |
| `candidate-8c9a1d489fafc083` | BC-06 canonical |
| `candidate-0959b2e029a92423` | BC-05 duplicate |
| `candidate-2e3854741699b1ab` | BC-05 canonical |
| `candidate-2a974cf3bc1633da` | duplicate umbrella of BC-07/BC-08 |
| `candidate-ce953eac5599277c` | BC-07 canonical |
| `candidate-6cb01aa6087e5e8d` | BC-08 canonical |
| `candidate-b203ff52ff896b1d` | duplicate umbrella of BC-07/BC-08 |
| `candidate-c344a11191545e77` | S-07 suppressed |
| `candidate-629381afdab4939f` | BC-11 affected instance |
| `candidate-8ffbc8223a97b3f7` | BC-15 duplicate/affected instance |
| `candidate-fe1264272299ec66` | BC-15 affected reschedule sink |
| `candidate-528ab297f9e165ea` | BC-13 canonical |
| `candidate-4da7159414378145` | D-01 duplicate |
| `candidate-8c08273461b0f94f` | D-01 duplicate |
| `candidate-77ba68f07b1957ae` | BC-12 canonical |
| `candidate-4c948dce5464085d` | S-09 disproved by dependency cap |
| `candidate-5b98fcd3b96bed46` | BC-10 duplicate |
| `candidate-a49999e6c01766ea` | BC-10 duplicate amplifier |
| `candidate-46c10c5469e2e1bc` | BC-10 canonical |
| `candidate-00ba8be129314008` | BC-12 duplicate |
| `candidate-328989694d17e46b` | D-01 canonical deferred |
| `candidate-6bf1f6d1f7fe03b3` | S-08 suppressed canonical |
| `candidate-745b246c9f3ba8f3` | S-04 suppressed |
| `candidate-2bf2e652afd830dc` | BC-17 canonical |
| `candidate-29419666d0fdf023` | BC-04 canonical |
| `candidate-df1cde6e05ea02d8` | BC-18 share-target instance |
| `candidate-2a323032f2166007` | BC-18 protocol-handler instance |
| `candidate-a29d6ce869de91b2` | S-06 suppressed canonical |
| `candidate-ef4326b1448798bf` | S-06 suppressed duplicate |
| `candidate-81e230b1690cf8dd` | S-06 suppressed duplicate |
| `candidate-85f0dfa06d11653c` | BC-19 canonical |
| `candidate-fb5278a4e1e52a43` | BC-01 canonical |
| `candidate-5b249159efc8d9d5` | BC-01 duplicate |
| `candidate-afe1fe150488c6b4` | BC-01 duplicate/oracle instance |
| `candidate-57ac0885fb711af6` | BC-02 canonical |
| `candidate-43301e4b0a9f60c8` | BC-01 duplicate/manual-refresh instance |
| `candidate-a4ac9bcf7340342c` | BC-02 duplicate/worker instance |
| `candidate-47c1127e40263f85` | BC-02 duplicate |

## Verification performed

- `php server/tests/run.php`: **706 passed, 0 failed** in the repository without optional Sabre dependencies.
- Disposable copy with locked Composer dependencies: **718 passed, 0 failed**.
- `node web/tests/smoke.mjs`: **297 passed, 0 failed**.
- `node tools/mcp/test.mjs`: **28 passed, 0 failed**.
- Real MCP adapter/local mock API reproduction: route escape confirmed for authenticated PATCH and DELETE.
- Real Chrome/local-only service-worker reproduction: prior authenticated response replayed after cookie clearing and origin failure.
- Real Chrome event-link reproduction: alleged `javascript:` XSS did not execute with the application's `_blank`/`noopener` context; same-context control executed.
- Real Sabre 4.6.1 ICS round trip: UID content-line injection and raw `X-ALT-DESC` propagation confirmed.
- Exact Sabre recurrence path: 3,500-occurrence dependency cap confirmed; far-past daily rule stopped in about 11.57 ms.
- Exact ICS folding helper: strongly superlinear behavior confirmed at 1/2/4 MiB.
- Git worktree remained clean; no repository files were changed.

## Recommended fix order

1. BC-01 and BC-02: one reusable outbound destination/redirect/IP policy.
2. BC-03: MCP schema enforcement, integer path segments, and update-field allowlisting.
3. BC-04: stop caching authenticated API responses and purge legacy API caches.
4. BC-07 through BC-10: bind iMIP identity/sequence and cap mail materialization.
5. BC-14 through BC-16: linearize ICS folding, clamp event spans, and use safe structural ICS serialization.
6. BC-05/BC-06 and remaining bounded-input issues.

## Fixed: iMIP forgery and replay (BC-07, BC-08, BC-09) — 2026-08-06

An iMIP message is unauthenticated email. Before this change, anything that knew an event's UID could cancel or rewrite that event by mailing the ingest address, and the owner would see nothing: a cancelled meeting looks exactly like one that was never accepted. UID knowledge is a low bar, since the UID travels in the invitation to every genuine participant.

The organizer recorded when an invitation is first accepted is now the trusted identity for that UID. `MailIngest::imipMayMutate()` gates every mutation of an existing event on it, and refuses when:

- the message's `ORGANIZER` and its envelope sender both fail to match the bound organizer (either one matching is accepted, because mailing lists and calendaring services legitimately send on an organizer's behalf), or
- the incoming `SEQUENCE` is lower than the stored one, which is a replayed older state that Message-ID dedup cannot catch, since a stale body can be resent under a fresh Message-ID, or
- no organizer was ever bound to the event. This is the UID-collision case in BC-08: a local event that never carried an invitation is not something unauthenticated mail gets to take ownership of. It fails closed.

Creating a new event from an unknown UID is still open. That is what an invitation is.

Refusals are recorded twice, for two different readers: `mail_ingest` keeps the per-message row with the reason in `error`, and the activity feed gets a log-only `refuse` entry (migration `012`) carrying the reason, the sender, and the organizer on file. Nothing is mutated, so these rows have no snapshot and are never undoable. They exist so a blocked tamper attempt is visible where the owner actually looks, rather than only in worker stdout.

### Residual risk: no inbound authentication verdict is available

The review's fix note says to match "authenticated mail identity where available". On this deployment it is not available. The ingest mailbox is MXroute, and inspection of real delivered messages found **no `Authentication-Results` header** on any of them — only `X-Spam-Status`, plus an `ARC-Authentication-Results` that Google stamped before forwarding, reading `arc=none`. So there is no inbound verdict to consult and a check for one would be dead code here.

That is deliberate on MXroute's part, not an oversight: they [do not act on ARC](https://blog.mxroute.com/arc-the-trust-me-bro-of-email-authentication), on the grounds that trust is not transitive and there is no universal trust network that would say which forwarders deserve to be believed. Their argument applies to us too. A stamped verdict is an intermediary's assertion, so consuming one would move the trust boundary rather than close it. Any real fix has to verify signatures against the message we actually hold.

What this means concretely: the bar rises from "know the UID" to "know the UID **and** know or spoof the organizer's address". Both the `From` header and the body `ORGANIZER` are attacker-controlled under plain SMTP, so a determined attacker who learns the organizer address can still pass the check. That is a real limit, not a solved problem.

The complete fix is verifying DKIM in-process against the organizer's domain, on the raw message, trusting no intermediary's claim about it. Tracked separately.

### Verification

Ten unit checks in `server/tests/run.php` cover the pure predicate: address normalisation (case, display name, `mailto:`), trusted-sender relay, forged organizer, missing organizer, stale and equal sequence, and both unbound-event cases.

Twenty assertions were run end to end against the deployed code and the production database, exercising the real `ingestMessage` path with generated messages: a genuine REQUEST creates and binds the organizer; a forged CANCEL is refused and the event stays uncancelled; a forged REQUEST is refused with title and start unchanged; both refusals appear in the activity feed with the correct source, summary, sender, and no snapshot; a stale SEQUENCE from the real organizer is refused; and the legitimate organizer can still update and cancel. The probe created and removed its own rows by exact id.
