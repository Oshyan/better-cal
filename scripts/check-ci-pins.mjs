#!/usr/bin/env node

import { readFileSync, readdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const problems = [];
const workflowDir = join(root, '.github', 'workflows');
for (const name of readdirSync(workflowDir).filter((file) => /\.ya?ml$/.test(file))) {
  const workflow = readFileSync(join(workflowDir, name), 'utf8');
  for (const match of workflow.matchAll(/^\s*- uses:\s*([^\s#]+)(?:\s+#.*)?$/gm)) {
    const ref = match[1].split('@').pop();
    if (!/^[0-9a-f]{40}$/.test(ref || '')) problems.push(`${name}: mutable action reference: ${match[1]}`);
  }
  for (const match of workflow.matchAll(/^\s*container:\s*(\S+)\s*$/gm)) {
    if (!/@sha256:[0-9a-f]{64}$/.test(match[1])) problems.push(`${name}: mutable container reference: ${match[1]}`);
  }
  if (workflow.includes('https://getcomposer.org/installer')
      && (!workflow.includes('https://composer.github.io/installer.sig')
        || !workflow.includes('hash_file("sha384", "/tmp/composer-setup.php")')
        || !workflow.includes('hash_equals($expected, $actual)'))) {
    problems.push(`${name}: Composer installer is not verified against the official SHA-384 signature`);
  }
}

if (problems.length > 0) {
  for (const problem of problems) console.error(`CI PIN ERROR: ${problem}`);
  process.exit(1);
}
console.log('ci pins: immutable action/container refs and verified Composer installer');
