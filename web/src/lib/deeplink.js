// Window math for the notification deep link (/?event=instanceId&at=ISO).
// The server emits &at= (the occurrence's start instant) alongside the id so
// the client can load a window that is guaranteed to contain the occurrence.
// Notifications sent before &at= existed fall back to the compact UTC
// timestamp the server embeds after the colon in the instanceId it generated
// (eventId:YYYYMMDDTHHMMSSZ), and finally to "now".

import { toISOWithOffset } from './dates.js';

const DAY_MS = 86400000;

/** Epoch ms of the occurrence the deep link points at (best effort). */
export function deepLinkAnchorMs(instanceId, atISO, nowMs) {
  if (atISO) {
    const t = Date.parse(atISO);
    if (!Number.isNaN(t)) return t;
  }
  const m = /^\d+:(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z$/.exec(instanceId || '');
  if (m) {
    return Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
  }
  return nowMs;
}

/**
 * The two windows the deep-link handler loads:
 * - broad: spans now and the occurrence with margin, merged into the normal
 *   occurrence cache (the app wants this range anyway when anchor is near now)
 * - tight: +/-36h around the occurrence, used for the targeted retry fetch
 */
export function deepLinkWindows(instanceId, atISO, nowMs) {
  const anchorMs = deepLinkAnchorMs(instanceId, atISO, nowMs);
  return {
    anchorMs,
    broad: {
      start: toISOWithOffset(new Date(Math.min(anchorMs, nowMs) - 2 * DAY_MS)),
      end: toISOWithOffset(new Date(Math.max(anchorMs, nowMs) + 16 * DAY_MS)),
    },
    tight: {
      start: toISOWithOffset(new Date(anchorMs - 1.5 * DAY_MS)),
      end: toISOWithOffset(new Date(anchorMs + 1.5 * DAY_MS)),
    },
  };
}
