#!/usr/bin/env node
// Vendored frontend libraries (web/vendor) are pinned in web/vendor/manifest.json:
// package, version, license, path inside the npm package, the transform applied
// after download, the sha256 of the file as published (upstreamSha256) and the
// sha256 of the file as it sits in the tree (sha256). Each package's license
// text is an entry too (LICENSE-<library>.txt, the package's own LICENSE), pinned
// and checked like its code. web/vendor/README.md and the Version column of
// THIRD_PARTY_NOTICES.md are written from the manifest, so a reviewer finds all
// of it next to the files and the notices can't name a stale version.
//
//   node scripts/vendor.mjs --verify            every file matches its manifest hash, nothing in
//                                               web/vendor is unlisted, every package has one
//                                               version and a license entry, the README and the
//                                               notices' versions are current (deploy runs this)
//   node scripts/vendor.mjs --check-upstream    download every pinned file again, check it against
//                                               upstreamSha256, apply its transform and check the
//                                               result is byte for byte the committed file
//   node scripts/vendor.mjs --fetch [name ...]  download the pinned versions (all, or the named
//                                               entries and their packages' license texts), apply
//                                               transforms, rewrite the hashes, licenses, README
//                                               and notices' versions
//   node scripts/vendor.mjs --readme            rewrite web/vendor/README.md and the notices'
//                                               versions from the manifest
//
// To upgrade: change the version in the manifest, --fetch that entry, review
// the diff (the hash lines and the file), run the suites, commit both.
// Downloads come from cdn.jsdelivr.net/npm, which serves npm package files
// byte for byte; the hash recorded after review is what later runs trust.

import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const vendorDir = join(root, 'web', 'vendor');
const manifestPath = join(vendorDir, 'manifest.json');
const readmePath = join(vendorDir, 'README.md');
const noticesPath = join(root, 'THIRD_PARTY_NOTICES.md');
// Files in web/vendor that are ours, not downloads.
const OWN = new Set(['manifest.json', 'index.js', 'README.md']);

const sha256 = (buf) => createHash('sha256').update(buf).digest('hex');
const fileUrl = (e) => `https://cdn.jsdelivr.net/npm/${e.package}@${e.version}/${e.path}`;

const TRANSFORMS = {
  // The published module ends with a sourceMappingURL comment for a .map we
  // do not ship; dropping it keeps the browser from requesting a 404.
  'strip-sourcemap': (s) => s.replace(/\n?\/\/# sourceMappingURL=\S+\s*$/, '\n'),
  // @preact/hooks imports "preact" by bare specifier; no import map here.
  'strip-sourcemap+preact-import': (s) => TRANSFORMS['strip-sourcemap'](s).replace('from"preact"', 'from"./preact.module.js"'),
};
// What each transform does, for the README.
const TRANSFORM_NOTES = {
  'strip-sourcemap': 'The last line, a `//# sourceMappingURL=` comment pointing at a source map we don\'t ship, is removed.',
  'strip-sourcemap+preact-import': 'As above, and `from"preact"` becomes `from"./preact.module.js"`, so it imports the copy next to it (there\'s no import map).',
};

function apply(entry, buf) {
  if (!entry.transform) return buf;
  const t = TRANSFORMS[entry.transform];
  if (!t) throw new Error(`unknown transform ${entry.transform}`);
  return Buffer.from(t(buf.toString('utf8')), 'utf8');
}

async function download(url) {
  const res = await fetch(url);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return Buffer.from(await res.arrayBuffer());
}

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else out.push(relative(vendorDir, p));
  }
  return out;
}

// THIRD_PARTY_NOTICES.md with each table row's Version cell set from the
// manifest entry its License cell links to (web/vendor/LICENSE-<library>.txt),
// and the license entries no row links to.
function notices(text, manifest) {
  const unlinked = new Set(Object.keys(manifest.files).filter((f) => manifest.files[f].path === 'LICENSE'));
  const out = text.replace(/^(\| [^|\n]+ \| )[^|\n]+( \| [^|\n]*\]\(web\/vendor\/([^)\s]+)\)[^|\n]* \|.*)$/gm, (line, head, tail, file) => {
    const e = manifest.files[file];
    if (!e) return line;
    unlinked.delete(file);
    return `${head}${e.version}${tail}`;
  });
  return { text: out, unlinked: [...unlinked] };
}

