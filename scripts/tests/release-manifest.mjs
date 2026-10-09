#!/usr/bin/env node

import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const script = join(root, 'scripts', 'release-manifest.mjs');
const dir = mkdtempSync(join(tmpdir(), 'bc-release-manifest.'));
const manifest = join(dir, 'bettercal-release.json');
let passed = 0;
let failed = 0;
const check = (name, condition) => {
  if (condition) { passed++; console.log(`  ok  ${name}`); }
  else { failed++; console.error(`FAIL  ${name}`); }
};
const run = (...args) => spawnSync(process.execPath, [script, ...args], { encoding: 'utf8' });

try {
  const write = run('--write', manifest, '--version', '1.2.3', '--priority', 'recommended', '--minimum-secure-version', '1.2.0');
  const verify = run('--verify', manifest, '--version', '1.2.3');
  check('canonical manifest writes and verifies', write.status === 0 && verify.status === 0);

  const badFloor = run('--write', manifest, '--version', '1.2.3', '--priority', 'security', '--minimum-secure-version', '1.2.2');
  check('security release requires its own version as the secure floor', badFloor.status !== 0);

  writeFileSync(manifest, '{"schemaVersion":1,"version":"1.2.3","priority":"recommended","minimumSecureVersion":"1.2.0"}');
  check('noncanonical manifest bytes are rejected', run('--verify', manifest, '--version', '1.2.3').status !== 0);
  check('tag/version disagreement is rejected', run('--verify', manifest, '--version', '1.2.4').status !== 0);
} finally {
  rmSync(dir, { recursive: true, force: true });
}

console.log(`Release manifest tests: ${passed} passed, ${failed} failed`);
if (failed) process.exit(1);
