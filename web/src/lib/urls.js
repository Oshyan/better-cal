// External event links are untrusted provider data. Keep URL activation and
// provider branding behind the same small, pure policy.
import PATTERNS from './patterns.js';

const URL_TAIL = new Set([')', ',', '.', ';', ':', '!', '?', ']']);

export function safeWebUrl(value) {
  if (typeof value !== 'string') return null;
  const raw = value.trim();
  if (!raw || raw.length > 2048 || /[\u0000-\u001f\u007f]/.test(raw)) return null;
  try {
    const url = new URL(raw);
    if (!['http:', 'https:'].includes(url.protocol) || !url.hostname) return null;
    return raw;
  } catch {
    return null;
  }
}

function trimUrlTail(raw) {
  let end = Math.min(raw.length, 2048);
  while (end > 0 && URL_TAIL.has(raw[end - 1])) end--;
  return raw.slice(0, end);
}

function providerFor(url) {
  let parsed;
  try { parsed = new URL(url); } catch { return null; }
  if (parsed.protocol !== 'https:' || parsed.username || parsed.password || parsed.port || parsed.hostname.endsWith('.')) return null;
  const host = parsed.hostname.toLowerCase();
  if (parsed.pathname === '/' || parsed.pathname === '') return null;
  for (const provider of PATTERNS.meetings) {
    const ok = provider.hosts.some((base) => host === base || (provider.subdomains && host.endsWith('.' + base)));
    if (ok) return { url, name: provider.name, host };
  }
  return null;
}

/** The first positively classified HTTPS meeting URL in untrusted text. */
export function meetingLinkInText(text) {
  if (typeof text !== 'string' || text === '') return null;
  const candidates = text.match(/https:\/\/[^\s<>"']+/gi) || [];
  for (const candidate of candidates) {
    const raw = trimUrlTail(candidate);
    if (safeWebUrl(raw) === null) continue;
    const meeting = providerFor(raw);
    if (meeting) return meeting;
  }
  return null;
}
