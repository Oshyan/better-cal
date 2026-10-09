#!/usr/bin/env node

import { readFileSync, writeFileSync } from 'node:fs';

const [, , auditPath, outdatedPath, repoPath, outputPath] = process.argv;
const parseObject = (path, label) => {
  let value;
  try { value = JSON.parse(readFileSync(path, 'utf8')); }
  catch (error) { throw new Error(`${label} is missing or invalid JSON: ${error.message}`); }
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(`${label} is not a JSON object`);
  return value;
};
const audit = parseObject(auditPath, 'Composer audit report');
const outdated = parseObject(outdatedPath, 'Composer outdated report');
const repo = parseObject(repoPath, 'Repository dependency report');
if (!Object.hasOwn(audit, 'advisories') || !audit.advisories || typeof audit.advisories !== 'object'
    || (Array.isArray(audit.advisories) && audit.advisories.length !== 0)) {
  throw new Error('Composer audit advisories are malformed');
}
if (audit.abandoned !== undefined && (!audit.abandoned || typeof audit.abandoned !== 'object')) {
  throw new Error('Composer audit abandoned packages are malformed');
}
if (!Array.isArray(outdated.installed)) throw new Error('Composer outdated report has no installed-package list');
if (!Array.isArray(repo.updates)) throw new Error('Repository dependency report has no update list');
const advisories = Object.entries(audit.advisories || {}).flatMap(([packageName, items]) =>
  (Array.isArray(items) ? items : [items]).filter(Boolean).map((item) => ({ ...item, packageName }))
);
const abandoned = audit.abandoned ? Object.entries(audit.abandoned) : [];
const composer = outdated.installed;
const extra = repo.updates;
const issueCount = advisories.length + abandoned.length + composer.length + extra.length;
if (issueCount === 0) {
  writeFileSync(outputPath, '');
  process.exit(0);
}
const lines = [
  '# Dependency review available', '',
  'This automated inventory found dependency changes to review. It does not authorize automatic merging.', '',
];
if (advisories.length) {
  lines.push('## Security advisories', '');
  for (const item of advisories) lines.push(`- ${item.packageName || item.package || 'Composer package'}: ${item.title || item.advisoryId || 'advisory'}`);
  lines.push('');
}
if (abandoned.length) {
  lines.push('## Abandoned Composer packages', '');
  for (const [name, replacement] of abandoned) lines.push(`- ${name}${replacement ? ` (suggested replacement: ${replacement})` : ''}`);
  lines.push('');
}
if (composer.length) {
  lines.push('## Composer updates', '');
  for (const item of composer) lines.push(`- ${item.name}: ${item.version} → ${item.latest}`);
  lines.push('');
}
if (extra.length) {
  lines.push('## Other pinned dependencies', '');
  for (const item of extra) lines.push(`- ${item.kind} — ${item.name}: ${item.current} → ${item.latest}`);
  lines.push('');
}
lines.push('Review the project dependency policy before updating. Security fixes take priority; non-security updates are assessed for relevant fixes, compatibility, maintenance value, and regression cost.');
writeFileSync(outputPath, lines.join('\n') + '\n');
