import { html, useState } from '../../vendor/index.js';
import { useStore, toast, set } from './store.js';
import { checkUpdates, dismissUpdate } from './api.js';
import { reloadForUpdate } from './drafts.js';
import { updateStatusText, checkResultText, bannerText, INSTALL_UPDATING_URL } from '../lib/updatetext.js';

// The wording lives in lib/updatetext.js: a step towards the newest release
// behind a signing-key change, a copy blocked on the newest it can verify,
// or plainly available.
export function UpdateBanner() {
  const update = useStore((s) => s.updates);
  const [busy, setBusy] = useState(false);
  const text = bannerText(update);
  if (!text) return null;

  const required = !!update.securityRequired;
  const dismiss = async () => {
    setBusy(true);
    try { await dismissUpdate(); }
    catch (e) { toast(e.message || 'Could not dismiss update notice', { error: true }); }
    finally { setBusy(false); }
  };
  return html`<div class=${'bc-sysbanner bc-updatebanner' + (required ? ' is-required' : '')} role=${required ? 'alert' : 'status'}>
    <span>${text}</span>
    ${update.blocked && html`<a class="bc-btn" href=${INSTALL_UPDATING_URL} target="_blank" rel="noopener">How to update</a>`}
    ${update.releaseUrl && !update.blocked && html`<a class="bc-btn" href=${update.releaseUrl} target="_blank" rel="noopener">View release</a>`}
    ${update.dismissible && html`<button type="button" class="bc-icon-btn" disabled=${busy} aria-label="Dismiss this release" title="Dismiss this release" onClick=${dismiss}>×</button>`}
  </div>`;
}

// This page runs an older version than the one now installed on this device
// (main.js). Not the release banner above, which is about the server.
export function AppReloadBanner() {
  const ready = useStore((s) => s.appUpdateReady);
  if (!ready) return null;
  // At the top, under the toolbar, like the stopped-worker warning: at the
  // bottom of a large screen it went unseen. Not a dialog, since the update
  // also applies itself once the app is out of sight.
  return html`<div class="bc-sysbanner bc-sysbanner-top is-info is-update" role="status">
    <span>Better-Cal was updated. Reload to use the new version.</span>
    <button type="button" class="bc-btn bc-btn-primary" onClick=${reloadForUpdate}>Reload</button>
    <button type="button" class="bc-icon-btn" aria-label="Not now" title="Not now" onClick=${() => set({ appUpdateReady: false })}>×</button>
  </div>`;
}

export function OfflineBanner() {
  const offline = useStore((s) => s.offlineReadOnly);
  if (!offline) return null;
  return html`<div class="bc-sysbanner" role="status">
    <span>Offline: showing saved calendar data in read-only mode. Changes need a connection.</span>
  </div>`;
}

export function UpdateSettings({ update }) {
  const [checking, setChecking] = useState(false);
  const check = async () => {
    setChecking(true);
    try {
      const next = await checkUpdates();
      toast(checkResultText(next));
    } catch (e) {
      toast(e.message || 'Could not check for updates', { error: true });
    } finally {
      setChecking(false);
    }
  };
  const status = updateStatusText(update);
  return html`<span class="bc-set-value">${status}${update && update.lastError ? html`<br /><span class="bc-sys-bad">Last check failed: ${update.lastError}</span>` : ''}</span>
    <button type="button" class="bc-btn" disabled=${checking} onClick=${check}>${checking ? 'Checking…' : 'Check now'}</button>
    ${update && update.blocked && html`<a class="bc-btn" href=${INSTALL_UPDATING_URL} target="_blank" rel="noopener">How to update</a>`}
    ${update && update.releaseUrl && update.available && html`<a class="bc-btn" href=${update.releaseUrl} target="_blank" rel="noopener">View release</a>`}`;
}
