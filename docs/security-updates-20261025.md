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
4. Run `drush --uri=https://donesemprenedores.smallpush.org updatedb --yes` and
   `drush ... php:script /var/www/html/ops/upgrade-civicrm.php` as `www-data`.
5. Rebuild caches, verify versions, database and endpoints, then disable
   maintenance mode.

The CiviCRM migration follows the console queue flow used by `cv upgrade:db`;
the legacy Headless runner failed in the isolated test because its table lock
conflicts with lazy entity metadata queries.

Database migrations cannot safely be undone just by selecting an older image.
If necessary, enter maintenance mode, restore the matching private database and
upload backup, and redeploy the previous Git revision/image as a coordinated
operation. Retain the new CiviCRM keys with any database that has used them.

Host package/proxy results and final endpoint checks are recorded in the follow-up
operations report for this release.
