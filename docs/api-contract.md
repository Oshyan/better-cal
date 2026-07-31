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
  Occurrence: `{instanceId, eventId, calendarId, uid, title, description, location, url, start, end, allDay, tzid, recurring:boolean, source, attendance, status, tags:[], people:[], styleJson, createdAt, updatedAt, isNew:boolean}`
  `start`/`end` are ISO8601 with offset. Recurring events arrive pre-expanded; `instanceId = eventId + ":" + occurrenceStartUtc` where occurrenceStartUtc uses compact UTC basic format `YYYYMMDDTHHMMSSZ` (e.g. `42:20260801T190000Z`). This format is FROZEN; clients treat instanceId as opaque and use the occurrence's `start` field when an API call needs `instanceStart`.
- `POST /events` `{calendarId,title,start,end,allDay?,tzid?,description?,location?,url?,rrule?,tagNames?,personNames?}` → occurrence (first instance).
- `PATCH /events/:id` body same fields plus `{scope:"this"|"following"|"all", instanceStart?}` (scope required when event is recurring) → `{ok:true}`.
- `DELETE /events/:id` `{scope?,instanceStart?}` → `{ok:true}`.
- `POST /events/:id/attendance` `{attendance:"none"|"interested"|"going"|"hidden"}` → `{ok:true}`.
- `POST /quickadd` `{text, tz, commit?:boolean, calendarId?}` → `{draft:{title,start,end,allDay,location,personNames:[],calendarId,confidence,source:"llm"|"fallback"}}`; if `commit:true` also creates and returns `{event}`.
- `POST /undo` → `{ok:true, undone:{entity,op}}` or 404 if nothing to undo.

## Search
- `GET /search?q=text&limit=50` → `{results:[occurrence...]}` (FULLTEXT, past included, ranked, newest window first).

## Outbound feeds
- `GET /outfeeds` / `POST /outfeeds` `{name, scope:{type:"all"|"calendar"|"search", calendarId?, q?}, description?}` → `{id,name,url,scope,description}`.
- `DELETE /outfeeds/:id`.
- Public: `GET /feed/{token}.ics` (no auth; X-WR-CALNAME + description embedded).

## Misc
- `GET /health` (no auth) → `{ok:true,time,db:true,version}`.

## Frontend state contract (bettercal-ui props)

bettercal-ui components receive plain occurrence objects (above) plus a `CalendarMeta` map `{id:{color,name,visible}}`, and emit intents: `onCreateRange({start,end,allDay})`, `onMoveEvent({instanceId,newStart,newEnd})`, `onResizeEvent({instanceId,newStart,newEnd,edge})`, `onOpenEvent(instanceId,anchorRect)`, `onExpandDay(dateISO)`, `onRequestWindow({start,end})` (infinite scroll data demand). All drag math lives in the library; persistence lives in the app layer.
