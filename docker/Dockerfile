# WordPress 7.1.3 with WooCommerce 11.2.1 on Apache, for this plugin's tests
# and for trying it out, with WordPress's own PHPUnit test library at
# /wordpress-phpunit. The PHP version is a build argument: CI builds 8.1, 8.4
# and 8.5.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libzip-dev unzip mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install -j"$(nproc)" mysqli zip \
 && (pecl install pcov && docker-php-ext-enable pcov || echo "pcov is not available for this PHP: no coverage")

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Each download is checked against its published file's SHA-256.
ARG WP_CLI_VERSION=2.12.0
ARG WP_CLI_SHA256=ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c
RUN curl -fsSL -o /usr/local/bin/wp "https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar" \
 && echo "${WP_CLI_SHA256}  /usr/local/bin/wp" | sha256sum -c - \
 && chmod +x /usr/local/bin/wp

# WordPress, and its PHPUnit test library (tests/phpunit from
# wordpress-develop at the same version), which the tests boot WordPress
# with.
ARG WORDPRESS_VERSION=7.1.3
ARG WORDPRESS_SHA256=eb58a90ed55147f90f7bd55d0d3334960a69b7e8a4cc142c8ddfea8965218227
ARG WORDPRESS_DEVELOP_SHA256=92cce063c80fcb55271e91e8cc22df809dddb09d8d33d83fe91f5e8ee821d6b5
RUN curl -fsSL -o /tmp/wordpress.zip "https://downloads.wordpress.org/release/wordpress-${WORDPRESS_VERSION}.zip" \
 && echo "${WORDPRESS_SHA256}  /tmp/wordpress.zip" | sha256sum -c - \
 && unzip -q /tmp/wordpress.zip -d /tmp \
 && cp -a /tmp/wordpress/. /var/www/html/ \
 && rm -rf /tmp/wordpress /tmp/wordpress.zip \
 && curl -fsSL -o /tmp/develop.tar.gz "https://github.com/WordPress/wordpress-develop/archive/refs/tags/${WORDPRESS_VERSION}.tar.gz" \
 && echo "${WORDPRESS_DEVELOP_SHA256}  /tmp/develop.tar.gz" | sha256sum -c - \
 && mkdir /wordpress-phpunit \
 && tar xzf /tmp/develop.tar.gz -C /wordpress-phpunit --strip-components=3 \
      "wordpress-develop-${WORDPRESS_VERSION}/tests/phpunit/includes" "wordpress-develop-${WORDPRESS_VERSION}/tests/phpunit/data" \
 && rm /tmp/develop.tar.gz

ARG WOOCOMMERCE_VERSION=11.2.1
ARG WOOCOMMERCE_SHA256=5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586
RUN curl -fsSL -o /tmp/woocommerce.zip "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" \
 && echo "${WOOCOMMERCE_SHA256}  /tmp/woocommerce.zip" | sha256sum -c - \
 && unzip -q /tmp/woocommerce.zip -d /var/www/html/wp-content/plugins \
 && rm /tmp/woocommerce.zip

COPY docker/wp-tests-config.php /wordpress-phpunit/wp-tests-config.php
ENV WP_TESTS_DIR=/wordpress-phpunit

# Apache on WP_PORT (the compose file runs on the host's network, so the
# shop's address is the same from the browser, from Monokulo and from the
# tests).
ARG WP_PORT=8092
RUN sed -i "s/^Listen 80$/Listen ${WP_PORT}/" /etc/apache2/ports.conf \
 && sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${WP_PORT}>/" /etc/apache2/sites-available/000-default.conf \
 && printf 'display_errors = Off\nlog_errors = On\nerror_reporting = E_ALL\nmemory_limit = 512M\npcov.enabled = 0\npcov.directory = /repo\n' > /usr/local/etc/php/conf.d/wordpress.ini

# Files Apache makes (uploads, logs) stay writable by the tests, which boot
# WordPress in-process as another user.
RUN echo 'umask 000' >> /etc/apache2/envvars \
 && chown -R www-data:www-data /var/www/html

COPY docker/wp-setup /usr/local/bin/wp-setup
COPY docker/mu-plugins/ /usr/src/mu-plugins/
RUN chmod +x /usr/local/bin/wp-setup
