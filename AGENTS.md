# AGENTS.md

## Project Shape
- This is a Drupal 11 + CiviCRM base project with the `civicrm_secure` custom profile and the `smallpush_ui` theme required via Composer.
- The public document root is `web/`; Composer/Drupal scaffold generates most of it, so commit only intentional custom code/config there.
- Root `settings.php` and `civicrm.settings.php` are deployment templates; the Docker build copies them to `web/sites/default/`.
- Docker Compose runs two services: `web` from the local `Dockerfile` and `db` from `mariadb:10.11`.


## Commands
- First local setup: `cp .env.example .env`, then edit `.env` values, especially `DRUPAL_HASH_SALT`.
- Full local build/run: `docker compose up -d --build`.
- Focused Docker validation test: `composer test:docker`.
- Validate Compose without starting services: `docker compose --env-file .env.example config` when no local `.env` exists.
- Check running services: `docker compose ps`.
- Follow app logs: `docker compose logs -f web`; database logs: `docker compose logs -f db`.
- Local URL defaults to `http://localhost:8080`; override via `PORT` in `.env`.
- Local Drush commands run inside the web container, e.g. `docker compose exec web ./vendor/bin/drush cr`.

## Dependency And Generated Files
- Keep `composer.json` and `composer.lock` in sync; the Docker build installs from the lockfile with `composer install --no-dev --no-interaction`.
- `composer.json` applies `patches/civicrm-core-opendir.patch`; keep `patches/` available before Composer install in Dockerfile changes.
- To update Drupal/CiviCRM dependencies, change constraints if needed, run the relevant `composer update ...`, commit both manifests, then rebuild.
- Do not commit generated Composer/Drupal artifacts ignored by `.gitignore`: `vendor/`, `web/core/`, `web/modules/contrib/`, `web/themes/contrib/`, `web/profiles/contrib/`, `web/libraries/`, or `web/sites/*/files/`.
- Do not commit `.env`; use `.env.example` for documented defaults.

## Runtime Configuration
- Database connection values come from `DB_HOST`, `DB_USER`, `DB_PASSWORD`, and `DB_NAME`; Compose sets `DB_HOST=db` for the web container.
- CiviCRM defaults to the same MariaDB database via `CIVICRM_DB_*` variables mapped from `DB_*`; `CIVICRM_SITE_KEY` should be set in Dokploy/production.
- Apache serves `/var/www/html/web`; do not change paths assuming `/var/www/html` is the document root.
- `web/sites/default/files` is the persisted upload/private/config-sync area via the `drupal_data` volume.
- `web/healthz` is a static Docker/Dokploy health endpoint; it must not depend on Drupal bootstrap.
- Dokploy recreates containers from Git. Production container entrypoint (`docker-entrypoint.sh`) automatically installs site baseline via `civicrm_secure` profile and sets `smallpush_ui` as default theme if uninstalled. Permanent dependency/config changes belong in repository files and environment variables, never manual container edits.

## Dokploy Notes
- Dokploy deploys from Git and recreates the web container; do not make permanent Composer/config edits inside the running container.
- Production configuration should come from repo files plus Dokploy environment variables, not from manual edits under `web/sites/default/` in the container.
- For a failed CiviCRM install, README has the exact Drush uninstall/cleanup commands; back up the database before using the php:eval cleanup.

## Verification Notes
- No CI workflow, PHPUnit config, PHPCS config, PHPStan config, or task runner is present in the repo.
- `composer test:docker` validates Compose with `.env.example` and builds the `web` image.
- For infrastructure-only changes, use `docker compose --env-file .env.example config` as the focused check.
- For Dockerfile, Composer, patches, or runtime config changes, prefer `docker compose --env-file .env.example build web`; use `docker compose up -d --build` and `docker compose ps` when runtime smoke testing is needed.
- When adding custom code, default to TDD, but first add an explicit test runner/config because none exists yet.
