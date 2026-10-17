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

Every release carries `bettercal-release.sig`: one statement naming the version, the tag's commit and the SHA-256 of the zip and the manifest, signed by at least one key listed in `server/config/release-keys.php`. `update.php` refuses a release unless one of those signatures verifies, and in a git checkout it also checks that the tag points at the signed commit. The app's update check records a release (and a raised security floor) only when its signature verifies too. So publishing a release on GitHub isn't enough to reach installs: it also takes a signing key, and those never touch GitHub. These checks protect the signed updater, not a fresh install or an unchecked upload. [Optional local verification](install.md#optional-verification-before-upload) explains the trust needed before running downloaded code.

Two keys, either one enough. Installs can't tell them apart, so what keeps each safe is where it's kept:

- **A hardware security key** (`fido2-ed25519`), the everyday signer since 0.9.86: a FIDO2 credential stored on the key itself, used with its PIN and a touch, from any computer. The key protects its private key, but malicious software on the computer could substitute a statement during a legitimate PIN and touch. `release.sh prepare` signs with it (`scripts/release-sign-fido.py`; `enroll` made the credential). The key signs its authenticator data followed by SHA-256 of the statement, and the app requires the touch and PIN flags.
- **The key file** (`ed25519`), the recovery signer. It lives in the maintainer's password manager, not on disk. To use it, save it to a file for the moment, then `BETTERCAL_RELEASE_KEY=FILE scripts/release.sh prepare routine X.Y.Z --recovery`, and delete the file afterwards. `scripts/release-sign.php` does the work.

Verification is sodium's Ed25519 for both, so installs need nothing new.

**Changing keys.** Installs check each update with the key list they already have, so a release signed by a key on the current list can add a key or drop one. `update.php` says so whenever a release changes the list ("adds the signing key …, removes …"), so a change is never quiet. A lost or broken hardware key: enroll a new one, add it to `release-keys.php`, remove the old entry, and sign that release with the key file (`--recovery`). An exposed key file: make a new one (`php scripts/release-sign.php --new-key`), replace the old entry, and sign that release with the hardware key. Only if every listed key were lost at once would installs need one update by hand, to a release carrying new keys.

**Always prepare releases through the checked helper.** Use `scripts/release.sh prepare` for both the hardware key and `--recovery`. The low-level statement and signing commands do not check the release contents or maintainer intent; they are not an alternative release workflow. A signature check after signing only proves which bytes were signed, not that those bytes were the intended release. To check an existing release without signing, use [optional local verification](install.md#optional-verification-before-upload).

## Preparing the signing environment

Before preparing a release, run `bash scripts/release-signer.sh prepare` with a current uv that can download the Python version in `scripts/release-signer/python-version`. This downloads that exact uv-managed Python and installs only binary wheels matching the complete hashes in `scripts/release-signer/requirements.txt`. Nothing accesses the hardware key during preparation. Homebrew and system Python are not used for signing.

Run `bash scripts/release-signer.sh check` to check the prepared environment without a key. The environment is kept in the ignored `.release-signer/` folder. Ordinary signing checks the pinned interpreter, lockfile and installed files, then runs that interpreter directly, without a package resolver or network downloads. Release preparation checks this before creating a tag or draft. A missing or changed environment stops with instructions, not an automatic repair.

To update signer libraries, edit `scripts/release-signer/requirements.in`, then run `uv pip compile --managed-python --python 3.14.8 --generate-hashes --no-header --no-annotate --only-binary :all: scripts/release-signer/requirements.in -o scripts/release-signer/requirements.txt`. Review the versions and hashes. Change the Python pin deliberately when updating Python. Prepare a replacement with `BETTERCAL_SIGNER_DIR=/path/to/new-environment bash scripts/release-signer.sh prepare`, check it, and use that same setting for releases. Keep the previous environment until the replacement checks out. Neither a version pin nor a file hash proves a dependency is benign.

## Checks before either signer

The downloaded manifest must match the locally generated manifest byte for byte. A mismatch stops before the hardware key or recovery key is used. Re-running prepare regenerates intent from the explicit priority and security-floor arguments.

The draft ZIP is checked for compressed and expanded size, unsafe paths, duplicate entries, links and special files before comparison. Its entries are streamed and hashed, never extracted on the signing computer. The existing outside-git rebuild with Composer 2.10.3 and shared assembler remains required. Both builds disable Composer scripts and plugins.

Every library in the tagged Composer lockfile is also checked against an export made locally from its exact Git commit. The verifier checks received and cached Git objects, uses committed export rules and the assembler's existing exclusions, and rejects additions, missing files and changed bytes. Composer-generated files remain covered by the whole-release rebuild comparison. No per-file hash list needs refreshing on an ordinary dependency update.

The object cache is under `~/.cache/better-cal/dependency-git`; it can be removed when not preparing a release. A cold check needs network access and more time; cached unchanged packages do not need fetching. A package without the supported Git source mapping, or with generated distribution files that do not reproduce, stops release preparation for review. Do not bypass differences or grow a silent exception list. Checking a selected commit does not audit the code in that commit.

The git-based updater still installs libraries independently with Composer after checking the signed application commit. That documented path is outside the release ZIP's dependency guarantee.

## Extension release

Browser-extension releases use `extension-vX.Y.Z`, remain separate from application tags, and must be marked not-latest. Prepare their complete final assets on a draft and publish only after verification. Do not attach `bettercal-release.json`; the application update checker deliberately ignores extension tags.
