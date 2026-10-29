# Tides

The Tides plugin puts NOAA tide predictions on your calendar, for any of its 3,500 or so US stations (coasts, bays, Hawaii, Alaska, Puerto Rico and the Pacific islands). No account or API key is needed. Each tide calendar is one station with its own choices, so you can have "low tides under a foot at the beach on weekend mornings" in one calendar and "every high tide at the harbor" in another.

## Setting It Up

1. Open Plugins and turn on Tides. Within a minute it loads NOAA's station list.
2. Choose "Add tide calendar". Search for a station by place, state or station number, pick it, and choose which tides to show (below).
3. Add it. It fills within a minute, named after its station ("Tides, Sample Harbor, CA") unless you give it a name.

Your tide calendars are listed on the Tides card; click one to change it. Each calendar's own settings (the gear beside it in the sidebar) show the same choices, plus the plugin's latest note on it, like "12 low tides at Sample Harbor in the next 90 days". Delete a tide calendar like any other calendar (Undo brings it back).

## Choosing Tides

For low tides and high tides separately:

- **Include** them or not. Low tides are on to start, high tides off.
- **Height:** lows at or below a height, highs at or above one, in feet or meters. Empty means any height.
- **Daylight:** any time, after sunrise, before sunset, or between the two, each with minutes to spare ("at least an hour after sunrise", "30 minutes before sunset"). Negative minutes reach the other way, before sunrise or after sunset.
- **Times of day:** not before and not after a clock time. A window that ends earlier than it starts runs overnight (9 PM to 3 AM).
- **Days of the week.**

Times of day, days and sunrise are all at the station, in its local time, since that's where the tide happens. The events themselves show at their true moment in your own time zone, so a station in Hawaii shows its 6 AM low at 9 AM if you're on Pacific time.

Each calendar looks up to 365 days ahead (90 to start). "Shade around each tide" also shades the week and day views for a number of minutes either side of each tide shown, strongest at the tide and fading out, which is handy for tide-pooling or launching a kayak. Each tide is a line across the day at its time, labelled with its height and time in the calendar's color.

## What Shows

Each tide is a moment ("Low tide -0.8 ft"), in the day's header and as a line on the timeline. Its details give the height in both units, the time at the station, that day's sunrise and sunset there, and how long before (or after) sunset it is. Heights are above mean lower low water, NOAA's usual zero for tide tables.

## Sunrise, Sunset and Weather for the Same Place

A tide calendar knows where its station is as soon as you pick it. Its settings show "Linked calendars", with a button for each plugin that takes a place: Sun for that day's sunrise and sunset there, Weather for the forecast. The calendars you add this way follow the station: move the tide calendar to another station and they move with it, renamed to match. Each is listed there, opens with a click, and has Unlink, which keeps the place it has and stops following. You can also set any Sun or Weather calendar to "the same place as" a tide calendar in its own settings.

## Sharing

Tide calendars roll forward on their own. To share one with someone, or see it in another calendar app, give it an outbound feed in Settings → Connections; the feed follows the calendar as it refills.
