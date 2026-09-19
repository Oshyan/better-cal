#!/usr/bin/env node
// Vendored frontend libraries (web/vendor) are pinned in web/vendor/manifest.json:
// package, version, path inside the npm package, the transform applied after
// download, and the sha256 of the file as it sits in the tree.
//
//   node scripts/vendor.mjs --verify            every file matches its manifest hash;
//                                               nothing in web/vendor is unlisted (deploy runs this)
//   node scripts/vendor.mjs --fetch [name ...]  download the pinned versions (all, or the named
//                                               entries), apply transforms, rewrite the hashes
//
// To upgrade: change the version in the manifest, --fetch that entry, review
// the diff (the hash line and the file), run the suites, commit both.
// Downloads come from cdn.jsdelivr.net/npm, which serves npm package files
// byte for byte; the hash recorded after review is what later runs trust.

import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const vendorDir = join(root, 'web', 'vendor');
const manifestPath = join(vendorDir, 'manifest.json');
// Files in web/vendor that are ours, not downloads.
const OWN = new Set(['manifest.json', 'index.js']);

const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');

const TRANSFORMS = {
  // The published module ends with a sourceMappingURL comment for a .map we
  // do not ship; dropping it keeps the browser from requesting a 404.
  'strip-sourcemap': (s) => s.replace(/\n?\/\/# sourceMappingURL=\S+\s*$/, '\n'),
  // @preact/hooks imports "preact" by bare specifier; no import map here.
  'strip-sourcemap+preact-import': (s) => TRANSFORMS['strip-sourcemap'](s).replace('from"preact"', 'from"./preact.module.js"'),
};

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else out.push(relative(vendorDir, p));
  }
  return out;
}

function verify(manifest) {
  let bad = 0;
  for (const [file, entry] of Object.entries(manifest.files)) {
    let actual;
    try { actual = sha256(readFileSync(join(vendorDir, file))); } catch { actual = '(missing)'; }
    if (actual !== entry.sha256) {
      bad++;
      console.log(`MISMATCH ${file} (${entry.package}@${entry.version}): ${actual} != ${entry.sha256}`);
    }
  }
  for (const file of walk(vendorDir)) {
    if (OWN.has(file) || manifest.files[file] || file === '.DS_Store') continue;
    bad++;
    console.log(`UNLISTED ${file}: not in web/vendor/manifest.json`);
  }
  const n = Object.keys(manifest.files).length;
  console.log(bad === 0 ? `vendor: ${n} files match the manifest` : `vendor: ${bad} problem(s)`);
  return bad === 0;
}

async function fetchEntries(manifest, names) {
  const wanted = names.length ? names : Object.keys(manifest.files);
  for (const file of wanted) {
    const entry = manifest.files[file];
    if (!entry) { console.log(`no manifest entry: ${file}`); process.exitCode = 1; continue; }
    const url = `https://cdn.jsdelivr.net/npm/${entry.package}@${entry.version}/${entry.path}`;
    const res = await fetch(url);
    if (!res.ok) { console.log(`FAILED ${url}: HTTP ${res.status}`); process.exitCode = 1; continue; }
    let buf = Buffer.from(await res.arrayBuffer());
    if (entry.transform) {
      const t = TRANSFORMS[entry.transform];
      if (!t) { console.log(`unknown transform ${entry.transform} for ${file}`); process.exitCode = 1; continue; }
      buf = Buffer.from(t(buf.toString('utf8')), 'utf8');
    }
    const hash = sha256(buf);
    mkdirSync(dirname(join(vendorDir, file)), { recursive: true });
    writeFileSync(join(vendorDir, file), buf);
    console.log(`${hash === entry.sha256 ? 'unchanged' : 'UPDATED  '} ${file} <- ${entry.package}@${entry.version} (${buf.length} bytes)`);
    entry.sha256 = hash;
  }
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const args = process.argv.slice(2);
if (args[0] === '--verify') {
  process.exit(verify(manifest) ? 0 : 1);
} else if (args[0] === '--fetch') {
  await fetchEntries(manifest, args.slice(1));
} else {
  console.log('usage: node scripts/vendor.mjs --verify | --fetch [file ...]');
  process.exit(2);
}
