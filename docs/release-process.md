# Branch and release process

## Permanent branches

| Branch | Purpose | Accepted changes |
| --- | --- | --- |
| `develop` | Default integration and test branch | Pull requests from short-lived work branches |
| `production` | Deployable production history | Pull requests from `release/*` or `hotfix/*` branches after CI passes |
| `main` | Historical branch retained during migration | No new development |

`@smallPush` is the current development owner through `.github/CODEOWNERS`. When a GitHub development team exists, replace the account with that team.

## Short-lived branches

- `feature/<short-description>`: new behavior, created from `develop` and merged into `develop`.
- `fix/<short-description>`: non-urgent fixes, created from `develop` and merged into `develop`.
- `chore/<short-description>`: maintenance and documentation, created from `develop` and merged into `develop`.
- `dependency/<package-or-platform>`: dependency updates, created from `develop` and merged into `develop`.
- `release/<project-version>`: release stabilization, created from `develop` and merged into `production`.
- `hotfix/<short-description>`: urgent production fixes, created from `production`, merged into `production`, and then merged back into `develop`.

Delete short-lived branches after merging. Do not commit directly to `develop` or `production` after branch protection is enabled.

## Version source

`release.json` records three exact versions:

- `project`: this repository's semantic version (`MAJOR.MINOR.PATCH`).
- `drupal`: the exact `drupal/core-recommended` version in `composer.lock`.
- `civicrm`: the exact shared version of `civicrm/civicrm-core` and `civicrm/civicrm-drupal-8` in `composer.lock`.

Use project version increments consistently:

- `PATCH`: backwards-compatible fixes and maintenance.
- `MINOR`: backwards-compatible features or supported Drupal/CiviCRM upgrades.
- `MAJOR`: incompatible configuration, deployment, data, or platform changes.

Any Drupal or CiviCRM update must include `composer.json`, `composer.lock`, and `release.json` in the same pull request. Run `composer release:version` to verify the manifest and print the required tag.

## Tags

Production releases use annotated, immutable tags with clean semantic versions:

```text
v<project>
```

Example:

```text
v1.3.0
```

Create a release only from the tested commit on `production`:

```bash
TAG="$(composer release:version)"
git tag -a "$TAG" -m "Release $TAG"
git push origin "$TAG"
```

The tag workflow rejects a name that does not match `release.json` or platform versions that do not match `composer.lock`.

## Release sequence

1. Update and test changes on `develop` through pull requests.
2. Set the next project version in `release.json` and create `release/<project-version>` from `develop`.
3. Allow only release fixes on the release branch and open a pull request to `production`.
4. Merge only after the version and Docker checks pass.
5. Create the annotated tag printed by `composer release:version` on the resulting `production` commit.
6. Merge `production` back into `develop` if release fixes were added.
