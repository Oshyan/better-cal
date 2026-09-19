// Google Calendar connector (docs/google-calendar.md): connect an account,
// see every calendar Google shows it, add one as a read-only subscribed
// calendar here. Lives on Settings, Connections, inline with the other ways
// in and out. The consent round trip is a full navigation:
// /api/v1/google/connect sends the browser to Google, the callback lands on
// the /google handoff path, which reopens that tab.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, toast } from './store.js';
import { api, loadCalendars } from './api.js';

function Row({ label, hint, children }) {
  return html`<div class="bc-set-row">
    <span class="bc-set-label">${label}</span>
    <span class="bc-set-control">${children}</span>
    ${hint && html`<span class="bc-set-hint">${hint}</span>`}
  </div>`;
}

export function GoogleConnector() {
  const calendars = useStore((s) => s.calendars);
  const [status, setStatus] = useState(null); // {configured, accounts:[{id,email,status,error}]}
  const [lists, setLists] = useState({});      // accountId -> [{id,name,accessRole,primary,color,calendarId}] | 'loading' | 'error'
  const [busy, setBusy] = useState(null);      // googleCalendarId or 'disconnect:<id>' in flight
  const [confirmDisconnect, setConfirmDisconnect] = useState(null);

  const load = async () => {
    try {
      const d = await api('/google/status');
      setStatus(d);
      for (const a of d.accounts || []) loadList(a.id);
    } catch (e) {
      setStatus({ configured: false, accounts: [], error: e.message });
    }
  };
  const loadList = async (accountId) => {
    setLists((l) => ({ ...l, [accountId]: 'loading' }));
    try {
      const d = await api('/google/accounts/' + accountId + '/calendars');
      setLists((l) => ({ ...l, [accountId]: d.calendars || [] }));
    } catch (e) {
      setLists((l) => ({ ...l, [accountId]: 'error:' + e.message }));
    }
  };
  useEffect(() => { load(); }, []);

  const connect = () => { window.location.href = '/api/v1/google/connect'; };
  const disconnect = async (a) => {
    setBusy('disconnect:' + a.id);
    try {
      await api('/google/accounts/' + a.id + '/disconnect', { method: 'POST' });
      toast('Disconnected ' + a.email + '. Its calendars stay until you delete them.');
      setConfirmDisconnect(null);
      await load();
    } catch (e) {
      toast('Could not disconnect: ' + e.message, { error: true });
    } finally {
      setBusy(null);
    }
  };
  const add = async (a, c) => {
    setBusy(c.id);
    try {
      const cal = await api('/google/accounts/' + a.id + '/subscribe', {
        method: 'POST', body: { googleCalendarId: c.id, name: c.name, color: c.color || undefined, accessRole: c.accessRole },
      });
      await loadCalendars();
      const n = cal.health && cal.health.eventCount;
      toast('Added "' + cal.name + '"' + (n != null ? ' (' + n + ' events)' : '') + '. It checks Google every ' + cal.pollIntervalMinutes + ' minutes.');
      await loadList(a.id);
    } catch (e) {
      toast('Could not add: ' + e.message, { error: true });
    } finally {
      setBusy(null);
    }
  };
  const roleLabel = (r) => ({ owner: 'owner', writer: 'can edit', reader: 'can view', freeBusyReader: 'free/busy only' }[r] || r);
  // Google states a role, not a kind; the server derives the kind from the
  // calendar id (GoogleAuth::calendarKind) and sorts by it.
  const KIND = {
    yours: ['Yours', 'A calendar you own'],
    shared: ['Shared with you', 'Someone else owns it and shared it with you; the one shape no iCal address can reach'],
    feed: ['Feed copy', "Google's own copy of an ICS subscription; subscribing to the ICS address here is fresher"],
    google: ['Google', 'Provided by Google (holidays, birthdays)'],
  };
  const localName = (id) => { const c = calendars.find((x) => x.id === id); return c ? c.name : null; };

  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">Google Calendar</h2>
    <p class="bc-set-lead">Calendars through a Google account: yours, ones you subscribe to, and ones shared with you, including shared-but-not-public calendars no iCal address can reach. Where the account can edit, so can you here: a change goes to Google first and lands back within a second. Edits made in Google arrive within the check interval.</p>
    ${status === null && html`<${Row} label="Account"><span class="bc-set-value">Loading…</span><//>`}
    ${status && !status.configured && html`<${Row} label="Account" hint="Reading calendars through a Google account needs an OAuth client on this server: BETTERCAL_GOOGLE_CLIENT_ID and _SECRET, see docs/google-calendar.md. Ten minutes, once.">
      <span class="bc-set-value">Not set up on this server.</span>
    <//>`}
    ${status && status.configured && html`
      <${Row} label=${status.accounts.length ? 'Accounts' : 'Account'}>
        <div class="bc-google-accounts">
          ${status.accounts.map((a) => html`<div class="bc-google-account" key=${a.id}>
            <span class="bc-google-email">${a.email}</span>
            ${a.status !== 'ok' && html`<span class="bc-google-err" title=${a.error || ''}>needs reconnecting</span>`}
            ${confirmDisconnect === a.id
              ? html`<span class="bc-google-confirm">Disconnect? Its calendars stay, but stop updating.
                  <button type="button" class="bc-btn bc-btn-danger" disabled=${busy === 'disconnect:' + a.id} onClick=${() => disconnect(a)}>Disconnect</button>
                  <button type="button" class="bc-link-btn" onClick=${() => setConfirmDisconnect(null)}>Keep</button></span>`
              : html`<button type="button" class="bc-link-btn" onClick=${() => setConfirmDisconnect(a.id)}>Disconnect</button>`}
          </div>`)}
          <button type="button" class="bc-btn" onClick=${connect}>${status.accounts.length ? 'Connect another account' : 'Connect a Google account'}</button>
        </div>
      <//>
      ${status.accounts.map((a) => {
        const list = lists[a.id];
        return html`<div class="bc-google-block" key=${a.id}>
          <h3 class="bc-google-h">${status.accounts.length > 1 ? a.email : 'Calendars'}</h3>
          ${(list === undefined || list === 'loading') && html`<span class="bc-set-value">Asking Google…</span>`}
          ${typeof list === 'string' && list.startsWith('error:') && html`<span class="bc-set-value bc-google-err">${list.slice(6)} <button type="button" class="bc-link-btn" onClick=${() => loadList(a.id)}>Retry</button></span>`}
          ${Array.isArray(list) && list.length === 0 && html`<span class="bc-set-value">Google lists no calendars for this account.</span>`}
          ${Array.isArray(list) && list.length > 0 && html`<table class="bc-sys-table bc-google-list">
            <thead><tr><th>Calendar</th><th>Kind</th><th>Access</th><th></th></tr></thead>
            <tbody>${list.map((c) => html`<tr key=${c.id}>
              <td><span class="bc-cal-dot" style=${c.color ? 'background:' + c.color : ''}></span> ${c.name}${c.primary ? html` <span class="bc-google-tag">primary</span>` : ''}</td>
              <td title=${(KIND[c.kind] || ['', ''])[1]}>${(KIND[c.kind] || [c.kind])[0]}</td>
              <td>${roleLabel(c.accessRole)}</td>
              <td>${c.calendarId
                ? html`<span class="bc-set-value" title=${'Here as "' + (localName(c.calendarId) || c.name) + '"'}>Added</span>`
                : html`<button type="button" class="bc-link-btn" disabled=${busy === c.id} onClick=${() => add(a, c)}>${busy === c.id ? 'Adding…' : 'Add'}</button>`}</td>
            </tr>`)}</tbody>
          </table>`}
        </div>`;
      })}
    `}
  </section>`;
}

