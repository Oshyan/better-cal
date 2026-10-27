// Plugins ops page (Manage → Plugins): the whole observability story for the
// plugin system. Per plugin: enable toggle, declared permissions, last run,
// object counts, warnings, declarative settings, run-now, uninstall with an
// impact preview. Nothing here executes plugin code; it reads what worker
// runs wrote and flips flags.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { state, set, toast, useStore } from './store.js';
import { api, refreshWindow, loadCalendars } from './api.js';
import { PageShell, EmptyState, Skeleton } from './PageShell.js';
import { Icon } from '../ui/icons.js';
import { SchemaForm } from './SchemaForm.js';
import { OwnCalendarsList } from './OwnCalendar.js';

async function reloadPlugins() {
  const d = await api('/plugins');
  set({ plugins: d.plugins || [], pluginSeq: state.pluginSeq + 1 });
}

// After a save or Run now, follow the plugin until that run has finished, so
// what it found (a database's fields, say) appears without a manual reload.
// A run that starts right away (the server runs it after answering) is
// checked every 1.5 seconds; one left to the worker's next minute every 4,
// giving up after two minutes. It keeps following after you go back to the
// calendar, which then reloads the plugin's events as soon as the run is
// done.
async function followRunAfterSave(id, lastRunAt, now = false) {
  const every = now ? 1500 : 4000;
  for (let i = 0; i < 120000 / every; i++) {
    await new Promise((r) => setTimeout(r, every));
    await reloadPlugins();
    const p = (state.plugins || []).find((x) => x.id === id);
    if (p && p.lastRun && p.lastRun.at !== lastRunAt) {
      // The run has changed the plugin's events: the calendar picks them
      // up now, not at its next 30-second check.
      window.dispatchEvent(new Event('bc-check-changes'));
      return;
    }
  }
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
  // Arriving from a calendar's "Plugin settings" link opens this plugin's
  // settings (or, for one of its own calendars, that calendar's) and brings
  // them into view. Watched, so a link followed on this page works too.
  const focus = useStore((s) => s.pluginFocus);
  const [openSettings, setOpenSettings] = useState(focus === p.id && state.pluginCalendarFocus == null);
  const [warnings, setWarnings] = useState(null);
  const [busy, setBusy] = useState(false);
  const cardRef = useRef(null);
  useEffect(() => {
    if (focus !== p.id) return;
    set({ pluginFocus: null });
    if (state.pluginCalendarFocus == null) setOpenSettings(true);
    if (cardRef.current) cardRef.current.scrollIntoView({ block: 'start' });
  }, [focus]);

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

  const needs = p.needsSetup || [];
  const needsText = 'Fill in ' + needs.join(' and ') + ' to start.';
  // Turning on a plugin that still needs settings opens them: it waits for
  // them rather than running (and failing) every minute.
  const toggle = () => act(
    () => api('/plugins/' + p.id + '/' + (p.enabled ? 'disable' : 'enable'), { method: 'POST' }),
    p.enabled ? p.name + ' turned off' : p.name + ' turned on. ' + (needs.length > 0 ? needsText : 'Its first run starts within a minute.'),
  ).then(() => { if (!p.enabled && needs.length > 0) setOpenSettings(true); });

  const runNow = () => act(async () => {
    const lastRunAt = p.lastRun ? p.lastRun.at : null;
    const d = await api('/plugins/' + p.id + '/run', { method: 'POST' });
    if ((d.queued || []).length === 0 && (d.alreadyQueued || []).length > 0) {
      toast(p.name + ' is already running or about to.');
    } else {
      toast(d.now ? 'Running ' + p.name + ' now. This page updates when it\'s done.' : p.name + ' runs within a minute.');
    }
    followRunAfterSave(p.id, lastRunAt, !!d.now).catch(() => {});
  });

  // Uninstalling asks here, in the card, rather than in two browser dialogs:
  // what goes, and the plain choices, with Cancel focused (Esc cancels).
  const [confirming, setConfirming] = useState(false);
  const cancelRef = useRef(null);
  useEffect(() => { if (confirming && cancelRef.current) cancelRef.current.focus(); }, [confirming]);
  const uninstall = (deleteCalendars) => {
    setConfirming(false);
    setOpenSettings(false);
    act(
      () => api('/plugins/' + p.id + '/uninstall', { method: 'POST', body: { deleteCalendars } }),
      p.name + "'s data deleted",
    ).then(() => loadCalendars());
  };
  const c = p.counts;
  const secretWord = (p.settingsSchema || []).some((f) => f.type === 'secret') ? ' (including any key)' : '';
  const removes = ['its settings' + secretWord + ' and stored data', c.ranges > 0 && c.ranges + ' bands', c.warnings > 0 && c.warnings + ' warnings'].filter(Boolean);

  const loadWarnings = async () => {
    if (warnings !== null) { setWarnings(null); return; }
    const d = await api('/plugins/' + p.id + '/warnings');
    setWarnings(d.warnings || []);
  };

  const lr = p.lastRun;
  return html`<div class="bc-plugin-card${p.enabled ? '' : ' is-off'}" ref=${cardRef}>
    <div class="bc-plugin-head">
      <label class="bc-check bc-plugin-toggle" title=${p.enabled ? 'Disable' : 'Enable'}>
        <input type="checkbox" checked=${p.enabled} disabled=${busy || p.errors.length > 0} onChange=${toggle} />
      </label>
      <div class="bc-plugin-title">
        ${p.enabled && p.settingsSchema.length > 0
          ? html`<button type="button" class="bc-plugin-name bc-link-btn" aria-expanded=${openSettings} title="Settings" onClick=${() => setOpenSettings(!openSettings)}>${p.name}</button>`
          : html`<span class="bc-plugin-name">${p.name}</span>`}
        <span class="bc-plugin-version">v${p.version}</span>
        ${p.permissions.map((perm) => html`<span key=${perm} class="bc-plugin-perm">${perm}</span>`)}
      </div>
      <div class="bc-plugin-tools">
        ${p.enabled && html`<button type="button" class="bc-btn" disabled=${busy || needs.length > 0} title=${needs.length > 0 ? needsText : 'Run its jobs now'} onClick=${runNow}>Run now</button>`}
        ${p.enabled && p.settingsSchema.length > 0 && html`<button type="button" class="bc-icon-btn${openSettings ? ' is-open' : ''}" title="Settings" onClick=${() => setOpenSettings(!openSettings)}><${Icon} name="settings" size=${13} /></button>`}
        ${p.installed && html`<button type="button" class="bc-icon-btn" title="Delete data" disabled=${busy} onClick=${() => setConfirming(true)}><${Icon} name="trash" size=${13} /></button>`}
      </div>
    </div>
    ${confirming && html`<div class="bc-plugin-confirm" role="alertdialog" aria-label=${'Delete ' + p.name + ' data'}
      onKeyDown=${(e) => { if (e.key === 'Escape') { e.stopPropagation(); setConfirming(false); } }}>
      <p><strong>Delete ${p.name}'s data?</strong> This turns ${p.name} off and deletes ${removes.join(', ').replace(/, ([^,]*)$/, ' and $1')}. To pause it and keep everything, untick it instead.${c.events > 0
        ? ' Its ' + c.events + ' events can stay as an ordinary local calendar that no longer syncs (turning ' + p.name + ' on again starts fresh beside it), or be deleted, which can\'t be undone.' : ''}</p>
      <div class="bc-plugin-confirm-actions">
        <button type="button" class="bc-btn" ref=${cancelRef} onClick=${() => setConfirming(false)}>Cancel</button>
        ${c.events > 0
          ? html`<button type="button" class="bc-btn" onClick=${() => uninstall(false)}>Delete data, keep events as a local calendar</button>
            <button type="button" class="bc-btn bc-btn-danger" onClick=${() => uninstall(true)}>Delete data and events</button>`
          : html`<button type="button" class="bc-btn bc-btn-danger" onClick=${() => uninstall(false)}>Delete data</button>`}
      </div>
    </div>`}
    <p class="bc-plugin-desc">${p.description}</p>
    ${p.errors.length > 0 && html`<div class="bc-plugin-errors">${p.errors.map((e) => html`<div key=${e}><${Icon} name="warning" size=${12} /> ${e}</div>`)}</div>`}
    ${p.disabledReason && html`<div class="bc-plugin-errors"><div><${Icon} name="warning" size=${12} /> ${p.disabledReason}</div></div>`}
    ${p.enabled && html`<div class="bc-plugin-status">
      ${needs.length > 0
        ? html`<span class="bc-plugin-run is-setup">${needsText} ${!openSettings && html`<button type="button" class="bc-link-btn" onClick=${() => setOpenSettings(true)}>Open settings</button>`}</span>`
        : lr
        ? html`<span class=${'bc-plugin-run is-' + lr.outcome} title=${lr.logTail}>${lr.outcome === 'ok' ? 'Last run' : 'Last run ' + lr.outcome} · ${lr.job} · ${fmtAgo(lr.at)}${lr.durationMs != null ? ' · ' + lr.durationMs + ' ms' : ''}</span>`
        : html`<span class="bc-plugin-run">No runs yet. The first starts within a minute.</span>`}
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
        ${w.message}${w.fix ? html`<span class="bc-plugin-fix">. ${w.fix}</span>` : ''}
      </li>`)}
      ${warnings.length === 0 && html`<li>No open warnings.</li>`}
    </ul>`}
    ${p.enabled && p.ownCalendars && html`<${OwnCalendarsList} plugin=${p} />`}
    ${openSettings && p.enabled && html`<${SchemaForm}
      schema=${p.settingsSchema}
      values=${p.settings}
      onSave=${async (draft) => {
        try {
          const lastRunAt = p.lastRun ? p.lastRun.at : null;
          const d = await api('/plugins/' + p.id + '/settings', { method: 'PATCH', body: draft });
          const following = (d.queued || []).length > 0;
          toast(p.name + (following
            ? (d.now ? ' settings saved. It\'s running now, and this page updates when it\'s done.' : ' settings saved. It runs within a minute, and this page updates when it has.')
            : ' settings saved. They take effect on its next run.'));
          await reloadPlugins();
          if (following) followRunAfterSave(p.id, lastRunAt, !!d.now).catch(() => {});
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
    ${!loaded && plugins.length === 0 && html`<${Skeleton} rows=${5} />`}
    ${loaded && plugins.length === 0 && html`<${EmptyState} text="No plugins found in server/plugins/." />`}
    ${plugins.map((p) => html`<${PluginRow} key=${p.id} p=${p} />`)}
  </${PageShell}>`;
}
