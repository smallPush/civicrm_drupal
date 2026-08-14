# syntax=docker/dockerfile:1
FROM php:8.3-apache

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
RUN a2enmod rewrite expires headers \
    && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

# Configure Apache static asset caching headers
RUN { \
    echo '<IfModule mod_expires.c>'; \
    echo '  ExpiresActive On'; \
    echo '  ExpiresDefault "access plus 1 hour"'; \
    echo '  ExpiresByType image/jpeg "access plus 1 year"'; \
    echo '  ExpiresByType image/png "access plus 1 year"'; \
    echo '  ExpiresByType image/gif "access plus 1 year"'; \
    echo '  ExpiresByType image/webp "access plus 1 year"'; \
    echo '  ExpiresByType image/svg+xml "access plus 1 year"'; \
    echo '  ExpiresByType image/x-icon "access plus 1 year"'; \
    echo '  ExpiresByType image/vnd.microsoft.icon "access plus 1 year"'; \
    echo '  ExpiresByType text/css "access plus 1 year"'; \
    echo '  ExpiresByType text/javascript "access plus 1 year"'; \
    echo '  ExpiresByType application/javascript "access plus 1 year"'; \
    echo '  ExpiresByType font/ttf "access plus 1 year"'; \
    echo '  ExpiresByType font/otf "access plus 1 year"'; \
    echo '  ExpiresByType font/woff "access plus 1 year"'; \
    echo '  ExpiresByType font/woff2 "access plus 1 year"'; \
    echo '  ExpiresByType application/font-woff "access plus 1 year"'; \
    echo '  ExpiresByType application/vnd.ms-fontobject "access plus 1 year"'; \
    echo '</IfModule>'; \
    echo '<IfModule mod_headers.c>'; \
    echo '  <FilesMatch "\.(css|js|jpg|jpeg|png|gif|webp|svg|ico|ttf|otf|woff|woff2|eot)$">'; \
    echo '    Header set Cache-Control "max-age=31536000, public, immutable"'; \
    echo '  </FilesMatch>'; \
    echo '</IfModule>'; \
} > /etc/apache2/conf-available/static-cache.conf \
&& a2enconf static-cache

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

# Set default PHP memory limit and OPcache production settings
RUN echo 'memory_limit = 512M' > /usr/local/etc/php/conf.d/memory-limit.ini \
    && { \
        echo '[opcache]'; \
        echo 'opcache.enable = 1'; \
        echo 'opcache.enable_cli = 1'; \
        echo 'opcache.memory_consumption = 256'; \
        echo 'opcache.interned_strings_buffer = 16'; \
        echo 'opcache.max_accelerated_files = 20000'; \
        echo 'opcache.validate_timestamps = 0'; \
        echo 'opcache.revalidate_freq = 0'; \
        echo 'opcache.save_comments = 1'; \
        echo 'opcache.fast_shutdown = 1'; \
        echo 'realpath_cache_size = 4096K'; \
        echo 'realpath_cache_ttl = 600'; \
    } > /usr/local/etc/php/conf.d/opcache-recommended.ini

# Set working directory
WORKDIR /var/www/html

# Copy dependency metadata and patches before install so Docker builds are reproducible.
COPY composer.json composer.lock ./
COPY patches ./patches

# Allow CiviCRM composer plugins and install dependencies with optimized autoloader.
# The GitHub token is mounted only for this step and is not persisted in an image layer.
RUN --mount=type=secret,id=github_token,required=true \
    export COMPOSER_AUTH="$(php -r '$token = trim(file_get_contents("/run/secrets/github_token")); echo json_encode(["github-oauth" => ["github.com" => $token]]);')" \
    && composer config allow-plugins.cweagans/composer-patches true \
    && composer config allow-plugins.civicrm/composer-compile-plugin true \
    && composer config allow-plugins.civicrm/composer-downloads-plugin true \
    && composer config allow-plugins.civicrm/civicrm-asset-plugin true \
    && composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer config allow-plugins.phpstan/extension-installer true \
    && composer install --no-dev --no-interaction --optimize-autoloader 

# Copy application files
COPY . .
# Add default settings
COPY settings.php /var/www/html/web/sites/default/settings.php
COPY civicrm.settings.php /var/www/html/web/sites/default/civicrm.settings.php
# Static endpoint for Docker/Dokploy health checks that does not depend on Drupal bootstrap.
RUN printf 'ok\n' > /var/www/html/web/healthz

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure DocumentRoot to the web directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Configure entrypoint script for automatic installation and initialization
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]

