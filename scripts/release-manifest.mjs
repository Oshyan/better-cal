#!/usr/bin/env node

import { readFileSync, writeFileSync } from 'node:fs';

const args = process.argv.slice(2);
const value = (flag) => {
  const index = args.indexOf(flag);
  return index >= 0 ? args[index + 1] : null;
};
const semver = (v) => typeof v === 'string' && /^\d+\.\d+\.\d+$/.test(v);
const compare = (a, b) => {
  const aa = a.split('.').map(Number);
  const bb = b.split('.').map(Number);
  for (let i = 0; i < 3; i++) if (aa[i] !== bb[i]) return aa[i] - bb[i];
  return 0;
};
const canonical = (version, priority, minimumSecureVersion) => JSON.stringify({
  schemaVersion: 1,
  version,
  priority,
  minimumSecureVersion,
}) + '\n';

function validate(manifest) {
  if (!manifest || Object.keys(manifest).join(',') !== 'schemaVersion,version,priority,minimumSecureVersion'
      || manifest.schemaVersion !== 1 || !semver(manifest.version)
      || !['routine', 'recommended', 'security'].includes(manifest.priority)
      || !semver(manifest.minimumSecureVersion)
      || compare(manifest.minimumSecureVersion, manifest.version) > 0
      || (manifest.priority === 'security' && manifest.minimumSecureVersion !== manifest.version)) {
    throw new Error('invalid Better-Cal release manifest');
  }
}

if (args[0] === '--write') {
  const path = args[1];
  const manifest = {
    schemaVersion: 1,
    version: value('--version'),
    priority: value('--priority'),
    minimumSecureVersion: value('--minimum-secure-version'),
  };
  validate(manifest);
  writeFileSync(path, canonical(manifest.version, manifest.priority, manifest.minimumSecureVersion));
  console.log(`release manifest: ${manifest.version} (${manifest.priority}, secure floor ${manifest.minimumSecureVersion})`);
} else if (args[0] === '--verify') {
  const raw = readFileSync(args[1], 'utf8');
  const manifest = JSON.parse(raw);
  validate(manifest);
  if (raw !== canonical(manifest.version, manifest.priority, manifest.minimumSecureVersion)) throw new Error('release manifest is not canonical');
  const expected = value('--version');
  if (expected && manifest.version !== expected) throw new Error(`manifest version ${manifest.version} does not match ${expected}`);
  console.log(`release manifest verified: ${manifest.version}`);
} else {
  console.error('usage: release-manifest.mjs --write FILE --version X.Y.Z --priority routine|recommended|security --minimum-secure-version X.Y.Z');
  console.error('   or: release-manifest.mjs --verify FILE [--version X.Y.Z]');
  process.exit(2);
}
