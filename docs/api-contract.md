# Better-Cal API Contract (v1)

Base path `/api/v1`. JSON everywhere. Auth: `bc_session` cookie; non-GET requires `X-CSRF` header. Alternative: `Authorization: Bearer bc_...` personal access token (CSRF-exempt; see Tokens). Errors: `{"error":{"code","message"}}`.

## Auth
- `POST /auth/login` `{email, password}` → `{ok:true}` + sets cookie. 401 on failure.
- `POST /auth/logout` → `{ok:true}`.
- `GET /me` → `{user:{id,email,displayName,settings}, csrf}` or 401. (`csrf` is null under bearer auth.)

## Personal access tokens
Token value: `bc_` + 43 url-safe base64 chars; stored sha256-hashed, shown once at creation. Bearer requests skip CSRF. Management endpoints are session-auth only: a bearer token gets 403 `session_required`.
- `GET /tokens` → `{tokens:[{id,name,createdAt,lastUsedAt}]}`.
- `POST /tokens` `{name}` → `{id,name,token}` (token shown once).
- `DELETE /tokens/:id` → `{ok:true}`.
- CLI equivalent: `php server/bin/token.php --create --name=X | --list | --revoke=ID`.
- Agent-facing guide: [agent-api.md](agent-api.md); MCP server in `tools/mcp/`.

## Calendars & structure
- `GET /calendars` → `{calendars:[{id,name,color,kind,sourceUrl,visible,position,folderIds:[],tagNames:[],health:{lastPolledAt,status,error,stale:boolean}}], folders:[{id,name,position}], tags:[{id,name}]}`
- `POST /calendars` `{name,color,folderIds?,tagNames?}` → calendar object.
- `PATCH /calendars/:id` (any of name,color,visible,position,folderIds,tagNames,pollIntervalMinutes,staleAfterDays) → calendar object.
- `DELETE /calendars/:id` → `{ok:true}` (soft: deletes calendar + events).
- `POST /calendars/subscribe` `{url,name?,color?}` → calendar object; server fetches immediately, then polls hourly.
- `POST /calendars/:id/refresh` → `{ok:true, imported:N}` (force poll now).
- `POST /calendars/import` multipart file `ics` + `name?` → calendar object + `{imported:N}`.
- `POST /folders` `{name}` / `PATCH /folders/:id` / `DELETE /folders/:id`.

## Events
- `GET /events?start=ISO&end=ISO&calendars=1,2&q=&includeHidden=0` → `{events:[occurrence...]}`
  Occurrence: `{instanceId, eventId, calendarId, uid, title, description, location, url, start, end, allDay, tzid, recurring:boolean, source, attendance, status, score, tags:[], people:[], styleJson, createdAt, updatedAt, isNew:boolean}`
  `score` is a number 0-1 or null: the background trainable-ranking score (feed events only; null until the worker has scored the event or while fewer than 5 feedback signals exist). Clients sort by it client-side; the API's order is unchanged.
  Additive field: occurrences matching an enabled `dim` filter also carry `"dimmed": true`; the field is absent otherwise. Occurrences matching an enabled `hide` filter are omitted from `/events` and `/search` responses entirely. Prompt-filter verdicts (see Filters) feed the same `dimmed`/omission mechanics with the same precedence (hide beats dim).
  `start`/`end` are ISO8601 with offset. Recurring events arrive pre-expanded; `instanceId = eventId + ":" + occurrenceStartUtc` where occurrenceStartUtc uses compact UTC basic format `YYYYMMDDTHHMMSSZ` (e.g. `42:20260801T190000Z`). This format is FROZEN; clients treat instanceId as opaque and use the occurrence's `start` field when an API call needs `instanceStart`.
- `POST /events` `{calendarId,title,start,end,allDay?,tzid?,description?,location?,url?,rrule?,tagNames?,personNames?}` → occurrence (first instance).
- `PATCH /events/:id` body same fields plus `{scope:"this"|"following"|"all", instanceStart?}` (scope required when event is recurring) → `{ok:true}`.
- `DELETE /events/:id` `{scope?,instanceStart?}` → `{ok:true}`.
- `POST /events/:id/attendance` `{attendance:"none"|"interested"|"going"|"hidden"}` → `{ok:true}`. On feed events an attendance change also auto-records a ranking feedback signal (`going`/`interested` → up, `hidden` → down; `none` records nothing).
- `POST /events/:id/feedback` `{signal:"up"|"down"}` → `{ok:true}`. Explicit thumbs feedback (distinct from attendance); accumulates as training signal for background ranking. Not undoable.
- `POST /quickadd` `{text, tz, commit?:boolean, calendarId?}` → `{draft:{title,start,end,allDay,location,personNames:[],calendarId,confidence,source:"llm"|"fallback"}}`; if `commit:true` also creates and returns `{event}`.
- `POST /undo` → `{ok:true, undone:{entity,op}}` or 404 if nothing to undo.

