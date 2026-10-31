// The order of calendars in a sidebar group (#187), and where a dragged
// calendar lands. Pure (no DOM, no store) so the tests can load it.

const byName = (a, b) => String(a.name).localeCompare(String(b.name), undefined, { sensitivity: 'base', numeric: true }) || a.id - b.id;

/**
 * A group's calendars in its order: a folder's own (folder.calendarIds, as
 * the server sends it), the calendar list's for the main list, or A to Z
 * when the group's sort is 'az'. A calendar filed in the folder but not in
 * its list yet (a change still on its way) goes last.
 */
export function sortedCalendars(calendars, group) {
  let list;
  if (group && group.id != null) {
    const byId = new Map(calendars.map((c) => [c.id, c]));
    const ids = Array.isArray(group.calendarIds) ? group.calendarIds : [];
    list = ids.map((id) => byId.get(id)).filter((c) => c && (c.folderIds || []).includes(group.id));
    for (const c of calendars) if ((c.folderIds || []).includes(group.id) && !ids.includes(c.id)) list.push(c);
  } else {
    list = [...calendars];
  }
  return group && group.sort === 'az' ? list.sort(byName) : list;
}

// Where a calendar goes in a list without it: before the one it's over,
// or after it on its lower half; at the end when over none.
function placeIn(order, calId, overId, after) {
  if (overId === calId) return order.indexOf(calId);
  const rest = order.filter((id) => id !== calId);
  const i = overId == null ? -1 : rest.indexOf(overId);
  return i < 0 ? rest.length : i + (after ? 1 : 0);
}

/**
 * What dropping a dragged calendar does, or null for nothing:
 * - {kind: 'place', folderId, at, from}: into a folder at a place in its
 *   own order, out of folder `from` when it came from one (not with
 *   Option held, `copy`, which files it in both);
 * - {kind: 'unfile', folderId}: dragged from a folder to the main list,
 *   out of that folder;
 * - {kind: 'list', at}: a new place in the main list.
 * from and to are a folder id or 'all' (the main list); order is the
 * target's own order of calendar ids (A to Z or not); overId the row the
 * pointer is over, and after whether on its lower half. A group listed
 * A to Z has no places to drop at: within it nothing moves, and one
 * dropped in from elsewhere goes at the end of its own order.
 */
export function calendarDrop({ calId, from, to, toSort, order, overId = null, after = false, copy = false }) {
  if (to === 'all') {
    if (from !== 'all') return { kind: 'unfile', folderId: from };
    if (toSort === 'az') return null;
    const at = placeIn(order, calId, overId, after);
    return at === order.indexOf(calId) ? null : { kind: 'list', at };
  }
  if (typeof to !== 'number') return null;
  const same = from === to;
  if (same && toSort === 'az') return null;
  const at = toSort === 'az' ? order.filter((id) => id !== calId).length : placeIn(order, calId, overId, after);
  if (same && at === order.indexOf(calId)) return null;
  return { kind: 'place', folderId: to, at, from: !same && typeof from === 'number' && !copy ? from : null };
}
