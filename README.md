# panel-bot-gls

Panel de administración del **maestro de rutas** que consume el bot
[`bot-gls`](../bot-gls). Sustituye al `rutas.xlsx` que hoy se entrega a mano.

El contexto completo —por qué existe, el contrato con el bot, el modelo de datos y las
decisiones de arquitectura— está en **[`CONTEXTO.md`](CONTEXTO.md)**. Este README
sólo explica cómo levantarlo.

## Requisitos

**Docker, y nada más.** No hace falta PHP, Composer ni Node en el host: todo corre en
contenedores. En WSL, Docker Desktop necesita tener activada la integración con la distro
(*Settings → Resources → WSL Integration*).

## Arrancar

```bash
./bootstrap.sh
```

Crea el `.env`, construye la imagen, instala Laravel + Livewire + Tailwind, levanta
Postgres, genera la `APP_KEY`, migra y deja los tres servicios corriendo. Es idempotente:
se puede volver a lanzar sin romper nada.

Después:

| | |
|---|---|
| Panel | http://localhost:8000 |
| Vite (hot reload) | http://localhost:5173 |
| Postgres | `127.0.0.1:5432` |

**Antes de nada, editá el `.env`:** `DB_PASSWORD` y `RUTAS_TOKEN` vienen con valores
`CAMBIAR_*`. Para el token: `openssl rand -hex 32`, y el mismo valor va en el `.env` de
`bot-gls`.

## Día a día

```bash
docker compose exec app php artisan <lo que sea>
docker compose exec app composer <lo que sea>
docker compose exec app php artisan test

docker compose logs -f            # todos
docker compose logs -f vite       # sólo el build de front

docker compose down               # parar
docker compose down -v            # parar y BORRAR la base de datos
```

Si un comando de `artisan` deja ficheros con dueño equivocado, revisá que `UID`/`GID` del
`.env` coincidan con `id -u` / `id -g`.

## Servicios

| Servicio | Qué es | Puerto |
|---|---|---|
| `app` | PHP 8.4 + Composer, corre `artisan serve` | 8000 |
| `vite` | `node:22-slim`, corre `npm run dev` | 5173 |
| `postgres` | Postgres 17 | 5432 |

Node vive en su propio contenedor y no está en la imagen de PHP: son ciclos de vida
distintos y mezclarlos ataría la versión de Node a la de PHP sin ganar nada.

Los puertos se cambian desde el `.env` (`APP_PORT`, `VITE_PORT`, `DB_PORT_HOST`) si tenés
algo ocupándolos.

> El `Dockerfile` es **de desarrollo** — trae Composer y monta el código por volumen. La
> imagen de producción será otra, y está pendiente (`CONTEXTO.md` §7, fase 5).
