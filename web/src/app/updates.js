import { html, useState } from '../../vendor/index.js';
import { useStore, toast } from './store.js';
import { checkUpdates, dismissUpdate } from './api.js';

export function UpdateBanner() {
  const update = useStore((s) => s.updates);
  const [busy, setBusy] = useState(false);
  if (!update || !update.showBanner || !update.available) return null;

  const required = !!update.securityRequired;
  const recommended = update.priority === 'recommended';
  const text = required
    ? `A Better-Cal security update is required. Update to ${update.minimumSecureVersion} or newer.`
    : recommended
      ? `Better-Cal ${update.latestVersion} is recommended.`
      : `Better-Cal ${update.latestVersion} is available.`;
  const dismiss = async () => {
    setBusy(true);
    try { await dismissUpdate(); }
    catch (e) { toast(e.message || 'Could not dismiss update notice', { error: true }); }
    finally { setBusy(false); }
  };
  return html`<div class=${'bc-sysbanner bc-updatebanner' + (required ? ' is-required' : '')} role=${required ? 'alert' : 'status'}>
    <span>${text}</span>
    ${update.releaseUrl && html`<a class="bc-btn" href=${update.releaseUrl} target="_blank" rel="noopener">View release</a>`}
    ${update.dismissible && html`<button type="button" class="bc-icon-btn" disabled=${busy} aria-label="Dismiss this release" title="Dismiss this release" onClick=${dismiss}>×</button>`}
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
      toast(next.available ? `Better-Cal ${next.latestVersion} is available` : 'Better-Cal is up to date');
    } catch (e) {
      toast(e.message || 'Could not check for updates', { error: true });
    } finally {
      setChecking(false);
    }
  };
  const status = !update || !update.checkedAt
    ? 'Not checked yet'
    : update.securityRequired
      ? `Security update required: ${update.minimumSecureVersion} or newer`
      : update.available
        ? `Version ${update.latestVersion} is available`
        : `Up to date (checked ${new Date(update.checkedAt).toLocaleString()})`;
  return html`<span class="bc-set-value">${status}${update && update.lastError ? html`<br /><span class="bc-sys-bad">Last check failed: ${update.lastError}</span>` : ''}</span>
    <button type="button" class="bc-btn" disabled=${checking} onClick=${check}>${checking ? 'Checking…' : 'Check now'}</button>
    ${update && update.releaseUrl && update.available && html`<a class="bc-btn" href=${update.releaseUrl} target="_blank" rel="noopener">View release</a>`}`;
}
