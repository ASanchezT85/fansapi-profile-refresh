# PHP CLI image shared by Horizon, the scheduler, the test runner and the local web server.
# pcntl is required by Horizon and by the queue worker to enforce job timeouts.
FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev libicu-dev \
    && docker-php-ext-install pcntl pdo_pgsql zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /app
