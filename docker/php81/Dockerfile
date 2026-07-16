FROM php:8.1-cli-bullseye

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libonig-dev libxml2-dev \
    && docker-php-ext-install mbstring xml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app