function writeDocs(manifest) {
  writeFileSync(readmePath, readme(manifest));
  writeFileSync(noticesPath, notices(readFileSync(noticesPath, 'utf8'), manifest).text);
}

function readme(manifest) {
  const rows = Object.entries(manifest.files).map(([file, e]) =>
    `| \`${file}\` | [${e.package}](https://www.npmjs.com/package/${e.package}/v/${e.version}) ${e.version} | ${e.license || ''} | [\`${e.path}\`](${fileUrl(e)}) | \`${e.upstreamSha256 || ''}\` | ${e.transform ? `\`${e.sha256}\` (changed, see below)` : 'same'} |`);
  const changed = Object.entries(manifest.files).filter(([, e]) => e.transform);
  return `# Vendored libraries

This folder holds the open-source libraries the web app uses, copied from their published npm packages and changed only as listed under Changes below. There's no build step, so they're committed as published, which for several of them means minified. Nothing here is our own code in minified form: ours are \`index.js\` (it re-exports the UI runtime), \`manifest.json\` and this file. Each library's license text, as its package publishes it, is here as \`LICENSE-<library>.txt\`, pinned and checked like its code.

This file is written from \`manifest.json\` by \`node scripts/vendor.mjs --readme\`; don't edit it by hand.

## Checking them

- \`node scripts/vendor.mjs --check-upstream\` downloads every file below from the npm CDN, checks it against the upstream fingerprint, makes the documented changes, and confirms the result is byte for byte the committed file. It needs Node 18 or later and nothing else.
- \`node scripts/vendor.mjs --verify\` checks the committed files against the manifest without downloading anything. Every deploy runs it and refuses to ship on a mismatch.
- To check one by hand, download the linked file and compare: \`curl -sL <link> | shasum -a 256\` gives the upstream fingerprint. To skip the CDN, \`npm pack <package>@<version>\` fetches the package from the npm registry itself; the file is at its path inside.

## Files

Fingerprints are SHA-256. "In the tree" differs from upstream only where the file was changed, as described under the table.

| File | Package | License | Upstream file | Upstream SHA-256 | In the tree |
|---|---|---|---|---|---|
${rows.join('\n')}

## Changes

${changed.length === 0 ? 'None: every file is exactly as published.' : changed.map(([file, e]) => `- \`${file}\`: ${TRANSFORM_NOTES[e.transform] || e.transform} The code is in \`scripts/vendor.mjs\` (\`TRANSFORMS\`).`).join('\n')}

Everything else is exactly as published.

## Upgrading

Change the version in \`manifest.json\`, run \`node scripts/vendor.mjs --fetch <file>\`, review the new file and fingerprints, run the test suites, and commit both. See \`docs/dependencies.md\`.
`;
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
    if (!entry.transform && entry.upstreamSha256 && entry.upstreamSha256 !== entry.sha256) {
      bad++;
      console.log(`MANIFEST ${file}: unchanged from upstream, but the two fingerprints differ`);
    }
  }
  for (const file of walk(vendorDir)) {
    if (OWN.has(file) || manifest.files[file] || file === '.DS_Store') continue;
    bad++;
    console.log(`UNLISTED ${file}: not in web/vendor/manifest.json`);
  }
  let current = '';
  try { current = readFileSync(readmePath, 'utf8'); } catch { /* missing */ }
  if (current !== readme(manifest)) {
    bad++;
    console.log('README web/vendor/README.md is out of date: run node scripts/vendor.mjs --readme');
  }
  const packages = new Map();
  for (const [file, e] of Object.entries(manifest.files)) {
    const seen = packages.get(e.package);
    if (!seen) { packages.set(e.package, { version: e.version, file, license: e.path === 'LICENSE' }); continue; }
    if (e.path === 'LICENSE') seen.license = true;
    if (seen.version !== e.version) {
      bad++;
      console.log(`VERSION  ${file}: ${e.package}@${e.version}, but ${seen.file} is ${e.package}@${seen.version}`);
    }
  }
  for (const [pkg, p] of packages) {
    if (p.license) continue;
    bad++;
    console.log(`LICENSE  ${pkg}: no entry for its license text (path LICENSE) in web/vendor/manifest.json`);
  }
  let text = '';
  try { text = readFileSync(noticesPath, 'utf8'); } catch { /* missing */ }
  const listed = notices(text, manifest);
  if (listed.text !== text) {
    bad++;
    console.log('NOTICES  THIRD_PARTY_NOTICES.md names a version the manifest does not: run node scripts/vendor.mjs --readme');
  }
  for (const file of listed.unlinked) {
    bad++;
    console.log(`NOTICES  THIRD_PARTY_NOTICES.md has no row linking web/vendor/${file}`);
  }
  const n = Object.keys(manifest.files).length;
  console.log(bad === 0 ? `vendor: ${n} files match the manifest` : `vendor: ${bad} problem(s)`);
  return bad === 0;
}

