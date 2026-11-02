// The change check: the server changes underneath us (mail ingest, feed
// polls, CalDAV edits from other devices, the agent API). GET
// /changes/cursor says which of its three parts moved, each part owes the
// reloads it covers, and everything owed is then reloaded together, merged
// into what's on screen: no reload of the page, no lost place, nothing
// taken out of your hands. One that fails stays owed for the next check
// (reloads.js, docs/design/loading-model.md). App.js decides when it runs.

import { api, ownChangeCursor } from './api.js';
import { state, set, invalidateRecords, newGeneration } from './store.js';
import { owe, runOwed } from './reloads.js';
import { noticeServerVersion } from './appversion.js';
import { refreshBadge } from './badge.js';

let cursor = null;
let review = null;
let status = null;
let checking = null;

/**
 * Read the cursor (one read at a time; asked again meanwhile, it shares
 * that one), owe what moved, and run everything owed. A reload still on
 * its way isn't waited for: a move seen meanwhile owes it once more.
 * Resolves once this check's reloads have settled.
 */
export function checkChanges() {
  if (!checking) checking = check().finally(() => { checking = null; });
  return checking.then((reached) => (reached ? runOwed() : null));
}

// Whether the server was reached; what's owed waits for a check that is.
async function check() {
  let d;
  try {
    d = await api('/changes/cursor');
  } catch {
    return false;
  }
  // A different version installed on the server: look for it now.
  if (d) noticeServerVersion(d.app);
  if (!d || !d.cursor) return true;
  const moved = cursor !== null && d.cursor !== cursor;
  const reviewMoved = review !== null && d.review !== review;
  const statusMoved = status !== null && d.status !== status;
  cursor = d.cursor;
  review = d.review ?? null;
  status = d.status ?? null;
  if (moved) {
    invalidateRecords(); // something changed somewhere; fetched records may be stale
    set({ availSeq: state.availSeq + 1 });
    owe('people', 'views', 'settings', 'calendars', 'review');
    // The events window, unless the cursor is where this tab's own last
    // save left it: that save refetched it after reading the cursor, so it
    // holds every change up to here. The rest still reloads, as other
    // changes may have come in before the save.
    if (d.cursor !== ownChangeCursor()) {
      newGeneration();
      owe('window');
    }
  }
  // The Review badge: held mail, proposals and possible duplicates arrive
  // without an event (their own part), and unanswered invitations or
  // decisions made elsewhere ride on the events part.
  if (reviewMoved) owe('review');
  if (statusMoved) owe('calendars', 'status', 'plugins');
  if (moved || reviewMoved || statusMoved) set({ activitySeq: state.activitySeq + 1 });
  // The app icon's number (#170), when something it counts may have moved.
  refreshBadge({ changed: moved || reviewMoved });
  return true;
}
