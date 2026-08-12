# syntax=docker/dockerfile:1
#
# Imagen de DESARROLLO: PHP 8.4 CLI + Composer.
#
# Node no está aquí a propósito: Vite corre en su propio servicio (`vite`, imagen
# node:22-slim). Mezclarlos engordaría esta imagen y ataría la versión de Node a
# la de PHP sin ninguna ventaja.
#
# La imagen de producción será otra, multi-stage y sin Composer. No reutilizar esta.

FROM php:8.4-cli-bookworm

# UID/GID del usuario del host. Sin esto, todo lo que escriba el contenedor
# (vendor/, storage/, ficheros de `artisan make:`) queda como root en tu WSL.
ARG UID=1000
ARG GID=1000

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libicu-dev \
        libzip-dev; \
    docker-php-ext-configure intl; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath; \
    rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# El grupo/usuario pueden existir ya con ese id en la imagen base; si es así, se reutiliza.
RUN set -eux; \
    if ! getent group "${GID}" >/dev/null; then groupadd -g "${GID}" app; fi; \
    if ! getent passwd "${UID}" >/dev/null; then \
        useradd -u "${UID}" -g "${GID}" -m -s /bin/bash app; \
    fi; \
    mkdir -p /var/www/html /home/app/.composer; \
    chown -R "${UID}:${GID}" /var/www/html /home/app

ENV COMPOSER_HOME=/home/app/.composer

USER ${UID}:${GID}
WORKDIR /var/www/html

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
