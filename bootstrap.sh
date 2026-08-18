#!/usr/bin/env bash
#
# Arranque del proyecto desde cero. Idempotente: se puede volver a correr.
#
#   ./bootstrap.sh
#
# Lo único que hace falta en el host es Docker. No hay PHP, Composer ni Node
# fuera de los contenedores, a propósito (ver CONTEXTO_BOT.md §5).

set -euo pipefail
cd "$(dirname "$0")"

paso() { printf '\n\033[1;34m▸ %s\033[0m\n' "$1"; }
aviso() { printf '\033[1;33m  ! %s\033[0m\n' "$1"; }

command -v docker >/dev/null || {
    echo "Falta docker. Si estás en WSL: Docker Desktop → Settings → Resources →" >&2
    echo "WSL Integration → activá tu distro → Apply & Restart." >&2
    exit 1
}

# --- 1. .env ----------------------------------------------------------------
paso "Preparando .env"
if [ ! -f .env ]; then
    cp .env.example .env
    # El UID/GID reales de quien corre esto, o los ficheros salen como root.
    sed -i "s/^UID=.*/UID=$(id -u)/; s/^GID=.*/GID=$(id -g)/" .env
    aviso ".env creado desde la plantilla. Cambiá DB_PASSWORD y RUTAS_TOKEN antes de exponer nada."
    aviso "Para el token: openssl rand -hex 32"
else
    echo "  .env ya existe, no lo toco."
fi

# --- 2. Imagen --------------------------------------------------------------
paso "Construyendo la imagen de PHP"
docker compose build app

# --- 3. Scaffold de Laravel -------------------------------------------------
# `composer create-project` exige un directorio vacío, y aquí ya viven el
# Dockerfile, el compose y CONTEXTO_BOT.md. Por eso se crea en /tmp y se copia
# con `cp -rn` (no-clobber): lo que ya existe en el repo manda.
if [ ! -f artisan ]; then
    paso "Instalando Laravel"
    docker compose run --rm --no-deps app bash -c '
        set -e
        composer create-project laravel/laravel /tmp/laravel --no-interaction --prefer-dist
        rm -f /tmp/laravel/.env /tmp/laravel/.env.example /tmp/laravel/.gitignore
        cp -rn /tmp/laravel/. /var/www/html/
        rm -rf /tmp/laravel
    '
else
    echo "  Laravel ya está instalado (existe artisan)."
fi

# --- 4. Livewire ------------------------------------------------------------
if [ ! -d vendor/livewire ]; then
    paso "Instalando Livewire"
    docker compose run --rm --no-deps app composer require livewire/livewire --no-interaction
else
    echo "  Livewire ya está instalado."
fi

# --- 5. Tailwind ------------------------------------------------------------
# Según la versión del esqueleto, Tailwind puede venir ya en package.json.
if ! grep -q '"tailwindcss"' package.json 2>/dev/null; then
    paso "Instalando Tailwind"
    docker compose run --rm --entrypoint sh vite \
        -c "npm install -D tailwindcss @tailwindcss/vite"
    aviso "Falta cablearlo: @tailwindcss/vite en vite.config.js y @import \"tailwindcss\"; en resources/css/app.css"
else
    echo "  Tailwind ya está en package.json."
fi

# --- 6. Base de datos -------------------------------------------------------
paso "Levantando Postgres"
docker compose up -d postgres
echo "  Esperando a que acepte conexiones..."
until [ "$(docker compose ps -q postgres | xargs -r docker inspect -f '{{.State.Health.Status}}')" = "healthy" ]; do
    sleep 2
done

# Los tests corren sobre Postgres, no sobre sqlite: el esquema usa una columna
# generada que sqlite no sabe ejecutar (CONTEXTO.md §4). Necesitan su propia base
# para no pisar la de desarrollo en cada `migrate:fresh`.
echo "  Creando la base de tests si no existe..."
DB_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2)
docker compose exec -T postgres psql -U "$DB_USER" -d postgres \
    -tc "SELECT 1 FROM pg_database WHERE datname='panel_testing'" | grep -q 1 \
    || docker compose exec -T postgres createdb -U "$DB_USER" panel_testing

paso "Clave de aplicación y migraciones"
grep -q '^APP_KEY=.\+' .env || docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --force

# Roles y permisos: sin el catálogo sembrado no hay ningún permiso que conceder
# y cualquier cuenta se queda en la puerta de todas las pantallas (CONTEXTO.md
# §7, fase 12). Es idempotente, así que repetirlo no rompe nada.
docker compose run --rm app php artisan db:seed --class=RolesAndPermissionsSeeder --force

# --- 7. Arriba --------------------------------------------------------------
paso "Levantando todo"
docker compose up -d

printf '\n\033[1;32m✓ Listo.\033[0m\n'
echo "  Panel:    http://localhost:$(grep -E '^APP_PORT=' .env | cut -d= -f2)"
echo "  Vite:     http://localhost:$(grep -E '^VITE_PORT=' .env | cut -d= -f2)"
echo "  Postgres: 127.0.0.1:$(grep -E '^DB_PORT_HOST=' .env | cut -d= -f2)"
echo
echo "  Logs:     docker compose logs -f"
echo "  Artisan:  docker compose exec app php artisan <lo que sea>"
