// Plugins ops page (Manage → Plugins): the whole observability story for the
// plugin system. Per plugin: enable toggle, declared permissions, last run,
// object counts, warnings, declarative settings, run-now, uninstall with an
// impact preview. Nothing here executes plugin code; it reads what worker
// runs wrote and flips flags.

import { html, useState, useEffect } from '../../vendor/index.js';
import { state, set, toast, useStore } from './store.js';
import { api, refreshWindow, loadCalendars } from './api.js';
import { PageShell, EmptyState } from './PageShell.js';
import { Icon } from '../ui/icons.js';
import { SchemaForm } from './SchemaForm.js';

async function reloadPlugins() {
  const d = await api('/plugins');
  set({ plugins: d.plugins || [], pluginSeq: state.pluginSeq + 1 });
}

function fmtAgo(iso) {
  const ms = Date.now() - new Date(iso).getTime();
  const min = Math.round(ms / 60000);
  if (min < 1) return 'just now';
  if (min < 60) return min + ' min ago';
  const h = Math.round(min / 60);
  return h < 48 ? h + ' h ago' : Math.round(h / 24) + ' d ago';
}

function PluginRow({ p }) {
  const [openSettings, setOpenSettings] = useState(false);
  const [warnings, setWarnings] = useState(null);
  const [busy, setBusy] = useState(false);

  const act = async (fn, okMsg) => {
    setBusy(true);
    try {
      await fn();
      if (okMsg) toast(okMsg);
      await reloadPlugins();
      refreshWindow();
    } catch (e) {
      toast(e.message || 'Failed', { error: true });
    } finally {
      setBusy(false);
    }
  };

  const toggle = () => act(
    () => api('/plugins/' + p.id + '/' + (p.enabled ? 'disable' : 'enable'), { method: 'POST' }),
    p.enabled ? p.name + ' disabled' : p.name + ' enabled — first run is queued for the next worker tick',
  );

  const runNow = () => act(async () => {
    const d = await api('/plugins/' + p.id + '/run', { method: 'POST' });
    toast('Queued: ' + (d.queued || []).join(', ') + ' (runs within a minute)');
  });

  const uninstall = () => {
    const c = p.counts;
    const keep = window.confirm(
      'Uninstall ' + p.name + '?\n\nThis purges ' + c.ranges + ' bands, ' + c.warnings + ' warnings and its stored data.\n\n'
      + 'OK = keep its ' + c.events + ' events (calendars archived, hidden)\nCancel = choose again with delete',
    );
    let deleteCalendars = false;
    if (!keep) {
      if (!window.confirm('Delete its ' + c.calendars + ' calendar(s) and ' + c.events + ' events instead? This cannot be undone.')) return;
      deleteCalendars = true;
    }
    act(
      () => api('/plugins/' + p.id + '/uninstall', { method: 'POST', body: { deleteCalendars } }),
      p.name + ' uninstalled',
    ).then(() => loadCalendars());
  };

  const loadWarnings = async () => {
    if (warnings !== null) { setWarnings(null); return; }
    const d = await api('/plugins/' + p.id + '/warnings');
    setWarnings(d.warnings || []);
  };

  const lr = p.lastRun;
  return html`<div class="bc-plugin-card${p.enabled ? '' : ' is-off'}">
    <div class="bc-plugin-head">
      <label class="bc-check bc-plugin-toggle" title=${p.enabled ? 'Disable' : 'Enable'}>
        <input type="checkbox" checked=${p.enabled} disabled=${busy || p.errors.length > 0} onChange=${toggle} />
      </label>
      <div class="bc-plugin-title">
        <span class="bc-plugin-name">${p.name}</span>
        <span class="bc-plugin-version">v${p.version}</span>
        ${p.permissions.map((perm) => html`<span key=${perm} class="bc-plugin-perm">${perm}</span>`)}
      </div>
      <div class="bc-plugin-tools">
        ${p.enabled && html`<button type="button" class="bc-btn" disabled=${busy} onClick=${runNow}>Run now</button>`}
        ${p.enabled && p.settingsSchema.length > 0 && html`<button type="button" class="bc-icon-btn${openSettings ? ' is-open' : ''}" title="Settings" onClick=${() => setOpenSettings(!openSettings)}><${Icon} name="settings" size=${13} /></button>`}
        ${p.installed && html`<button type="button" class="bc-icon-btn" title="Uninstall" disabled=${busy} onClick=${uninstall}><${Icon} name="trash" size=${13} /></button>`}
      </div>
    </div>
    <p class="bc-plugin-desc">${p.description}</p>
    ${p.errors.length > 0 && html`<div class="bc-plugin-errors">${p.errors.map((e) => html`<div key=${e}><${Icon} name="warning" size=${12} /> ${e}</div>`)}</div>`}
    ${p.disabledReason && html`<div class="bc-plugin-errors"><div><${Icon} name="warning" size=${12} /> ${p.disabledReason}</div></div>`}
    ${p.enabled && html`<div class="bc-plugin-status">
      ${lr
        ? html`<span class=${'bc-plugin-run is-' + lr.outcome} title=${lr.logTail}>${lr.outcome === 'ok' ? 'Last run' : 'Last run ' + lr.outcome} · ${lr.job} · ${fmtAgo(lr.at)}${lr.durationMs != null ? ' · ' + lr.durationMs + ' ms' : ''}</span>`
        : html`<span class="bc-plugin-run">No runs yet — queued for the next worker tick</span>`}
      ${lr && lr.runId && lr.undoableMutations > 0 && html`<button
        type="button" class="bc-link-btn" disabled=${busy}
        title=${'Reverse all ' + lr.undoableMutations + ' change(s) this run made'}
        onClick=${() => act(
          () => api('/plugins/runs/' + lr.runId + '/undo', { method: 'POST' }),
          'Run undone',
        )}
      >Undo this run (${lr.undoableMutations})</button>`}
      <span class="bc-plugin-counts">
        ${p.counts.events > 0 && html`<span>${p.counts.events} events</span>`}
        ${p.counts.ranges > 0 && html`<span>${p.counts.ranges} bands</span>`}
        ${p.counts.warnings > 0 && html`<button type="button" class="bc-link-btn" onClick=${loadWarnings}>${p.counts.warnings} warnings${warnings ? ' ▾' : ''}</button>`}
      </span>
    </div>`}
    ${warnings && html`<ul class="bc-plugin-warnlist">
      ${warnings.map((w) => html`<li key=${w.id} class=${'is-' + w.severity}>
        ${w.message}${w.fix ? html`<span class="bc-plugin-fix"> — ${w.fix}</span>` : ''}
      </li>`)}
      ${warnings.length === 0 && html`<li>No open warnings.</li>`}
    </ul>`}
    ${openSettings && html`<${SchemaForm}
      schema=${p.settingsSchema}
      values=${p.settings}
      onSave=${async (draft) => {
        try {
          await api('/plugins/' + p.id + '/settings', { method: 'PATCH', body: draft });
          toast(p.name + ' settings saved — takes effect on its next run');
          await reloadPlugins();
          return true;
        } catch (e) {
          toast(e.message || 'Save failed', { error: true });
          return false;
        }
      }}
    />`}
  </div>`;
}

export function PluginsPage() {
  const plugins = useStore((s) => s.plugins);
  const [loaded, setLoaded] = useState(false);
  useEffect(() => {
    reloadPlugins().catch((e) => toast('Load failed: ' + e.message, { error: true })).finally(() => setLoaded(true));
  }, []);

  return html`<${PageShell}
    title="Plugins"
    note="Plugins run in the background worker and contribute events, calendar bands, and warnings. Drop a plugin folder into server/plugins/ and it appears here."
  >
    ${!loaded && html`<p class="bc-page-note">Loading…</p>`}
    ${loaded && plugins.length === 0 && html`<${EmptyState} text="No plugins found in server/plugins/." />`}
    ${plugins.map((p) => html`<${PluginRow} key=${p.id} p=${p} />`)}
  </${PageShell}>`;
}
