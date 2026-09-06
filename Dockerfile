############################################
# Base Image
############################################

# Learn more about the Server Side Up PHP Docker Images at:
# https://serversideup.net/open-source/docker-php/
FROM serversideup/php:8.5-fpm-nginx-alpine AS base

## Uncomment if you need to install additional PHP extensions
# USER root
# RUN install-php-extensions bcmath gd

############################################
# Development Image
############################################
FROM base AS development

# We can pass USER_ID and GROUP_ID as build arguments
# to ensure the www-data user has the same UID and GID
# as the user running Docker.
ARG USER_ID
ARG GROUP_ID

# Switch to root so we can set the user ID and group ID
USER root

# Set the user ID and group ID for www-data
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID  && \
    docker-php-serversideup-set-file-permissions --owner $USER_ID:$GROUP_ID

# Drop privileges back to www-data
USER www-data

############################################
# CI image
############################################
FROM base AS ci

# Sometimes CI images need to run as root
# so we set the ROOT user and configure
# the PHP-FPM pool to run as www-data
USER root

############################################
# Composer dependencies
############################################

# `vendor/` is gitignored, so the production image has to build it. Dependencies
# are installed from the lock file first so the layer caches across code changes.
FROM base AS vendor

WORKDIR /var/www/html

COPY --chown=www-data:www-data composer.json composer.lock ./

RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --no-progress

COPY --chown=www-data:www-data . .

RUN composer dump-autoload --no-dev --optimize --no-interaction && \
    php artisan package:discover --ansi

############################################
# Front-end assets
############################################

# `public/build/` is gitignored too. Ziggy is imported straight out of
# `vendor/tightenco/ziggy` by resources/js/app.js, so vendor has to be in place
# before Vite runs.
FROM node:24-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .
COPY --from=vendor /var/www/html/vendor ./vendor

RUN npm run build

############################################
# Production Image
############################################

# Keep this stage last: Render builds the final stage of the Dockerfile and its
# blueprint format has no way to name a target.
FROM base AS deploy
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor
COPY --from=vendor --chown=www-data:www-data /var/www/html/bootstrap/cache /var/www/html/bootstrap/cache
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

# Create the SQLite directory and set the owner to www-data (remove this if you're not using SQLite)
RUN mkdir -p /var/www/html/.infrastructure/volume_data/sqlite/ && \
    chown -R www-data:www-data /var/www/html/.infrastructure/volume_data/sqlite/

USER www-data
