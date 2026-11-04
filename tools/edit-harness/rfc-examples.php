<?php
// Hand-listed occurrences the harness's evaluator (Rfc.php) must give, one
// or more per family the harness draws and per bound kind (README, The
// Oracle). Each is [rule, start (local, or ISO with its offset), zone,
// all-day, how many to read (null: every one of a bounded rule), the
// expected starts as local ISO]. Dates were listed by hand from a calendar
// and RFC 5545 3.8.5.3's own examples; the product rules (the clock plus
// the gap, the first of two instants, the DAILY;BYDAY carried clock, the
// hour-naming rule's lost gap day, HOURLY on the wall clock, MINUTELY in elapsed time) are
// series-model.md's. The run stops when the evaluator disagrees with one.
declare(strict_types=1);

return [
    // DAILY, an interval, BYDAY with and without one
    ['FREQ=DAILY;COUNT=3', '2027-01-05 09:00', 'UTC', false, null, ['2027-01-05T09:00:00+00:00', '2027-01-06T09:00:00+00:00', '2027-01-07T09:00:00+00:00']],
    ['FREQ=DAILY;INTERVAL=3;UNTIL=20270114T235959Z', '2027-01-05 09:00', 'UTC', false, null, ['2027-01-05T09:00:00+00:00', '2027-01-08T09:00:00+00:00', '2027-01-11T09:00:00+00:00', '2027-01-14T09:00:00+00:00']],
    ['FREQ=DAILY;BYDAY=MO,WE,FR;COUNT=4', '2027-01-06 18:00', 'Europe/Berlin', false, null, ['2027-01-06T18:00:00+01:00', '2027-01-08T18:00:00+01:00', '2027-01-11T18:00:00+01:00', '2027-01-13T18:00:00+01:00']],
    ['FREQ=DAILY;INTERVAL=2;BYDAY=MO,WE,FR;COUNT=4', '2027-03-10 09:00', 'America/Los_Angeles', false, null, ['2027-03-10T09:00:00-08:00', '2027-03-12T09:00:00-08:00', '2027-03-22T09:00:00-07:00', '2027-03-24T09:00:00-07:00']],
    // WEEKLY bare, on a list, every other week with a week start (RFC 5545's own pair)
    ['FREQ=WEEKLY;COUNT=3', '2027-01-05 18:00', 'UTC', false, null, ['2027-01-05T18:00:00+00:00', '2027-01-12T18:00:00+00:00', '2027-01-19T18:00:00+00:00']],
    ['FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=MO', '1997-08-05 09:00', 'America/New_York', false, null, ['1997-08-05T09:00:00-04:00', '1997-08-10T09:00:00-04:00', '1997-08-19T09:00:00-04:00', '1997-08-24T09:00:00-04:00']],
    ['FREQ=WEEKLY;INTERVAL=2;COUNT=4;BYDAY=TU,SU;WKST=SU', '1997-08-05 09:00', 'America/New_York', false, null, ['1997-08-05T09:00:00-04:00', '1997-08-17T09:00:00-04:00', '1997-08-19T09:00:00-04:00', '1997-08-31T09:00:00-04:00']],
    ['FREQ=WEEKLY;UNTIL=20270119', '2027-01-05', 'UTC', true, null, ['2027-01-05T00:00:00+00:00', '2027-01-12T00:00:00+00:00', '2027-01-19T00:00:00+00:00']],
    // MONTHLY bare (a 31st skips short months), on days, from the end, clamped, a list, every other month and every third
    ['FREQ=MONTHLY;COUNT=3', '2027-01-31 09:30', 'UTC', false, null, ['2027-01-31T09:30:00+00:00', '2027-03-31T09:30:00+00:00', '2027-05-31T09:30:00+00:00']],
    ['FREQ=MONTHLY;INTERVAL=3;COUNT=3', '2027-01-15', 'UTC', true, null, ['2027-01-15T00:00:00+00:00', '2027-04-15T00:00:00+00:00', '2027-07-15T00:00:00+00:00']],
    ['FREQ=MONTHLY;INTERVAL=2;BYMONTHDAY=10;COUNT=3', '2027-01-10 13:00', 'UTC', false, null, ['2027-01-10T13:00:00+00:00', '2027-03-10T13:00:00+00:00', '2027-05-10T13:00:00+00:00']],
    ['FREQ=MONTHLY;BYMONTHDAY=31;COUNT=3', '2027-01-31 13:00', 'UTC', false, null, ['2027-01-31T13:00:00+00:00', '2027-03-31T13:00:00+00:00', '2027-05-31T13:00:00+00:00']],
    ['FREQ=MONTHLY;BYMONTHDAY=-1;COUNT=3', '2027-01-31 13:00', 'UTC', false, null, ['2027-01-31T13:00:00+00:00', '2027-02-28T13:00:00+00:00', '2027-03-31T13:00:00+00:00']],
    ['FREQ=MONTHLY;BYMONTHDAY=28,29,30;BYSETPOS=-1;COUNT=4', '2027-01-30 13:00', 'UTC', false, null, ['2027-01-30T13:00:00+00:00', '2027-02-28T13:00:00+00:00', '2027-03-30T13:00:00+00:00', '2027-04-30T13:00:00+00:00']],
    ['FREQ=MONTHLY;BYMONTHDAY=15,-1;COUNT=4', '2027-01-15 13:00', 'UTC', false, null, ['2027-01-15T13:00:00+00:00', '2027-01-31T13:00:00+00:00', '2027-02-15T13:00:00+00:00', '2027-02-28T13:00:00+00:00']],
    // MONTHLY nth weekdays in both spellings, a mixed list, set positions over a set
    ['FREQ=MONTHLY;BYDAY=2TU;COUNT=3', '2027-01-12 18:00', 'UTC', false, null, ['2027-01-12T18:00:00+00:00', '2027-02-09T18:00:00+00:00', '2027-03-09T18:00:00+00:00']],
    ['FREQ=MONTHLY;BYDAY=TU;BYSETPOS=-1;COUNT=3', '2027-01-26 18:00', 'UTC', false, null, ['2027-01-26T18:00:00+00:00', '2027-02-23T18:00:00+00:00', '2027-03-30T18:00:00+00:00']],
    ['FREQ=MONTHLY;BYDAY=1MO,3TU,-1FR;COUNT=5', '2027-01-04 18:00', 'UTC', false, null, ['2027-01-04T18:00:00+00:00', '2027-01-19T18:00:00+00:00', '2027-01-29T18:00:00+00:00', '2027-02-01T18:00:00+00:00', '2027-02-16T18:00:00+00:00']],
    ['FREQ=MONTHLY;BYDAY=4MO,-1MO;COUNT=4', '2027-01-25 18:00', 'UTC', false, null, ['2027-01-25T18:00:00+00:00', '2027-02-22T18:00:00+00:00', '2027-03-22T18:00:00+00:00', '2027-03-29T18:00:00+00:00']],
    ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=1,3;COUNT=4', '2027-02-01 18:00', 'UTC', false, null, ['2027-02-01T18:00:00+00:00', '2027-02-03T18:00:00+00:00', '2027-03-01T18:00:00+00:00', '2027-03-03T18:00:00+00:00']],
    ['FREQ=MONTHLY;BYDAY=MO,TU,WE,TH,FR;BYSETPOS=-2;COUNT=3', '1997-09-29 09:00', 'America/New_York', false, null, ['1997-09-29T09:00:00-04:00', '1997-10-30T09:00:00-05:00', '1997-11-27T09:00:00-05:00']],
    ['FREQ=MONTHLY;BYDAY=FR;BYMONTHDAY=13;COUNT=3', '2026-11-13 18:00', 'UTC', false, null, ['2026-11-13T18:00:00+00:00', '2027-08-13T18:00:00+00:00', '2028-10-13T18:00:00+00:00']],
    // YEARLY bare (29 February), on a month and day, the last of February, an nth weekday, a list of months
    ['FREQ=YEARLY;COUNT=3', '2028-02-29 09:00', 'UTC', false, null, ['2028-02-29T09:00:00+00:00', '2032-02-29T09:00:00+00:00', '2036-02-29T09:00:00+00:00']],
    ['FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=-1;COUNT=3', '2027-02-28', 'UTC', true, null, ['2027-02-28T00:00:00+00:00', '2028-02-29T00:00:00+00:00', '2029-02-28T00:00:00+00:00']],
    ['FREQ=YEARLY;BYMONTH=11;BYDAY=4TH;COUNT=3', '2027-11-25 18:00', 'UTC', false, null, ['2027-11-25T18:00:00+00:00', '2028-11-23T18:00:00+00:00', '2029-11-22T18:00:00+00:00']],
    ['FREQ=YEARLY;BYMONTH=3,9;BYMONTHDAY=10;COUNT=3', '2027-03-10 18:00', 'UTC', false, null, ['2027-03-10T18:00:00+00:00', '2027-09-10T18:00:00+00:00', '2028-03-10T18:00:00+00:00']],
    ['FREQ=YEARLY;BYMONTH=1,7;BYDAY=-1SU;COUNT=3', '2027-01-31 18:00', 'UTC', false, null, ['2027-01-31T18:00:00+00:00', '2027-07-25T18:00:00+00:00', '2028-01-30T18:00:00+00:00']],
    ['FREQ=YEARLY;BYMONTH=2;BYMONTHDAY=29', '2028-02-29 09:00', 'UTC', false, 3, ['2028-02-29T09:00:00+00:00', '2032-02-29T09:00:00+00:00', '2036-02-29T09:00:00+00:00']],
    // The capability table's other rows: week numbers, a day of the year, a day with a weekday, WEEKLY with BYMONTH, a set position
    ['FREQ=YEARLY;BYWEEKNO=20;BYDAY=MO;COUNT=3', '1997-05-12 09:00', 'America/New_York', false, null, ['1997-05-12T09:00:00-04:00', '1998-05-11T09:00:00-04:00', '1999-05-17T09:00:00-04:00']],
    ['FREQ=YEARLY;BYYEARDAY=100;COUNT=3', '2027-04-10 09:00', 'UTC', false, null, ['2027-04-10T09:00:00+00:00', '2028-04-09T09:00:00+00:00', '2029-04-10T09:00:00+00:00']],
    ['FREQ=WEEKLY;BYDAY=MO;BYMONTH=1,2,3,10,11,12;COUNT=3', '2027-03-22 09:00', 'UTC', false, null, ['2027-03-22T09:00:00+00:00', '2027-03-29T09:00:00+00:00', '2027-10-04T09:00:00+00:00']],
    ['FREQ=WEEKLY;BYDAY=MO,WE,FR;BYSETPOS=1;COUNT=3', '2027-01-04 09:00', 'UTC', false, null, ['2027-01-04T09:00:00+00:00', '2027-01-11T09:00:00+00:00', '2027-01-18T09:00:00+00:00']],
    // Sparse days under a short frequency: every one the rule names, however far apart
    ['FREQ=DAILY;BYMONTH=2;BYMONTHDAY=29;COUNT=3', '2028-02-29 09:00', 'UTC', false, null, ['2028-02-29T09:00:00+00:00', '2032-02-29T09:00:00+00:00', '2036-02-29T09:00:00+00:00']],
    ['FREQ=DAILY;BYMONTH=2;BYMONTHDAY=29', '2028-02-29 09:00', 'UTC', false, 3, ['2028-02-29T09:00:00+00:00', '2032-02-29T09:00:00+00:00', '2036-02-29T09:00:00+00:00']],
    ['FREQ=HOURLY;BYMONTH=1;COUNT=6', '2027-01-31 21:00', 'UTC', false, null, ['2027-01-31T21:00:00+00:00', '2027-01-31T22:00:00+00:00', '2027-01-31T23:00:00+00:00', '2028-01-01T00:00:00+00:00', '2028-01-01T01:00:00+00:00', '2028-01-01T02:00:00+00:00']],
    // Several times a day: an hour list, HOURLY, MINUTELY in elapsed time
    ['FREQ=DAILY;BYHOUR=9,15;BYMINUTE=0;COUNT=4', '2027-01-05 09:00', 'Europe/Berlin', false, null, ['2027-01-05T09:00:00+01:00', '2027-01-05T15:00:00+01:00', '2027-01-06T09:00:00+01:00', '2027-01-06T15:00:00+01:00']],
    ['FREQ=HOURLY;INTERVAL=6;COUNT=4', '2027-01-05 09:00', 'UTC', false, null, ['2027-01-05T09:00:00+00:00', '2027-01-05T15:00:00+00:00', '2027-01-05T21:00:00+00:00', '2027-01-06T03:00:00+00:00']],
    // HOURLY on the wall clock (the product rule): the pattern holds across a skipped hour, a skipped clock resolves by the offset before the gap
    ['FREQ=HOURLY;INTERVAL=3;COUNT=5', '2027-03-13 21:00', 'America/Los_Angeles', false, null, ['2027-03-13T21:00:00-08:00', '2027-03-14T00:00:00-08:00', '2027-03-14T03:00:00-07:00', '2027-03-14T06:00:00-07:00', '2027-03-14T09:00:00-07:00']],
    ['FREQ=HOURLY;COUNT=4', '2027-03-14 00:00', 'America/Los_Angeles', false, null, ['2027-03-14T00:00:00-08:00', '2027-03-14T01:00:00-08:00', '2027-03-14T03:00:00-07:00', '2027-03-14T04:00:00-07:00']],
    ['FREQ=MINUTELY;INTERVAL=720;COUNT=3', '2027-03-13 15:00', 'America/Los_Angeles', false, null, ['2027-03-13T15:00:00-08:00', '2027-03-14T04:00:00-07:00', '2027-03-14T16:00:00-07:00']],
    // Clocks that don't exist or happen twice (series-model.md, Zones, DST and All-Day Days)
    ['FREQ=WEEKLY;BYDAY=SU;COUNT=3', '2027-03-07 02:30', 'America/Los_Angeles', false, null, ['2027-03-07T02:30:00-08:00', '2027-03-14T03:30:00-07:00', '2027-03-21T02:30:00-07:00']],
    ['FREQ=WEEKLY;BYDAY=SU;BYHOUR=2;BYMINUTE=30;COUNT=3', '2027-03-07 02:30', 'America/Los_Angeles', false, null, ['2027-03-07T02:30:00-08:00', '2027-03-21T02:30:00-07:00', '2027-03-28T02:30:00-07:00']],
    ['FREQ=DAILY;BYHOUR=2,3;BYMINUTE=30;COUNT=3', '2027-03-13 02:30', 'America/Los_Angeles', false, null, ['2027-03-13T02:30:00-08:00', '2027-03-13T03:30:00-08:00', '2027-03-14T03:30:00-07:00']],
    ['FREQ=DAILY;BYDAY=MO,WE,SA;COUNT=4', '2027-03-10 02:30', 'America/Los_Angeles', false, null, ['2027-03-10T02:30:00-08:00', '2027-03-13T02:30:00-08:00', '2027-03-15T03:30:00-07:00', '2027-03-17T03:30:00-07:00']],
    ['FREQ=DAILY;COUNT=3', '2027-10-02 02:15', 'Australia/Lord_Howe', false, null, ['2027-10-02T02:15:00+10:30', '2027-10-03T02:45:00+11:00', '2027-10-04T02:15:00+11:00']],
    ['FREQ=DAILY;COUNT=3', '2027-11-06 01:30', 'America/Los_Angeles', false, null, ['2027-11-06T01:30:00-07:00', '2027-11-07T01:30:00-07:00', '2027-11-08T01:30:00-08:00']],
    ['FREQ=WEEKLY;COUNT=3', '2027-10-31T02:30:00+01:00', 'Europe/Berlin', false, null, ['2027-10-31T02:30:00+01:00', '2027-11-07T02:30:00+01:00', '2027-11-14T02:30:00+01:00']],
];
