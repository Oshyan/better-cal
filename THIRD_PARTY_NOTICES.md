# Third-party notices

Better-Cal is MIT licensed (see [LICENSE](LICENSE)). It includes or depends on the following software, each under its own license.

## Included in this repository

These front-end libraries are copied into `web/vendor/`, pinned and checksummed in `web/vendor/manifest.json`. Each license's full text is at the link.

| Library | Version | License | Files |
|---|---|---|---|
| [Preact](https://github.com/preactjs/preact) | 11.0.1 | [MIT](https://github.com/preactjs/preact/blob/11.0.1/LICENSE) | `preact.module.js`, `hooks.module.js` |
| [htm](https://github.com/developit/htm) | 3.1.1 | [Apache-2.0](https://github.com/developit/htm/blob/3.1.1/LICENSE) | `htm.module.js` |
| [Squire](https://github.com/fastmail/Squire) | 2.4.9 | [MIT](https://github.com/fastmail/Squire/blob/master/LICENSE) | `squire/squire-raw.js` |
| [DOMPurify](https://github.com/cure53/DOMPurify) | 3.4.16 | [Apache-2.0 or MPL-2.0](https://github.com/cure53/DOMPurify/blob/3.4.16/LICENSE) (used under Apache-2.0) | `squire/purify.min.js` |
| [Leaflet](https://github.com/Leaflet/Leaflet) | 1.9.4 | [BSD-2-Clause](https://github.com/Leaflet/Leaflet/blob/v1.9.4/LICENSE) | `leaflet/` |

The Apache-2.0 licensed files (htm, DOMPurify) are redistributed unmodified; their license text is at the links above. Only Preact's two files are changed: the source-map comment is removed, and `hooks.module.js` imports Preact by its local path.

## Installed by Composer

The server's PHP dependencies are not stored in this repository; `composer install` fetches them (see `server/composer.json` and `server/composer.lock`). They are MIT or BSD-3-Clause licensed (including sabre/dav and sabre/vobject, BSD-3-Clause), except:

- **PHPMailer** (`phpmailer/phpmailer`), LGPL-2.1-only. It is used unmodified as a separate library, installed and replaceable through Composer, which the LGPL allows for a differently licensed application.

`composer licenses` (run in `server/`) lists every installed package with its license.

## Data and services

Map tiles, geocoding, weather, tides and sun data come from external services at run time (OpenStreetMap, MapTiler, Open-Meteo, NOAA and others, depending on configuration and plugins). Their terms apply to that use, and the attribution they require is shown where their data appears.
