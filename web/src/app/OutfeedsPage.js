// Outbound feeds management: list, create with scope picker, copy URL, delete.

import { html, useState, useEffect } from '../../vendor/index.js';
import { useStore, state, toast } from './store.js';
import { api } from './api.js';
import { PageShell, EmptyState, Skeleton } from './PageShell.js';

export function OutfeedsSection() {
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

  const focusForm = () => {
    const el = document.querySelector('.bc-mgmt-form input');
    if (el) el.focus();
  };

  return html`<section class="bc-set-section">
    <h2 class="bc-set-h">Outbound feeds</h2>
    <p class="bc-set-lead">Each feed is a private token URL other calendar apps and people can subscribe to; what it carries is everything, one calendar, or a saved search.</p>
    <form class="bc-mgmt-form" onSubmit=${create}>
      <div class="bc-form-row">
        <label class="bc-field grow">
          <span>Feed name</span>
          <input placeholder="e.g. Work calendar for Google" value=${name} onInput=${(e) => setName(e.target.value)} required />
        </label>
        <label class="bc-field">
          <span>Includes</span>
          <select value=${scopeType} onChange=${(e) => setScopeType(e.target.value)}>
            <option value="all">All events</option>
            <option value="calendar">One calendar</option>
            <option value="search">Saved search</option>
          </select>
        </label>
        ${scopeType === 'calendar' && html`<label class="bc-field">
          <span>Calendar</span>
          <select value=${calendarId} onChange=${(e) => setCalendarId(e.target.value)}>
            ${calendars.map((c) => html`<option key=${c.id} value=${c.id}>${c.name}</option>`)}
          </select>
        </label>`}
        ${scopeType === 'search' && html`<label class="bc-field grow">
          <span>Search query</span>
          <input placeholder="Events matching this text" value=${q} onInput=${(e) => setQ(e.target.value)} required />
        </label>`}
      </div>
      <div class="bc-form-row">
        <label class="bc-field grow">
          <span>Description (optional)</span>
          <input placeholder="Shown to subscribing apps" value=${description} onInput=${(e) => setDescription(e.target.value)} />
        </label>
        <div class="bc-field bc-form-actions">
          <span aria-hidden="true"> </span>
          <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>Create feed</button>
        </div>
      </div>
    </form>

    ${feeds === null && html`<${Skeleton} rows=${3} />`}
    ${feeds !== null && feeds.length === 0 && html`<${EmptyState}
      text="No outbound feeds yet. A feed lets Google Calendar, Apple Calendar, or any other app subscribe to your Better-Cal events."
      actionLabel="Create your first feed" onAction=${focusForm}
    />`}
    ${feeds !== null && feeds.length > 0 && html`<div class="bc-card-list">
      ${feeds.map((f) => html`<div key=${f.id} class="bc-card">
        <div class="bc-card-main">
          <div class="bc-card-badges">
            <strong class="bc-card-title">${f.name}</strong>
            <span class="bc-badge">${scopeLabel(f.scope)}</span>
          </div>
          ${f.description && html`<div class="bc-card-sub">${f.description}</div>`}
          <code class="bc-outfeed-url" title=${f.url}>${f.url}</code>
        </div>
        <div class="bc-card-actions">
          <button type="button" class="bc-btn" onClick=${() => copy(f.url)}>Copy URL</button>
          <button type="button" class="bc-btn bc-btn-danger" onClick=${() => remove(f)}>Delete</button>
        </div>
      </div>`)}
    </div>`}
  </section>`;
}

/** The standalone page (route outfeeds); the section also renders on Settings, Connections. */
export function OutfeedsPage() {
  return html`<${PageShell} title="Outbound feeds"><${OutfeedsSection} /><//>`;
}
