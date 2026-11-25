FROM node:24 AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM composer:2 AS composer
FROM php:8.5-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip libzip-dev libpng-dev \
    && docker-php-ext-install pdo_mysql zip gd \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-interaction
COPY --from=frontend /app/public/build ./public/build
EXPOSE 8094
