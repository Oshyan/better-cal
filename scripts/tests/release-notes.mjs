#!/usr/bin/env node

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { changelogEntry, absoluteLinks, releaseNotes, bareMentions, UPDATING_URL } from '../release-notes.mjs';

let passed = 0;
let failed = 0;
const check = (name, condition) => {
  if (condition) { passed++; console.log(`  ok  ${name}`); }
  else { failed++; console.error(`FAIL  ${name}`); }
};

const sample = [
  '# Changelog',
  '',
  '## 1.2.3 (2026-01-02)',
  '',
  '- **A fix.** See [SECURITY.md](SECURITY.md#past-advisories) and [the docs](docs/install.md).',
  '',
  'Operators: run the migration.',
  '',
  '## 1.2.2 (2026-01-01)',
  '',
  '- Older.',
].join('\n');

check('entry: one version, without its heading or the next', changelogEntry(sample, '1.2.3') === '- **A fix.** See [SECURITY.md](SECURITY.md#past-advisories) and [the docs](docs/install.md).\n\nOperators: run the migration.');
check('entry: the last section runs to the end', changelogEntry(sample, '1.2.2') === '- Older.');
check('entry: 1.2.2 does not match 1.2.23', changelogEntry('## 1.2.23 (2026-01-01)\n\n- x', '1.2.2') === null);
check('links: repository paths become absolute at the tag', absoluteLinks('[S](SECURITY.md#a)', 'v1.2.3') === '[S](https://github.com/Oshyan/better-cal/blob/v1.2.3/SECURITY.md#a)');
check('links: web links and anchors stay', absoluteLinks('[a](https://x.example/) [b](#c)', 'v1') === '[a](https://x.example/) [b](#c)');

check('mentions: an @name in prose is found', JSON.stringify(bareMentions('("@Sam" finds Sam Sample)')) === '["@Sam"]');
check('mentions: in backticks or a code block it is text', bareMentions('Type `@Sam` or\n```\nDinner with @Sam\n```') .length === 0);
check('mentions: email addresses are not mentions', bareMentions('Write to someone@example.test').length === 0);
let mentionError = '';
try { releaseNotes('## 2.0.0 (2026-01-03)\n\n- Link a person with @Sam.', '2.0.0'); } catch (e) { mentionError = e.message; }
check('mentions: a release with one is refused, naming it', mentionError.includes('@Sam') && mentionError.includes('backticks'));

const notes = releaseNotes(sample, '1.2.3', 'v1.2.2');
check('notes: carry the Operators line', notes.includes('Operators: run the migration.'));
check('notes: link the update steps', notes.includes(UPDATING_URL));
check('notes: link the commits since the previous release', notes.includes('/compare/v1.2.2...v1.2.3'));
let threw = false;
try { releaseNotes(sample, '9.9.9'); } catch { threw = true; }
check('notes: a version without an entry is refused', threw);

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
const version = readFileSync(join(root, 'VERSION'), 'utf8').trim();
check('the current VERSION has a changelog entry', changelogEntry(readFileSync(join(root, 'CHANGELOG.md'), 'utf8'), version) !== null);

console.log(`${passed} passed, ${failed} failed`);
process.exit(failed ? 1 : 0);
