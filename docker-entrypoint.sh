#!/usr/bin/env bash
set -e

FILES_DIR="/var/www/html/web/sites/default/files"
mkdir -p "$FILES_DIR" \
         "$FILES_DIR/civicrm/templates_c" \
         "$FILES_DIR/civicrm/upload" \
         "$FILES_DIR/civicrm/custom" \
         "$FILES_DIR/civicrm/ConfigAndLog" \
         "$FILES_DIR/private" \
         "$FILES_DIR/config_sync"

chown -R www-data:www-data "$FILES_DIR"
chmod -R u+rwX,g+rwX "$FILES_DIR"

drush() {
  runuser -u www-data -- /var/www/html/vendor/bin/drush "$@"
}

# Wait for database connection if DB_HOST and DB_USER are set
if [ -n "${DB_HOST:-}" ] && [ -n "${DB_USER:-}" ]; then
  echo "Checking database connection to ${DB_HOST}:${DB_PORT:-3306}..."
  MAX_RETRIES=30
  RETRY_COUNT=0
  until mariadb-admin ping -h"${DB_HOST}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" --silent 2>/dev/null || [ $RETRY_COUNT -eq $MAX_RETRIES ]; do
    echo "Waiting for database connection ($RETRY_COUNT/$MAX_RETRIES)..."
    sleep 2
    RETRY_COUNT=$((RETRY_COUNT + 1))
  done

  if [ $RETRY_COUNT -eq $MAX_RETRIES ]; then
    echo "Warning: Database connection timed out. Proceeding anyway..."
  fi
fi

PAGE_CACHE_MAX_AGE="${DRUPAL_PAGE_CACHE_MAX_AGE:-900}"

# Check if Drupal site is installed
BOOTSTRAP_STATUS="$(drush status --field=bootstrap 2>/dev/null || true)"

if [[ "$BOOTSTRAP_STATUS" != *"Successful"* ]]; then
  EXISTING_DRUPAL_TABLES="$(
    mariadb -h"${DB_HOST}" -P"${DB_PORT:-3306}" -u"${DB_USER}" -p"${DB_PASSWORD}" "${DB_NAME}" \
      --batch --skip-column-names \
      -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('config', 'key_value');" \
      2>/dev/null || true
  )"
  if [[ "$EXISTING_DRUPAL_TABLES" != "0" ]]; then
    echo "Drupal bootstrap failed, but the database is not confirmed empty. Refusing automatic reinstallation." >&2
    exit 1
  fi

  echo "Drupal site is not installed. Launching automatic installation with civicrm_secure profile..."
  drush site:install civicrm_secure \
    --uri="${CIVICRM_UF_BASEURL:-http://localhost:8080}" \
    --site-name="${SITE_NAME:-My Drupal Site}" \
    --account-name="${ADMIN_USER:-admin}" \
    --account-pass="${ADMIN_PASSWORD:-admin}" \
    -y
  echo "Applying performance settings to fresh installation (page_cache_max_age=${PAGE_CACHE_MAX_AGE}, css/js preprocess=1)..."
  drush cset system.performance cache.page.max_age "${PAGE_CACHE_MAX_AGE}" -y || true
  drush cset system.performance css.preprocess 1 -y || true
  drush cset system.performance js.preprocess 1 -y || true
  echo "Rebuilding cache..."
  drush cr || true
else
  echo "Drupal site is already installed."
  echo "Applying performance settings to existing installation (page_cache_max_age=${PAGE_CACHE_MAX_AGE}, css/js preprocess=1)..."
  drush cset system.performance cache.page.max_age "${PAGE_CACHE_MAX_AGE}" -y || true
  drush cset system.performance css.preprocess 1 -y || true
  drush cset system.performance js.preprocess 1 -y || true
  echo "Rebuilding cache on deployment..."
  drush cr || true
fi

# Ensure CiviCRM directory permissions after cache rebuild
mkdir -p "$FILES_DIR/civicrm/templates_c"
chown -R www-data:www-data "$FILES_DIR/civicrm"
chmod -R u+rwX,g+rwX "$FILES_DIR/civicrm"

exec "$@"
