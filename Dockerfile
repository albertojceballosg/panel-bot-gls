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

# `pg_dump` y `pg_restore` para el módulo de copias de seguridad (CONTEXTO.md §7,
# fase 7). No son una dependencia del stack de la regla 2 de `CLAUDE.md` —no hay
# paquete de Composer ni de npm por medio—, son las herramientas oficiales del
# motor que ya usamos.
#
# **Del repositorio de PostgreSQL y no del de Debian**: bookworm trae el cliente
# 15, y `pg_dump` se niega a volcar un servidor más nuevo que él («server version
# mismatch»). El nuestro es el 17 (`docker-compose.yml`), así que el cliente tiene
# que ser 17. Si algún día se sube el servidor, esta versión sube con él.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends curl ca-certificates gnupg; \
    install -d /usr/share/postgresql-common/pgdg; \
    curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
        -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc; \
    echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
        > /etc/apt/sources.list.d/pgdg.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends postgresql-client-17; \
    rm -rf /var/lib/apt/lists/*

# PHP viene con 2 MB de subida, que no da ni para el volcado de una base
# pequeña: la pantalla de copias (§7, fase 7) deja subir uno para restaurarlo, y
# con el valor de fábrica el navegador cortaría el fichero sin decir por qué.
RUN printf 'upload_max_filesize=64M\npost_max_size=64M\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

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