async function checkUpstream(manifest) {
  let bad = 0;
  for (const [file, entry] of Object.entries(manifest.files)) {
    const url = fileUrl(entry);
    let raw;
    try { raw = await download(url); } catch (e) { bad++; console.log(`FAILED   ${file} <- ${url}: ${e.message}`); continue; }
    const up = sha256(raw);
    if (up !== entry.upstreamSha256) {
      bad++;
      console.log(`UPSTREAM ${file}: ${url} is ${up}, the manifest says ${entry.upstreamSha256}`);
      continue;
    }
    let mine;
    try { mine = readFileSync(join(vendorDir, file)); } catch { bad++; console.log(`MISSING  ${file}`); continue; }
    if (!apply(entry, raw).equals(mine)) {
      bad++;
      console.log(`DIFFERS  ${file}: the committed file is not ${entry.package}@${entry.version}${entry.transform ? ' with its documented change' : ''}`);
      continue;
    }
    console.log(`ok       ${file} = ${entry.package}@${entry.version}${entry.transform ? ` (${entry.transform})` : ''}`);
  }
  const n = Object.keys(manifest.files).length;
  console.log(bad === 0 ? `vendor: all ${n} files are the published releases` : `vendor: ${bad} problem(s)`);
  return bad === 0;
}

async function fetchEntries(manifest, names) {
  // A named entry brings its package's license text along, so an upgrade
  // fetches the license of the version it moves to.
  const licenseOf = (name) => Object.keys(manifest.files).filter((f) =>
    manifest.files[name] && manifest.files[f].package === manifest.files[name].package && manifest.files[f].path === 'LICENSE');
  const wanted = names.length ? [...new Set(names.flatMap((name) => [name, ...licenseOf(name)]))] : Object.keys(manifest.files);
  const licenses = new Map();
  for (const file of wanted) {
    const entry = manifest.files[file];
    if (!entry) { console.log(`no manifest entry: ${file}`); process.exitCode = 1; continue; }
    let raw;
    try { raw = await download(fileUrl(entry)); } catch (e) { console.log(`FAILED ${fileUrl(entry)}: ${e.message}`); process.exitCode = 1; continue; }
    const buf = apply(entry, raw);
    const hash = sha256(buf);
    mkdirSync(dirname(join(vendorDir, file)), { recursive: true });
    writeFileSync(join(vendorDir, file), buf);
    console.log(`${hash === entry.sha256 ? 'unchanged' : 'UPDATED  '} ${file} <- ${entry.package}@${entry.version} (${buf.length} bytes)`);
    const pkg = `${entry.package}@${entry.version}`;
    if (!licenses.has(pkg)) {
      try { licenses.set(pkg, JSON.parse((await download(`https://cdn.jsdelivr.net/npm/${pkg}/package.json`)).toString('utf8')).license || null); } catch { licenses.set(pkg, null); }
    }
    // Key order kept stable so the manifest diff shows only what changed.
    manifest.files[file] = {
      package: entry.package,
      version: entry.version,
      license: licenses.get(pkg) || entry.license || null,
      path: entry.path,
      ...(entry.transform ? { transform: entry.transform } : {}),
      upstreamSha256: sha256(raw),
      sha256: hash,
    };
  }
  writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n');
  writeDocs(manifest);
}

const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'));
const args = process.argv.slice(2);
if (args[0] === '--verify') {
  process.exit(verify(manifest) ? 0 : 1);
} else if (args[0] === '--check-upstream') {
  process.exit((await checkUpstream(manifest)) ? 0 : 1);
} else if (args[0] === '--fetch') {
  await fetchEntries(manifest, args.slice(1));
} else if (args[0] === '--readme') {
  writeDocs(manifest);
} else {
  console.log('usage: node scripts/vendor.mjs --verify | --check-upstream | --fetch [file ...] | --readme');
  process.exit(2);
}
