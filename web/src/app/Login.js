// Login screen: email + password against POST /auth/login.

import { html, useState } from '../../vendor/index.js';
import { login } from './api.js';
import { set, state } from './store.js';
import { loadSession, startSession } from './session.js';

export function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [shown, setShown] = useState(false);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError('');
    try {
      await login(email, password);
      // The same session a page load starts (session.js): people, views,
      // plugins, the device's zone and the rest, not just the calendars.
      await loadSession();
      // A share or deep link that arrived while signed out is still owed.
      const handoff = state.pendingHandoff;
      if (handoff) set({ pendingHandoff: null });
      startSession(handoff);
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
