# Releasing Better-Cal

How the maintainer cuts a release. Deploying it to a server with the maintainer's scripts is in [deploying.md](deploying.md); what installs check is in [SECURITY.md](../SECURITY.md#releases-and-updates).

Published releases are immutable: their tag and assets can't be replaced, though titles, notes, prerelease state and the "latest" label can still be edited. A bad tag or asset is fixed with a new patch release.

## Application Release

1. Make the release commit on `main`: update `VERSION`, add the matching `CHANGELOG.md` section (ending with its Operators line), run the full suite, push, and wait for the `tests` workflow to pass.
2. Prepare a draft:

   ```sh
   scripts/release.sh prepare routine 1.1.0
   ```

   After `prepare` comes the priority, `routine`, `recommended` or `security`, then the cumulative minimum secure version: a security fix normally sets it to the new version, and later routine releases keep that floor until another security release raises it.

   The helper tags the green commit and pushes the tag, writes and verifies the manifest (`bettercal-release.json`), and creates a draft GitHub release with it. The notes are the version's `CHANGELOG.md` section with links made absolute, then a pointer to the update steps and the comparison with the previous tag (`scripts/release-notes.mjs`); the app's update notice links there, so the Operators line is what someone updating reads first. It then runs `.github/workflows/release-zip.yml`, which builds `better-cal-X.Y.Z.zip` from the tag (the source without CI and tests, a `.release-files` list of what it ships, and the PHP libraries installed from the lock file in the pinned PHP 8.4 image, assembled by `scripts/release-assemble.sh`) and attaches it to the draft. Finally it runs the [checks below](#checks-before-signing) and signs, asking for the security key's PIN and a touch.
3. Deploy that exact commit and complete the production smoke checks while the release is still a draft.
4. Publish only after those checks pass:

   ```sh
   scripts/release.sh publish --deployment-verified
   ```

   The flag records the human check. The helper rechecks the clean commit, tag, CI result and manifest, refuses to publish unless the repository's immutable-release setting is on and the draft has exactly one zip, publishes the draft, and confirms GitHub reports it immutable.

Installs trust only a published, non-prerelease, immutable `vX.Y.Z` release of this repository with exactly one manifest whose GitHub SHA-256 digest matches, and `server/bin/update.php` installs one only when its signature also verifies.

## Signing

Every release carries `bettercal-release.sig`: one statement naming the version, the tag's commit and the SHA-256 of the zip and the manifest, signed by at least one key in `server/config/release-keys.php`. `update.php` refuses a release unless a signature verifies, and in a git checkout it also checks that the tag points at the signed commit; the app's update notice records a release (and a raised security floor) only when it verifies too. So publishing on GitHub isn't enough to reach installs: it also takes a signing key, and those never touch GitHub. These checks protect the updater, not a fresh install or a hand upload ([Optional verification before upload](install.md#optional-verification-before-upload) covers those).

Any one listed key is enough. Installs can't tell the keys apart, so what keeps each safe is where it's kept:

- **Hardware security keys** (`fido2-ed25519`), the everyday signers (two are listed; either signs): a FIDO2 credential on each key itself, used with its PIN and a touch, from any computer. `release.sh prepare` signs with it (`scripts/release-sign-fido.py`; `bash scripts/release-signer.sh enroll --key-id NAME`, named after the key's model, makes a credential and prints its entry for `release-keys.php`, and `show` prints it again). The key signs its authenticator data followed by the SHA-256 of the statement, and installs require the touch and PIN flags. It protects its private key, but malicious software on the computer could substitute a statement during a genuine PIN and touch.
- **The key file** (`ed25519`), the recovery signer, kept in the maintainer's password manager, not on disk. To use it, save it to a file for the moment, run `BETTERCAL_RELEASE_KEY=FILE scripts/release.sh prepare routine X.Y.Z --recovery`, and delete the file. `scripts/release-sign.php` does the work.

Both verify with sodium's Ed25519, so installs need nothing extra.

**Changing keys.** Installs check each update with the key list of the release they run, so a release signed by a key already listed can add or drop one, and `update.php` says so whenever a release changes the list. A newly added key can sign on its own only once a release listing it has shipped; `prepare` refuses a signature the previous release's keys wouldn't accept. For a lost or broken hardware key: enroll a new one, add it to `release-keys.php`, remove the old entry, and sign that release with the other hardware key or `--recovery`. For an exposed key file: make a new one (`php scripts/release-sign.php --new-key`), replace the old entry, and sign that release with the hardware key. Only if every listed key were lost at once would installs need one update by hand.

**Always sign through `scripts/release.sh prepare`,** with either key. The low-level statement and signing commands don't check the release contents or your intent, and a signature check afterwards only proves which bytes were signed, not that they were the intended release. To check an existing release without signing, use [optional verification](install.md#optional-verification-before-upload).

## Preparing the Signing Environment

Before preparing a release, run `bash scripts/release-signer.sh prepare` with a current uv. It downloads the exact uv-managed Python named in `scripts/release-signer/python-version` and installs only binary wheels matching the hashes in `scripts/release-signer/requirements.txt`, into the ignored `.release-signer/` folder. It doesn't touch the hardware key, and Homebrew or system Python is never used for signing.

`bash scripts/release-signer.sh check` checks the environment without a key. Signing checks the pinned interpreter, lock file and installed files, then runs that interpreter directly, with no package resolver or downloads. Release preparation runs this check before creating a tag or draft, and a missing or changed environment stops with instructions rather than repairing itself.

To update the signer's libraries, edit `scripts/release-signer/requirements.in`, run `uv pip compile --managed-python --python 3.14.8 --generate-hashes --no-header --no-annotate --only-binary :all: scripts/release-signer/requirements.in -o scripts/release-signer/requirements.txt`, and review the versions and hashes (change the Python pin only on purpose). Prepare the replacement with `BETTERCAL_SIGNER_DIR=/path/to/new-environment bash scripts/release-signer.sh prepare`, check it, and use the same setting for releases; keep the old environment until the new one checks out. Pins and hashes don't prove a dependency is benign.

## Checks Before Signing

Before either key is used, `prepare`:

- **compares the manifest** GitHub holds with the one it generated, byte for byte, from the priority and floor arguments;
- **checks the draft zip** for size, unsafe paths, duplicate entries, links and special files, streaming and hashing its entries without extracting them;
- **rebuilds the release** outside git from the tagged source with Composer 2.10.3 and the same assembler (Composer scripts and plugins off in both builds) and compares it with GitHub's zip file by file;
- **checks every library** in the tagged lock file against an export made from its exact Git commit, rejecting added, missing or changed files.

The library check caches Git objects in `~/.cache/better-cal/dependency-git`, which can be deleted between releases; a cold check needs network access and takes longer. A package without a supported Git source, or whose generated files don't reproduce, stops the release for review: don't bypass the difference or keep a list of exceptions. Checking a commit doesn't audit its code. The git-checkout updater still installs libraries with Composer after checking the signed commit, outside this guarantee.

## Extension Release

Browser-extension releases are tagged `extension-vX.Y.Z`, separate from application tags, and must be marked not-latest. Prepare their final assets on a draft and publish only after checking it. Don't attach `bettercal-release.json`; the app's update check ignores extension tags.
