# Releasing Better-Cal

Published releases are immutable: their tag and assets cannot be replaced. Titles, notes, prerelease state and the “latest” designation remain editable. A bad tag or asset is corrected by a new patch release.

## Application release

1. Make the release commit on `main`: update `VERSION`, add the matching `CHANGELOG.md` section, run the full suite, push, and wait for the `tests` workflow to pass.
2. Prepare a draft with the final release manifest:

   ```sh
   scripts/release.sh prepare routine 1.1.0
   ```

   The second argument is `routine`, `recommended`, or `security`. The last argument is the cumulative minimum secure version. A security fix normally sets that floor to the new version; later routine releases retain the same floor until another security release raises it.
3. The helper creates and pushes the annotated version tag at the exact green commit, generates and verifies `bettercal-release.json`, and creates a draft GitHub release with that final asset.
4. Deploy that exact commit and complete the production smoke checks while the release is still a draft.
5. Publish only after those checks succeed:

   ```sh
   scripts/release.sh publish --deployment-verified
   ```

   The explicit flag records the human gate. The helper rechecks the clean commit, tag, CI result and manifest, refuses to publish unless the repository's immutable-release setting is enabled, publishes the existing draft, and confirms GitHub reports the release immutable.

The application trusts only a published, non-prerelease, immutable `vX.Y.Z` release in this repository with exactly one canonical manifest asset whose GitHub-provided SHA-256 digest matches. It never installs code automatically.

## Extension release

Browser-extension releases use `extension-vX.Y.Z`, remain separate from application tags, and must be marked not-latest. Prepare their complete final assets on a draft and publish only after verification. Do not attach `bettercal-release.json`; the application update checker deliberately ignores extension tags.
