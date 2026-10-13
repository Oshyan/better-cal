#!/usr/bin/env node
// The GitHub release page for a version: its CHANGELOG.md entry (what changed
// and the Operators line saying what an update needs), then how to update and
// the commit comparison. The update notice in the app links here, so this is
// what someone running Better-Cal reads before updating.
//
//   node scripts/release-notes.mjs --version 0.9.74 [--previous v0.9.73] [--write notes.md]
//
// Links in the changelog that are relative to the repository (SECURITY.md,
// docs/...) are made absolute at the release's tag, since a release page has
// no repository to be relative to.

import { readFileSync, writeFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

export const REPO_URL = 'https://github.com/Oshyan/better-cal';
export const UPDATING_URL = `${REPO_URL}/blob/main/docs/install.md#updating`;

/** The body of one version's section, without its heading; null when absent. */
export function changelogEntry(changelog, version) {
  const lines = changelog.split('\n');
  const head = new RegExp(`^## ${version.replace(/\./g, '\\.')}( |$)`);
  const start = lines.findIndex((l) => head.test(l));
  if (start < 0) return null;
  let end = lines.findIndex((l, i) => i > start && /^## /.test(l));
  if (end < 0) end = lines.length;
  return lines.slice(start + 1, end).join('\n').trim();
}

/** Repository-relative markdown links made absolute at a tag. */
export function absoluteLinks(md, tag) {
  return md.replace(/\]\((?!https?:|mailto:|#)([^)\s]+)\)/g, (_, path) => `](${REPO_URL}/blob/${tag}/${path.replace(/^\.\//, '')})`);
}

export function releaseNotes(changelog, version, previousTag = null) {
  const entry = changelogEntry(changelog, version);
  if (entry === null) throw new Error(`CHANGELOG.md has no ${version} section`);
  const tag = `v${version}`;
  return [
    absoluteLinks(entry, tag),
    '',
    '---',
    '',
    `**Updating:** back up your database first, then follow [Updating Better-Cal](${UPDATING_URL}). Skipping versions is fine: the migration applies everything in between. If you skip some, read their "Operators" lines too.`,
    ...(previousTag ? ['', `**Commits:** ${REPO_URL}/compare/${previousTag}...${tag}`] : []),
    '',
  ].join('\n');
}

if (process.argv[1] && fileURLToPath(import.meta.url) === process.argv[1]) {
  const arg = (name) => { const i = process.argv.indexOf(name); return i > 0 ? process.argv[i + 1] : null; };
  const version = arg('--version');
  if (!version || !/^\d+\.\d+\.\d+$/.test(version)) {
    console.error('usage: node scripts/release-notes.mjs --version X.Y.Z [--previous vX.Y.Z] [--write FILE]');
    process.exit(2);
  }
  const root = join(dirname(fileURLToPath(import.meta.url)), '..');
  const notes = releaseNotes(readFileSync(join(root, 'CHANGELOG.md'), 'utf8'), version, arg('--previous'));
  const out = arg('--write');
  if (out) writeFileSync(out, notes); else process.stdout.write(notes);
}
