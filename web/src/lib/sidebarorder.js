// The order of the sidebar's groups: your folders, All calendars (Other
// calendars on a phone), People and Plugins, in any order you choose. Kept
// as one list of keys in Settings (sidebarOrder): 'f' + a folder's id, 'all',
// 'people', 'plugins'. Pure, so the smoke suite can check it.
//
// A group the list doesn't name yet (a new folder, or before any order was
// chosen) takes its default place: folders in their own order, then All
// calendars, People and Plugins. A key for a folder that no longer exists is
// skipped.

export const BUILT_IN_GROUPS = ['all', 'people', 'plugins'];

export function folderKey(folder) {
  return 'f' + folder.id;
}

/**
 * Every group's key, in sidebar order.
 * @param {{id:number}[]} folders in their stored order
 * @param {string[]|undefined} order settings.sidebarOrder
 */
export function sidebarGroups(folders, order) {
  const known = new Set([...folders.map(folderKey), ...BUILT_IN_GROUPS]);
  const out = [];
  for (const key of Array.isArray(order) ? order : []) {
    if (known.has(key) && !out.includes(key)) out.push(key);
  }
  // Unlisted folders go in before the first built-in group the list placed
  // after every folder it names: a new folder appears with the others, not
  // below People.
  const missingFolders = folders.map(folderKey).filter((k) => !out.includes(k));
  if (missingFolders.length) {
    const lastFolder = out.reduce((at, k, i) => (k.startsWith('f') ? i : at), -1);
    const at = out.findIndex((k, i) => i > lastFolder && BUILT_IN_GROUPS.includes(k));
    out.splice(at === -1 ? out.length : at, 0, ...missingFolders);
  }
  for (const key of BUILT_IN_GROUPS) if (!out.includes(key)) out.push(key);
  return out;
}

/** The order with one group moved to a new index (in the full order). */
export function moveGroup(keys, key, toIndex) {
  const from = keys.indexOf(key);
  if (from === -1) return keys.slice();
  const out = keys.slice();
  out.splice(from, 1);
  out.splice(Math.max(0, Math.min(toIndex, out.length)), 0, key);
  return out;
}
