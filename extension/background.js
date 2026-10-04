// Keeps the rules in step with the saved address. The options page applies
// them when the address is saved; this re-applies them at startup and after
// an update (dynamic rules survive restarts, but a browser that dropped them
// would otherwise silently stop redirecting), and opens the options page on
// first install so the address gets set.

importScripts('rules.js');

chrome.runtime.onInstalled.addListener(async ({ reason }) => {
  const origin = await storedOrigin();
  await applyOrigin(origin);
  if (!origin && reason === 'install') chrome.runtime.openOptionsPage();
});

chrome.runtime.onStartup.addListener(async () => {
  await applyOrigin(await storedOrigin());
});

// The "new event" key (2.1): a small window on <Better-Cal>/new, which opens
// quick add. Global, so it works while Chrome is in the background (Chrome
// allows global keys only as Ctrl+Shift+digit; Cmd on a Mac). Off when the
// person turned it off in the options; with no address set yet, the options
// open instead.
chrome.commands.onCommand.addListener(async (command) => {
  if (command !== 'new-event') return;
  const { newEventKey } = await chrome.storage.sync.get('newEventKey');
  if (newEventKey === false) return;
  const origin = await storedOrigin();
  if (!origin) {
    chrome.runtime.openOptionsPage();
    return;
  }
  chrome.windows.create({ url: origin + '/new', type: 'popup', width: 520, height: 760, focused: true });
});
