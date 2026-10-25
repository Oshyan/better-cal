// Drafts reach both storages where they must (1.1.1): logout from a browser
// tab also removes what the installed app kept, and an installed-app draft
// past its day is swept at boot from any window, while a fresh one stays.
import assert from 'node:assert/strict';

const storage = () => {
  const m = new Map();
  return { getItem: (k) => (m.has(k) ? m.get(k) : null), setItem: (k, v) => m.set(k, String(v)), removeItem: (k) => m.delete(k), has: (k) => m.has(k) };
};
const saved = { localStorage: globalThis.localStorage, sessionStorage: globalThis.sessionStorage };
globalThis.localStorage = storage();
globalThis.sessionStorage = storage();
const { clearAllDrafts, sweepStaleDrafts } = await import('../src/app/drafts.js');

const put = (s, key, at) => s.setItem(key, JSON.stringify({ text: 'Invented draft', at }));
put(localStorage, 'bc-editor-draft', Date.now());
put(localStorage, 'bc-quickadd-draft', Date.now());
put(sessionStorage, 'bc-editor-draft', Date.now());
clearAllDrafts();
for (const s of [localStorage, sessionStorage]) {
  assert.equal(s.has('bc-editor-draft') || s.has('bc-quickadd-draft'), false, 'logout clears drafts in both storages');
}

put(localStorage, 'bc-editor-draft', Date.now() - 25 * 3600000);
put(localStorage, 'bc-quickadd-draft', Date.now() - 3600000);
put(sessionStorage, 'bc-editor-draft', Date.now() - 48 * 3600000);
localStorage.setItem('bc-resume', 'kept');
sweepStaleDrafts();
assert.equal(localStorage.has('bc-editor-draft'), false, 'an installed-app draft past a day is swept');
assert.equal(localStorage.has('bc-quickadd-draft'), true, 'a fresh installed-app draft stays');
assert.equal(sessionStorage.has('bc-editor-draft'), true, "a tab's own draft has no age limit");
assert.equal(localStorage.getItem('bc-resume'), 'kept', 'only draft keys are touched');
localStorage.setItem('bc-quickadd-draft', 'not json');
sweepStaleDrafts();
assert.equal(localStorage.has('bc-quickadd-draft'), false, 'an unreadable installed-app draft is swept');

Object.assign(globalThis, saved);
console.log('Draft storage regression tests passed');
