// Window math for the notification deep link (/?event=instanceId&at=ISO).
// The server emits &at= (the occurrence's start instant) alongside the id so
// the client can load a window that is guaranteed to contain the occurrence.
// Notifications sent before &at= existed fall back to the compact UTC
// timestamp the server embeds after the colon in the instanceId it generated
// (eventId:YYYYMMDDTHHMMSSZ), and finally to "now".

import { toISOWithOffset } from './dates.js';

const DAY_MS = 86400000;
// A reminder may arrive 28 days early. Sixty days preserves that legitimate
// near-term path while ensuring an attacker-controlled year cannot turn one
// URL into thousands of paginated occurrence requests.
const BROAD_WINDOW_DISTANCE_MS = 60 * DAY_MS;

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
 * - broad: for nearby occurrences only, spans now and the occurrence with
 *   margin and is merged into the normal occurrence cache
 * - tight: +/-36h around the occurrence, used for the targeted retry fetch
 */
export function deepLinkWindows(instanceId, atISO, nowMs) {
  const anchorMs = deepLinkAnchorMs(instanceId, atISO, nowMs);
  const nearby = Math.abs(anchorMs - nowMs) <= BROAD_WINDOW_DISTANCE_MS;
  return {
    anchorMs,
    broad: nearby ? {
      start: toISOWithOffset(new Date(Math.min(anchorMs, nowMs) - 2 * DAY_MS)),
      end: toISOWithOffset(new Date(Math.max(anchorMs, nowMs) + 16 * DAY_MS)),
    } : null,
    tight: {
      start: toISOWithOffset(new Date(anchorMs - 1.5 * DAY_MS)),
      end: toISOWithOffset(new Date(anchorMs + 1.5 * DAY_MS)),
    },
  };
}
