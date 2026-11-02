// What the app says about releases (app/updates.js), from GET /updates
// (docs/api-contract.md). Pure, so the wording is tested (tests/smoke.mjs).
//
// Three states besides "up to date":
// - available: latestVersion is newer than this copy;
// - a step: newer releases up to newestVersion are signed with a key this
//   copy doesn't list, latestVersion brings it, so the update takes two runs;
// - blocked: this copy already runs the newest release it can verify, and
//   the newer ones are signed with a key it doesn't list, so the updater
//   can't go on and the update is by hand. bin/update.php concludes the same.

export const INSTALL_UPDATING_URL = 'https://github.com/Oshyan/better-cal/blob/main/docs/install.md#updating';

function steppingStone(update) {
  const newest = update && update.newestVersion;
  return newest && newest !== update.latestVersion ? `; releases up to ${newest} need it first` : '';
}

function blockedText(update) {
  return `Better-Cal ${update.newestVersion} is out, but this copy can't verify it: it's signed with a key this copy doesn't list. Update by hand (Updating in docs/install.md).`;
}

/** Settings, About: the Updates row. */
export function updateStatusText(update) {
  if (!update || !update.checkedAt) return 'Not checked yet';
  if (update.securityRequired) return `Security update required: ${update.minimumSecureVersion} or newer`;
  if (update.available) return `Version ${update.latestVersion} is available${steppingStone(update)}`;
  if (update.blocked) return blockedText(update);
  return `Up to date (checked ${new Date(update.checkedAt).toLocaleString()})`;
}

/** The toast after Check now. */
export function checkResultText(next) {
  if (next.available) return `Better-Cal ${next.latestVersion} is available${steppingStone(next)}`;
  if (next.blocked) return blockedText(next);
  return 'Better-Cal is up to date';
}

/** The release banner's line, or null when there is nothing to show. */
export function bannerText(update) {
  if (!update || !update.showBanner || !(update.available || update.blocked)) return null;
  if (update.securityRequired) return `A Better-Cal security update is required. Update to ${update.minimumSecureVersion} or newer.`;
  if (update.blocked) return blockedText(update);
  const recommended = update.priority === 'recommended';
  return `Better-Cal ${update.latestVersion} is ${recommended ? 'recommended' : 'available'}${steppingStone(update)}.`;
}
