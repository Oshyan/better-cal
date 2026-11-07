# Third-party notices

Better-Cal is MIT licensed (see [LICENSE](LICENSE)). It includes or depends on the following software, each under its own license.

## Included in this repository

These front-end libraries are copied into `web/vendor/`, pinned and checksummed in `web/vendor/manifest.json`. The versions below are written from the manifest by `node scripts/vendor.mjs --readme`, and `--verify` fails when they differ. Each library's license, as its package publishes it, ships beside it in `web/vendor/` (linked), pinned and checked like its code.

| Library | Version | License | Files |
|---|---|---|---|
| [Preact](https://github.com/preactjs/preact) | 11.0.2 | [MIT](web/vendor/LICENSE-preact.txt) | `preact.module.js`, `hooks.module.js` |
| [htm](https://github.com/developit/htm) | 3.1.1 | [Apache-2.0](web/vendor/LICENSE-htm.txt) | `htm.module.js` |
| [Squire](https://github.com/fastmail/Squire) | 2.4.10 | [MIT](web/vendor/LICENSE-squire.txt) | `squire/squire-raw.js` |
| [DOMPurify](https://github.com/cure53/DOMPurify) | 3.4.17 | [Apache-2.0 or MPL-2.0](web/vendor/LICENSE-dompurify.txt) (used under Apache-2.0) | `squire/purify.min.js` |
| [Leaflet](https://github.com/Leaflet/Leaflet) | 1.9.4 | [BSD-2-Clause](web/vendor/LICENSE-leaflet.txt) | `leaflet/` |

The Apache-2.0 licensed files (htm, DOMPurify) are redistributed unmodified, with their license text beside them. Only Preact's two files are changed: the source-map comment is removed, and `hooks.module.js` imports Preact by its local path.

## PHP libraries (Composer)

The server's PHP libraries aren't stored in this repository; `server/composer.json` and `server/composer.lock` pin them. Each release zip includes them, installed from that lock file into `server/vendor/`, each with its own license file. A git checkout gets them with `composer install`. They are MIT or BSD-3-Clause licensed (the sabre libraries, including sabre/dav and sabre/vobject, are BSD-3-Clause), except:

- **PHPMailer** (`phpmailer/phpmailer`), LGPL-2.1-only. It's included unmodified, as a separate library in its own folder (`server/vendor/phpmailer/phpmailer`), so it can be replaced or changed, which the LGPL allows for a differently licensed application.

The release zip leaves out the libraries' own command-line tools, examples, tests and docs, which Better-Cal never runs. Their code and license files are as published. `composer licenses` (run in `server/`) lists every installed package with its license.

## Data and services

Map tiles, geocoding, weather, tides and sun data come from external services at run time (OpenStreetMap, MapTiler, Open-Meteo, NOAA and others, depending on configuration and plugins). Their terms apply to that use, and the attribution they require is shown where their data appears.