## Search
- `GET /search?q=text&limit=50` → `{results:[occurrence...]}` (FULLTEXT, past included, ranked, newest window first).

## Filters
Keyword/regex/prompt filters applied server-side to `/events` and `/search`. Keyword = case-insensitive substring; regex = PCRE, evaluated case-insensitively. Scope `global` applies to everything, `folder` to every calendar in the folder, `calendar` to that calendar (`scopeId` required for folder/calendar scope). Action `hide` removes matching occurrences; `dim` adds `"dimmed": true` to them. Across all filter types, `hide` beats `dim`.
- `GET /filters` → `{filters:[{id, scope:"global"|"folder"|"calendar", scopeId, type:"keyword"|"regex"|"prompt", config, action:"hide"|"dim", enabled:boolean}]}`.
  `config` by type: keyword/regex `{pattern, fields:["title"|"description"|"location", ...]}`; prompt `{prompt, negativePrompt?, threshold?}`.
- `POST /filters` `{scope, scopeId?, type, config, action?, enabled?}` → filter (201). `config.fields` defaults to all three fields. Invalid regex → 400 `filter_invalid_regex`. Prompt: `config.prompt` required (max 2000 chars), `negativePrompt` optional, `threshold` optional number 0-1 (when set, the LLM score decides pass/fail at that cutoff instead of the model's boolean).
- `PATCH /filters/:id` (any of scope, scopeId, type, config, action, enabled) → filter. Changing a prompt filter's type/config invalidates its cached verdicts and re-evaluates in the background.
- `DELETE /filters/:id` → `{ok:true}`.

Prompt filters are never evaluated in the request path. A background worker job (`filter_eval`) scores feed events against the prompt/negative prompt in Gemini batches (≤25 events per call) and caches per-(filter, event) verdicts in `filter_evals`; `/events` and `/search` join that cache and apply the filter's action to events whose verdict is `fail`. Evaluation triggers: prompt-filter create/update (future feed events, capped at the 500 most recent), each feed poll that changes events, and a nightly catch-up. A missing or not-yet-computed verdict is treated as pass — LLM failures never hide events or block responses. Related: a `rank_events` job (hourly and after polls) uses accumulated feedback signals (≥5 required) to score future feed events onto `occurrence.score`.

## Saved views
Named snapshots of client view state; `config` is client-defined: `{viewType, visibleCalendarIds, folderCollapse, filterText, anchor:"today"|dayKey}`.
- `GET /views` → `{views:[{id, name, config, position}]}`.
- `POST /views` `{name, config}` → view (201).
- `PATCH /views/:id` `{name?, config?, position?}` → view.
- `DELETE /views/:id` → `{ok:true}`.

## Outbound feeds
- `GET /outfeeds` / `POST /outfeeds` `{name, scope:{type:"all"|"calendar"|"search", calendarId?, q?}, description?}` → `{id,name,url,scope,description}`.
- `DELETE /outfeeds/:id`.
- Public: `GET /feed/{token}.ics` (no auth; X-WR-CALNAME + description embedded).

## Misc
- `GET /health` (no auth) → `{ok:true,time,db:true,version}`.

## Frontend state contract (bettercal-ui props)

bettercal-ui components receive plain occurrence objects (above) plus a `CalendarMeta` map `{id:{color,name,visible}}`, and emit intents: `onCreateRange({start,end,allDay})`, `onMoveEvent({instanceId,newStart,newEnd})`, `onResizeEvent({instanceId,newStart,newEnd,edge})`, `onOpenEvent(instanceId,anchorRect)`, `onExpandDay(dateISO)`, `onRequestWindow({start,end})` (infinite scroll data demand), `onSetAttendance(occ, attendance)` (agenda triage on feed events), `onFeedback(occ, "up"|"down")` (thumbs training signal on feed events). AgendaList also accepts `sortMode: "time"|"match"`; in match mode it renders the caller-ordered flat list (see `web/src/lib/rank.js` sortByMatch) instead of day groups. All drag math lives in the library; persistence lives in the app layer.
