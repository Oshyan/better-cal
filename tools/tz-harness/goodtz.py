# Replace Better-Cal's generated VTIMEZONEs with full-history ones from zoneinfo,
# and record whether DTSTART is itself an occurrence of the rule (RFC 5545 says
# the set is undefined when it is not).
import json, sys, datetime as dt, re
import icalendar
from dateutil.rrule import rrulestr
cases = json.load(open('cases.json'))
cache = {}
for c in cases:
    cal = icalendar.Calendar.from_ical(c['ics'])
    new = icalendar.Calendar()
    for k, v in cal.items(): new.add(k, v)
    for sub in cal.subcomponents:
        if sub.name == 'VTIMEZONE':
            tzid = str(sub['TZID'])
            if tzid not in cache:
                cache[tzid] = icalendar.Timezone.from_tzid(tzid, first_date=dt.date(2017,1,1), last_date=dt.date(2031,1,1))
            new.add_component(cache[tzid])
        else:
            new.add_component(sub)
    c['ics'] = new.to_ical().decode()
    d0 = dt.datetime.strptime(c['start'] + ('T00:00' if c['allDay'] else 'T' + c['time']), '%Y-%m-%dT%H:%M')
    rule = re.sub(r'UNTIL=(\d{8})(T\d{6}Z)?', lambda m: 'UNTIL=' + m.group(1) + 'T235959', c['rrule'])
    c['dtstartMatches'] = next(iter(rrulestr(rule, dtstart=d0)), None) == d0
json.dump(cases, open('cases_goodtz.json', 'w'))
print('ok', sum(1 for c in cases if not c['dtstartMatches']), 'cases where DTSTART is not an occurrence of its rule')
