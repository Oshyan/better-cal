// System health on the client: the one-time boot notices and the banner.
//
// The rule, from the audit that produced this: interrupt only when a failure
// changes what the user is looking at or relying on right now. Everything
// else is a line on the Settings page and an entry in Activity.
//
//   - reminders undeliverable to THIS device  -> banner until fixed
//   - prompt filters not being evaluated      -> one toast at boot (the grid
//                                                is showing things it shouldn't)
//   - a visible calendar's feed failing        -> one toast at boot (the badge
//                                                already says so; this is the
//                                                "since when")

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, state, set, toast } from './store.js';
import { currentPushEndpoint, enablePush } from './push.js';
import { fmtSince } from '../lib/since.js';

function failingRows(health) {
  return ((health && health.rows) || []).filter((r) => r.status === 'failing');
}

// Called once after boot, after loadSystemHealth has had its chance.
export function announceSystemHealth() {
  const rows = failingRows(state.systemHealth);
  if (rows.length === 0) return;
  const filters = rows.find((r) => r.subject === 'job:filter_eval');
  if (filters) {
    toast('Prompt filters are not being evaluated (failing since ' + fmtSince(filters.firstFailedAt) + '). Events they would hide may be showing.', { error: true });
  }
  const visibleIds = new Set(state.calendars.filter((c) => c.visible).map((c) => c.id));
  const feeds = rows.filter((r) => r.kind === 'feed' && r.consecutiveFailures >= 2
    && visibleIds.has(Number(r.subject.split(':')[1])));
  if (feeds.length === 1) {
    toast(feeds[0].label + " hasn't updated since " + fmtSince(feeds[0].firstFailedAt), { error: true });
  } else if (feeds.length > 1) {
    toast(feeds.length + " feeds haven't updated (oldest since " + fmtSince(feeds[feeds.length - 1].firstFailedAt) + '). See Settings, System.', { error: true });
  }
}

// Persistent banner: reminders cannot reach this device. Nothing else earns
// one. Re-enabling re-subscribes, which clears the failing subscription on
// the server; dismiss hides it for this session only.
export function SystemBanner() {
  const health = useStore((s) => s.systemHealth);
  const [endpoint, setEndpoint] = useState(null);
  const [dismissed, setDismissed] = useState(false);
  const [busy, setBusy] = useState(false);
  useEffect(() => { currentPushEndpoint().then(setEndpoint); }, [health]);

  const mine = endpoint && failingRows(health).find((r) => r.kind === 'push' && r.endpoint === endpoint);
  if (!mine || dismissed) return null;

  const fix = async () => {
    setBusy(true);
    try {
      await enablePush();
      toast('Reminders re-enabled on this device');
      set({ systemHealth: { ...state.systemHealth, rows: state.systemHealth.rows.filter((r) => r !== mine) } });
    } catch (e) {
      toast(e.message || 'Could not re-enable reminders', { error: true });
    } finally {
      setBusy(false);
    }
  };

  return html`<div class="bc-sysbanner" role="alert">
    <span>Reminders can't reach this device (since ${fmtSince(mine.firstFailedAt)}). ${mine.lastError || ''}</span>
    <button type="button" class="bc-btn" disabled=${busy} onClick=${fix}>${busy ? 'Re-enabling…' : 'Re-enable reminders'}</button>
    <button type="button" class="bc-icon-btn" aria-label="Dismiss for now" title="Dismiss for now" onClick=${() => setDismissed(true)}>×</button>
  </div>`;
}
