# Security updates — 2026-10-25

## Application release

The production application tracks the `main` branch of `smallPush/civicrm_drupal`.
This security release updates the existing main-based application, preserving
the separate `production` branch and its independent customizations.

| Component | Previous | Updated |
| --- | --- | --- |
| PHP | 8.3.36 | 8.3.36 (already patched) |
| Composer | 2.7.9 | 2.10.3 |
| Drupal | 11.4.0-rc2 | 11.4.11 |
| Webform | 6.3.0-rc2 | 6.3.1 |
| CiviCRM | 6.15.5 (database 6.15.0) | 6.19.2 |
| Guzzle | 7.10.6 | 7.16.0 |
| Guzzle PSR-7 | 2.11.1 | 2.13.2 |
| Dompdf | 3.1.5 | 3.1.6 |
| PhpSpreadsheet | 2.4.6 | 5.10.0 |
| phpseclib | 3.0.55 | 3.0.57 |
| Symfony HttpFoundation / YAML | 7.4.13 | 7.4.21 |

The complete reproducible package list is `composer.lock`. Composer security
blocking is enabled, and Drupal/Webform constraints require stable fixed versions.

## Configuration

- The PHP security baseline is also built into the image. Keep session upload
  progress disabled for the PHP advisory of 23 October (CVE-2026-115560).
- Application code is root-owned; only the persistent runtime directory is
  writable by Apache. `.git`, credentials and generated dependencies are excluded
  from the Docker build context.
- CiviCRM translations matching the locked release are included at build time.
- `CIVICRM_CRED_KEYS` and `CIVICRM_SIGN_KEYS` are read from the environment.
  `ops/configure-civicrm-keys.py` initializes missing production keys privately in
  Dokploy and retains existing keys. Secret values never belong in Git.
- Startup initializes runtime directories only; it never reinstalls Drupal.

## Backup and validation

`ops/security-backup.py` created verified logical dumps of all seven active
databases, configuration/upload archives, private Swarm definitions and checksums
under `/root/backups/updates-20261025T115619Z/` (root-only, outside Git).

`ops/test-upgrade.py` clones the application database and uploaded files into an
isolated internal Docker network. The clone cannot send mail or reach production
services. Tests passed for Drupal database updates, the CiviCRM 6.19.2 migration,
cache rebuild, Drupal bootstrap, login HTTP 200 and all Composer platform checks.
The test removes only its own temporary containers, volumes, network and file copy.

Both Composer manifest validation and locked dependency audit passed; the audit
returned no advisories and no abandoned packages.

## Deployment and rollback

1. Verify backups and the isolated migration test.
2. Persist required CiviCRM keys in Dokploy; enable Drupal maintenance mode.
3. Publish the tested commit to `main`; deploy through Dokploy from Git. The
   fallback `ops/deploy-from-git.py` queues the existing private webhook locally
   without printing its credential.
4. Run `python3 ops/migrate-production.py`. This runs Drupal and CiviCRM updates
   as `www-data`; it temporarily permits MySQL trigger creation during migration
   and restores the original `log_bin_trust_function_creators` value afterwards.
   It never grants `SUPER` to the application database user.
5. Rebuild caches, verify versions, database and endpoints, then disable
   maintenance mode.

The CiviCRM migration follows the console queue flow used by `cv upgrade:db`;
the legacy Headless runner failed in the isolated test because its table lock
conflicts with lazy entity metadata queries.

Database migrations cannot safely be undone just by selecting an older image.
If necessary, enter maintenance mode, restore the matching private database and
upload backup, and redeploy the previous Git revision/image as a coordinated
operation. Retain the new CiviCRM keys with any database that has used them.

## Host and platform updates applied

| Component | Previous | Updated |
| --- | --- | --- |
| Docker Engine / CLI / rootless extras | 28.5.0 | 29.9.2 |
| containerd | 2.2.2 | 2.4.2 |
| runc (bundled) | 1.3.4 | 1.5.2 |
| Buildx | 0.31.1 | 0.38.1 |
| Compose | 5.1.1 | 5.6.1 |
| Docker Model plugin | 1.1.28 | 1.2.9 |
| Traefik | 3.1 | 3.7.16 |
| Dokploy | 0.30.8 | 0.30.10 |

`ops/upgrade-host.sh` records exact Debian package versions. The previous seven
Docker packages were downloaded to the private pre-upgrade backup's `packages/`
directory. No packages remained upgradable after installation.

Platform image versions and immutable registry digests are recorded in
`ops/platform-images-20261025.json` and in the actual Swarm service specifications.
They were applied with `docker service update --image <pinned-reference>
--update-failure-action rollback <service>`, preserving mounts, secrets, networks
and limits. Dokploy uses stop-first updates on this single-node server because
its published host port cannot be bound by two tasks simultaneously.

A second verified backup, including the migrated application database and the
new CiviCRM keys inside Dokploy's private database dump, is available under
`/root/backups/updates-20261025T125059Z/`.

Docker's package upgrade restarted the runtime and Swarm recreated the active
tasks. The manager returned Ready/Leader. The persistent administrative firewall
was reapplied and checked in both IPv4 and IPv6. Traefik discovery and Dokploy's
health endpoint worked against the updated Docker API.

Final machine-readable evidence is `docs/security-verification-20261025.json`,
generated by `ops/verify-server.py`. It records packages, running images, dependency
audits, Drupal bootstrap, configuration hashes, firewall checks and the eleven
HTTPS endpoints. The six previously operational domains still respond 200.
The five pre-existing 502 responses (realstate, findwork, local, clota and demo)
remain pre-existing failures, not successful health checks or newly fixed apps.

The application source deployment was built by Dokploy from Git commit `2e6c098`;
the follow-up operations commit adds evidence and host-side maintenance tooling.
Network segmentation between projects and per-application memory limits remain
separate hardening work; this release updates software versions and their
necessary deployment configuration.
