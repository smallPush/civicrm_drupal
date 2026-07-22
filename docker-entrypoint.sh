#!/usr/bin/env bash
set -e

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

# Check if Drupal site is installed
BOOTSTRAP_STATUS="$(./vendor/bin/drush status --field=bootstrap 2>/dev/null || true)"

if [[ "$BOOTSTRAP_STATUS" != *"Successful"* ]]; then
  echo "Drupal site is not installed. Launching automatic installation with civicrm_secure profile..."
  ./vendor/bin/drush site:install civicrm_secure \
    --uri="${CIVICRM_UF_BASEURL:-http://localhost:8080}" \
    --site-name="${SITE_NAME:-My Drupal Site}" \
    --account-name="${ADMIN_USER:-admin}" \
    --account-pass="${ADMIN_PASSWORD:-admin}" \
    -y
else
  echo "Drupal site is already installed. Rebuilding cache..."
  ./vendor/bin/drush cr || true
fi

exec "$@"
