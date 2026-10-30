FROM docker.io/serversideup/php:8.4-fpm-nginx-v4.5.3

ENV PHP_OPCACHE_ENABLE=1

# CVE-2026-111497: a v4.5.3 da imagem base traz o libbrotli1 1.1.0-2+b7; a correção (1.1.0-2+deb13u1) vem do apt.
# Pode sair quando a imagem base for atualizada para uma versão que já traga o pacote corrigido.
USER root
RUN apt-get update \
    && apt-get install -y --no-install-recommends --only-upgrade libbrotli1 \
    && rm -rf /var/lib/apt/lists/*
USER www-data

COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader

COPY --chown=www-data:www-data . .
RUN composer dump-autoload --no-dev --optimize
