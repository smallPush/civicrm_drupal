FROM php:8.3.36-apache

# Install required packages and PHP extensions for Drupal and CiviCRM
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libfreetype6-dev \
    curl \
    git \
    unzip \
    mariadb-client \
    wget \
    && rm -rf /var/lib/apt/lists/*

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mysqli pdo_pgsql zip intl opcache bcmath

# Enable Apache modules and set a default server name for container logs
RUN a2enmod rewrite \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Install Composer
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

# Apply the security baseline even when deployed without the host bind mount.
COPY docker/php-security.ini /usr/local/etc/php/conf.d/zz-security.ini

# Set default PHP memory limit for Drupal, CiviCRM, and Drush to 512 mg as requested.
RUN echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/memory-limit.ini

# Set working directory
WORKDIR /var/www/html

# Copy dependency metadata and patches before install so Docker builds are reproducible.
COPY composer.json composer.lock ./
COPY patches ./patches

# Allow CiviCRM composer plugins and install dependencies
RUN composer config allow-plugins.cweagans/composer-patches true \
    && composer config allow-plugins.civicrm/composer-compile-plugin true \
    && composer config allow-plugins.civicrm/composer-downloads-plugin true \
    && composer config allow-plugins.civicrm/civicrm-asset-plugin true \
    && composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer config allow-plugins.phpstan/extension-installer true \
    && composer install --no-dev --no-interaction 

# Ship matching translations so bootstrap and upgrades do not need network access.
RUN CIVICRM_VERSION="$(composer show civicrm/civicrm-core --format=json | php -r '$data = json_decode(stream_get_contents(STDIN), true); echo $data["versions"][0];')" \
    && curl -fsSL "https://download.civicrm.org/civicrm-${CIVICRM_VERSION}-l10n.tar.gz" \
      | tar -xz --strip-components=1 -C vendor/civicrm/civicrm-core \
          civicrm/l10n civicrm/sql/civicrm_data.ca_ES.mysql civicrm/sql/civicrm_acl.ca_ES.mysql

# Copy application files
COPY . .
# Add default settings
COPY settings.php /var/www/html/web/sites/default/settings.php
COPY civicrm.settings.php /var/www/html/web/sites/default/civicrm.settings.php
# Static endpoint for Docker/Dokploy health checks that does not depend on Drupal bootstrap.
RUN printf 'ok\n' > /var/www/html/web/healthz

# Application code stays root-owned; only persistent runtime files are writable.
RUN install -d -o www-data -g www-data -m 755 /var/www/html/web/sites/default/files

# Configure DocumentRoot to the web directory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --chmod=755 docker/security-entrypoint.sh /usr/local/bin/security-entrypoint
ENTRYPOINT ["security-entrypoint"]
EXPOSE 80
CMD ["apache2-foreground"]
