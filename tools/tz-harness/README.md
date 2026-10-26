# Time Zone Cross-Check

Runs the same repeating events through Better-Cal and two independent engines, and reports where they disagree. Run it after changing anything in `Recurrence`, `Ics`, `Time` or how events are stored.

```bash
./tools/tz-harness/run.sh
```

It needs PHP with `server/vendor` installed, [uv](https://docs.astral.sh/uv/) (Python 3.12) and Node 20+. It takes about a minute and writes its generated files here (ignored by git).

## What It Does

1. `gen.php` builds about 3,800 series the way Better-Cal stores them, expands each with `Recurrence::expand` and exports it with `Ics::buildCalendar`. The matrix: 16 zones (west and east of UTC, half-hour and 45-minute offsets, southern hemisphere, no DST, UTC), five times of day (including hours skipped or repeated at DST changes), ten rules (daily, weekly with days, monthly by date and by weekday, yearly, COUNT and UNTIL), four start dates around DST changes plus an older series, all-day and timed, each with a skipped day and an edited one.
2. `goodtz.py` marks series whose start isn't a day of their own rule.
3. `oracle_py.py` expands the exports with [recurring-ical-events](https://github.com/niccokunzmann/python-recurring-ical-events) (dateutil and the zoneinfo database).
4. `oracle_icaljs.mjs` expands them with [ical.js](https://github.com/kewisch/ical.js) (the library behind Thunderbird and Nextcloud's calendar), reading Better-Cal's own VTIMEZONE blocks, so it checks those too.
5. `compare.mjs` sorts every disagreement away from the window edges into three groups: the series starts on a day its own rule doesn't produce (undefined in RFC 5545, so engines differ), the difference is within a day of a DST change, or unexplained. **Unexplained should be 0.**
6. `roundtrip.php` exports, re-imports (as CalDAV would store it) and expands again, and reports any occurrence that moved.

A smaller round trip runs with the server tests on every test run and deploy (`server/tests/run.php`).

## Known, Accepted Differences

- **Starts outside the rule** (a Thursday start for a Mon/Wed/Fri rule): sabre counts the start, Python includes but doesn't count it, and ical.js leaves it out. Better-Cal moves such a start to the rule's first day when a series is created or its days change.
- **DST days:** a one-hour event that starts in the repeated hour lasts two hours by the clock in all three engines. The oracles disagree among themselves about zero-length times inside a spring-forward gap.
- **Older years in VTIMEZONE:** exported rules are today's, projected back to 1970 (as Google and Outlook do). For a zone whose DST rules changed (Sao Paulo dropped DST in 2019), ical.js differs for dates before the change; clients that use their own zone database are unaffected.

## References

- RFC 5545: 3.3.5 (local times in gaps and repeated hours), 3.8.4.4 (RECURRENCE-ID), 3.8.5.3 (RRULE, COUNT, UNTIL): https://www.rfc-editor.org/rfc/rfc5545
- Google Calendar Events (`start.timeZone` is the zone a series repeats in; `originalStartTime`): https://developers.google.com/workspace/calendar/api/v3/reference/events
- Thunderbird `CalRecurrenceInfo.onStartDateChange` and Nextcloud calendar-js `RecurrenceManager.updateStartDateOfMasterItem`: how a series' exceptions follow when its start moves.
- TC39 Temporal (`ZonedDateTime`, `PlainDate`): https://tc39.es/proposal-temporal/docs/zoneddatetime.html
