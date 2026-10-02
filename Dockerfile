FROM composer:2 AS vendor
RUN apk add --no-cache icu-dev && docker-php-ext-install intl
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --no-scripts

FROM node:26-alpine AS frontend
WORKDIR /app
COPY --from=vendor /app/vendor/filament ./vendor/filament
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
COPY app/Filament ./app/Filament
RUN npm run build

FROM php:8.5-apache
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev unzip curl \
    && docker-php-ext-install intl pdo_pgsql zip opcache pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && sed -ri 's!Listen 80!Listen 8080!' /etc/apache2/ports.conf \
    && sed -ri 's!<VirtualHost \*:80>!<VirtualHost *:8080>!' /etc/apache2/sites-available/*.conf
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build
RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["apache2-foreground"]
