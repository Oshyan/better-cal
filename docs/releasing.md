# Releasing Better-Cal

Published releases are immutable: their tag and assets cannot be replaced. Titles, notes, prerelease state and the “latest” designation remain editable. A bad tag or asset is corrected by a new patch release.

## Application release

1. Make the release commit on `main`: update `VERSION`, add the matching `CHANGELOG.md` section, run the full suite, push, and wait for the `tests` workflow to pass.
2. Prepare a draft with the final release manifest:

   ```sh
   scripts/release.sh prepare routine 1.1.0
   ```

   The second argument is `routine`, `recommended`, or `security`. The third is the cumulative minimum secure version. A security fix normally sets that floor to the new version; later routine releases retain the same floor until another security release raises it.
3. The helper creates and pushes the annotated version tag at the exact green commit, generates and verifies `bettercal-release.json`, and creates a draft GitHub release with that final asset. The release notes are the version's `CHANGELOG.md` section, with repository links made absolute, then a pointer to the update steps in `docs/install.md` and the commit comparison with the previous tag (`scripts/release-notes.mjs`). The app's update notice links to this page, so the changelog section's Operators line is what someone updating reads first. The helper then starts `.github/workflows/release-zip.yml`, which builds `better-cal-X.Y.Z.zip` from the tag (the source without CI and tests, a `.release-files` list of what it ships, and the PHP libraries installed from the lock file in the pinned PHP 8.4 image), attaches it to the draft, and waits for it; `publish` refuses a draft without exactly one. `scripts/release-assemble.sh` does the assembling. The helper then rebuilds the same release on the maintainer's machine (the tagged source, and the libraries installed from the lock file with the same pinned Composer, outside git as on GitHub) and compares it file by file with GitHub's zip; it signs only when they match, then asks for the security key's PIN and a touch. `server/bin/update.php` installs from that zip, after checking it against GitHub's SHA-256 digest and the signature.
4. Deploy that exact commit and complete the production smoke checks while the release is still a draft.
5. Publish only after those checks succeed:

   ```sh
   scripts/release.sh publish --deployment-verified
   ```

   The explicit flag records the human gate. The helper rechecks the clean commit, tag, CI result and manifest, refuses to publish unless the repository's immutable-release setting is enabled, publishes the existing draft, and confirms GitHub reports the release immutable.

The application trusts only a published, non-prerelease, immutable `vX.Y.Z` release in this repository with exactly one canonical manifest asset whose GitHub-provided SHA-256 digest matches. It never installs code automatically; `server/bin/update.php`, run by the operator, installs a release only when its signature also verifies (below).

## Signing

Every release carries `bettercal-release.sig`: one statement naming the version, the tag's commit and the SHA-256 of the zip and the manifest, signed by at least one key listed in `server/config/release-keys.php`. `update.php` refuses a release unless one of those signatures verifies, and in a git checkout it also checks that the tag points at the signed commit. The app's update check records a release (and a raised security floor) only when its signature verifies too. So publishing a release on GitHub isn't enough to reach installs: it also takes a signing key, and those never touch GitHub. Nothing changes for people installing or updating; the checks are inside the app.

Two keys, either one enough. Installs can't tell them apart, so what keeps each safe is where it's kept:

- **A hardware security key** (`fido2-ed25519`), the everyday signer since 0.9.86: a FIDO2 credential stored on the key itself, used with its PIN and a touch, from any computer. Someone who got into the maintainer's machine still couldn't sign without the key in hand and its PIN. `release.sh prepare` signs with it (`scripts/release-sign-fido.py`; `enroll` made the credential). The key signs its authenticator data followed by SHA-256 of the statement, and the app requires the touch and PIN flags.
- **The key file** (`ed25519`), the recovery signer. It lives in the maintainer's password manager, not on disk. To use it, save it to a file for the moment, then `BETTERCAL_RELEASE_KEY=FILE scripts/release.sh prepare routine X.Y.Z --recovery`, and delete the file afterwards. `scripts/release-sign.php` does the work.

Verification is sodium's Ed25519 for both, so installs need nothing new.

**Changing keys.** Installs check each update with the key list they already have, so a release signed by a key on the current list can add a key or drop one. `update.php` says so whenever a release changes the list ("adds the signing key …, removes …"), so a change is never quiet. A lost or broken hardware key: enroll a new one, add it to `release-keys.php`, remove the old entry, and sign that release with the key file (`--recovery`). An exposed key file: make a new one (`php scripts/release-sign.php --new-key`), replace the old entry, and sign that release with the hardware key. Only if every listed key were lost at once would installs need one update by hand, to a release carrying new keys.

**By hand.** To sign or check a release outside `release.sh`: `php scripts/release-sign.php --statement --version=X.Y.Z --commit=SHA --zip=FILE --manifest=FILE --out=statement.txt`, then `--sign --statement-file=statement.txt --out=bettercal-release.sig`, and `--verify=bettercal-release.sig` with the same `--version`, `--commit`, `--zip` and `--manifest` to confirm.

## Extension release

Browser-extension releases use `extension-vX.Y.Z`, remain separate from application tags, and must be marked not-latest. Prepare their complete final assets on a draft and publish only after verification. Do not attach `bettercal-release.json`; the application update checker deliberately ignores extension tags.
