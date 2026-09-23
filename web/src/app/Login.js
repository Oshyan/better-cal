// Login screen: email + password against POST /auth/login.

import { html, useState } from '../../vendor/index.js';
import { login, loadCalendars, loadConfig } from './api.js';
import { set, state } from './store.js';
import { runHandoff } from './handoff.js';
import { resyncPush } from './push.js';

export function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  // Visible by default. Masking protects against someone reading over your
  // shoulder, which is rare; it costs everyone typos they cannot see, every
  // time. Hide is one click away for the shared-screen moment.
  const [shown, setShown] = useState(true);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      await login(email, password);
      await loadCalendars();
      loadConfig(); // fire-and-forget
      resyncPush(); // this browser's reminders survive a password reset
      // A share or deep link that arrived while signed out is still owed.
      const handoff = state.pendingHandoff;
      if (handoff) {
        set({ pendingHandoff: null });
        runHandoff(handoff).catch(() => { /* best-effort */ });
      }
    } catch (err) {
      setError(err.status === 401 ? 'Wrong email or password' : err.message);
    }
    setBusy(false);
  };

  return html`<div class="bc-login-wrap">
    <form class="bc-login" onSubmit=${submit}>
      <h1 class="bc-login-brand">Better-Cal</h1>
      <label class="bc-field">
        <span>Email</span>
        <input type="email" value=${email} onInput=${(e) => setEmail(e.target.value)} required autofocus autocomplete="email" />
      </label>
      <label class="bc-field">
        <span>Password</span>
        <span class="bc-login-pw">
          <input type=${shown ? 'text' : 'password'} value=${password} onInput=${(e) => setPassword(e.target.value)} required autocomplete="current-password" />
          <button type="button" class="bc-link-btn" onClick=${() => setShown(!shown)} aria-pressed=${!shown}>${shown ? 'Hide' : 'Show'}</button>
        </span>
      </label>
      ${error && html`<div class="bc-login-error" role="alert">${error}</div>`}
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>${busy ? 'Signing in' : 'Sign in'}</button>
    </form>
  </div>`;
}
