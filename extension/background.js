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
