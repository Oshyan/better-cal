# The app and reminders

Better-Cal works in any browser tab, but it's built to be installed as an app on your phone and computer: the same Better-Cal, with its own icon and window. On a phone, installing is what makes reminders work properly.

## Why Install It

- **Its own icon and window.** It opens straight to your calendar, with no browser tabs or address bar, and stays open in the dock, taskbar or app switcher like any other app.
- **Reminders.** Notifications arrive even when it's closed. On an iPhone or iPad this needs the installed app: Safari can't send notifications from an ordinary tab.
- **It starts without a connection.** The app itself is kept on the device, so it opens offline and shows what it last loaded.
- **It works with the rest of your device.** On Android, share a link or some text to it from another app and it lands in quick add. With Chrome or Edge on a computer, `.ics` files and calendar (webcal) links can open in it. On Android, and with Chrome or Edge on a computer, long-press or right-click its icon for **New event**.

You don't have to install it. Everything works in a tab, and phone calendar apps can sync with Better-Cal over CalDAV instead ([caldav.md](caldav.md)).

## Installing

The welcome screen's **Install the app** row offers to do it when your browser allows. Otherwise:

- **Android (Chrome, Edge, Samsung Internet):** the browser menu, then **Install app** (or **Add to Home screen**).
- **iPhone and iPad:** in Safari, the **Share** button, then **Add to Home Screen**. Notifications need iOS 16.4 or later.
- **Mac or PC, Chrome or Edge:** the install icon at the right end of the address bar, or the menu's **Install Better-Cal**.
- **Mac, Safari:** **File**, then **Add to Dock**.
- **Firefox on a computer** has no install. Chrome, Edge or Safari do.

Updates arrive on their own: the app picks up a new version within the next open or two.

## Turning On Reminders

Do this on each phone and computer you want reminders on, in the installed app if you installed it.

1. Open **Settings**, then **Notifications**.
2. Next to **Reminders on this device**, press **Enable**.
3. **Your browser or phone asks whether to allow notifications.** Choose **Allow**. That question comes from the browser or phone, not Better-Cal, and only appears after you press a button, which is why the app never asks on its own.
4. Press **Send test notification** to check one arrives.

If no question appeared:

- **Chrome may have asked quietly.** It sometimes shows a small crossed-out bell in the address bar instead of a pop-up. Click it and choose Allow.
- **"Blocked in this browser"** next to the button means notifications were refused for Better-Cal at some point. Allow them in the phone's or browser's settings (next section), then press **Enable** again.
- **"Add Better-Cal to your Home Screen"** on an iPhone or iPad means you're in a Safari tab. Install the app and turn reminders on there.
- **"Server not configured"** means the server has no push keys. The installer creates them; on an install done by hand, whoever runs the server adds them with one command ([Install](install.md#install)). Email reminders still work if email is set up.

If you've already allowed notifications but this device isn't signed up (after reinstalling the app, say), Better-Cal turns reminders back on by itself when it opens. If you never decided, it offers a **Turn on** button at most once every two weeks.

## Changing Notification Settings

**In Better-Cal**, under Settings, Notifications:

- **Reminders on this device:** Enable or Disable, and Send test notification.
- **Devices receiving reminders:** every phone and browser that gets them, when each last got one, and **Remove** for any you don't recognize or no longer use.
- **Delivery channel:** push, email, both, or push with email as a fallback when a device can't be reached.
- **Default reminders** for timed and all-day events. A calendar can have its own defaults (in its settings), and an event its own reminders (in the editor).
- **Show on app icon:** a number on the installed app's icon: Review items waiting, events today, or events still to come today (Nothing by default). Events count the planned and maybe ones on calendars you show; still to come leaves out all-day events and ones that have ended.

The app icon number works on a computer (installed from Chrome, Edge or Safari on a Mac) and on iPhone or iPad when Better-Cal is on the Home Screen and allowed to send notifications. Android doesn't show numbers on web app icons, only a dot while a notification is showing. The number updates while the app is open and whenever a notification arrives. Chrome and Edge also refresh it in the background, about once a day at a time they choose. Browsers don't let it update silently otherwise, so on iPhone an events count can be a few hours old first thing in the morning, until the app opens or a reminder arrives.

**On your phone or computer**, for sound, banners, the lock screen, or to unblock Better-Cal:

- **Android:** Settings, Apps, Better-Cal, Notifications. On some phones the switch sits with the browser instead: Chrome, Settings, Site settings, Notifications.
- **Android battery:** if reminders arrive late, set Settings, Apps, Chrome (or Better-Cal), Battery to **Unrestricted**. Battery saving can hold notifications back for minutes or hours. Better-Cal shows this tip on Android when you turn reminders on.
- **iPhone and iPad:** Settings, Notifications, Better-Cal. It appears there after you've allowed notifications once.
- **Chrome or Edge on a computer:** the icon to the left of the address, then **Site settings**, then **Notifications**.
- **Mac:** System Settings, Notifications, then your browser (or Better-Cal, if you installed it). Focus modes can hold notifications too.
- **Windows:** Settings, System, Notifications, then your browser or Better-Cal.

## Coming From Google Calendar

If Google Calendar's notifications are still on, you'll get each reminder twice. Once Better-Cal's are working, turn Google's off in Google Calendar's settings for each calendar. [Moving from Google Calendar](migration.md) has the rest.
