// Move a local calendar to Google (0.9.4, #55), from its settings panel.
// Better-Cal creates a calendar in the connected Google account (or uses one
// picked from the account), uploads every event, and from then on edits it in
// place like any Google calendar here, so people on Google Calendar can see
// it live and, shared that way, add to it. The server does the work
// (GoogleMove); this shows the choice, then the upload's progress.

import { html, useState, useEffect } from '../../vendor/index.js';
import { api, loadCalendars } from './api.js';
import { toast } from './store.js';
import { Icon } from '../ui/icons.js';

export function MoveToGoogle({ cal }) {
  const [google, setGoogle] = useState(null); // {configured, accounts}
  const [open, setOpen] = useState(false);
  const [move, setMove] = useState(null); // the server's latest move for this calendar
  const [accountId, setAccountId] = useState(null);
  const [target, setTarget] = useState('new'); // 'new' or a Google calendar id
  const [lists, setLists] = useState({}); // accountId -> the account's calendars
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api('/google/status').then((d) => {
      setGoogle(d);
      if (d.accounts && d.accounts.length) setAccountId(d.accounts[0].id);
    }).catch(() => setGoogle({ configured: false, accounts: [] }));
    api('/calendars/' + cal.id + '/move-to-google').then((d) => setMove(d.move)).catch(() => {});
  }, [cal.id]);

  // While an upload runs, follow its progress; when it ends, the calendar
  // list is reloaded so this calendar shows up as a Google one.
  const running = move && (move.status === 'queued' || move.status === 'running');
  useEffect(() => {
    if (!running) return undefined;
    const t = setInterval(async () => {
      try {
        const d = await api('/calendars/' + cal.id + '/move-to-google');
        setMove(d.move);
        if (d.move && d.move.status === 'done') {
          toast('Moved to Google. Share it from Google Calendar: its settings, Share with specific people.');
          loadCalendars();
        }
      } catch { /* try again on the next tick */ }
    }, 2000);
    return () => clearInterval(t);
  }, [running, cal.id]);

  useEffect(() => {
    if (!open || accountId == null || lists[accountId]) return;
    api('/google/accounts/' + accountId + '/calendars')
      .then((d) => setLists((l) => ({ ...l, [accountId]: d.calendars || [] })))
      .catch(() => setLists((l) => ({ ...l, [accountId]: [] })));
  }, [open, accountId]); // eslint-disable-line

  if (!google || !google.configured) return null;
  const accounts = google.accounts || [];
  const account = accounts.find((a) => a.id === accountId) || null;
  // Calendars this account may edit that aren't here already; never the
  // account's main calendar, where this one's events would mix with everything.
  const usable = (lists[accountId] || []).filter((c) => (c.accessRole === 'owner' || c.accessRole === 'writer') && !c.calendarId && c.kind !== 'feed' && !c.primary);
  const needsReconnect = target === 'new' && account && !account.canCreateCalendars;

  const start = async () => {
    setBusy(true);
    try {
      const d = await api('/calendars/' + cal.id + '/move-to-google', {
        method: 'POST', body: { accountId, googleCalendarId: target === 'new' ? null : target },
      });
      setMove(d);
      if (d.status === 'done') {
        toast('Moved to Google. Share it from Google Calendar: its settings, Share with specific people.');
        loadCalendars();
      }
    } catch (e) {
      toast(e.message || 'Could not start the move', { error: true });
    }
    setBusy(false);
  };

  if (running) {
    return html`<div class="bc-calset-field bc-move">
      <span class="bc-calset-label">Moving to Google</span>
      <span class="bc-calset-value">Uploading ${move.done} of ${move.total} event${move.total === 1 ? '' : 's'}… It takes no edits until this finishes.</span>
    </div>`;
  }

  return html`<div class="bc-calset-field bc-move">
    <span class="bc-calset-label">Google</span>
    ${move && move.status === 'failed' && html`<span class="bc-calset-value bc-move-error">
      The move stopped: ${move.error || 'unknown error'}. ${move.done} of ${move.total} uploaded so far; trying again continues from there.
      <button type="button" class="bc-btn" disabled=${busy} onClick=${start}>Try again</button>
    </span>`}
    ${accounts.length === 0 && html`<span class="bc-calset-value">To share this calendar with people who use Google Calendar, connect a Google account first (Settings, Connections).</span>`}
    ${accounts.length > 0 && !open && !(move && move.status === 'failed') && html`<button type="button" class="bc-btn" onClick=${() => setOpen(true)}>
      <${Icon} name="google" size=${14} /> Move to Google…
    </button>`}
    ${accounts.length > 0 && open && html`<div class="bc-move-panel">
      <p class="bc-move-note">Moves this calendar into your Google account, so people who use Google Calendar can see it live, and add to it if you share it with them that way. It stays here too, edited in place like your other Google calendars. Its tags, people, reminders and trips stay. What changes: edits to it can't be undone here, as for any Google calendar. "Adopt as local calendar" brings it back.</p>
      ${accounts.length > 1 && html`<label class="bc-move-row"><span>Account</span>
        <select value=${String(accountId)} onChange=${(e) => { setAccountId(Number(e.target.value)); setTarget('new'); }}>
          ${accounts.map((a) => html`<option key=${a.id} value=${String(a.id)}>${a.email}</option>`)}
        </select>
      </label>`}
      <label class="bc-move-row"><span>Into</span>
        <select value=${target} onChange=${(e) => setTarget(e.target.value)}>
          <option value="new">A new Google calendar named "${cal.name}"</option>
          ${usable.length > 0 && html`<optgroup label="Or one you already have in Google">
            ${usable.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
          </optgroup>`}
        </select>
      </label>
      ${target !== 'new' && html`<p class="bc-move-note">Best into an empty calendar: what's already in it will show up here as well.</p>`}
      ${needsReconnect && html`<p class="bc-move-note">Google needs your permission once more before Better-Cal can create calendars there.
        <a class="bc-btn" href="/api/v1/google/connect">Reconnect ${account.email}</a></p>`}
      <div class="bc-move-actions">
        <button type="button" class="bc-btn bc-btn-primary" disabled=${busy || needsReconnect} onClick=${start}>Move to Google</button>
        <button type="button" class="bc-btn" onClick=${() => setOpen(false)}>Cancel</button>
      </div>
    </div>`}
  </div>`;
}
