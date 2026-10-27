# Vendored libraries

This folder holds the open-source libraries the web app uses, copied from their published npm packages and changed only as listed under Changes below. There's no build step, so they're committed as published, which for several of them means minified. Nothing here is our own code in minified form: ours are `index.js` (it re-exports the UI runtime), `manifest.json` and this file.

This file is written from `manifest.json` by `node scripts/vendor.mjs --readme`; don't edit it by hand.

## Checking them

- `node scripts/vendor.mjs --check-upstream` downloads every file below from the npm CDN, checks it against the upstream fingerprint, makes the documented changes, and confirms the result is byte for byte the committed file. It needs Node 18 or later and nothing else.
- `node scripts/vendor.mjs --verify` checks the committed files against the manifest without downloading anything. Every deploy runs it and refuses to ship on a mismatch.
- To check one by hand, download the linked file and compare: `curl -sL <link> | shasum -a 256` gives the upstream fingerprint. To skip the CDN, `npm pack <package>@<version>` fetches the package from the npm registry itself; the file is at its path inside.

## Files

Fingerprints are SHA-256. "In the tree" differs from upstream only where the file was changed, as described under the table.

| File | Package | License | Upstream file | Upstream SHA-256 | In the tree |
|---|---|---|---|---|---|
| `preact.module.js` | [preact](https://www.npmjs.com/package/preact/v/11.0.2) 11.0.2 | MIT | [`dist/preact.mjs`](https://cdn.jsdelivr.net/npm/preact@11.0.2/dist/preact.mjs) | `4d2617f3f082550223d37f781114b5f723362fc4a337459715a7083664dc10c1` | `3bfb01ccb2130f85c688ac2751ff059eda1019dd86cd6f05b06fa15a72a37d54` (changed, see below) |
| `hooks.module.js` | [preact](https://www.npmjs.com/package/preact/v/11.0.2) 11.0.2 | MIT | [`hooks/dist/hooks.mjs`](https://cdn.jsdelivr.net/npm/preact@11.0.2/hooks/dist/hooks.mjs) | `6cb79fe9959ad185b8b86134aff47c12fd20216b24837597b19be59a133d9947` | `05c7f3fb9e18d49981672106420d5ca014a4023efee0439cabaebf0a6988e93b` (changed, see below) |
| `htm.module.js` | [htm](https://www.npmjs.com/package/htm/v/3.1.1) 3.1.1 | Apache-2.0 | [`dist/htm.module.js`](https://cdn.jsdelivr.net/npm/htm@3.1.1/dist/htm.module.js) | `ab33dd3f38059b9be4d5f5350128eefb2356639c4e0bbe9d9e8b3ba75847e9e4` | same |
| `squire/squire-raw.js` | [squire-rte](https://www.npmjs.com/package/squire-rte/v/2.4.10) 2.4.10 | MIT | [`dist/squire-raw.js`](https://cdn.jsdelivr.net/npm/squire-rte@2.4.10/dist/squire-raw.js) | `e2e9afc30b7e9d2bb01cbd7109e58c21c3fe4b67556b3e12c308a392768ad8a2` | same |
| `squire/purify.min.js` | [dompurify](https://www.npmjs.com/package/dompurify/v/3.4.17) 3.4.17 | (MPL-2.0 OR Apache-2.0) | [`dist/purify.min.js`](https://cdn.jsdelivr.net/npm/dompurify@3.4.17/dist/purify.min.js) | `cd37898f9782adf54820c413191b5691803f0f137c799d6edcfd38523b4c2c31` | same |
| `leaflet/leaflet.js` | [leaflet](https://www.npmjs.com/package/leaflet/v/1.9.4) 1.9.4 | BSD-2-Clause | [`dist/leaflet.js`](https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js) | `db49d009c841f5ca34a888c96511ae936fd9f5533e90d8b2c4d57596f4e5641a` | same |
| `leaflet/leaflet.css` | [leaflet](https://www.npmjs.com/package/leaflet/v/1.9.4) 1.9.4 | BSD-2-Clause | [`dist/leaflet.css`](https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css) | `a7837102824184820dfa198d1ebcd109ff6d0ff9a2672a074b9a1b4d147d04c6` | same |
| `leaflet/images/marker-icon.png` | [leaflet](https://www.npmjs.com/package/leaflet/v/1.9.4) 1.9.4 | BSD-2-Clause | [`dist/images/marker-icon.png`](https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-icon.png) | `574c3a5cca85f4114085b6841596d62f00d7c892c7b03f28cbfa301deb1dc437` | same |
| `leaflet/images/marker-icon-2x.png` | [leaflet](https://www.npmjs.com/package/leaflet/v/1.9.4) 1.9.4 | BSD-2-Clause | [`dist/images/marker-icon-2x.png`](https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-icon-2x.png) | `00179c4c1ee830d3a108412ae0d294f55776cfeb085c60129a39aa6fc4ae2528` | same |
| `leaflet/images/marker-shadow.png` | [leaflet](https://www.npmjs.com/package/leaflet/v/1.9.4) 1.9.4 | BSD-2-Clause | [`dist/images/marker-shadow.png`](https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/images/marker-shadow.png) | `264f5c640339f042dd729062cfc04c17f8ea0f29882b538e3848ed8f10edb4da` | same |

## Changes

- `preact.module.js`: The last line, a `//# sourceMappingURL=` comment pointing at a source map we don't ship, is removed. The code is in `scripts/vendor.mjs` (`TRANSFORMS`).
- `hooks.module.js`: As above, and `from"preact"` becomes `from"./preact.module.js"`, so it imports the copy next to it (there's no import map). The code is in `scripts/vendor.mjs` (`TRANSFORMS`).

Everything else is exactly as published.

## Upgrading

Change the version in `manifest.json`, run `node scripts/vendor.mjs --fetch <file>`, review the new file and fingerprints, run the test suites, and commit both. See `docs/dependencies.md`.
