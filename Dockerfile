# syntax=docker/dockerfile:1

########## Stage 1: composer dependencies ##########
FROM composer:2 AS dependencies
WORKDIR /build
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --no-progress \
    --prefer-dist --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

########## Stage 2: front-end assets (tailwind scans vendor views) ##########
FROM node:22-alpine AS assets
WORKDIR /build
COPY package.json package-lock.json .npmrc ./
RUN npm ci
COPY --from=dependencies /build/vendor ./vendor
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm run build

########## Stage 3: runtime ##########
FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite pdo_mysql \
    && a2enmod rewrite headers \
    && sed -ri 's#DocumentRoot /var/www/html#DocumentRoot /var/www/html/public#' \
        /etc/apache2/sites-available/000-default.conf \
    && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . .
COPY --from=dependencies /build/vendor ./vendor
COPY --from=assets /build/public/build ./public/build

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint
RUN chmod +x /usr/local/bin/app-entrypoint

EXPOSE 80

ENTRYPOINT ["app-entrypoint"]
CMD ["apache2-foreground"]
