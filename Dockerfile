FROM docker.io/serversideup/php:8.4-fpm-nginx-v4.5.3

ENV PHP_OPCACHE_ENABLE=1

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize
