// Where you are in a view. Pure (no DOM, no store) so the tests can load it.

import { epochDayOfKey, keyOfEpochDay } from './dates.js';

/**
 * The remembered day (anchor), or the middle of what's on screen once you've
 * scrolled away from it. Scrolling doesn't move the anchor (writing it back
 * as you scroll would fight Today's glide), so after a scroll it can be
 * weeks away: Next, the arrow keys, switching views and a reload started
 * from there and jumped back (#183).
 *
 * arrived: the view has shown the anchor since it was last set. Until then
 * the screen is still on its way there (a glide, a view just switched, a
 * report from before a jump), so the anchor stands: Next pressed twice
 * during a glide goes two weeks on, and Today then a view switch opens on
 * today.
 */
export function hereKeyOf(anchor, visibleDays, arrived = true) {
  const d = visibleDays;
  if (!arrived || !d || !d.start || !d.end || (anchor >= d.start && anchor <= d.end)) return anchor;
  return keyOfEpochDay(Math.floor((epochDayOfKey(d.start) + epochDayOfKey(d.end)) / 2));
}
