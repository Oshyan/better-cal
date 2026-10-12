// Move a local calendar to Google (0.9.4, #55). Better-Cal creates a
// calendar in the connected Google account (or uses one picked from the
// account), uploads every event, and from then on edits it in place like any
// Google calendar here, so people on Google Calendar can see it live and,
// shared that way, add to it. The server does the work (GoogleMove).
//
// The calendar's settings show a line about it and open MoveDialog, a dialog
// of its own (0.9.58): the sidebar's settings panel was too narrow for the
// choice, the permission step and the progress. The dialog is mounted once
// at the app level (state.moveDialog = {calendarId}), so it outlives the panel
// and can be reopened when Google sends you back from its consent screen.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { api, loadCalendars } from './api.js';
import { useStore, set, toast } from './store.js';
import { Icon } from '../ui/icons.js';
import { trapFocus } from '../ui/DayExpand.js';
import { usePasswordStepUp } from './PasswordStepUp.js';
import { useBackClose } from './backclose.js';
import { GOOGLE_RETURN_KEY } from './handoff.js';

const MOVED_TOAST = 'Moved to Google. Share it from Google Calendar: its settings, Share with specific people.';

function useMoveStatus(calendarId, active) {
  const [move, setMove] = useState(undefined); // undefined: not loaded yet
  const running = !!move && (move.status === 'queued' || move.status === 'running');
  useEffect(() => {
    if (calendarId == null || !active) return undefined;
    let stop = false;
    api('/calendars/' + calendarId + '/move-to-google').then((d) => { if (!stop) setMove(d.move); }).catch(() => { if (!stop) setMove(null); });
    return () => { stop = true; };
  }, [calendarId, active]);
  // While an upload runs, follow its progress; when it ends, the calendar
  // list is reloaded so this calendar shows up as a Google one.
  useEffect(() => {
    if (!running) return undefined;
    const t = setInterval(async () => {
      try {
        const d = await api('/calendars/' + calendarId + '/move-to-google');
        // Just started: until the new move is on record, keep showing it starting.
        setMove((cur) => (cur && cur.starting && !d.move ? cur : d.move));
        if (d.move && d.move.status === 'done') {
          toast(MOVED_TOAST);
          loadCalendars();
        }
      } catch { /* try again on the next tick */ }
    }, 2000);
    return () => clearInterval(t);
  }, [running, calendarId]);
  return [move, setMove, running];
}

/** The calendar settings line: what's going on, and the way into the dialog. */
export function MoveToGoogle({ cal }) {
  const [google, setGoogle] = useState(null);
  const dialogOpen = useStore((s) => !!s.moveDialog && s.moveDialog.calendarId === cal.id);
  // Re-read when the dialog closes, so the line matches what happened there.
  const [move, , running] = useMoveStatus(cal.id, !dialogOpen);
  useEffect(() => {
    api('/google/status').then(setGoogle).catch(() => setGoogle({ configured: false, accounts: [] }));
  }, [cal.id]);
  if (!google || !google.configured) return null;
  const open = () => set({ moveDialog: { calendarId: cal.id } });
  const accounts = google.accounts || [];
  let line = null;
  if (running) line = `Moving to Google: ${move.done} of ${move.total} uploaded. It takes no edits until this finishes.`;
  else if (move && move.status === 'failed' && !move.cancelled) line = 'The move to Google stopped part-way. This calendar is read-only until you continue or stop it.';
  else if (move && move.status === 'failed' && move.cancelled) line = 'A move to Google was stopped; part of it may remain at Google.';
  return html`<div class="bc-calset-field bc-move">
    <span class="bc-calset-label">Google</span>
    ${line && html`<span class=${'bc-calset-value' + (move && move.status === 'failed' && !move.cancelled ? ' bc-move-error' : '')}>${line}</span>`}
    ${accounts.length === 0
      ? html`<span class="bc-calset-value">To share this calendar with people who use Google Calendar, connect a Google account first (Settings, Connections).</span>`
      : html`<button type="button" class="bc-btn" onClick=${open}>
          <${Icon} name="google" size=${14} /> ${running || (move && move.status === 'failed' && !move.cancelled) ? 'Move details…' : 'Move to Google…'}
        </button>`}
  </div>`;
}

/** The move itself: choose, allow, start, follow, and finish or stop. Mounted once in App. */
export function MoveDialog() {
  const dialog = useStore((s) => s.moveDialog);
  const calendars = useStore((s) => s.calendars);
  const calendarId = dialog ? dialog.calendarId : null;
  const cal = calendarId != null ? (calendars || []).find((c) => c.id === calendarId) || null : null;
  const close = () => set({ moveDialog: null });
  useBackClose(!!dialog, close);
  if (!dialog) return null;
  return html`<${MoveDialogBody} key=${calendarId} calendarId=${calendarId} cal=${cal} close=${close} />`;
}

