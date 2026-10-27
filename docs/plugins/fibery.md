# Fibery

The Fibery plugin puts tasks and projects from your Fibery workspace on the calendar, and sends date changes back. Drag a task to another day (or use Move, or change its time in the API) and its date changes in Fibery while you wait. Changes made in Fibery show up within a minute.

## Setting It Up

1. In Fibery, open your avatar menu, then Settings, API Keys, and generate a key.
2. In Better-Cal, open Plugins, turn on Fibery and open its settings. Enter your workspace (`yourteam.fibery.io`) and the key, then Save.
3. Within a minute your databases appear. Choose the one that holds tasks and save; its date fields and other fields appear next. Pick the date field, tick the details you want to see, and save again. Projects work the same way and are optional.

Tasks and projects each get their own calendar (Fibery tasks, Fibery projects), so you can hide either from the sidebar. Both start as Mine, with no reminders; turn reminders on in a calendar's settings if you want them.

## What Shows

- **Items with a date in the chosen field.** Finished items (a final workflow state, like Done or Cancelled) are hidden unless you turn that off, and an optional checkbox field can keep items off the calendar too.
- **Tasks** are all-day on their date, or at their time with Times set that way under Tasks (midnight still reads as all-day). A due time is a moment with no length, as in Fibery, so a task due late at night stays on its day. An unfinished task whose date has passed shows on today, marked overdue, unless you turn that off.
- **Projects** with a date range show as a bar across their days. "Longest to show" under Projects (60 days to start) keeps ongoing ones, like a year-long area of work, from running across every week.
- **Details** you tick (state, priority, project, tags and so on) are listed in the event's details, with a link to the item in Fibery. "Include descriptions" adds each item's description as plain text.

## Changing Dates Here

Only the time can change from Better-Cal; titles, details and everything else are edited in Fibery. A task moved to another day keeps its time of day, so one due at midnight stays at midnight; it has one date, so it moves but doesn't stretch. A project keeps its length when moved, and a resize changes its start or end. If Fibery refuses a change, the event goes back and the message says why. Undo works as for any move: it puts the date back in Fibery (an overdue task goes back to its real due date, not today).

Dates follow Fibery's own rules: date-times are shown in your Home time zone, and date-only fields and date ranges never shift between zones.
