// Login screen: email + password against POST /auth/login.

import { html, useState } from '../../vendor/index.js';
import { login, loadCalendars, loadConfig } from './api.js';

export function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
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
        <input type="password" value=${password} onInput=${(e) => setPassword(e.target.value)} required autocomplete="current-password" />
      </label>
      ${error && html`<div class="bc-login-error" role="alert">${error}</div>`}
      <button type="submit" class="bc-btn bc-btn-primary" disabled=${busy}>${busy ? 'Signing in' : 'Sign in'}</button>
    </form>
  </div>`;
}