function MoveDialogBody({ calendarId, cal, close }) {
  const [google, setGoogle] = useState(null); // {configured, accounts}
  const [move, setMove, running] = useMoveStatus(calendarId, true);
  const [accountId, setAccountId] = useState(null);
  const [target, setTarget] = useState('new'); // 'new' or a Google calendar id
  const [lists, setLists] = useState({}); // accountId -> the account's calendars
  const [busy, setBusy] = useState(false);
  const stepUp = usePasswordStepUp();
  const panelRef = useRef(null);

  useEffect(() => {
    api('/google/status').then((d) => {
      setGoogle(d);
      if (d.accounts && d.accounts.length) setAccountId(d.accounts[0].id);
    }).catch(() => setGoogle({ configured: false, accounts: [] }));
  }, [calendarId]);

  useEffect(() => {
    if (accountId == null || lists[accountId]) return;
    api('/google/accounts/' + accountId + '/calendars')
      .then((d) => setLists((l) => ({ ...l, [accountId]: d.calendars || [] })))
      .catch(() => setLists((l) => ({ ...l, [accountId]: [] })));
  }, [accountId]); // eslint-disable-line

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Tab') trapFocus(panelRef.current, e); };
    document.addEventListener('keydown', onKey, true);
    if (panelRef.current) panelRef.current.focus({ preventScroll: true });
    return () => document.removeEventListener('keydown', onKey, true);
  }, []);

  const name = cal ? cal.name : 'this calendar';
  const accounts = (google && google.accounts) || [];
  const account = accounts.find((a) => a.id === accountId) || null;
  // Calendars this account may edit that aren't here already; never the
  // account's main calendar, where this one's events would mix with everything.
  const usable = (lists[accountId] || []).filter((c) => (c.accessRole === 'owner' || c.accessRole === 'writer') && !c.calendarId && c.kind !== 'feed' && !c.primary);
  const failed = move && move.status === 'failed' && !move.cancelled;
  // Google grants permissions one at a time. An account connected before
  // moves existed lacks the one to create calendars; it is asked for once,
  // and an account that has it never sees this step.
  const needsPermission = !!account && !account.reauthRequired && target === 'new' && !account.canCreateCalendars;
  const needsReconnect = !!account && account.reauthRequired;

  const goToGoogle = async (purpose) => {
    setBusy(true);
    try {
      const d = await stepUp.run(purpose, () => api('/google/connect', { method: 'POST' }));
      if (d && d.url) {
        // Come back to this dialog, not to Settings, Connections.
        try { sessionStorage.setItem(GOOGLE_RETURN_KEY, JSON.stringify({ kind: 'move', calendarId })); } catch { /* lands on Connections instead */ }
        window.location.href = d.url;
      }
    } catch (e) {
      toast(e.message || 'Could not reach Google', { error: true });
    } finally {
      setBusy(false);
    }
  };

  const start = async () => {
    setBusy(true);
    try {
      const selectedAccountId = failed ? move.googleAccountId : accountId;
      const selectedTarget = failed ? (move.createdNew ? null : move.googleCalendarId) : (target === 'new' ? null : target);
      const before = move;
      const d = await stepUp.run('move this calendar to Google', () => {
        // Starting uploads for up to 12 seconds before it answers (a small
        // calendar finishes in that one request), so show the move at once
        // and follow its real progress while the request is still running.
        setMove({ status: 'queued', done: 0, total: 0, starting: true });
        return api('/calendars/' + calendarId + '/move-to-google', {
          method: 'POST', body: { accountId: selectedAccountId, googleCalendarId: selectedTarget },
        }).catch((e) => { setMove(before); throw e; });
      });
      if (!d) { setMove(before); return; }
      setMove(d);
      if (d.status === 'done') {
        toast(MOVED_TOAST);
        loadCalendars();
      }
    } catch (e) {
      toast(e.message || 'Could not start the move', { error: true });
    } finally {
      setBusy(false);
    }
  };

  const stop = async () => {
    setBusy(true);
    try {
      const d = await stepUp.run('stop this Google calendar move', () => api('/calendars/' + calendarId + '/move-to-google', { method: 'DELETE' }));
      if (!d) return;
      setMove(d);
      toast('Move stopped. This calendar is editable again; remove any partial copy you do not want from Google Calendar.');
    } catch (e) {
      toast(e.message || 'Could not stop the move', { error: true });
    } finally {
      setBusy(false);
    }
  };

  let body;
  if (google === null || move === undefined) {
    body = html`<p class="bc-move-note">Loading…</p>`;
  } else if (!google.configured || accounts.length === 0) {
    body = html`<p class="bc-move-note">Connect a Google account first (Settings, Connections), then come back here.</p>`;
  } else if (running) {
    const pct = move.total ? Math.round((move.done / move.total) * 100) : 0;
    body = html`<div class="bc-move-progress">
      <p>${move.total ? `Uploading ${move.done} of ${move.total} event${move.total === 1 ? '' : 's'}…` : 'Starting the move…'}</p>
      <div class="bc-move-bar" role="progressbar" aria-valuemin="0" aria-valuemax=${move.total} aria-valuenow=${move.done}><i style=${`width:${pct}%`}></i></div>
      <p class="bc-move-note">"${name}" takes no edits until this finishes, so a half-uploaded calendar can't change underneath it. You can close this; the move carries on.</p>
    </div>`;
  } else if (move && move.status === 'done') {
    body = html`<p class="bc-move-note">"${name}" is now a Google calendar. Share it from Google Calendar: its settings, Share with specific people.</p>`;
  } else if (failed) {
    body = html`<div class="bc-move-error">
      <p>The move stopped: ${move.error || 'unknown error'}. ${move.done} of ${move.total} uploaded so far.</p>
      <p class="bc-move-note">"${name}" stays read-only until you continue or stop the move, so a partial upload can't overwrite later edits.</p>
      <div class="bc-move-actions">
        <button type="button" class="bc-btn bc-btn-primary" disabled=${busy} onClick=${start}>Try again</button>
        <button type="button" class="bc-btn" disabled=${busy} onClick=${stop}>Stop move and keep local</button>
      </div>
    </div>`;
  } else {
    const stopped = move && move.status === 'failed' && move.cancelled;
    body = html`<div class="bc-move-form">
      ${stopped && html`<p class="bc-move-note bc-move-warn">${move.stoppedByOwner
        ? `You stopped the last move. ${move.done} of ${move.total} events may remain at Google; remove that partial copy there if you don't want it.`
        : move.stoppedByUpgrade
          ? 'An unfinished move from an older version was stopped safely during a security upgrade. Some events may remain at Google; review that copy first.'
          : `The last move was stopped by the account security reset. ${move.done} of ${move.total} events may already be at Google; it won't resume by itself.`}</p>`}
      <p class="bc-move-note">Moves "${name}" into your Google account, so people who use Google Calendar can see it live, and add to it if you share it with them that way. It stays here too, edited in place like your other Google calendars, with its tags, people, reminders and trips. What changes: edits to it can't be undone here, as for any Google calendar. "Adopt as local calendar" brings it back.</p>
      ${accounts.length > 1 && html`<label class="bc-move-field"><span>Google account</span>
        <select value=${String(accountId)} onChange=${(e) => { setAccountId(Number(e.target.value)); setTarget('new'); }}>
          ${accounts.map((a) => html`<option key=${a.id} value=${String(a.id)}>${a.email}</option>`)}
        </select>
      </label>`}
      <label class="bc-move-field"><span>Into</span>
        <select value=${target} onChange=${(e) => setTarget(e.target.value)}>
          <option value="new">A new Google calendar named "${name}"</option>
          ${usable.length > 0 && html`<optgroup label="Or one you already have in Google">
            ${usable.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
          </optgroup>`}
        </select>
      </label>
      ${target !== 'new' && html`<p class="bc-move-note">Best into an empty calendar: what's already in it will show up here as well.</p>`}
      ${needsPermission && html`<div class="bc-move-step">
        <p>To make a new calendar there, Google needs to let Better-Cal create calendars in ${account.email}. This adds that one permission to your existing connection; you'll go to Google to allow it and come straight back here.</p>
        <button type="button" class="bc-btn bc-btn-primary" disabled=${busy} onClick=${() => goToGoogle('allow creating calendars in Google')}>Allow on Google</button>
      </div>`}
      ${needsReconnect && html`<div class="bc-move-step">
        <p>Google access for ${account.email} was paused by the account security reset. Reconnect it on Google to continue; you'll come straight back here.</p>
        <button type="button" class="bc-btn bc-btn-primary" disabled=${busy} onClick=${() => goToGoogle('reconnect this Google account')}>Reconnect on Google</button>
      </div>`}
    </div>`;
  }

  const ready = google && accounts.length > 0 && !running && !failed && !(move && move.status === 'done');
  return html`<div class="bc-overlay bc-move-overlay" onClick=${(e) => { if (e.target === e.currentTarget) close(); }}>
    <div class="bc-move-dialog" ref=${panelRef} role="dialog" aria-modal="true" aria-label=${'Move ' + name + ' to Google'} tabindex="-1">
      <div class="bc-move-head">
        <h2><${Icon} name="google" size=${16} /> Move to Google</h2>
        <button type="button" class="bc-icon-btn" aria-label="Close" onClick=${close}><${Icon} name="close" size=${15} /></button>
      </div>
      ${body}
      <div class="bc-move-actions bc-move-foot">
        ${ready && html`<button type="button" class="bc-btn bc-btn-primary" disabled=${busy || needsPermission || needsReconnect} onClick=${start}
          title=${needsPermission || needsReconnect ? 'Allow it on Google first' : ''}>Move to Google</button>`}
        <button type="button" class="bc-btn" onClick=${close}>${running || (move && move.status === 'done') ? 'Close' : 'Cancel'}</button>
      </div>
      ${stepUp.prompt}
    </div>
  </div>`;
}
