#!/usr/bin/env node

import { readFileSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const manifest = JSON.parse(readFileSync(join(root, 'web', 'vendor', 'manifest.json'), 'utf8'));
const workflow = readFileSync(join(root, '.github', 'workflows', 'tests.yml'), 'utf8');
const updates = [];

const packages = new Map();
for (const entry of Object.values(manifest.files)) packages.set(entry.package, entry.version);
for (const [name, current] of packages) {
  const response = await fetch(`https://registry.npmjs.org/${encodeURIComponent(name)}`);
  if (!response.ok) throw new Error(`npm registry returned HTTP ${response.status} for ${name}`);
  const latest = (await response.json())?.['dist-tags']?.latest;
  if (typeof latest !== 'string') throw new Error(`npm registry supplied no latest version for ${name}`);
  if (latest !== current) updates.push({ kind: 'vendored frontend', name, current, latest });
}

const image = workflow.match(/^\s*container:\s*php:8\.4-cli@(sha256:[0-9a-f]{64})\s*$/m);
if (!image) throw new Error('Could not find the pinned PHP 8.4 CLI image');
const tokenResponse = await fetch('https://auth.docker.io/token?service=registry.docker.io&scope=repository:library/php:pull');
if (!tokenResponse.ok) throw new Error(`Docker token service returned HTTP ${tokenResponse.status}`);
const token = (await tokenResponse.json()).token;
const imageResponse = await fetch('https://registry-1.docker.io/v2/library/php/manifests/8.4-cli', {
  headers: {
    Authorization: `Bearer ${token}`,
    Accept: 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json',
  },
});
if (!imageResponse.ok) throw new Error(`Docker registry returned HTTP ${imageResponse.status}`);
const latestDigest = imageResponse.headers.get('docker-content-digest');
if (!/^sha256:[0-9a-f]{64}$/.test(latestDigest || '')) throw new Error('Docker registry supplied no manifest digest');
if (latestDigest !== image[1]) updates.push({ kind: 'CI image', name: 'php:8.4-cli', current: image[1], latest: latestDigest });

if (process.argv.includes('--json')) {
  console.log(JSON.stringify({ updates }, null, 2));
} else if (updates.length === 0) {
  console.log('dependency status: vendored libraries and PHP CI image are current');
} else {
  for (const update of updates) console.log(`${update.kind}: ${update.name} ${update.current} -> ${update.latest}`);
}
