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

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Install Composer
COPY --from=composer:2.7 /usr/bin/composer /usr/local/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json ./

# Allow CiviCRM composer plugins and install dependencies
RUN composer config allow-plugins.cweagans/composer-patches true \
    && composer config allow-plugins.civicrm/composer-compile-plugin true \
    && composer config allow-plugins.civicrm/composer-downloads-plugin true \
    && composer config allow-plugins.civicrm/civicrm-asset-plugin true \
    && composer config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true \
    && composer config allow-plugins.phpstan/extension-installer true \
    && composer install --no-dev --no-interaction 

# Copy application files
COPY . .
# Add default settings
COPY settings.php /var/www/html/web/sites/default/settings.php

# Adjust permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure DocumentRoot to the web directory
ENV APACHE_DOCUMENT_ROOT /var/www/html/web
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

EXPOSE 80
CMD ["apache2-foreground"]
