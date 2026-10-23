# Laravel 12 on PHP 8.3 + Apache, for Render.com (Docker runtime).
# Secrets (APP_KEY, DB_*, DEFAULT_ACCOUNT_PASSWORD) are NOT in the image: set them as environment variables on Render.
# Exact patch version on purpose: 8.3.36 fixes PHP2Shell (CVE-2026-109618 / CVE-2026-109619); never go below it.
FROM php:8.3.36-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libicu-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql gd zip intl bcmath opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP: production settings, enough memory / time for the Excel and PDF exports
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-app.ini"
# Apache: serve only public/, allow Laravel's .htaccess rewrites
COPY docker/apache.conf /etc/apache2/conf-available/zz-app.conf
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf \
    && a2enconf zz-app

WORKDIR /var/www/html

# dependencies first (cached layer), then the code
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

CMD ["/usr/local/bin/start.sh"]
