# Independent oracle: icalendar + recurring-ical-events (python-dateutil rrule, zoneinfo tz data).
import json, sys, datetime as dt
from zoneinfo import ZoneInfo
import icalendar, recurring_ical_events

UTC = dt.timezone.utc
cases = json.load(open(sys.argv[1]))
out = {}
for c in cases:
    cal = icalendar.Calendar.from_ical(c["ics"])
    res = {}
    for wname, w in c["bettercal"].items():
        ws = dt.datetime.strptime(w["from"], "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
        we = dt.datetime.strptime(w["to"], "%Y-%m-%d %H:%M:%S").replace(tzinfo=UTC)
        lst = []
        try:
            evs = recurring_ical_events.of(cal).between(ws, we)
        except Exception as e:
            res[wname] = {"error": repr(e)}
            continue
        for ev in evs:
            s = ev["DTSTART"].dt
            e = ev["DTEND"].dt
            if isinstance(s, dt.datetime):
                if s.tzinfo is None:
                    s = s.replace(tzinfo=ZoneInfo(c["zone"])); e = e.replace(tzinfo=ZoneInfo(c["zone"]))
                lst.append(s.astimezone(UTC).strftime("%Y%m%dT%H%M%SZ") + "/" + e.astimezone(UTC).strftime("%Y%m%dT%H%M%SZ"))
            else:
                lst.append("D" + s.strftime("%Y%m%d") + "/" + e.strftime("%Y%m%d"))
        res[wname] = {"occ": sorted(lst)}
    out[c["n"]] = res
json.dump(out, open(sys.argv[2], "w"))
print(len(out), "cases")
