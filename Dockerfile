# syntax=docker/dockerfile:1
#
# ServerAvatar MCP: one image for the web app (nginx + PHP-FPM, port 8080, runs as www-data).
# The same image runs the scheduler (see docker-compose.yml). Secrets are never baked in: APP_KEY,
# database settings and everything else arrive as environment variables at run time.

ARG PHP_VERSION=8.3

# ---- 1. PHP dependencies (production only) -------------------------------------------------------
FROM serversideup/php:${PHP_VERSION}-fpm-nginx AS vendor
USER root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

# ---- 2. Front-end build ---------------------------------------------------------------------------
FROM node:26-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
# The Vite plugin resolves `ziggy-js` from the PHP package.
COPY --from=vendor /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY vite.config.js tsconfig.json components.json ./
COPY resources ./resources
COPY public ./public
RUN npm run build

# ---- 3. The app ------------------------------------------------------------------------------------
FROM serversideup/php:${PHP_VERSION}-fpm-nginx AS app
USER root
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV PHP_OPCACHE_ENABLE=1 \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    SSL_MODE=off

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .
COPY --chown=www-data:www-data --from=vendor /app/vendor ./vendor
COPY --chown=www-data:www-data --from=assets /app/public/build ./public/build

RUN mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --no-dev --optimize --no-scripts --no-interaction \
    # package:discover needs the framework to boot; a throwaway key is used for this one command only.
    && APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache \
    && rm /usr/bin/composer

# Runs at every start, before the web server: checks APP_KEY, creates the OAuth signing keys once, migrates.
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/

USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD php -r 'exit(@file_get_contents("http://127.0.0.1:8080/up") === false ? 1 : 0);'
