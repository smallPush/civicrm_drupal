#!/bin/sh
set -eu
# Initialize only runtime directories. Never install Drupal or change its database.
if [ "$(id -u)" = 0 ]; then
  install -d -o www-data -g www-data -m 755 \
    /var/www/html/web/sites/default/files/config_sync \
    /var/www/html/web/sites/default/files/private \
    /var/www/html/web/sites/default/files/civicrm/templates_c
fi
exec docker-php-entrypoint "$@"
