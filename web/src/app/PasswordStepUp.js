// One shared recent-password gate for sensitive browser-session actions. The
// server is authoritative; this only turns step_up_required into a usable,
// retry-in-place flow instead of a raw error.

import { html, useState } from '../../vendor/index.js';
import { api } from './api.js';

export function usePasswordStepUp() {
  const [pending, setPending] = useState(null);
  const [password, setPassword] = useState('');
  const [shown, setShown] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');

  const run = async (label, operation) => {
    try {
      return await operation();
    } catch (e) {
      if (!e || e.code !== 'step_up_required') throw e;
      setPassword('');
      setShown(false);
      setError('');
      return new Promise((resolve, reject) => setPending({ label, operation, resolve, reject }));
    }
  };

  const cancel = () => {
    if (pending) pending.resolve(null);
    setPending(null);
    setPassword('');
    setShown(false);
    setError('');
  };

  const submit = async (e) => {
    e.preventDefault();
    if (!pending || busy) return;
    setBusy(true);
    setError('');
    try {
      await api('/auth/step-up', { method: 'POST', body: { password } });
      const request = pending;
      setPending(null);
      setPassword('');
      setShown(false);
      try {
        request.resolve(await request.operation());
      } catch (retryError) {
        request.reject(retryError);
      }
    } catch (e) {
      setError(e && e.code === 'invalid_credentials' ? 'That password is not correct.' : (e.message || 'Could not confirm your password.'));
    } finally {
      setBusy(false);
    }
  };

  const prompt = pending && html`<div class="bc-stepup-backdrop" onClick=${(e) => { if (e.target === e.currentTarget) cancel(); }}>
    <form class="bc-stepup" role="dialog" aria-modal="true" aria-labelledby="bc-stepup-title" onSubmit=${submit}>
      <h2 id="bc-stepup-title">Confirm it’s you</h2>
      <p>Enter your Better-Cal password to ${pending.label}. You won’t be asked again for ten minutes.</p>
      <label class="bc-field">
        <span>Password</span>
        <span class="bc-login-pw">
          <input type=${shown ? 'text' : 'password'} value=${password} onInput=${(e) => setPassword(e.target.value)} required autofocus autocomplete="current-password" />
          <button type="button" class="bc-link-btn" onClick=${() => setShown(!shown)} aria-pressed=${!shown}>${shown ? 'Hide' : 'Show'}</button>
        </span>
      </label>
      ${error && html`<div class="bc-login-error" role="alert">${error}</div>`}
      <div class="bc-stepup-actions">
        <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>${busy ? 'Confirming…' : 'Confirm'}</button>
        <button type="button" class="bc-btn" disabled=${busy} onClick=${cancel}>Cancel</button>
      </div>
    </form>
  </div>`;

  return { run, prompt };
}
