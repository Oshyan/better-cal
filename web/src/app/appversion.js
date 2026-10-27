// Which version of the app this page runs, and looking for a newer one now.
// The service worker finds a new version when the page loads, when it comes
// back into view and every 30 minutes; a page left open on screen could run
// the old code against the updated server for up to that long. The server
// reports the version it now serves in the 30-second change check (App.js),
// so a page that differs looks right away, and then takes the new version at
// its next page change (drafts.js) as well as when it's hidden.

// main.js is loaded as /assets/src/app/main.js?v=<version> (Http\AppShell).
export const pageVersion = (() => {
  try {
    const src = document.querySelector('script[type="module"][src*="/src/app/main.js"]')?.getAttribute('src') || '';
    const v = new URL(src, location.origin).searchParams.get('v');
    return v ? 'bc-' + v : null;
  } catch {
    return null;
  }
})();

let checker = null;
let lastLook = 0;

/** main.js: how to ask the service worker to look for a new version. */
export function setUpdateChecker(fn) {
  checker = fn;
}

/** Ask the worker to look now, at most every few seconds (one small, usually unchanged, request). */
export function lookForUpdate(minGapMs = 10000) {
  if (!checker || Date.now() - lastLook < minGapMs) return;
  lastLook = Date.now();
  checker();
}

/** The server says which version it serves; one that isn't this page's means an update to fetch. */
export function noticeServerVersion(serverVersion) {
  if (!serverVersion || !pageVersion || serverVersion === 'bc-unversioned' || serverVersion === pageVersion) return;
  lookForUpdate();
}
