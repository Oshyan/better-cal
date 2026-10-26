# Dependency Update Policy

Better-Cal watches its dependencies but never merges or deploys an update automatically.

## What Is Checked

- Dependabot opens weekly pull requests for Composer dependencies and GitHub Actions.
- The weekly `dependency watch` workflow checks committed browser libraries and the pinned PHP CI image and maintains one review issue when changes exist.
- CI and deployment run `composer audit --no-dev --locked`; a known advisory in the production lock set blocks release and deployment.
- `scripts/check-ci-pins.mjs` requires full action commit SHAs, a container digest and verification of Composer's installer signature.

## How Updates Are Chosen

Security fixes are reviewed first. A fix should normally ship promptly after checking reachability, the upstream advisory, compatibility and the relevant test suites.

Non-security updates are still reviewed each week:

- Patch and minor updates are accepted when they contain relevant fixes, improve compatibility or maintenance, or keep a dependency from becoming stale.
- Major updates need an explicit review of migration cost, behavior changes and project value; a new major number alone is not a reason to update or to defer forever.
- Abandoned packages prompt a replacement or removal review.
- An update that adds no project value may be deferred with a short reason on the tracking issue or pull request.

Every accepted update keeps its lock, digest or committed-file hash in the same change and runs the complete automated suite. Dependency pull requests are not auto-merged, and a green advisory scan does not substitute for ordinary compatibility review.

## Updating a Browser Library

Change the version in `web/vendor/manifest.json`, run `node scripts/vendor.mjs --fetch <file>`, review the transformed file and hashes, then run `node scripts/vendor.mjs --verify` and the frontend suites. `--fetch` records the published file's fingerprint (`upstreamSha256`), the committed one's (`sha256`) and the package's license, and rewrites `web/vendor/README.md`; `--verify` fails if that list is stale. `node scripts/vendor.mjs --check-upstream` re-downloads every pinned file and confirms the committed copy is that release with only its documented change.
