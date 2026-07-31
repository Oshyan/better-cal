// Outbound feeds management: list, create with scope picker, copy URL, delete.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, set, state, toast } from './store.js';
import { api } from './api.js';

export function OutfeedsPage() {
  const [feeds, setFeeds] = useState(null);
  const [name, setName] = useState('');
  const [scopeType, setScopeType] = useState('all');
  const [calendarId, setCalendarId] = useState('');
  const [q, setQ] = useState('');
  const [description, setDescription] = useState('');
  const [busy, setBusy] = useState(false);
  const calendars = useStore((s) => s.calendars);

  const load = async () => {
    try {
      const data = await api('/outfeeds');
      setFeeds(data.outfeeds || data.feeds || (Array.isArray(data) ? data : []));
    } catch (e) {
      toast('Failed to load feeds: ' + e.message, { error: true });
      setFeeds([]);
    }
  };

  useEffect(() => { load(); }, []);

  const create = async (e) => {
    e.preventDefault();
    setBusy(true);
    const scope = { type: scopeType };
    if (scopeType === 'calendar') scope.calendarId = Number(calendarId || (calendars[0] && calendars[0].id));
    if (scopeType === 'search') scope.q = q;
    try {
      await api('/outfeeds', { method: 'POST', body: { name: name || 'Feed', scope, description: description || undefined } });
      toast('Feed created');
      setName(''); setDescription(''); setQ('');
      await load();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
    setBusy(false);
  };

  const remove = async (feed) => {
    try {
      await api('/outfeeds/' + feed.id, { method: 'DELETE' });
      toast('Feed deleted');
      await load();
    } catch (err) {
      toast('Failed: ' + err.message, { error: true });
    }
  };

  const copy = async (url) => {
    try {
      await navigator.clipboard.writeText(new URL(url, location.origin).href);
      toast('URL copied');
    } catch {
      toast('Copy failed; URL: ' + url, { error: true, duration: 10000 });
    }
  };

  const scopeLabel = (scope) => {
    if (!scope) return '';
    if (scope.type === 'all') return 'All events';
    if (scope.type === 'calendar') {
      const cal = state.calendars.find((c) => c.id === scope.calendarId);
      return 'Calendar: ' + ((cal && cal.name) || scope.calendarId);
    }
    return 'Search: ' + (scope.q || '');
  };

  return html`<div class="bc-page">
    <div class="bc-page-head">
      <h1>Outbound feeds</h1>
      <button type="button" class="bc-btn" onClick=${() => set({ route: 'calendar' })}>Back to calendar</button>
    </div>
    <p class="bc-page-note">Each feed is a token ICS URL other apps can subscribe to.</p>

    <form class="bc-outfeed-form" onSubmit=${create}>
      <input placeholder="Feed name" value=${name} onInput=${(e) => setName(e.target.value)} required />
      <select value=${scopeType} onChange=${(e) => setScopeType(e.target.value)} aria-label="Feed scope">
        <option value="all">All events</option>
        <option value="calendar">One calendar</option>
        <option value="search">Saved search</option>
      </select>
      ${scopeType === 'calendar' && html`<select value=${calendarId} onChange=${(e) => setCalendarId(e.target.value)} aria-label="Calendar">
        ${calendars.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
      </select>`}
      ${scopeType === 'search' && html`<input placeholder="Search query" value=${q} onInput=${(e) => setQ(e.target.value)} required />`}
      <input placeholder="Description (optional)" value=${description} onInput=${(e) => setDescription(e.target.value)} />
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>Create feed</button>
    </form>

    ${feeds === null && html`<div class="bc-empty">Loading feeds</div>`}
    ${feeds !== null && feeds.length === 0 && html`<div class="bc-empty">No outbound feeds yet</div>`}
    ${feeds !== null && feeds.length > 0 && html`<div class="bc-outfeed-list">
      ${feeds.map((f) => html`<div key=${f.id} class="bc-outfeed-row">
        <div class="bc-outfeed-main">
          <strong>${f.name}</strong>
          <span class="bc-outfeed-scope">${scopeLabel(f.scope)}</span>
          ${f.description && html`<span class="bc-outfeed-desc">${f.description}</span>`}
        </div>
        <code class="bc-outfeed-url">${f.url}</code>
        <button type="button" class="bc-btn" onClick=${() => copy(f.url)}>Copy URL</button>
        <button type="button" class="bc-btn bc-btn-danger" onClick=${() => remove(f)}>Delete</button>
      </div>`)}
    </div>`}
  </div>`;
}
