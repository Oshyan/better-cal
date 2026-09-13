// Relative age of an ISO instant, for "failing since 3h ago" style notices.
// Coarse on purpose: these describe streaks, not schedules. Pure.

export function fmtSince(iso, now = Date.now()) {
  if (!iso) return 'unknown';
  const t = new Date(iso).getTime();
  if (Number.isNaN(t)) return 'unknown';
  const s = Math.max(0, Math.round((now - t) / 1000));
  if (s < 90) return 'just now';
  const m = Math.round(s / 60);
  if (m < 60) return m + 'm ago';
  const h = Math.round(m / 60);
  if (h < 36) return h + 'h ago';
  const d = Math.round(h / 24);
  return d + 'd ago';
}
