# ============================================================
# Stage 1: RoadRunner binary
# ============================================================
FROM ghcr.io/roadrunner-server/roadrunner:2023.3.5 AS rr-binary

# ============================================================
# Stage 2: Front-end assets
# ============================================================
FROM node:20 AS frontend-builder
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

# ============================================================
# Stage 3: PHP dependencies
# ============================================================
FROM composer:2.7 AS composer-builder
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install \
    --optimize-autoloader \
    --no-scripts \
    --no-interaction \
    --ignore-platform-reqs
COPY . .
RUN composer run-script post-autoload-dump --no-interaction || true

# ============================================================
# Stage 4: Final image
# ============================================================
FROM php:8.2-cli

RUN apt-get update \
 && apt-get install -y \
    ca-certificates \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    git \
    curl \
 && docker-php-ext-install \
    pdo_mysql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    sockets \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

RUN echo 'display_errors = stderr'    > /usr/local/etc/php/conf.d/zz-octane.ini \
 && echo 'log_errors = On'           >> /usr/local/etc/php/conf.d/zz-octane.ini \
 && echo 'error_log = /dev/stderr'   >> /usr/local/etc/php/conf.d/zz-octane.ini \
 && echo 'variables_order = "EGPCS"' >> /usr/local/etc/php/conf.d/zz-octane.ini

ENV APP_BASE_PATH=/var/www/html
WORKDIR /var/www/html

COPY --from=composer-builder /app/vendor ./vendor
COPY --from=frontend-builder /app/public/build ./public/build
COPY . .

# Delete any stale bootstrap cache baked in from the local machine.
# The correct packages.php is regenerated at startup by package:discover in start.sh.
RUN rm -f bootstrap/cache/*.php

RUN mkdir -p public/vendor/horizon && cp -r vendor/laravel/horizon/dist/. public/vendor/horizon/

COPY --from=rr-binary /usr/bin/rr ./rr
RUN chmod +x ./rr

COPY docker/start.sh /start.sh
RUN sed -i 's/\r//' /start.sh && chmod +x /start.sh

RUN chown -R www-data:www-data /var/www/html \
 && chmod -R 755 storage \
 && chmod -R 755 bootstrap/cache

USER www-data
EXPOSE 8000

CMD ["/start.sh"]
