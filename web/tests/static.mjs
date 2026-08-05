// Static source checks over the whole frontend tree. These catch the class of
// break that unit tests structurally cannot: a reference that only explodes
// when a particular branch renders (an icon in a drawer nobody opened during
// the test run). Run: node web/tests/static.mjs
//
// 1. Every module parses as a real ES module.
// 2. Every <${Component}> referenced in an htm template is in scope.
//
// Both exist because each has already shipped a live break: a stray import
// inserted inside a multi-line import statement (blank app), and an <${Icon}>
// used in files that imported some OTHER icon (dead "+ New" button, frozen
// agenda).

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'src');

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (name.endsWith('.js')) out.push(p);
  }
  return out;
}

let passed = 0;
let failed = 0;
const fail = (msg) => { failed++; console.log('FAIL: ' + msg); };

const files = walk(root);

// --- 1. every module parses ---------------------------------------------
for (const file of files) {
  try {
    new vm.SourceTextModule(readFileSync(file, 'utf8'), { identifier: file });
    passed++;
  } catch (e) {
    fail('syntax in ' + file + ': ' + e.message);
  }
}

// --- 2. every templated component is in scope ---------------------------
for (const file of files) {
  const src = readFileSync(file, 'utf8');
  const used = new Set();
  for (const m of src.matchAll(/<\$\{([A-Za-z_$][\w$]*)\}/g)) used.add(m[1]);
  if (used.size === 0) continue;

  const scope = new Set();
  for (const m of src.matchAll(/^import\s+\{([^}]*)\}\s+from/gms)) {
    for (const part of m[1].split(',')) {
      const name = part.trim().split(/\s+as\s+/).pop().trim();
      if (name) scope.add(name);
    }
  }
  for (const m of src.matchAll(/^import\s+([A-Za-z_$][\w$]*)\s+from/gm)) scope.add(m[1]);
  for (const m of src.matchAll(/(?:function|const|let|var|class)\s+([A-Za-z_$][\w$]*)/g)) scope.add(m[1]);

  for (const name of used) {
    if (scope.has(name)) passed++;
    else fail('<' + name + '> used but not in scope in ' + file);
  }
}

console.log('');
console.log(passed + ' checks passed, ' + failed + ' failed (' + files.length + ' modules)');
if (failed > 0) process.exit(1);
