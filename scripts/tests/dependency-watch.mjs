#!/usr/bin/env node

import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const script = join(root, 'scripts', 'dependency-watch-report.mjs');
const dir = mkdtempSync(join(tmpdir(), 'bc-dependency-watch.'));
let passed = 0;
let failed = 0;
const check = (name, condition) => {
  if (condition) { passed++; console.log(`  ok  ${name}`); }
  else { failed++; console.error(`FAIL  ${name}`); }
};

function run(audit, outdated, repo, holds = '{}') {
  const auditPath = join(dir, 'audit.json');
  const outdatedPath = join(dir, 'outdated.json');
  const repoPath = join(dir, 'repo.json');
  const outputPath = join(dir, 'report.md');
  writeFileSync(auditPath, audit);
  writeFileSync(outdatedPath, outdated);
  writeFileSync(repoPath, repo);
  writeFileSync(outputPath, 'unchanged sentinel');
  const holdsPath = join(dir, 'holds.json');
  writeFileSync(holdsPath, holds);
  const result = spawnSync(process.execPath, [script, auditPath, outdatedPath, repoPath, outputPath, holdsPath], { encoding: 'utf8' });
  return { ...result, output: readFileSync(outputPath, 'utf8') };
}

try {
  const clean = run(
    JSON.stringify({ advisories: {}, abandoned: [] }),
    JSON.stringify({ installed: [] }),
    JSON.stringify({ updates: [] }),
  );
  check('valid clean reports succeed', clean.status === 0 && clean.output === '');

  const update = run(
    JSON.stringify({ advisories: {}, abandoned: [] }),
    JSON.stringify({ installed: [{ name: 'sample/library', version: '1.0.0', latest: '1.1.0' }] }),
    JSON.stringify({ updates: [{ kind: 'CI image', name: 'sample:latest', current: 'sha256:old', latest: 'sha256:new' }] }),
  );
  const locked = run(
    JSON.stringify({ advisories: {}, abandoned: [] }),
    JSON.stringify({ locked: [{ name: 'sample/library', version: '1.0.0', latest: '2.0.0' }] }),
    JSON.stringify({ updates: [] }),
  );
  check('a --locked report (packages under "locked") is read too', locked.status === 0
    && locked.output.includes('sample/library: 1.0.0 → 2.0.0'));
  const holds = JSON.stringify({ 'sample/held': { major: 5, reason: 'Its parent pins the old major.', until: 'the parent allows it' } });
  const onlyHeld = run(
    JSON.stringify({ advisories: {}, abandoned: [] }),
    JSON.stringify({ locked: [{ name: 'sample/held', version: '4.6.1', latest: '5.0.0' }] }),
    JSON.stringify({ updates: [] }),
    holds,
  );
  check('a held major alone opens no review', onlyHeld.status === 0 && onlyHeld.output === '');
  const heldAndOther = run(
    JSON.stringify({ advisories: {}, abandoned: [] }),
    JSON.stringify({ locked: [{ name: 'sample/held', version: '4.6.1', latest: '5.0.0' }, { name: 'sample/parent', version: '4.7.1', latest: '5.0.0' }] }),
    JSON.stringify({ updates: [] }),
    holds,
  );
  check('when something else is due, the held update is listed as on hold, not as an update',
    heldAndOther.status === 0 && heldAndOther.output.includes('sample/parent: 4.7.1 → 5.0.0')
    && heldAndOther.output.includes('## On hold') && !/## Composer updates[^#]*sample\/held/.test(heldAndOther.output));
  const nextMajor = run(
    JSON.stringify({ advisories: {}, abandoned: [] }),
    JSON.stringify({ locked: [{ name: 'sample/held', version: '4.6.1', latest: '6.0.0' }] }),
    JSON.stringify({ updates: [] }),
    holds,
  );
  check('a hold covers only its major: the next one is flagged', nextMajor.output.includes('sample/held: 4.6.1 → 6.0.0'));
  check('valid updates create a human review report', update.status === 0
    && update.output.includes('sample/library: 1.0.0 → 1.1.0')
    && update.output.includes('does not authorize automatic merging'));

  const malformedAudit = run('not json', JSON.stringify({ installed: [] }), JSON.stringify({ updates: [] }));
  check('malformed audit data fails closed without replacing the report',
    malformedAudit.status !== 0 && malformedAudit.output === 'unchanged sentinel');

  const missingOutdated = run(JSON.stringify({ advisories: {}, abandoned: [] }), '{}', JSON.stringify({ updates: [] }));
  check('missing Composer update inventory fails closed', missingOutdated.status !== 0);
} finally {
  rmSync(dir, { recursive: true, force: true });
}

console.log(`Dependency watch tests: ${passed} passed, ${failed} failed`);
if (failed) process.exit(1);
